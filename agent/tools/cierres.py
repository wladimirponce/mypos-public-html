"""
Herramientas de cierres diarios — solo lectura.
Endpoints:
  GET /v1/cierres-diarios/pendientes?empresa_id=X → cajas sin cierre del día
"""

from __future__ import annotations

from typing import Optional

from langchain_core.tools import tool

from tools.mypos_client import web_get


@tool
async def cierres_pendientes(
    empresa_id: int,
    sucursal_id: Optional[int] = None,
) -> str:
    """
    Consulta si hay cajas sin cerrar al día de hoy.
    Alerta si alguna sucursal tiene el cierre diario pendiente.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        sucursal_id: Filtrar por sucursal (opcional).
    """
    params: dict = {}
    if sucursal_id:
        params["sucursal_id"] = sucursal_id

    try:
        data = await web_get("/v1/cierres-diarios/pendientes", empresa_id, params)
    except Exception as exc:
        return f"Error al consultar cierres pendientes: {exc}"

    if not data.get("success"):
        return f"No se pudo consultar cierres: {data.get('message', 'sin detalle')}"

    payload = data.get("data") or {}
    pendientes = payload.get("pendientes") or []
    total = int(payload.get("total_pendientes") or len(pendientes))

    if not pendientes and total == 0:
        scope = f" en sucursal {sucursal_id}" if sucursal_id else ""
        return f"Sin cierres pendientes{scope}. Todas las cajas están al día."

    lines = [f"⚠ {total} cierre(s) pendiente(s) hoy:"]
    for p in pendientes[:10]:
        suc = p.get("sucursal_nombre") or f"Sucursal {p.get('sucursal_id', '?')}"
        caja = p.get("caja_nombre") or f"Caja {p.get('caja_id', '?')}"
        apertura = p.get("hora_apertura") or p.get("abierta_desde") or ""
        apertura_str = f" · abierta desde {apertura}" if apertura else ""
        lines.append(f"  · {suc} — {caja}{apertura_str}")

    if total > 10:
        lines.append(f"  … y {total - 10} más.")

    lines.append("Recuerda cerrar las cajas antes de terminar el día.")
    return "\n".join(lines)
