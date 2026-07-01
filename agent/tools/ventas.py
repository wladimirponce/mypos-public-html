"""
Herramientas de ventas — solo lectura.
Endpoints:
  GET /v1/reportes/resumen-ventas      → totales del período
  GET /v1/reportes/ventas-por-producto → ranking de productos
"""

from __future__ import annotations

from datetime import date, timedelta
from typing import Optional

from langchain_core.tools import tool

from tools.mypos_client import web_get


def _first_value(item: dict, *keys: str, default=None):
    for key in keys:
        value = item.get(key)
        if value not in (None, ""):
            return value
    return default


def _to_float(value) -> float:
    try:
        return float(value or 0)
    except (TypeError, ValueError):
        return 0.0


def _format_quantity(value) -> str:
    qty = _to_float(value)
    if qty.is_integer():
        return str(int(qty))
    return f"{qty:,.2f}".rstrip("0").rstrip(".")


def _date_range(periodo: str) -> tuple[str, str]:
    """Devuelve (fecha_desde, fecha_hasta) para períodos predefinidos."""
    today = date.today()
    if periodo == "ayer":
        d = today - timedelta(days=1)
        return str(d), str(d)
    if periodo == "semana":
        inicio = today - timedelta(days=today.weekday())  # lunes
        return str(inicio), str(today)
    if periodo == "mes":
        return str(today.replace(day=1)), str(today)
    if periodo == "mes_anterior":
        primero_mes = today.replace(day=1)
        ultimo_ant = primero_mes - timedelta(days=1)
        return str(ultimo_ant.replace(day=1)), str(ultimo_ant)
    # default: hoy
    return str(today), str(today)


def _formato_resumen(data: dict, label: str, sucursal_id: Optional[int]) -> str:
    neto = float(data.get("total_neto") or 0)
    iva = float(data.get("total_iva") or 0)
    total = neto + iva
    qty = int(data.get("cantidad_ventas") or 0)
    ticket = total / qty if qty else 0
    suc = f" · sucursal {sucursal_id}" if sucursal_id else ""
    return (
        f"Ventas {label}{suc}: ${total:,.0f} · {qty} transacciones\n"
        f"  Neto: ${neto:,.0f} · IVA: ${iva:,.0f} · Ticket prom: ${ticket:,.0f}"
    )


@tool
async def ventas_periodo(
    empresa_id: int,
    periodo: str = "hoy",
    sucursal_id: Optional[int] = None,
) -> str:
    """
    Resumen de ventas para un período predefinido.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        periodo: Uno de 'hoy', 'ayer', 'semana', 'mes', 'mes_anterior'.
        sucursal_id: Filtrar por sucursal (opcional).
    """
    periodo = periodo.lower().strip()
    if periodo not in ("hoy", "ayer", "semana", "mes", "mes_anterior"):
        periodo = "hoy"

    fecha_desde, fecha_hasta = _date_range(periodo)
    params: dict = {"fecha_desde": fecha_desde, "fecha_hasta": fecha_hasta}
    if sucursal_id:
        params["sucursal_id"] = sucursal_id

    try:
        data = await web_get("/v1/reportes/resumen-ventas", empresa_id, params)
    except Exception as exc:
        return f"Error al consultar ventas: {exc}"

    if not data.get("success"):
        return f"Sin datos de ventas: {data.get('message', 'sin detalle')}"

    labels = {
        "hoy": "de hoy",
        "ayer": "de ayer",
        "semana": "de esta semana",
        "mes": "del mes actual",
        "mes_anterior": "del mes anterior",
    }
    return _formato_resumen(data.get("data") or {}, labels[periodo], sucursal_id)


@tool
async def ventas_por_producto(
    empresa_id: int,
    fecha_desde: str,
    fecha_hasta: str,
    top: int = 10,
    sucursal_id: Optional[int] = None,
) -> str:
    """
    Ranking de productos más vendidos en un rango de fechas.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        fecha_desde: Fecha inicio YYYY-MM-DD.
        fecha_hasta: Fecha fin YYYY-MM-DD.
        top: Cantidad de productos a mostrar (default 10, máx 20).
        sucursal_id: Filtrar por sucursal (opcional).
    """
    top = max(1, min(top, 20))
    params: dict = {
        "fecha_desde": fecha_desde,
        "fecha_hasta": fecha_hasta,
        "limit": top,
        "orden": "total",
    }
    if sucursal_id:
        params["sucursal_id"] = sucursal_id

    try:
        data = await web_get("/v1/reportes/ventas-por-producto", empresa_id, params)
    except Exception as exc:
        return f"Error al consultar ranking: {exc}"

    if not data.get("success"):
        return f"Sin datos: {data.get('message', 'sin detalle')}"

    raw_items = data.get("data") or []
    items = []
    for item in raw_items:
        qty = _to_float(_first_value(item, "cantidad_vendida", "cantidad", "unidades", default=0))
        total = _to_float(_first_value(item, "total_vendido", "total", "monto", default=0))
        if qty > 0 or total > 0:
            items.append(item)
        if len(items) >= top:
            break
    if not items:
        return "Sin ventas registradas en el período indicado."

    lines = [f"Top {len(items)} productos ({fecha_desde} a {fecha_hasta}):"]
    for i, item in enumerate(items, 1):
        name = _first_value(item, "nombre", "producto_nombre", "producto", "descripcion", default="?")
        qty = _first_value(item, "cantidad_vendida", "cantidad", "unidades", default=0)
        total = _to_float(_first_value(item, "total_vendido", "total", "monto", default=0))
        lines.append(
            f"  {i}. {name}: "
            f"{_format_quantity(qty)} uds - ${total:,.0f}"
        )
    return "\n".join(lines)
