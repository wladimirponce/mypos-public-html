"""
Capa 2.5 — Consultas SQL dinámicas en línea ("el agente fabrica la
herramienta en el momento").

Cuando ni las reglas, ni las skills, ni el clasificador reconocen una
consulta de DATOS, este módulo:
  1. Le pide al LLM una plantilla SQL (reutiliza sql_skill_generator, que
     solo conoce las tablas/columnas de agent/sql_whitelist.json).
  2. Arma el envelope sql_readonly y lo pre-valida (v1: sin parámetros
     "extraido" — esos siguen requiriendo aprobación humana).
  3. Lo ejecuta vía POST /api/v1/agente/consulta-adhoc del backend, que
     RE-VALIDA con el mismo validador PHP, inyecta el tenant del contexto
     autenticado, aplica flag por empresa + cuota diaria y audita.
  4. Formatea las filas para el chat (sin segundo viaje al LLM).
  5. Registra el SQL en la bandeja Consultas IA como skill CANDIDATA ya
     probada: si la pregunta se repite, un clic en admin la promueve a skill
     permanente (instantánea y sin LLM). Las herramientas creadas en línea
     se solidifican con el uso.

Cualquier tropiezo devuelve None y la consulta sigue su curso normal hacia
la capa 3 (agente completo) — esta capa nunca rompe el chat.
"""

from __future__ import annotations

import uuid
from typing import Any, Optional

MAX_FILAS_CHAT = 10


def construir_envelope(consulta: str, propuesta: dict) -> Optional[dict]:
    """
    Envelope sql_readonly listo para el validador, o None si la propuesta
    no sirve para ejecución en línea (no resoluble, sin SQL, o con
    parámetros extraídos del texto libre — v1 los deriva a la bandeja).
    """
    if not propuesta.get("resoluble"):
        return None
    sql = str(propuesta.get("sql_template") or "").strip()
    if not sql:
        return None

    params = propuesta.get("params_permitidos") or {}
    if not isinstance(params, dict):
        return None
    for definicion in params.values():
        if isinstance(definicion, dict) and definicion.get("fuente") == "extraido":
            return None  # requiere aprobación humana (bandeja)

    row_limit = propuesta.get("row_limit")
    if not isinstance(row_limit, int) or not (1 <= row_limit <= 200):
        row_limit = 50

    return {
        "id": "adhoc_" + uuid.uuid4().hex[:12],
        "status": "aprobada",  # metadato de flujo; la seguridad es el validador
        "schema_version": 1,
        "tipo": "sql_readonly",
        "intent": "consulta_adhoc",
        "patterns": [consulta[:300] or "consulta adhoc"],
        "sql_template": sql,
        "tablas_referenciadas": list(propuesta.get("tablas_referenciadas") or []),
        "params_permitidos": params,
        "row_limit": row_limit,
        "notes": str(propuesta.get("notes") or ""),
    }


def _formato_valor(valor: Any) -> str:
    if valor is None:
        return "-"
    if isinstance(valor, bool):
        return "sí" if valor else "no"
    if isinstance(valor, (int, float)) or (
        isinstance(valor, str) and valor.replace(".", "", 1).lstrip("-").isdigit()
    ):
        try:
            numero = float(valor)
            if numero.is_integer():
                return f"{int(numero):,}".replace(",", ".")
            return f"{numero:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
        except (TypeError, ValueError):
            pass
    return str(valor)


def formatear_filas(columns: list, rows: list, truncated: bool, notes: str) -> str:
    """Respuesta de chat legible a partir del resultado del backend."""
    if not rows:
        base = "La consulta no arrojó resultados."
        return base + (f"\n(Consulté: {notes})" if notes else "")

    lineas: list[str] = []

    # Caso especial: una sola celda → frase directa.
    if len(rows) == 1 and len(columns) == 1:
        col = str(columns[0]).replace("_", " ")
        lineas.append(f"{col}: {_formato_valor(rows[0].get(columns[0]))}")
    else:
        for i, row in enumerate(rows[:MAX_FILAS_CHAT], 1):
            partes = [
                f"{str(col).replace('_', ' ')}: {_formato_valor(row.get(col))}"
                for col in columns
            ]
            lineas.append(f"{i}. " + " · ".join(partes))
        restantes = len(rows) - MAX_FILAS_CHAT
        if restantes > 0:
            lineas.append(f"… y {restantes} fila(s) más.")
        if truncated:
            lineas.append("(Resultado truncado al límite de la consulta.)")

    if notes:
        lineas.append(f"\nConsulté: {notes}")
    return "\n".join(lineas)


