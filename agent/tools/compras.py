"""
Herramientas de compras — solo lectura.
Endpoints:
  GET /v1/compras?estado=PENDIENTE&empresa_id=X       → OC sin confirmar
  GET /v1/compras-inteligentes/sugerencias?empresa_id=X → reposición automática
"""

from __future__ import annotations

from typing import Optional

from langchain_core.tools import tool

from tools.mypos_client import web_get


@tool
async def compras_pendientes(
    empresa_id: int,
    sucursal_id: Optional[int] = None,
    limite: int = 10,
) -> str:
    """
    Lista las órdenes de compra pendientes de confirmar o en borrador.
    Útil para saber qué compras están esperando aprobación o recepción.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        sucursal_id: Filtrar por sucursal (opcional).
        limite: Máximo de resultados (default 10).
    """
    params: dict = {"per_page": max(1, min(limite, 50)), "page": 1}
    if sucursal_id:
        params["sucursal_id"] = sucursal_id

    pendientes_total: list[dict] = []
    for estado in ("PENDIENTE", "BORRADOR"):
        try:
            data = await web_get("/v1/compras", empresa_id, {**params, "estado": estado})
        except Exception:
            continue
        if not data.get("success"):
            continue
        payload = data.get("data") or {}
        items = payload.get("compras") or (payload if isinstance(payload, list) else [])
        pendientes_total.extend(items)

    if not pendientes_total:
        return "No hay órdenes de compra pendientes ni en borrador."

    pendientes_total = pendientes_total[: max(1, min(limite, 50))]
    lines = [f"Órdenes de compra pendientes ({len(pendientes_total)}):"]
    for c in pendientes_total:
        proveedor = c.get("proveedor_nombre") or c.get("proveedor") or "Sin proveedor"
        estado = c.get("estado") or "?"
        total = float(c.get("total") or 0)
        fecha = c.get("fecha_documento") or c.get("fecha_ingreso") or ""
        folio = c.get("folio") or c.get("id") or "?"
        lines.append(
            f"  [{estado}] #{folio} · {proveedor}"
            f" · ${total:,.0f}"
            + (f" · {fecha}" if fecha else "")
        )
    return "\n".join(lines)


@tool
async def sugerencias_reposicion(
    empresa_id: int,
    sucursal_id: Optional[int] = None,
    dias_consumo: int = 60,
) -> str:
    """
    Genera sugerencias automáticas de reposición basadas en el historial de consumo.
    Identifica qué productos conviene comprar, en qué cantidad y a qué proveedor.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        sucursal_id: Filtrar por ubicación/sucursal (opcional).
        dias_consumo: Período de historial para calcular consumo promedio (default 60 días).
    """
    params: dict = {"dias_consumo": dias_consumo}
    if sucursal_id:
        params["ubicacion_id"] = sucursal_id

    try:
        data = await web_get("/v1/compras-inteligentes/sugerencias", empresa_id, params)
    except Exception as exc:
        return f"Error al obtener sugerencias: {exc}"

    if not data.get("success"):
        return f"Sin sugerencias: {data.get('message', 'sin detalle')}"

    payload = data.get("data") or {}
    sugerencias = payload.get("sugerencias") or []
    por_proveedor = payload.get("por_proveedor") or {}

    if not sugerencias:
        return "No hay productos que requieran reposición en este momento."

    lines = [
        f"Sugerencias de reposición ({len(sugerencias)} productos, "
        f"basado en {dias_consumo} días de consumo):"
    ]

    if por_proveedor:
        for proveedor, items in list(por_proveedor.items())[:5]:
            total_items = len(items)
            lines.append(f"\n  Proveedor: {proveedor} ({total_items} productos)")
            for s in items[:4]:
                nombre = s.get("nombre_producto") or s.get("nombre") or "?"
                qty = float(s.get("cantidad_sugerida") or s.get("cantidad") or 0)
                lines.append(f"    · {nombre}: reponer {qty:g} uds")
            if total_items > 4:
                lines.append(f"    … y {total_items - 4} más de este proveedor")
    else:
        for s in sugerencias[:10]:
            nombre = s.get("nombre_producto") or s.get("nombre") or "?"
            qty = float(s.get("cantidad_sugerida") or s.get("cantidad") or 0)
            lines.append(f"  · {nombre}: reponer {qty:g} uds")
        if len(sugerencias) > 10:
            lines.append(f"  … y {len(sugerencias) - 10} más.")

    return "\n".join(lines)
