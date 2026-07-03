"""
Clasificador de intención ligero — SEGUNDA INSTANCIA del agente.

Cuando el router por reglas (_try_direct_intent en main.py) no reconoce la
consulta, este módulo usa un modelo chico y barato (por defecto Gemini 1.5
Flash) SOLO para entender qué quiere el usuario y devolver {intent, query,
periodo} en JSON.

No usa tool-calling ni el grafo pesado de LangGraph: es una única llamada con
un prompt minúsculo, así consume muy pocos tokens y la cuota dura mucho más.
El despacho a la tool correspondiente lo hace main._dispatch_intent().
"""

from __future__ import annotations

import json
import os
import re
from typing import Optional

from config import settings

_models = {}

# Vocabulario de intenciones — debe coincidir con main._dispatch_intent()
VALID_INTENTS = {
    "ventas", "top_productos", "stock_critico", "reposicion", "cajas",
    "cierres", "iva", "compras", "folios", "cliente", "producto",
    "stock_producto", "exportar", "ayuda", "accion", "desconocido",
}

_SYSTEM = """Eres un clasificador de intención para MyPOS, un punto de venta chileno.
Recibes UNA consulta de un operador y respondes SOLO con un JSON válido en una sola
línea, sin explicaciones y sin ```:
{"intent":"<intent>","query":"<texto o vacío>","periodo":"<hoy|ayer|semana|mes|mes_anterior o vacío>"}

intents posibles:
- ventas: totales o monto de ventas de un período. Completa "periodo".
- top_productos: ranking de los productos MÁS VENDIDOS. Completa "periodo".
- producto: pregunta por precio, existencia, marcas o tipos de un producto. "query"=el producto (ej: "carne", "coca cola", "cigarros").
- stock_producto: stock o existencias de UN producto concreto. "query"=el producto.
- stock_critico: qué productos están por agotarse o bajo el mínimo.
- reposicion: qué conviene comprar o reponer.
- cajas: estado de las cajas (abiertas, cerradas, saldo).
- cierres: cajas sin cierre diario / cierres pendientes.
- iva: IVA, impuestos o lo que se paga al SII.
- compras: órdenes de compra pendientes a proveedores.
- folios: folios o CAF del SII disponibles.
- cliente: buscar un cliente. "query"=nombre, RUT o email.
- exportar: pide una planilla/excel enviada al correo. "query"=tema exacto (productos|clientes|proveedores|stock|ventas|compras|cierres). Completa "periodo" si aplica.
- ayuda: pregunta qué puede hacer el asistente.
- accion: pide MODIFICAR datos (anular venta, cerrar caja, cambiar precio, emitir documento, ajustar stock).
- desconocido: no calza con ninguna.

Reglas:
- Si no hay producto ni cliente que extraer, "query":"".
- Si no se menciona período, "periodo":"".
- Responde SOLO el JSON, nada más."""


def _is_quota_error(exc: Exception) -> bool:
    text = f"{exc.__class__.__name__}: {exc}".lower()
    return (
        "resource_exhausted" in text
        or "429" in text
        or "quota" in text
        or "rate limit" in text
    )


def _get_model(provider: str = "google_genai"):
    """Instancia perezosa del modelo clasificador (cacheada)."""
    if provider in _models:
        return _models[provider]

    from langchain.chat_models import init_chat_model

    if provider == "grok":
        api_key = settings.grok_api_key
        if api_key:
            os.environ.setdefault("GROK_API_KEY", api_key)
        _models[provider] = init_chat_model(
            settings.grok_model,
            model_provider="openai",
            temperature=0,
            timeout=10,
            max_retries=0,
            api_key=api_key or None,
            base_url=settings.grok_api_base,
        )
        return _models[provider]

    api_key = settings.google_api_key
    if api_key:
        os.environ.setdefault("GOOGLE_API_KEY", api_key)

    _models[provider] = init_chat_model(
        settings.classifier_model,
        model_provider="google_genai",
        temperature=0,
        timeout=10,
        max_retries=0,
        api_key=api_key or None,
    )
    return _models[provider]


async def _classify_with_model(message: str, provider: str) -> Optional[dict]:
    from langchain_core.messages import SystemMessage, HumanMessage

    model = _get_model(provider)
    resp = await model.ainvoke(
        [SystemMessage(content=_SYSTEM), HumanMessage(content=message.strip())]
    )
    data = _extract_json(_content_to_text(getattr(resp, "content", "")))
    if not data:
        return None

    intent = str(data.get("intent") or "").strip()
    if intent not in VALID_INTENTS:
        return None

    return {
        "intent": intent,
        "query": str(data.get("query") or "").strip(),
        "periodo": str(data.get("periodo") or "").strip(),
    }


def _content_to_text(content) -> str:
    """Normaliza el contenido de la respuesta a string plano."""
    if isinstance(content, str):
        return content
    if isinstance(content, list):
        parts = []
        for c in content:
            if isinstance(c, dict):
                parts.append(str(c.get("text", "")))
            else:
                parts.append(str(c))
        return " ".join(parts)
    return str(content or "")


def _extract_json(text: str) -> Optional[dict]:
    """Extrae el primer objeto JSON del texto, tolerando fences ```json."""
    if not text:
        return None
    text = text.strip()
    text = re.sub(r"^```(?:json)?", "", text).strip()
    text = re.sub(r"```$", "", text).strip()
    match = re.search(r"\{.*\}", text, re.DOTALL)
    if not match:
        return None
    try:
        return json.loads(match.group(0))
    except (json.JSONDecodeError, ValueError):
        return None


async def classify(message: str) -> Optional[dict]:
    """
    Clasifica la consulta. Devuelve {"intent","query","periodo"} o None si no se
    pudo interpretar. Puede propagar excepciones de cuota/disponibilidad de
    Gemini; main.py las traduce a un mensaje amable y activa el cooldown.
    """
    try:
        return await _classify_with_model(message, "google_genai")
    except Exception as exc:
        if settings.grok_api_key and _is_quota_error(exc):
            return await _classify_with_model(message, "grok")
        raise
