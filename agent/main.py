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
from datetime import date, datetime, timedelta
import json
import os
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
_fallback_graph = None
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
        "El asistente esta regulando consultas avanzadas por alta demanda. "
        f"Durante aproximadamente {minutes} min priorizare respuestas directas: "
        "ventas de hoy, producto por codigo/nombre, stock y cajas."
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


def _grok_ready() -> bool:
    return bool(settings.grok_api_key) and settings.llm_provider != "grok"


def _safe_log_text(value: object, limit: int = 4000) -> str:
    text = str(value or "").replace("\r\n", "\n").replace("\r", "\n").strip()
    if len(text) > limit:
        return text[:limit] + "\n...[truncado]"
    return text


def _unanswered_log_path() -> str:
    path = settings.unanswered_log_path
    if not os.path.isabs(path):
        path = os.path.join(os.path.dirname(__file__), path)
    return path


def _tool_for_intent(intent: str) -> str:
    return {
        "ventas": "ventas_periodo",
        "top_productos": "ventas_por_producto",
        "stock_critico": "stock_critico",
        "reposicion": "sugerencias_reposicion",
        "cajas": "estado_cajas",
        "cierres": "cierres_pendientes",
        "iva": "resumen_iva",
        "compras": "compras_pendientes",
        "folios": "estado_folios_sii",
        "cliente": "buscar_cliente",
        "producto": "buscar_producto",
        "stock_producto": "buscar_producto",
        "ayuda": "ayuda",
    }.get(intent, "")


def _default_params_for_intent(intent: str, periodo: str, query: str) -> dict:
    if intent == "top_productos":
        return {"periodo_default": periodo or "hoy", "top": 10}
    if intent == "ventas":
        return {"periodo_default": periodo or "hoy"}
    if intent in ("producto", "stock_producto", "cliente"):
        return {"query": query}
    return {}


def _build_unanswered_proposal(message: str, reply: str) -> dict:
    text = _normalize_text(message)
    intent, query, periodo = _detect_intent_rules(text, message)
    tool = _tool_for_intent(intent or "")
    resoluble = bool(intent and tool)

    return {
        "titulo": "Propuesta para resolver consulta no contestada",
        "resoluble": resoluble,
        "tipo": "skill_json",
        "accion_sugerida": "crear_skill" if resoluble else "clasificar_con_ia",
        "intent_sugerido": intent or "",
        "tool_sugerida": tool,
        "confianza": "alta" if resoluble else "pendiente",
        "patterns_sugeridos": [_safe_log_text(message, 300)],
        "params_sugeridos": _default_params_for_intent(intent or "", periodo, query),
        "respuesta_fallback": _safe_log_text(reply, 800),
        "notas": (
            "Puede aprobarse y crearse como skill segura porque usa una herramienta existente."
            if resoluble
            else "Requiere clasificacion humana o IA antes de crear una skill."
        ),
    }


def _load_unanswered_entries(path: str) -> list:
    if not os.path.isfile(path):
        return []
    raw = ""
    try:
        with open(path, "r", encoding="utf-8") as fh:
            raw = fh.read().strip()
        if raw == "":
            return []
        data = json.loads(raw)
        if isinstance(data, list):
            return data
        if isinstance(data, dict):
            return [data]
    except Exception:
        if raw:
            now = datetime.now().isoformat(timespec="seconds")
            return [{
                "id": "legacy_" + uuid.uuid4().hex,
                "created_at": now,
                "updated_at": now,
                "status": "pendiente",
                "source": "legacy_txt",
                "consulta": "Contenido legacy importado desde texto libre",
                "respuesta": _safe_log_text(raw),
                "propuesta": {
                    "titulo": "Migrar registro legacy",
                    "resoluble": False,
                    "tipo": "legacy_txt",
                    "accion_sugerida": "revisar_manual",
                    "intent_sugerido": "",
                    "tool_sugerida": "",
                    "confianza": "pendiente",
                    "patterns_sugeridos": [],
                    "params_sugeridos": {},
                    "notas": "Este registro venia del formato TXT anterior.",
                },
            }]
        return []
    return []


def _write_unanswered_entries(path: str, entries: list) -> None:
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(entries, fh, ensure_ascii=False, indent=2)
        fh.write("\n")


