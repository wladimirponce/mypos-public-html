"""
Tool de ejecucion de skills "sql_readonly" -- consultas de solo lectura
aprobadas desde admin que no calzan con ninguna tool fija existente.

IMPORTANTE: esta tool NUNCA arma ni valida SQL. Solo reenvia skill_id +
params extraidos al backend, que es quien vuelve a validar la skill contra
la whitelist (agent/sql_whitelist_validator.php) y ejecuta con el
empresa_id/sucursal_id que TenantMiddleware ya verifico contra el JWT --
nunca con lo que esta tool le mande. Ver
web/mypos-backend/backend/src/Services/ConsultaFlexibleService.php para la
cadena de confianza completa.
"""

from __future__ import annotations

from langchain_core.tools import tool

from tools.mypos_client import web_post


def _formatear_filas(columns: list[str], rows: list[dict], truncated: bool) -> str:
    if not rows:
        return "No se encontraron resultados para esa consulta."

    lineas = []
    for row in rows:
        partes = [f"{col}: {row.get(col)}" for col in columns]
        lineas.append(" · ".join(partes))

    texto = "\n".join(lineas)
    if truncated:
        texto += "\n(hay mas resultados de los mostrados, se aplico un limite)"
    return texto


@tool
async def ejecutar_consulta_flexible(
    empresa_id: int,
    skill_id: str,
    sucursal_id: int | None = None,
) -> str:
    """
    Ejecuta una skill aprobada de tipo sql_readonly (consulta de solo
    lectura que no calza con ninguna tool fija) y devuelve el resultado en
    texto legible.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        skill_id: Identificador de la skill aprobada (agent/skills/{id}.json).
        sucursal_id: Filtrar por sucursal (opcional).
    """
    try:
        data = await web_post(
            "/v1/agente/consulta-flexible",
            empresa_id,
            json_body={"params": {}},
            params={"skill_id": skill_id, **({"sucursal_id": sucursal_id} if sucursal_id else {})},
        )
    except Exception as exc:
        return f"No se pudo ejecutar la consulta: {exc}"

    if not data.get("success"):
        return f"No se pudo ejecutar la consulta: {data.get('message', 'sin detalle')}"

    resultado = data.get("data") or {}
    return _formatear_filas(
        resultado.get("columns") or [],
        resultado.get("rows") or [],
        bool(resultado.get("truncated")),
    )
