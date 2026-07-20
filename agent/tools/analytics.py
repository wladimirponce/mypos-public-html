"""Herramientas deterministas de analitica MyPOS; todas son de solo lectura."""

from __future__ import annotations

from langchain_core.tools import tool
from tools.mypos_client import web_get


@tool
async def calidad_datos(empresa_id: int) -> str:
    """Indica si los datos permiten recomendaciones confiables."""
    data = await web_get("/v1/analytics/calidad-datos", empresa_id)
    payload = data.get("data") or {}
    if not data.get("success"):
        return data.get("message", "No fue posible evaluar la calidad de datos.")
    problemas = [p for p in payload.get("problemas", []) if int(p.get("cantidad") or 0) > 0]
    lines = [f"Preparacion analitica: {payload.get('puntaje', 0)}% ({payload.get('nivel', '?')})."]
    lines.extend(f"- {p.get('titulo')}: {p.get('cantidad')}. {p.get('impacto')}" for p in problemas[:8])
    if not problemas:
        lines.append("No se detectaron brechas en las validaciones actuales.")
    return "\n".join(lines)


@tool
async def analitica_negocio(empresa_id: int) -> str:
    """Resume rotacion, margen, inventario, sucursales y proveedores."""
    data = await web_get("/v1/analytics/resumen", empresa_id)
    payload = data.get("data") or {}
    if not data.get("success"):
        return data.get("message", "No fue posible consultar la analitica.")
    r = payload.get("resumen") or {}
    lines = [f"Ultimos 30 dias: {int(r.get('ventas') or 0)} ventas por ${int(r.get('total') or 0):,.0f}; margen ${int(r.get('margen') or 0):,.0f}."]
    for p in (payload.get("productos") or [])[:5]:
        dias = p.get("dias_inventario")
        lines.append(f"- {p.get('nombre')}: {float(p.get('unidades_vendidas') or 0):g} unidades; cobertura {dias if dias is not None else 'sin rotacion'} dias.")
    return "\n".join(lines)


@tool
async def capital_inmovilizado(empresa_id: int, dias: int = 90) -> str:
    """Consulta capital en inventario y productos sin movimiento."""
    data = await web_get("/v1/reportes/salud-financiera", empresa_id, {"dias": max(30, min(dias, 365))})
    payload = data.get("data") or {}
    if not data.get("success"):
        return data.get("message", "No fue posible consultar la salud financiera.")
    lines = list(payload.get("mensajes") or [])
    lines.extend(f"- {p.get('nombre')}: ${int(p.get('valor') or 0):,.0f} inmovilizados." for p in (payload.get("stock_muerto") or [])[:5])
    return "\n".join(lines)