def _is_unanswered_reply(reply: str) -> bool:
    text = _normalize_text(reply)
    markers = (
        "regulando consultas avanzadas",
        "regulando las consultas",
        "alta demanda",
        "no pude responder",
        "no pudo responder",
        "no pude completar",
        "no se pudo completar",
        "no se pudo inicializar",
        "no esta disponible",
        "no respondio",
        "tardo demasiado",
        "necesita revisar su configuracion",
        "proveedor ia",
        "error interno del agente",
        "llm_provider no soportado",
        "no configurado para llm_provider",
    )
    return any(marker in text for marker in markers)


def _append_unanswered_log(
    message: str,
    reply: str,
    thread_id: str,
    empresa_id: int,
    sucursal_id: Optional[int],
    operator_name: str,
) -> None:
    path = _unanswered_log_path()
    try:
        directory = os.path.dirname(path)
        if directory:
            os.makedirs(directory, exist_ok=True)

        now = datetime.now().isoformat(timespec="seconds")
        entry = {
            "id": uuid.uuid4().hex,
            "created_at": now,
            "updated_at": now,
            "status": "pendiente",
            "source": "agent",
            "thread_id": _safe_log_text(thread_id, 200),
            "empresa_id": empresa_id,
            "sucursal_id": sucursal_id,
            "operador": _safe_log_text(operator_name, 200),
            "consulta": _safe_log_text(message),
            "respuesta": _safe_log_text(reply),
            "propuesta": _build_unanswered_proposal(message, reply),
        }
        entries = _load_unanswered_entries(path)
        entries.append(entry)
        _write_unanswered_entries(path, entries)
    except Exception:
        # El registro de aprendizaje no debe romper el chat operativo.
        return


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


async def _get_fallback_graph():
    global _fallback_graph
    if _fallback_graph is not None:
        return _fallback_graph

    from graph.builder import build_graph

    _fallback_graph = await build_graph(
        llm_provider="grok",
        llm_model=settings.grok_model,
    )
    return _fallback_graph


import re as _re

_RUT_PATTERN = _re.compile(r"\b\d{7,8}[-\s]?[0-9kK]\b")

# Prefijos que se eliminan para extraer el término de búsqueda
_PRODUCT_PREFIXES = (
    "cuanto vale ", "cuanto cuesta ", "que valor tiene ",
    "precio de ", "valor de ", "precio del ", "valor del ",
    "busca ", "buscar ", "consulta ", "consultar ",
    "producto ", "el producto ",
    "dame el stock del ", "dame el stock de ", "dame stock del ", "dame stock de ",
    "ver stock de ", "ver stock del ", "muestra el stock de ", "muestra stock de ",
    "stock del ", "stock de ", "stock ",
    "codigo ", "el codigo ",
)
_CLIENT_PREFIXES = (
    "busca al cliente ", "busca cliente ", "buscar cliente ",
    "busca a ", "cliente ", "clientes ",
    "datos de ", "info de ", "informacion de ",
    "busca el rut ", "rut ",
)

# Marcadores de pregunta natural por un producto: "¿tienen X?", "¿qué tipos de
# X hay?", "¿venden X?". Solo se evalúan al final, cuando ningún otro intent
# coincidió, así que palabras amplias como "hay"/"tiene" son seguras aquí.
_PRODUCT_QUESTION_MARKERS = (
    "tipos de ", "tipo de ", "clases de ", "clase de ",
    "variedad de ", "variedades de ", "que marcas", "que marca de ",
    "tienen ", "tienes ", "tiene ", "tenes ", "hay ",
    "venden ", "vende ", "vendes ", "manejan ", "trabajan con ",
    "cuentan con ", "disponen de ", "ofrecen ", "tendran ", "tendras ",
    "que productos", "cuales productos", "muestrame", "muestra ",
    "lista de productos", "lista de precios",
)

