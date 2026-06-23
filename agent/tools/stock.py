"""
Herramientas de stock — solo lectura.
Endpoints usados:
  GET /admin/fb/?action=productos_buscar    → buscar por texto o código de barra
  GET /admin/fb/?action=stock_producto      → stock de un producto en una sucursal
  GET /api/v1/stock/producto/{id}           → stock consolidado (todas las ubicaciones)
"""

from typing import Optional
from langchain_core.tools import tool

from tools.mypos_client import fb_get, web_get


@tool
async def buscar_producto(empresa_id: int, query: str) -> str:
    """
    Busca un producto por nombre o código de barras.
    Retorna nombre, precio y código de barras de los primeros resultados.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        query: Texto de búsqueda o código de barras.
    """
    try:
        data = await fb_get("productos_buscar", {"q": query})
    except Exception as exc:
        return f"Error al buscar producto: {exc}"

    if not data.get("ok"):
        return f"Sin resultados: {data.get('error', 'sin detalle')}"

    items = (data.get("data") or [])[:5]
    if not items:
        return f"No se encontraron productos para '{query}'."

    lines = [f"Productos encontrados para '{query}':"]
    for p in items:
        precio = p.get("precio_venta") or p.get("precio") or 0
        lines.append(
            f"  • [{p.get('codigo_barra', '–')}] {p.get('nombre', '?')} — ${precio:,.0f}"
        )
    return "\n".join(lines)


@tool
async def consultar_stock(
    empresa_id: int,
    producto_id: int,
    sucursal_id: Optional[int] = None,
) -> str:
    """
    Consulta el stock disponible de un producto.
    Si se omite sucursal_id retorna el stock consolidado de todas las ubicaciones.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        producto_id: ID del producto a consultar.
        sucursal_id: ID de sucursal (opcional).
    """
    # Stock por sucursal (vía FB API)
    if sucursal_id:
        try:
            data = await fb_get(
                "stock_producto",
                {"producto_id": producto_id, "sucursal_id": sucursal_id},
            )
        except Exception as exc:
            return f"Error al consultar stock: {exc}"

        if not data.get("ok"):
            return f"Sin datos de stock: {data.get('error', 'sin detalle')}"

        stock = data.get("data", {})
        return (
            f"Stock producto {producto_id} en sucursal {sucursal_id}: "
            f"{stock.get('cantidad', 0)} unidades disponibles."
        )

    # Stock consolidado (vía web backend, fuente de verdad: stock_ubicacion)
    try:
        data = await web_get(
            f"/v1/stock/producto/{producto_id}",
            empresa_id,
        )
    except Exception as exc:
        return f"Error al consultar stock consolidado: {exc}"

    if not data.get("success"):
        return f"Sin datos: {data.get('message', 'sin detalle')}"

    ubicaciones = data.get("data") or []
    if not ubicaciones:
        return f"Producto {producto_id} sin stock registrado en ninguna ubicación."

    total = sum(u.get("cantidad", 0) for u in ubicaciones)
    lines = [f"Stock producto {producto_id} (total: {total} uds):"]
    for u in ubicaciones:
        lines.append(
            f"  • {u.get('nombre_ubicacion', '?')}: {u.get('cantidad', 0)} uds"
        )
    return "\n".join(lines)
