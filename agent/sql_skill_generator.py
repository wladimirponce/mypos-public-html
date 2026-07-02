"""
Generador de propuestas de skill "sql_readonly" a partir de una consulta que
ninguna capa del agente supo responder.

IMPORTANTE: este modulo SOLO propone. Nunca ejecuta SQL, nunca escribe un
archivo de skill, nunca marca nada como aprobado. La propuesta que devuelve
se guarda en el JSON de "Consultas IA" (agent/tmp/agent_unanswered.txt) para
que un humano la revise/edite en admin, y solo se convierte en una skill real
-- y solo se ejecuta -- despues de pasar por
agent/sql_whitelist_validator.php (envelope + SQL) en admin y de nuevo en el
backend al momento de ejecutar. Ver agent/sql_whitelist_validator.php para el
formato exacto que debe respetar el resultado.
"""

from __future__ import annotations

import json
import re
from typing import Any, Optional

from classifier import _get_model, _content_to_text  # reutiliza el mismo plumbing de LLM
from sql_whitelist import describe_schema_for_prompt

_SYSTEM_TEMPLATE = """Eres un asistente que propone plantillas SQL de SOLO LECTURA para MyPOS,
un punto de venta chileno multiempresa. Un operador hizo una pregunta que el
sistema de reglas no pudo responder. Tu trabajo es proponer un borrador de
consulta SQL que un humano va a revisar y aprobar antes de que se use.

Tablas y columnas PERMITIDAS (cualquier otra tabla/columna sera rechazada,
no la uses bajo ninguna circunstancia):
{schema}

Reglas OBLIGATORIAS de la plantilla SQL:
- Debe ser un UNICO SELECT. Nunca INSERT/UPDATE/DELETE/DROP/ALTER, nunca UNION,
  nunca subconsultas derivadas (FROM/JOIN seguido de otro SELECT), nunca
  comentarios, nunca punto y coma extra.
- SIEMPRE debe filtrar por empresa_id usando un placeholder con nombre
  ":empresa_id" -- JAMAS un numero literal. empresa_id NUNCA se extrae de la
  pregunta del usuario, siempre lo inyecta el sistema.
- Cualquier otro dato que necesites extraer de la pregunta (ej. nombre de un
  producto) debe ir como placeholder con nombre distinto (ej. :producto_like),
  nunca interpolado directamente en el texto del SQL.
- Agrega siempre un LIMIT razonable (usa el campo row_limit para declararlo,
  no hace falta repetirlo en el SQL salvo que la pregunta pida "el primero"/
  "el mas barato", en cuyo caso SI conviene LIMIT 1 en el propio SQL).

Responde SOLO un JSON valido en una sola linea, sin explicaciones y sin ```,
con esta forma exacta:
{{"sql_template":"...","tablas_referenciadas":["..."],"params_permitidos":{{"empresa_id":{{"tipo":"int","fuente":"contexto","requerido":true}}}},"row_limit":1,"patterns":["frase 1","frase 2"],"notes":"explicacion breve en español de que hace la consulta","resoluble":true}}

Si la pregunta NO se puede responder con las tablas permitidas, responde:
{{"resoluble":false,"notes":"explicacion breve de por que no se puede"}}
"""


def _build_system_prompt() -> str:
    return _SYSTEM_TEMPLATE.format(schema=describe_schema_for_prompt())


def _extract_json_object(text: str) -> Optional[dict]:
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


async def propose_sql_template(consulta: str) -> dict[str, Any]:
    """
    Devuelve un borrador de skill sql_readonly (sin validar/aprobar) o
    {"resoluble": False, "notes": "..."} si el LLM considera que no se puede
    resolver con las tablas permitidas. Puede propagar excepciones de
    cuota/disponibilidad del LLM -- el caller (endpoint HTTP) las traduce a
    un error legible.
    """
    from langchain_core.messages import SystemMessage, HumanMessage

    model = _get_model("google_genai")
    resp = await model.ainvoke(
        [
            SystemMessage(content=_build_system_prompt()),
            HumanMessage(content=consulta.strip()),
        ]
    )
    data = _extract_json_object(_content_to_text(getattr(resp, "content", "")))
    if not data:
        return {
            "resoluble": False,
            "notes": "El LLM no devolvio un JSON valido para esta consulta.",
        }

    if data.get("resoluble") is False:
        return {
            "resoluble": False,
            "notes": str(data.get("notes") or "El LLM indica que no es resoluble con las tablas permitidas."),
        }

    return {
        "resoluble": True,
        "sql_template": str(data.get("sql_template") or ""),
        "tablas_referenciadas": list(data.get("tablas_referenciadas") or []),
        "params_permitidos": data.get("params_permitidos") or {},
        "row_limit": data.get("row_limit"),
        "patterns": list(data.get("patterns") or []),
        "notes": str(data.get("notes") or ""),
    }