# Prefijos a remover para extraer el término de producto de una pregunta natural.
# Se combinan con _PRODUCT_PREFIXES y se ordenan de más largo a más corto para
# que el strip tome siempre la coincidencia más específica.
_PRODUCT_QUESTION_PREFIXES = tuple(sorted(set((
    "que tipos de ", "que tipo de ", "que clases de ", "que clase de ",
    "tipos de ", "tipo de ", "clases de ", "clase de ",
    "variedades de ", "variedad de ", "que marcas de ", "que marca de ",
    "que productos de ", "que productos ", "cuales productos ",
    "me puedes mostrar ", "puedes mostrarme ", "muestrame los ", "muestrame las ",
    "muestrame ", "muestra los ", "muestra las ", "muestra ",
    "que ", "cuales ", "cuanto ",
    "tienen disponible ", "tienen disponibles ", "tienen ", "tienes ", "tiene ",
    "tenes ", "hay ", "venden ", "vende ", "vendes ", "manejan ",
    "trabajan con ", "cuentan con ", "disponen de ", "ofrecen ",
    "tendran ", "tendras ", "me das ", "dame ",
    "lista de precios de ", "lista de ",
)) | set(_PRODUCT_PREFIXES), key=len, reverse=True))

# Sufijos a remover ("...que tiene", "...disponibles", "...en stock").
_PRODUCT_QUESTION_SUFFIXES = (
    " que tienen", " que tiene", " que tienes", " que hay", " que venden",
    " tienen", " tiene", " tienes", " hay", " venden", " vende",
    " disponibles", " disponible", " en stock", " a la venta", " para vender",
)

# Términos que tras el strip no sirven como búsqueda → mejor pasar al clasificador.
_PRODUCT_STOPWORDS = frozenset({
    "", "alguien", "algo", "eso", "esto", "esa", "ese", "productos", "producto",
    "cosas", "cosa", "stock", "precio", "precios", "marcas", "marca", "tipos",
    "tipo", "clase", "clases", "variedad", "aqui", "alla", "disponible",
    "disponibles", "venta", "ventas",
})

