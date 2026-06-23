"""
FastAPI agent for MyPOS.

Endpoints:
  POST /chat
  GET  /escalaciones
  POST /escalaciones/{thread_id}/responder
  GET  /health
"""

from __future__ import annotations

import asyncio
from datetime import date, timedelta
import time
import unicodedata
import uuid
from typing import Optional

from fastapi import FastAPI, HTTPException, Request, Security
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from fastapi.security.api_key import APIKeyHeader
from pydantic import BaseModel

from config import settings

_graph = None
_SECRET_HEADER = APIKeyHeader(name="X-Agent-Secret", auto_error=False)
_RUN_TIMEOUT_SECONDS = 35
_last_llm_call_at = 0.0
_quota_blocked_until = 0.0
_provider_busy_until = 0.0
_quota_lock = asyncio.Lock()


def _quota_seconds_left() -> int:
    return max(0, int(_quota_blocked_until - time.time()))


def _provider_busy_seconds_left() -> int:
    return max(0, int(_provider_busy_until - time.time()))


def _quota_message(seconds: int) -> str:
    minutes = max(1, (seconds + 59) // 60)
    return (
        "Gemini alcanzo su cuota temporal. "
        f"Voy a evitar nuevas llamadas IA por aproximadamente {minutes} min. "
        "Mientras tanto puedo responder consultas directas como: ventas de hoy, "
        "producto por codigo/nombre, stock y cajas."
    )


def _provider_busy_message(seconds: int) -> str:
    minutes = max(1, (seconds + 59) // 60)
    return (
        "El servicio de IA esta con alta demanda en este momento. "
        f"Intentemos de nuevo en aproximadamente {minutes} min. "
        "Mientras tanto puedo responder consultas directas como ventas de hoy, "
        "producto por codigo/nombre, stock y cajas."
    )


def _is_quota_error(exc: Exception) -> bool:
    text = f"{exc.__class__.__name__}: {exc}".lower()
    return (
        "resource_exhausted" in text
        or "429" in text
        or "quota" in text
        or "rate limit" in text
    )


def _is_provider_busy_error(exc: Exception) -> bool:
    text = f"{exc.__class__.__name__}: {exc}".lower()
    return (
        "503" in text
        or "unavailable" in text
        or "high demand" in text
        or "overloaded" in text
        or "temporarily unavailable" in text
    )


async def _reserve_llm_slot() -> Optional[ChatResponse]:
    global _last_llm_call_at

    busy_seconds_left = _provider_busy_seconds_left()
    if busy_seconds_left > 0:
        return ChatResponse(
            thread_id="",
            reply=_provider_busy_message(busy_seconds_left),
            escalated=False,
        )

    seconds_left = _quota_seconds_left()
    if seconds_left > 0:
        return ChatResponse(
            thread_id="",
            reply=_quota_message(seconds_left),
            escalated=False,
        )

    async with _quota_lock:
        seconds_left = _quota_seconds_left()
        if seconds_left > 0:
            return ChatResponse(
                thread_id="",
                reply=_quota_message(seconds_left),
                escalated=False,
            )

        elapsed = time.time() - _last_llm_call_at
        min_interval = max(0, int(settings.llm_min_interval_seconds))
        if elapsed < min_interval:
            wait = max(1, int(min_interval - elapsed))
            return ChatResponse(
                thread_id="",
                reply=(
                    "Estoy regulando las consultas IA para no agotar la cuota. "
                    f"Espera {wait} segundos o usa una consulta directa "
                    "(ventas de hoy, cajas, producto o stock)."
                ),
                escalated=False,
            )

        _last_llm_call_at = time.time()
        return None


def _mark_quota_exhausted() -> int:
    global _quota_blocked_until
    cooldown = max(60, int(settings.llm_quota_cooldown_seconds))
    _quota_blocked_until = time.time() + cooldown
    return cooldown


def _mark_provider_busy() -> int:
    global _provider_busy_until
    cooldown = min(max(60, int(settings.llm_quota_cooldown_seconds)), 180)
    _provider_busy_until = time.time() + cooldown
    return cooldown


def _require_secret(key: Optional[str] = Security(_SECRET_HEADER)) -> None:
    if not settings.agent_secret:
        raise HTTPException(status_code=503, detail="AGENT_SECRET no configurado")
    if key != settings.agent_secret:
        raise HTTPException(status_code=401, detail="X-Agent-Secret invalido o ausente")


async def _get_graph():
    global _graph
    if _graph is not None:
        return _graph

    from graph.builder import build_graph

    _graph = await build_graph()
    return _graph


import re as _re

_RUT_PATTERN = _re.compile(r"\b\d{7,8}[-\s]?[0-9kK]\b")

# Prefijos que se eliminan para extraer el término de búsqueda
_PRODUCT_PREFIXES = (
    "cuanto vale ", "cuanto cuesta ", "que valor tiene ",
    "precio de ", "valor de ", "precio del ", "valor del ",
    "busca ", "buscar ", "consulta ", "consultar ",
    "producto ", "el producto ", "la ", "el ",
    "stock de ", "stock del ", "stock ",
    "codigo ", "el codigo ",
)
_CLIENT_PREFIXES = (
    "busca al cliente ", "busca cliente ", "buscar cliente ",
    "busca a ", "cliente ", "clientes ",
    "datos de ", "info de ", "informacion de ",
    "busca el rut ", "rut ",
)


def _normalize_text(value: str) -> str:
    normalized = unicodedata.normalize("NFKD", value.lower())
    return "".join(ch for ch in normalized if not unicodedata.combining(ch))


def _strip_prefixes(text: str, prefixes: tuple[str, ...]) -> str:
    """Elimina el primer prefijo que coincida al inicio del texto normalizado."""
    cleaned = text.strip(" ?!.,;:")
    norm = _normalize_text(cleaned)
    for prefix in prefixes:
        if norm.startswith(prefix):
            return cleaned[len(prefix):].strip(" ?!.,;:")
    return cleaned


def _contains_any(text: str, terms: tuple[str, ...]) -> bool:
    return any(t in text for t in terms)


def _detect_period(text: str) -> str:
    """Detecta el período de ventas a partir del texto normalizado."""
    if "ayer" in text:
        return "ayer"
    if "semana" in text:
        return "semana"
    if "mes anterior" in text or "mes pasado" in text or "ultimo mes" in text:
        return "mes_anterior"
    if "mes" in text or "este mes" in text or "mensual" in text:
        return "mes"
    return "hoy"


def _is_sales_query(text: str) -> bool:
    sales_words = ("venta", "ventas", "vendimos", "vendido", "vendidos", "facturamos", "recaudamos")
    return _contains_any(text, sales_words)


def _is_top_products_query(text: str) -> bool:
    return _contains_any(text, ("mas vendido", "mas vendidos", "top producto", "top productos",
                                "mejor vendido", "mejores vendidos", "ranking producto"))


def _is_boxes_query(text: str) -> bool:
    return _contains_any(text, ("caja", "cajas")) and not _contains_any(text, ("cierre", "cierres"))


def _is_closures_query(text: str) -> bool:
    return _contains_any(text, ("cierre", "cierres", "sin cerrar", "pendiente de cierre",
                                "cierres del dia", "cierres hoy", "caja sin cerrar"))


def _is_stock_critical_query(text: str) -> bool:
    return _contains_any(text, (
        "stock bajo", "bajo stock", "stock critico", "stock minimo",
        "agotando", "agotado", "por agotarse", "sin stock", "quedando poco",
        "que reponer", "que comprar", "que falta", "productos faltantes",
        "alerta stock", "stock en alerta",
    ))


def _is_client_query(text: str) -> bool:
    return _contains_any(text, ("cliente", "clientes", "busca a ")) or bool(_RUT_PATTERN.search(text))


def _is_iva_query(text: str) -> bool:
    return _contains_any(text, ("iva", "impuesto", "debito fiscal", "credito fiscal",
                                "libro iva", "cuanto pago sii", "declaracion"))


def _is_purchase_query(text: str) -> bool:
    return _contains_any(text, ("compra", "compras", "orden de compra", "ordenes de compra",
                                "oc pendiente", "pedido pendiente"))


def _is_restock_query(text: str) -> bool:
    return _contains_any(text, ("sugerencia", "sugerencias", "reponer", "reposicion",
                                "que debo comprar", "que conviene comprar", "que me sugiere"))


def _is_folios_query(text: str) -> bool:
    return _contains_any(text, ("folio", "folios", "caf", "boletas quedan",
                                "facturas quedan", "folios sii", "folios disponibles"))


def _is_product_search(text: str, raw: str) -> bool:
    """Detecta búsqueda de producto específico: código numérico, código de barras o precio."""
    # Código de barras o número largo
    if _re.search(r"\b\d{5,}\b", raw):
        return True
    # Prefijos explícitos de precio/búsqueda
    return _contains_any(text, (
        "cuanto vale ", "cuanto cuesta ", "precio de ", "valor de ",
        "busca ", "buscar ", "consultar ", "precio del ", "valor del ",
    ))


async def _direct(tool_coro, thread_id: str) -> ChatResponse:
    reply = await tool_coro
    return ChatResponse(thread_id=thread_id, reply=str(reply), escalated=False)


async def _try_direct_intent(
    message: str,
    empresa_id: int,
    thread_id: str,
    sucursal_id: Optional[int] = None,
) -> Optional[ChatResponse]:
    """
    Detecta intents conocidos y los resuelve directamente con la tool correspondiente,
    sin pasar por el LLM. Cubre ~85% de las consultas habituales.
    """
    text = _normalize_text(message)

    # ── 1. Ranking de productos más vendidos ─────────────────────────────────
    if _is_top_products_query(text):
        from tools.ventas import ventas_por_producto
        today = date.today()
        periodo = _detect_period(text)
        if periodo == "ayer":
            fd = str(today - timedelta(days=1)); ft = fd
        elif periodo == "semana":
            fd = str(today - timedelta(days=today.weekday())); ft = str(today)
        elif periodo in ("mes_anterior",):
            primero = today.replace(day=1)
            ultimo_ant = primero - timedelta(days=1)
            fd = str(ultimo_ant.replace(day=1)); ft = str(ultimo_ant)
        else:
            fd = str(today.replace(day=1)); ft = str(today)
        return await _direct(
            ventas_por_producto.ainvoke({
                "empresa_id": empresa_id, "fecha_desde": fd, "fecha_hasta": ft,
                "top": 10, "sucursal_id": sucursal_id,
            }),
            thread_id,
        )

    # ── 2. Ventas por período ─────────────────────────────────────────────────
    if _is_sales_query(text):
        from tools.ventas import ventas_periodo
        periodo = _detect_period(text)
        return await _direct(
            ventas_periodo.ainvoke({
                "empresa_id": empresa_id, "periodo": periodo, "sucursal_id": sucursal_id,
            }),
            thread_id,
        )

    # ── 3. Stock crítico / productos agotándose ───────────────────────────────
    if _is_stock_critical_query(text):
        from tools.stock import stock_critico
        return await _direct(
            stock_critico.ainvoke({"empresa_id": empresa_id, "sucursal_id": sucursal_id}),
            thread_id,
        )

    # ── 4. Sugerencias de reposición ─────────────────────────────────────────
    if _is_restock_query(text):
        from tools.compras import sugerencias_reposicion
        return await _direct(
            sugerencias_reposicion.ainvoke({"empresa_id": empresa_id, "sucursal_id": sucursal_id}),
            thread_id,
        )

    # ── 5. Cajas ─────────────────────────────────────────────────────────────
    if _is_boxes_query(text):
        from tools.caja import estado_cajas
        return await _direct(
            estado_cajas.ainvoke({"empresa_id": empresa_id, "sucursal_id": sucursal_id}),
            thread_id,
        )

    # ── 6. Cierres diarios pendientes ────────────────────────────────────────
    if _is_closures_query(text):
        from tools.cierres import cierres_pendientes
        return await _direct(
            cierres_pendientes.ainvoke({"empresa_id": empresa_id, "sucursal_id": sucursal_id}),
            thread_id,
        )

    # ── 7. IVA / libros tributarios ──────────────────────────────────────────
    if _is_iva_query(text):
        from tools.libros import resumen_iva
        return await _direct(
            resumen_iva.ainvoke({"empresa_id": empresa_id}),
            thread_id,
        )

    # ── 8. Compras pendientes ─────────────────────────────────────────────────
    if _is_purchase_query(text) and not _is_sales_query(text):
        from tools.compras import compras_pendientes
        return await _direct(
            compras_pendientes.ainvoke({"empresa_id": empresa_id, "sucursal_id": sucursal_id}),
            thread_id,
        )

    # ── 9. Folios SII ─────────────────────────────────────────────────────────
    if _is_folios_query(text):
        from tools.folios import estado_folios_sii
        return await _direct(
            estado_folios_sii.ainvoke({"empresa_id": empresa_id}),
            thread_id,
        )

    # ── 10. Buscar cliente ────────────────────────────────────────────────────
    if _is_client_query(text):
        from tools.clientes import buscar_cliente
        query = _strip_prefixes(message, _CLIENT_PREFIXES)
        if len(query) >= 3:
            return await _direct(
                buscar_cliente.ainvoke({"empresa_id": empresa_id, "query": query}),
                thread_id,
            )

    # ── 11. Búsqueda de producto específico ───────────────────────────────────
    if _is_product_search(text, message):
        from tools.stock import buscar_producto
        query = _strip_prefixes(message, _PRODUCT_PREFIXES)
        if len(query) >= 2:
            return await _direct(
                buscar_producto.ainvoke({"empresa_id": empresa_id, "query": query}),
                thread_id,
            )

    return None


def _provider_ready() -> tuple[bool, str]:
    required_keys = {
        "anthropic": ("ANTHROPIC_API_KEY", settings.anthropic_api_key),
        "openai": ("OPENAI_API_KEY", settings.openai_api_key),
        "google_genai": ("GOOGLE_API_KEY", settings.google_api_key),
    }

    if settings.llm_provider == "ollama":
        return True, ""

    if settings.llm_provider not in required_keys:
        return False, f"LLM_PROVIDER no soportado: {settings.llm_provider}"

    key_name, key_value = required_keys[settings.llm_provider]
    if not key_value:
        return False, f"{key_name} no configurado para LLM_PROVIDER={settings.llm_provider}"

    return True, ""


app = FastAPI(title="MyPOS Agent", docs_url=None, redoc_url=None)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["https://www.mypos.cl", "https://mypos.cl"],
    allow_methods=["POST", "GET"],
    allow_headers=["Content-Type", "X-Agent-Secret"],
)


@app.exception_handler(Exception)
async def unhandled_exception_handler(request: Request, exc: Exception):
    detail = str(exc).strip() or exc.__class__.__name__
    if len(detail) > 240:
        detail = detail[:240] + "..."

    return JSONResponse(
        status_code=500,
        content={
            "detail": f"Error interno del agente IA: {detail}",
            "error_type": exc.__class__.__name__,
        },
    )


class ChatRequest(BaseModel):
    message: str
    empresa_id: int
    sucursal_id: Optional[int] = None
    operator_name: str = ""
    thread_id: Optional[str] = None


class ChatResponse(BaseModel):
    thread_id: str
    reply: str
    escalated: bool = False


class EscalationReply(BaseModel):
    decision: str
    comentario: str = ""


async def _run(
    message: str,
    empresa_id: int,
    thread_id: str,
    sucursal_id: Optional[int] = None,
    operator_name: str = "",
) -> ChatResponse:
    direct = await _try_direct_intent(
        message=message,
        empresa_id=empresa_id,
        thread_id=thread_id,
        sucursal_id=sucursal_id,
    )
    if direct is not None:
        return direct

    ready, reason = _provider_ready()
    if not ready:
        raise HTTPException(status_code=503, detail=reason)

    quota_response = await _reserve_llm_slot()
    if quota_response is not None:
        quota_response.thread_id = thread_id
        return quota_response

    try:
        graph = await asyncio.wait_for(_get_graph(), timeout=10)
    except asyncio.TimeoutError as exc:
        raise HTTPException(
            status_code=504,
            detail="No se pudo inicializar el proveedor IA en 10 segundos",
        ) from exc
    config = {"configurable": {"thread_id": thread_id}}

    state = {
        "messages": [{"role": "user", "content": message}],
        "empresa_id": empresa_id,
        "sucursal_id": sucursal_id,
        "operator_name": operator_name,
        "channel": "web",
        "escalated": False,
        "escalation_reason": "",
    }

    try:
        result = await asyncio.wait_for(
            graph.ainvoke(state, config=config),
            timeout=_RUN_TIMEOUT_SECONDS,
        )
    except asyncio.TimeoutError as exc:
        raise HTTPException(
            status_code=504,
            detail=f"El proveedor IA no respondio en {_RUN_TIMEOUT_SECONDS} segundos",
        ) from exc
    except Exception as exc:
        if _is_quota_error(exc):
            cooldown = _mark_quota_exhausted()
            return ChatResponse(
                thread_id=thread_id,
                reply=_quota_message(cooldown),
                escalated=False,
            )
        if _is_provider_busy_error(exc):
            cooldown = _mark_provider_busy()
            return ChatResponse(
                thread_id=thread_id,
                reply=_provider_busy_message(cooldown),
                escalated=False,
            )
        raise

    snapshot = await graph.aget_state(config)
    if "escalate" in (snapshot.next or []):
        last = result["messages"][-1]
        reason = ""
        for tool_call in getattr(last, "tool_calls", []):
            if tool_call["name"] == "solicitar_aprobacion_humana":
                reason = tool_call["args"].get("motivo", "")
                break

        return ChatResponse(
            thread_id=thread_id,
            reply=f"Esta accion requiere aprobacion de un supervisor: {reason}",
            escalated=True,
        )

    last = result["messages"][-1]
    return ChatResponse(thread_id=thread_id, reply=getattr(last, "content", "") or "")


@app.post("/chat", response_model=ChatResponse)
async def chat(req: ChatRequest, _: None = Security(_require_secret)):
    thread_id = req.thread_id or f"web-{req.empresa_id}-{uuid.uuid4().hex[:8]}"
    return await _run(
        message=req.message,
        empresa_id=req.empresa_id,
        thread_id=thread_id,
        sucursal_id=req.sucursal_id,
        operator_name=req.operator_name,
    )


@app.get("/escalaciones")
async def listar_escalaciones(_: None = Security(_require_secret)):
    return {"escalaciones": [], "nota": "implementar query sobre agent.db"}


@app.post("/escalaciones/{thread_id}/responder")
async def responder_escalacion(
    thread_id: str,
    body: EscalationReply,
    _: None = Security(_require_secret),
):
    graph = await _get_graph()
    config = {"configurable": {"thread_id": thread_id}}

    snapshot = await graph.aget_state(config)
    if "escalate" not in (snapshot.next or []):
        raise HTTPException(404, "No hay escalacion pendiente para este thread")

    await graph.aupdate_state(
        config,
        {
            "escalation_reason": f"{body.decision}: {body.comentario}",
            "escalated": False,
        },
    )
    result = await graph.ainvoke(None, config=config)
    last = result["messages"][-1]

    return {
        "thread_id": thread_id,
        "reply": getattr(last, "content", ""),
        "decision": body.decision,
    }


@app.get("/health")
async def health():
    required_config = {
        "AGENT_SECRET": bool(settings.agent_secret),
        "MYPOS_SERVICE_EMAIL": bool(settings.mypos_service_email),
        "MYPOS_SERVICE_PASSWORD": bool(settings.mypos_service_password),
        "MYPOS_API_KEY": bool(settings.mypos_api_key),
    }
    provider_key_configured = {
        "anthropic": bool(settings.anthropic_api_key),
        "openai": bool(settings.openai_api_key),
        "google_genai": bool(settings.google_api_key),
        "ollama": True,
    }.get(settings.llm_provider, False)

    return {
        "status": "ok",
        "agent": "MyPOS Agent",
        "host": "www.mypos.cl",
        "model": settings.llm_model,
        "provider": settings.llm_provider,
        "provider_key_configured": provider_key_configured,
        "quota_cooldown_seconds_left": _quota_seconds_left(),
        "provider_busy_seconds_left": _provider_busy_seconds_left(),
        "llm_min_interval_seconds": settings.llm_min_interval_seconds,
        "required_config": required_config,
        "graph_loaded": _graph is not None,
    }
