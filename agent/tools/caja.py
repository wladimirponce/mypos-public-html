"""
Herramientas de caja — solo lectura.
Endpoints usados:
  GET /api/v1/cajas/estado                       → estado actual de cajas abiertas
  GET /api/v1/cierres-diarios/pendientes         → cierres del día pendientes
"""

from typing import Optional
from langchain_core.tools import tool

from tools.mypos_client import web_get


@tool
async def estado_cajas(empresa_id: int, sucursal_id: Optional[int] = None) -> str:
    """
    Consulta el estado de las cajas: cuáles están abiertas, cerradas o sin cierre.
    Incluye los cierres diarios pendientes.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        sucursal_id: Filtrar por sucursal (opcional).
    """
    params: dict = {}
    if sucursal_id:
        params["sucursal_id"] = sucursal_id

    try:
        estado = await web_get("/v1/cajas/estado", empresa_id, params)
        pendientes = await web_get("/v1/cierres-diarios/pendientes", empresa_id, params)
    except Exception as exc:
        return f"Error al consultar cajas: {exc}"

    lines: list[str] = []

    # Cajas abiertas
    cajas = (estado.get("data") or []) if estado.get("success") else []
    if cajas:
        abiertas = [c for c in cajas if c.get("estado") == "abierta"]
        cerradas = [c for c in cajas if c.get("estado") != "abierta"]
        lines.append(f"Cajas abiertas: {len(abiertas)} · Cerradas hoy: {len(cerradas)}")
        for c in abiertas:
            lines.append(
                f"  • {c.get('nombre', '?')} — abierta por {c.get('usuario', '?')}"
            )
    else:
        lines.append("Sin información de cajas.")

    # Cierres diarios pendientes
    pend = (pendientes.get("data") or []) if pendientes.get("success") else []
    if pend:
        lines.append(f"\nCierres diarios pendientes ({len(pend)}):")
        for p in pend:
            lines.append(
                f"  • Sucursal {p.get('sucursal_id', '?')} — "
                f"{p.get('fecha', '?')} sin cierre"
            )
    else:
        lines.append("\nCierres diarios: al día, sin pendientes.")

    return "\n".join(lines)
