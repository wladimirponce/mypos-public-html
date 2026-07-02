"""
Genera una respuesta directa en lenguaje natural para preguntas que NO se
pueden resolver via SQL (no son consultas de datos, ej. "¿funciona en
tablet?", "¿tiene soporte por WhatsApp?"). Complementa a
sql_skill_generator.py: ese propone plantillas SQL para preguntas de datos,
este redacta una respuesta conversacional para todo lo demas.

IMPORTANTE: este modulo NO tiene acceso a la base de datos ni a ninguna tool.
El prompt le pide explicitamente al LLM que si la pregunta requiere datos en
vivo (ventas, stock, precios, etc.) lo diga en vez de inventar una cifra --
para ESO existe sql_skill_generator.py / "Generar SQL con IA", no esto.
"""

from __future__ import annotations

from classifier import _get_model, _content_to_text

_SYSTEM = """Eres el asistente de soporte de MyPOS, un punto de venta chileno.
Un operador hizo una pregunta que el sistema automatico no supo responder.
Redacta una respuesta directa, breve y util en español para esa pregunta.

Reglas:
- Si la pregunta requiere datos en vivo del negocio (ventas, stock, precios,
  cajas, un producto o cliente especifico, cualquier numero real), NO
  inventes una cifra. Responde exactamente: "REQUIERE_DATOS_EN_VIVO" seguido
  de una linea explicando brevemente que dato se necesitaria consultar.
- Si es una pregunta conceptual, de uso del sistema, o de soporte (ej. "¿se
  puede usar en celular?", "¿tiene soporte por WhatsApp?", "¿como cierro
  caja?"), respondela directamente de forma clara y breve (maximo 4
  lineas), en un tono cercano y profesional.
- No hagas suposiciones sobre planes, precios o funcionalidades que no
  tengas certeza que existen en MyPOS; si no sabes, dilo.
"""


async def answer_directly(consulta: str) -> dict[str, str]:
    """
    Devuelve {"tipo": "respuesta_directa", "respuesta": "..."} o
    {"tipo": "requiere_datos", "respuesta": "..."} si el LLM determina que
    la pregunta necesita datos en vivo (en cuyo caso conviene usar "Generar
    SQL con IA" en vez de esto). Puede propagar excepciones de cuota/
    disponibilidad del LLM -- el caller (endpoint HTTP) las traduce.
    """
    from langchain_core.messages import SystemMessage, HumanMessage

    model = _get_model("google_genai")
    resp = await model.ainvoke(
        [SystemMessage(content=_SYSTEM), HumanMessage(content=consulta.strip())]
    )
    texto = _content_to_text(getattr(resp, "content", "")).strip()

    if texto.upper().startswith("REQUIERE_DATOS_EN_VIVO"):
        detalle = texto.split("\n", 1)[1].strip() if "\n" in texto else ""
        return {
            "tipo": "requiere_datos",
            "respuesta": detalle or "Esta pregunta necesita datos en vivo del negocio; usa 'Generar SQL con IA' en vez de esto.",
        }

    return {"tipo": "respuesta_directa", "respuesta": texto}
