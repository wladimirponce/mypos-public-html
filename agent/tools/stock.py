"""
Herramientas de stock y productos — solo lectura.
Endpoints usados:
  GET /api/v1/productos/buscar              → buscar por codigo exacto
  GET /api/v1/productos?q=texto             → buscar por nombre/codigo/barra
  GET /api/v1/stock/producto/{id}           → stock consolidado
"""

from typing import Optional
from langchain_core.tools import tool

from tools.mypos_client import web_get


def _money(value) -> str:
    try:
        amount = float(value or 0)
    except (TypeError, ValueError):
        amount = 0
    return f"${amount:,.0f}"


def _product_line(product: dict) -> str:
    code = (
        product.get("codigo_barra_usado")
        or product.get("codigo_barra_principal")
        or product.get("codigo")
        or product.get("sku")
        or "-"
    )
    price = product.get("precio_venta") or product.get("precio") or 0
    stock = product.get("stock_total")
    stock_label = f" · stock {stock}" if stock is not None else ""
    return f"  - [{code}] {product.get('nombre', '?')} - {_money(price)}{stock_label}"


@tool
async def buscar_producto(empresa_id: int, query: str) -> str:
    """
    Busca un producto por nombre o código de barras.
    Retorna nombre, precio y código de barras de los primeros resultados.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        query: Texto de búsqueda o código de barras.
    """
    query = query.strip()
    if not query:
        return "Indica el nombre, codigo interno o codigo de barras del producto."

    exact_error = ""
    try:
        exact = await web_get("/v1/productos/buscar", empresa_id, {"codigo": query})
        product = (exact.get("data") or {}).get("producto") if exact.get("success") else None
        if product:
            return "Producto encontrado:\n" + _product_line(product)
    except Exception as exc:
        exact_error = str(exc)

    try:
        data = await web_get(
            "/v1/productos",
            empresa_id,
            {"q": query, "per_page": 5, "page": 1},
        )
    except Exception as exc:
        detail = f" Error exacto: {exact_error}" if exact_error else ""
        return f"Error al buscar producto: {exc}.{detail}"

    if not data.get("success"):
        return f"Sin resultados: {data.get('message', 'sin detalle')}"

    payload = data.get("data") or {}
    if isinstance(payload, dict):
        items = (payload.get("productos") or [])[:5]
    elif isinstance(payload, list):
        items = payload[:5]
    else:
        items = []
    if not items:
        return f"No se encontraron productos para '{query}'."

    lines = [f"Productos encontrados para '{query}':"]
    for p in items:
        lines.append(_product_line(p))
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
    # Stock consolidado (via web backend, fuente de verdad: stock_ubicacion)
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
    if sucursal_id:
        ubicaciones = [
            u for u in ubicaciones
            if int(u.get("sucursal_id") or 0) == int(sucursal_id)
        ]

    if not ubicaciones:
        scope = f" en sucursal {sucursal_id}" if sucursal_id else ""
        return f"Producto {producto_id} sin stock registrado{scope}."

    total = sum(u.get("cantidad", 0) for u in ubicaciones)
    lines = [f"Stock producto {producto_id} (total: {total} uds):"]
    for u in ubicaciones:
        lines.append(
            f"  • {u.get('nombre_ubicacion', '?')}: {u.get('cantidad', 0)} uds"
        )
    return "\n".join(lines)