async def _registrar_candidata(
    consulta: str,
    envelope: dict,
    respuesta_preview: str,
    empresa_id: int,
    thread_id: str,
    sucursal_id: Optional[int],
    ejecutada: bool,
) -> None:
    """
    Deja el SQL en la bandeja Consultas IA como skill candidata con la
    propuesta YA en el formato que el botón "Crear skill" de admin espera.
    Nunca lanza: el registro de aprendizaje no bloquea el chat.
    """
    try:
        from tools.mypos_client import web_post

        estado = "ejecutada en línea" if ejecutada else "derivada a aprobación"
        await web_post(
            "/v1/agente/consultas-log",
            empresa_id,
            json_body={
                "uid": envelope["id"],
                "thread_id": thread_id,
                "operador": "capa_adhoc",
                "consulta": consulta[:2000],
                "respuesta": f"[ADHOC {estado}] " + respuesta_preview[:1500],
                "propuesta": {
                    "titulo": "Skill candidata generada por consulta dinámica",
                    "resoluble": True,
                    "tipo_sugerido": "sql_readonly",
                    "intent_sugerido": "consulta_" + envelope["id"][-8:],
                    "sql_template_sugerido": envelope["sql_template"],
                    "tablas_referenciadas_sugeridas": envelope["tablas_referenciadas"],
                    "params_sugeridos_sql": envelope["params_permitidos"],
                    "row_limit_sugerido": envelope["row_limit"],
                    "patterns_sugeridos": envelope["patterns"],
                    "confianza": "alta" if ejecutada else "pendiente",
                    "notas": f"[ADHOC {estado}] {envelope.get('notes', '')}",
                },
            },
            params={"sucursal_id": sucursal_id} if sucursal_id else None,
        )
    except Exception:
        return


async def intentar_consulta_adhoc(
    message: str,
    empresa_id: int,
    thread_id: str,
    sucursal_id: Optional[int] = None,
) -> Optional[str]:
    """
    Intenta resolver la consulta generando y ejecutando SQL en línea.
    Devuelve el texto de respuesta, o None para que siga a la capa 3.
    """
    import empresa_profile

    if not await empresa_profile.adhoc_habilitado(empresa_id):
        return None

    try:
        from sql_skill_generator import propose_sql_template

        propuesta = await propose_sql_template(message)
    except Exception:
        return None  # cuota/red del LLM: que lo maneje la capa 3

    envelope = construir_envelope(message, propuesta)
    if envelope is None:
        # No resoluble en línea; si al menos hay SQL propuesto, dejarlo como
        # candidata en la bandeja para aprobación humana.
        if propuesta.get("resoluble") and str(propuesta.get("sql_template") or "").strip():
            borrador = construir_envelope(message, {**propuesta, "params_permitidos": {}})
            if borrador is not None:
                borrador["params_permitidos"] = propuesta.get("params_permitidos") or {}
                await _registrar_candidata(
                    message, borrador, "requiere parámetros de texto libre",
                    empresa_id, thread_id, sucursal_id, ejecutada=False,
                )
        return None

    try:
        from tools.mypos_client import web_post

        data = await web_post(
            "/v1/agente/consulta-adhoc",
            empresa_id,
            json_body={"skill": envelope, "consulta": message[:500]},
            params={"sucursal_id": sucursal_id} if sucursal_id else None,
        )
    except Exception:
        # Flag apagado (403), cuota diaria (429), validador rechazó (500) o
        # red: en todos los casos la capa 3 sigue disponible.
        return None

    resultado = data.get("data") or {}
    respuesta = formatear_filas(
        list(resultado.get("columns") or []),
        list(resultado.get("rows") or []),
        bool(resultado.get("truncated")),
        str(envelope.get("notes") or ""),
    )

    await _registrar_candidata(
        message, envelope, respuesta, empresa_id, thread_id, sucursal_id, ejecutada=True,
    )
    return respuesta
