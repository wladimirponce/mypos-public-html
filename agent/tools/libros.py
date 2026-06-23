"""
Herramientas de libros tributarios — solo lectura.
Endpoints:
  GET /v1/libros/resumen-iva?empresa_id=X&periodo=YYYY-MM → resumen IVA mensual
"""

from __future__ import annotations

from datetime import date

from langchain_core.tools import tool

from tools.mypos_client import web_get


@tool
async def resumen_iva(
    empresa_id: int,
    periodo: str = "",
) -> str:
    """
    Resumen de IVA débito (ventas) e IVA crédito (compras) del período.
    Calcula el IVA a pagar al SII (débito - crédito).

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        periodo: Mes en formato YYYY-MM. Si se omite, usa el mes actual.
    """
    if not periodo:
        hoy = date.today()
        periodo = f"{hoy.year}-{hoy.month:02d}"

    try:
        data = await web_get(
            "/v1/libros/resumen-iva",
            empresa_id,
            {"periodo": periodo},
        )
    except Exception as exc:
        return f"Error al consultar libro IVA: {exc}"

    if not data.get("success"):
        return f"Sin datos: {data.get('message', 'sin detalle')}"

    r = data.get("data") or {}

    debito = float(r.get("iva_debito") or r.get("total_iva_ventas") or 0)
    credito = float(r.get("iva_credito") or r.get("total_iva_compras") or 0)
    neto = debito - credito

    ventas_netas = float(r.get("total_neto_ventas") or 0)
    compras_netas = float(r.get("total_neto_compras") or 0)

    signo = "A PAGAR" if neto >= 0 else "A FAVOR"
    lines = [
        f"Resumen IVA {periodo}:",
        f"  Ventas (neto): ${ventas_netas:,.0f} · IVA débito:  ${debito:,.0f}",
        f"  Compras (neto): ${compras_netas:,.0f} · IVA crédito: ${credito:,.0f}",
        f"  ─────────────────────────────────────",
        f"  IVA {signo}: ${abs(neto):,.0f}",
    ]
    return "\n".join(lines)