# Menú de ayuda — respuesta sin IA cuando el operador pregunta qué puede hacer.
_HELP_TEXT = (
    "Puedo consultar en tiempo real (sin esperar IA):\n"
    "• Ventas: 'ventas de hoy', 'cuánto vendimos ayer', 'ventas del mes'\n"
    "• Más vendidos: 'top productos del mes', 'lo más vendido de la semana'\n"
    "• Productos: 'precio del aceite 1L', '¿qué tipos de carne tiene?', '¿tienen coca cola?'\n"
    "• Stock: 'stock crítico', '¿qué se está agotando?', 'stock del pan'\n"
    "• Reposición: '¿qué me sugieres reponer?'\n"
    "• Cajas: 'estado de las cajas', 'cierres pendientes'\n"
    "• Compras: 'órdenes de compra pendientes'\n"
    "• Finanzas: 'IVA del mes', '¿cuánto pago al SII?'\n"
    "• SII: '¿cuántos folios quedan?'\n"
    "• Clientes: 'busca al cliente Juan Pérez', 'cliente RUT 12345678-9'"
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
    return _contains_any(text, (
        "mas vendido", "mas vendidos", "top producto", "top productos",
        "mejor vendido", "mejores vendidos", "ranking producto",
        "producto mas vendido", "productos mas vendidos",
        "se vendio mas", "vendio mas", "se vende mas", "vende mas",
        "producto se vendio", "producto que mas se vendio",
        "producto que mas estamos vendiendo", "que mas estamos vendiendo",
        "producto que mas vendemos", "que mas vendemos",
    ))


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
    """Detecta búsqueda de producto específico: código, nombre o precio."""
    # Código de barras o número largo (EAN, UPC, etc.)
    if _re.search(r"\b\d{5,}\b", raw):
        return True
    # "stock de/del [producto]" o "dame el stock de [producto]"
    if _contains_any(text, ("stock de ", "stock del ", "dame el stock", "dame stock",
                            "ver stock de ", "muestra stock de ")):
        return True
    # Prefijos explícitos de precio/búsqueda
    return _contains_any(text, (
        "cuanto vale ", "cuanto cuesta ", "precio de ", "valor de ",
        "precio del ", "valor del ",
        "busca ", "buscar ", "consultar ",
        "dame el precio", "dame el valor",
    ))


def _is_natural_product_query(text: str) -> bool:
    """Pregunta en lenguaje natural por un producto: '¿tienen X?', '¿hay X?'."""
    if _contains_any(text, _PRODUCT_QUESTION_MARKERS):
        return True
    # Verbo al final: 'que carnes hay', 'que vinos tienen', 'que quesos venden'.
    return any(
        text == verb or text.endswith(" " + verb)
        for verb in ("hay", "tienen", "tiene", "tienes", "venden", "vende")
    )


def _is_help_query(text: str) -> bool:
    """El operador pregunta qué puede hacer el asistente."""
    return _contains_any(text, (
        "que puedes hacer", "que sabes hacer", "que podes hacer",
        "en que me ayudas", "en que puedes ayudarme", "como me ayudas",
        "que consultas puedo", "que puedo preguntar", "que puedo consultar",
        "para que sirves", "opciones", "menu de ayuda", "ayuda",
    ))


def _extract_product_query(raw: str) -> str:
    """
    Extrae el término de búsqueda de una pregunta sobre productos, quitando
    palabras-pregunta, verbos y artículos. '¿Qué tipos de carne tiene?' → 'carne'.
    Devuelve '' si lo que queda no sirve como búsqueda (stopword).
    """
    cleaned = raw.strip(" ?!.,;:¿¡")

    # Quita prefijos iterativamente (más largo primero).
    changed = True
    while changed:
        changed = False
        norm = _normalize_text(cleaned)
        for prefix in _PRODUCT_QUESTION_PREFIXES:
            if norm.startswith(prefix):
                cleaned = cleaned[len(prefix):].strip(" ?!.,;:¿¡")
                changed = True
                break

    # Quita sufijos ("...que tiene", "...en stock").
    norm = _normalize_text(cleaned)
    for suffix in _PRODUCT_QUESTION_SUFFIXES:
        if norm.endswith(suffix):
            cleaned = cleaned[: len(cleaned) - len(suffix)].strip(" ?!.,;:¿¡")
            break

    if _normalize_text(cleaned) in _PRODUCT_STOPWORDS:
        return ""
    return cleaned


def _periodo_a_fechas(periodo: str) -> tuple[str, str]:
    """(fecha_desde, fecha_hasta) para un período predefinido. Default: mes a la fecha."""
    today = date.today()
    if periodo == "ayer":
        d = today - timedelta(days=1)
        return str(d), str(d)
    if periodo == "semana":
        return str(today - timedelta(days=today.weekday())), str(today)
    if periodo == "mes_anterior":
        primero = today.replace(day=1)
        ultimo_ant = primero - timedelta(days=1)
        return str(ultimo_ant.replace(day=1)), str(ultimo_ant)
    if periodo == "hoy":
        return str(today), str(today)
    return str(today.replace(day=1)), str(today)


async def _direct(tool_coro, thread_id: str) -> ChatResponse:
    reply = await tool_coro
    return ChatResponse(thread_id=thread_id, reply=str(reply), escalated=False)


async def _dispatch_intent(
    intent: str,
    empresa_id: int,
    thread_id: str,
    sucursal_id: Optional[int] = None,
    query: str = "",
    periodo: str = "",
) -> Optional[ChatResponse]:
    """
    Ejecuta la tool correspondiente a un intent ya identificado. Lo usan tanto
    el router por reglas como el clasificador Gemini Flash, para no duplicar la
    lógica de despacho. Devuelve None si el intent no se puede resolver aquí
    (faltan datos, o es 'accion'/'desconocido' que maneja el agente completo).
    """
    if intent == "ventas":
        from tools.ventas import ventas_periodo
        return await _direct(ventas_periodo.ainvoke({
            "empresa_id": empresa_id, "periodo": periodo or "hoy",
            "sucursal_id": sucursal_id,
        }), thread_id)

    if intent == "top_productos":
        from tools.ventas import ventas_por_producto
        fd, ft = _periodo_a_fechas(periodo or "mes")
        return await _direct(ventas_por_producto.ainvoke({
            "empresa_id": empresa_id, "fecha_desde": fd, "fecha_hasta": ft,
            "top": 10, "sucursal_id": sucursal_id,
        }), thread_id)

    if intent == "stock_critico":
        from tools.stock import stock_critico
        return await _direct(stock_critico.ainvoke({
            "empresa_id": empresa_id, "sucursal_id": sucursal_id,
        }), thread_id)

    if intent == "reposicion":
        from tools.compras import sugerencias_reposicion
        return await _direct(sugerencias_reposicion.ainvoke({
            "empresa_id": empresa_id, "sucursal_id": sucursal_id,
        }), thread_id)

    if intent == "cajas":
        from tools.caja import estado_cajas
        return await _direct(estado_cajas.ainvoke({
            "empresa_id": empresa_id, "sucursal_id": sucursal_id,
        }), thread_id)

    if intent == "cierres":
        from tools.cierres import cierres_pendientes
        return await _direct(cierres_pendientes.ainvoke({
            "empresa_id": empresa_id, "sucursal_id": sucursal_id,
        }), thread_id)

    if intent == "iva":
        from tools.libros import resumen_iva
        return await _direct(resumen_iva.ainvoke({"empresa_id": empresa_id}), thread_id)

    if intent == "compras":
        from tools.compras import compras_pendientes
        return await _direct(compras_pendientes.ainvoke({
            "empresa_id": empresa_id, "sucursal_id": sucursal_id,
        }), thread_id)

    if intent == "folios":
        from tools.folios import estado_folios_sii
        return await _direct(estado_folios_sii.ainvoke({"empresa_id": empresa_id}), thread_id)

    if intent == "cliente":
        if len(query.strip()) >= 3:
            from tools.clientes import buscar_cliente
            return await _direct(buscar_cliente.ainvoke({
                "empresa_id": empresa_id, "query": query.strip(),
            }), thread_id)
        return None

    if intent in ("producto", "stock_producto"):
        if len(query.strip()) >= 2:
            from tools.stock import buscar_producto
            return await _direct(buscar_producto.ainvoke({
                "empresa_id": empresa_id, "query": query.strip(),
            }), thread_id)
        return None

    if intent == "ayuda":
        return ChatResponse(thread_id=thread_id, reply=_HELP_TEXT, escalated=False)

    # accion / desconocido → lo resuelve el agente completo (escalación)
    return None


def _detect_intent_rules(text: str, raw: str) -> tuple[Optional[str], str, str]:
    """
    Detecta el intent por reglas (sin IA). Devuelve (intent, query, periodo).
    El orden importa: lo más específico primero; producto queda de último porque
    sus marcadores naturales ('hay', 'tiene') son amplios y solo deben capturar
    lo que nada más reconoció.
    """
    if _is_top_products_query(text):
        return "top_productos", "", _detect_period(text)
    if _is_sales_query(text):
        return "ventas", "", _detect_period(text)
    if _is_stock_critical_query(text):
        return "stock_critico", "", ""
    if _is_restock_query(text):
        return "reposicion", "", ""
    if _is_boxes_query(text):
        return "cajas", "", ""
    if _is_closures_query(text):
        return "cierres", "", ""
    if _is_iva_query(text):
        return "iva", "", ""
    if _is_purchase_query(text):
        return "compras", "", ""
    if _is_folios_query(text):
        return "folios", "", ""
    if _is_help_query(text):
        return "ayuda", "", ""
    if _is_client_query(text):
        q = _strip_prefixes(raw, _CLIENT_PREFIXES)
        if len(q) >= 3:
            return "cliente", q, ""
    if _is_product_search(text, raw) or _is_natural_product_query(text):
        q = _extract_product_query(raw)
        if len(q) >= 2:
            return "producto", q, ""
    return None, "", ""


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
    # Mensajes demasiado cortos — no tienen suficiente contexto para el LLM tampoco
    if len(message.strip()) < 3:
        return ChatResponse(
            thread_id=thread_id,
            reply=(
                "Puedo ayudarte con: ventas, stock, cajas, clientes, compras, IVA y folios SII. "
                "Intenta con: 'ventas de hoy', 'stock del aceite 1L', 'cierres pendientes'."
            ),
            escalated=False,
        )

    text = _normalize_text(message)

    # ── 0. Intent ambiguo: "qué productos debería revisar" ───────────────────
    if _contains_any(text, ("que producto", "que productos")) and _contains_any(
        text, ("revisar", "deberia", "debo", "tengo que", "ver", "mirar")
    ):
        return ChatResponse(
            thread_id=thread_id,
            reply=(
                "Puedo mostrarte varias vistas de productos:\n"
                "• 'stock critico' o 'que se esta agotando' — productos bajo minimo\n"
                "• 'mas vendidos del mes' — top 10 por ventas\n"
                "• 'que me sugiere reponer' — sugerencias de reposicion inteligente\n"
                "• 'precio del aceite 1L' — busqueda de producto especifico"
            ),
            escalated=False,
        )

    # ── Detección por reglas → despacho a la tool (sin IA) ───────────────────
    intent, query, periodo = _detect_intent_rules(text, message)
    if intent:
        resp = await _dispatch_intent(
            intent, empresa_id, thread_id, sucursal_id,
            query=query, periodo=periodo,
        )
        if resp is not None:
            return resp

    return None


def _provider_ready() -> tuple[bool, str]:
    required_keys = {
        "anthropic": ("ANTHROPIC_API_KEY", settings.anthropic_api_key),
        "openai": ("OPENAI_API_KEY", settings.openai_api_key),
        "google_genai": ("GOOGLE_API_KEY", settings.google_api_key),
        "grok": ("GROK_API_KEY", settings.grok_api_key),
    }

    if settings.llm_provider == "ollama":
        return True, ""

    if settings.llm_provider not in required_keys:
        return False, f"LLM_PROVIDER no soportado: {settings.llm_provider}"

    key_name, key_value = required_keys[settings.llm_provider]
    if not key_value:
        return False, f"{key_name} no configurado para LLM_PROVIDER={settings.llm_provider}"

    return True, ""


async def _try_classifier(
    message: str,
    empresa_id: int,
    thread_id: str,
    sucursal_id: Optional[int] = None,
) -> Optional[ChatResponse]:
    """
    SEGUNDA INSTANCIA: si las reglas no entendieron, usa Gemini 1.5 Flash como
    clasificador ligero (prompt mínimo → JSON) y despacha a la misma tool.
    Devuelve None para que caiga al agente completo cuando el intent es 'accion'
    o 'desconocido', o cuando no se pudo clasificar.
    """
    if not settings.classifier_enabled or not (settings.google_api_key or settings.grok_api_key):
        return None

    # Respeta los cooldown ya conocidos (no insistir si Gemini está sin cuota).
    busy_seconds = _provider_busy_seconds_left()
    if busy_seconds > 0:
        return ChatResponse(
            thread_id=thread_id,
            reply=_provider_busy_message(busy_seconds),
            escalated=False,
        )
    quota_seconds = _quota_seconds_left()
    if quota_seconds > 0 and not _grok_ready():
        return ChatResponse(
            thread_id=thread_id,
            reply=_quota_message(quota_seconds),
            escalated=False,
        )

    from classifier import classify

    try:
        result = await asyncio.wait_for(classify(message), timeout=12)
    except asyncio.TimeoutError:
        return None  # cae al agente completo
    except Exception as exc:
        if _is_quota_error(exc):
            cooldown = _mark_quota_exhausted()
            return ChatResponse(
                thread_id=thread_id, reply=_quota_message(cooldown), escalated=False,
            )
        if _is_provider_busy_error(exc):
            cooldown = _mark_provider_busy()
            return ChatResponse(
                thread_id=thread_id, reply=_provider_busy_message(cooldown), escalated=False,
            )
        return None  # error inesperado del clasificador → cae al agente completo

    if not result:
        return None

    intent = result["intent"]
    if intent in ("accion", "desconocido"):
        return None  # acciones y casos ambiguos → agente completo (escalación)

    return await _dispatch_intent(
        intent, empresa_id, thread_id, sucursal_id,
        query=result["query"], periodo=result["periodo"],
    )


async def _try_approved_skill(
    message: str,
    empresa_id: int,
    thread_id: str,
    sucursal_id: Optional[int] = None,
) -> Optional[ChatResponse]:
    from skill_engine import match_skill

    result = match_skill(message)
    if not result:
        return None

    return await _dispatch_intent(
        result["intent"],
        empresa_id,
        thread_id,
        sucursal_id,
        query=result.get("query", ""),
        periodo=result.get("periodo", ""),
    )


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


async def _invoke_graph(
    graph_getter,
    message: str,
    empresa_id: int,
    thread_id: str,
    sucursal_id: Optional[int],
    operator_name: str,
) -> ChatResponse:
    try:
        graph = await asyncio.wait_for(graph_getter(), timeout=10)
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


async def _run(
    message: str,
    empresa_id: int,
    thread_id: str,
    sucursal_id: Optional[int] = None,
    operator_name: str = "",
) -> ChatResponse:
    # 1ª instancia: router por reglas (sin IA, siempre funciona aunque no haya cuota)
    direct = await _try_direct_intent(
        message=message,
        empresa_id=empresa_id,
        thread_id=thread_id,
        sucursal_id=sucursal_id,
    )
    if direct is not None:
        return direct

    skill = await _try_approved_skill(
        message=message,
        empresa_id=empresa_id,
        thread_id=thread_id,
        sucursal_id=sucursal_id,
    )
    if skill is not None:
        return skill

    # 2ª instancia: clasificador ligero Gemini Flash → despacho directo
    classified = await _try_classifier(
        message=message,
        empresa_id=empresa_id,
        thread_id=thread_id,
        sucursal_id=sucursal_id,
    )
    if classified is not None:
        return classified

    # 3ª instancia: agente completo (acciones que escalan a humano)
    if _quota_seconds_left() > 0 and _grok_ready():
        return await _invoke_graph(
            _get_fallback_graph,
            message,
            empresa_id,
            thread_id,
            sucursal_id,
            operator_name,
        )

    ready, reason = _provider_ready()
    if not ready:
        if _grok_ready():
            return await _invoke_graph(
                _get_fallback_graph,
                message,
                empresa_id,
                thread_id,
                sucursal_id,
                operator_name,
            )
        raise HTTPException(status_code=503, detail=reason)

    quota_response = await _reserve_llm_slot()
    if quota_response is not None:
        quota_response.thread_id = thread_id
        return quota_response

    try:
        return await _invoke_graph(
            _get_graph,
            message,
            empresa_id,
            thread_id,
            sucursal_id,
            operator_name,
        )
    except Exception as exc:
        if _is_quota_error(exc) and _grok_ready():
            try:
                return await _invoke_graph(
                    _get_fallback_graph,
                    message,
                    empresa_id,
                    thread_id,
                    sucursal_id,
                    operator_name,
                )
            except Exception as fallback_exc:
                exc = fallback_exc

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


@app.post("/chat", response_model=ChatResponse)
async def chat(req: ChatRequest, _: None = Security(_require_secret)):
    thread_id = req.thread_id or f"web-{req.empresa_id}-{uuid.uuid4().hex[:8]}"
    try:
        response = await _run(
            message=req.message,
            empresa_id=req.empresa_id,
            thread_id=thread_id,
            sucursal_id=req.sucursal_id,
            operator_name=req.operator_name,
        )
    except HTTPException as exc:
        detail = str(exc.detail or exc.status_code)
        _append_unanswered_log(
            req.message,
            detail,
            thread_id,
            req.empresa_id,
            req.sucursal_id,
            req.operator_name,
        )
        raise
    except Exception as exc:
        _append_unanswered_log(
            req.message,
            f"{exc.__class__.__name__}: {exc}",
            thread_id,
            req.empresa_id,
            req.sucursal_id,
            req.operator_name,
        )
        raise

    if _is_unanswered_reply(response.reply):
        _append_unanswered_log(
            req.message,
            response.reply,
            response.thread_id,
            req.empresa_id,
            req.sucursal_id,
            req.operator_name,
        )
    return response


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
        "classifier_enabled": settings.classifier_enabled and bool(settings.google_api_key),
        "classifier_model": settings.classifier_model,
        "quota_cooldown_seconds_left": _quota_seconds_left(),
        "provider_busy_seconds_left": _provider_busy_seconds_left(),
        "llm_min_interval_seconds": settings.llm_min_interval_seconds,
        "required_config": required_config,
        "graph_loaded": _graph is not None,
    }
