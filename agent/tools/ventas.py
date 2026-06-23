"""
Herramientas de ventas, solo lectura.
Endpoints usados:
  GET /api/v1/reportes/resumen-ventas       -> totales del periodo
  GET /api/v1/reportes/ventas-por-producto  -> top productos
"""

from datetime import date
from typing import Optional

from langchain_core.tools import tool

from tools.mypos_client import web_get


@tool
async def resumen_ventas_hoy(empresa_id: int, sucursal_id: Optional[int] = None) -> str:
    """
    Resumen de ventas del dia de hoy para una empresa.
    Retorna total vendido, cantidad de transacciones y ticket promedio.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        sucursal_id: Filtrar por sucursal especifica opcional.
    """
    today = str(date.today())
    params: dict = {"fecha_desde": today, "fecha_hasta": today}
    if sucursal_id:
        params["sucursal_id"] = sucursal_id

    try:
        data = await web_get("/v1/reportes/resumen-ventas", empresa_id, params)
    except Exception as exc:
        return f"Error al consultar ventas: {exc}"

    if not data.get("success"):
        return f"Sin datos de ventas: {data.get('message', 'sin detalle')}"

    result = data.get("data") or {}
    neto = result.get("total_neto") or 0
    iva = result.get("total_iva") or 0
    total = neto + iva
    qty = result.get("cantidad_ventas") or 0
    ticket = total / qty if qty else 0
    suc_label = f" (sucursal {sucursal_id})" if sucursal_id else " (todas las sucursales)"

    return (
        f"Ventas hoy{suc_label}: ${total:,.0f} en {qty} transacciones. "
        f"Neto: ${neto:,.0f}. IVA: ${iva:,.0f}. Ticket promedio: ${ticket:,.0f}."
    )


@tool
async def ventas_por_producto(
    empresa_id: int,
    fecha_desde: str,
    fecha_hasta: str,
    sucursal_id: Optional[int] = None,
) -> str:
    """
    Top 5 productos mas vendidos en un rango de fechas.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        fecha_desde: Fecha inicio en formato YYYY-MM-DD.
        fecha_hasta: Fecha fin en formato YYYY-MM-DD.
        sucursal_id: Filtrar por sucursal opcional.
    """
    params: dict = {"fecha_desde": fecha_desde, "fecha_hasta": fecha_hasta}
    if sucursal_id:
        params["sucursal_id"] = sucursal_id

    try:
        data = await web_get("/v1/reportes/ventas-por-producto", empresa_id, params)
    except Exception as exc:
        return f"Error al consultar ventas por producto: {exc}"

    if not data.get("success"):
        return f"Sin datos: {data.get('message', 'sin detalle')}"

    items = (data.get("data") or [])[:5]
    if not items:
        return "Sin ventas registradas en el periodo indicado."

    lines = [f"Top productos ({fecha_desde} a {fecha_hasta}):"]
    for item in items:
        lines.append(
            f"  - {item.get('nombre', '?')}: "
            f"{item.get('cantidad', 0)} uds - ${item.get('total', 0):,.0f}"
        )
    return "\n".join(lines)
