"""
Herramientas de stock y productos — solo lectura.
Endpoints:
  GET /v1/productos?q=texto        → buscar por nombre/código/barra
  GET /v1/productos/buscar         → código exacto
  GET /v1/stock/producto/{id}      → stock consolidado por producto
"""

from __future__ import annotations

from typing import Optional

from langchain_core.tools import tool

from tools.mypos_client import web_get


def _money(value) -> str:
    try:
        return f"${float(value or 0):,.0f}"
    except (TypeError, ValueError):
        return "$0"


def _product_line(p: dict, *, show_stock: bool = True) -> str:
    code = (
        p.get("codigo_barra_usado")
        or p.get("codigo_barra_principal")
        or p.get("codigo")
        or p.get("sku")
        or "-"
    )
    precio = _money(p.get("precio_venta") or p.get("precio") or 0)
    stock = p.get("stock_total")
    minimo = float(p.get("stock_minimo") or 0)

    parts = [f"  [{code}] {p.get('nombre', '?')} · {precio}"]
    if show_stock and stock is not None:
        stock_f = float(stock)
        alerta = " ⚠ BAJO MÍNIMO" if stock_f <= minimo and minimo > 0 else ""
        parts.append(f" · stock {stock_f:g}{alerta}")
    return "".join(parts)


@tool
async def buscar_producto(empresa_id: int, query: str) -> str:
    """
    Busca un producto por nombre, código interno o código de barras.
    Retorna nombre, precio y stock de los primeros resultados.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        query: Texto, código interno o código de barras a buscar.
    """
    query = query.strip()
    if not query:
        return "Indica el nombre, código interno o código de barras del producto."

    # Intento búsqueda exacta por código primero
    try:
        exact = await web_get("/v1/productos/buscar", empresa_id, {"codigo": query})
        product = (exact.get("data") or {}).get("producto") if exact.get("success") else None
        if product:
            return "Producto encontrado:\n" + _product_line(product)
    except Exception:
        pass

    # Búsqueda por texto
    try:
        data = await web_get("/v1/productos", empresa_id, {"q": query, "per_page": 5, "page": 1})
    except Exception as exc:
        return f"Error al buscar producto: {exc}"

    if not data.get("success"):
        return f"Sin resultados: {data.get('message', 'sin detalle')}"

    payload = data.get("data") or {}
    items = (payload.get("productos") or payload if isinstance(payload, list) else [])[:5]
    if not items:
        return f"No se encontraron productos para '{query}'."

    lines = [f"Productos encontrados para '{query}':"]
    for p in items:
        lines.append(_product_line(p))
    return "\n".join(lines)


@tool
async def consultar_stock(
    empresa_id: int,
    producto_id: Optional[int] = None,
    nombre_o_codigo: Optional[str] = None,
    sucursal_id: Optional[int] = None,
) -> str:
    """
    Consulta el stock disponible de un producto por ID, nombre o código de barras.
    Retorna stock por ubicación/sucursal.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        producto_id: ID numérico del producto (si se conoce).
        nombre_o_codigo: Nombre parcial o código de barras del producto.
        sucursal_id: Filtrar por sucursal (opcional).
    """
    pid = producto_id

    # Resolver ID a partir de nombre/código si no viene
    if pid is None and nombre_o_codigo:
        try:
            exact = await web_get("/v1/productos/buscar", empresa_id, {"codigo": nombre_o_codigo})
            p = (exact.get("data") or {}).get("producto") if exact.get("success") else None
            if p:
                pid = int(p["id"])
            else:
                data = await web_get("/v1/productos", empresa_id, {"q": nombre_o_codigo, "per_page": 1})
                items = (data.get("data") or {})
                items = items.get("productos") or (items if isinstance(items, list) else [])
                if items:
                    pid = int(items[0]["id"])
        except Exception:
            pass

    if pid is None:
        return "No encontré el producto. Intenta con el nombre exacto o el código de barras."

    try:
        data = await web_get(f"/v1/stock/producto/{pid}", empresa_id)
    except Exception as exc:
        return f"Error al consultar stock: {exc}"

    if not data.get("success"):
        return f"Sin datos de stock: {data.get('message', 'sin detalle')}"

    ubicaciones = data.get("data") or []
    if sucursal_id:
        ubicaciones = [u for u in ubicaciones if int(u.get("sucursal_id") or 0) == sucursal_id]

    if not ubicaciones:
        scope = f" en sucursal {sucursal_id}" if sucursal_id else ""
        return f"Producto {pid} sin stock registrado{scope}."

    total = sum(float(u.get("cantidad") or 0) for u in ubicaciones)
    lines = [f"Stock producto {pid} (total: {total:g} uds):"]
    for u in ubicaciones:
        lines.append(f"  · {u.get('nombre_ubicacion', '?')}: {float(u.get('cantidad') or 0):g} uds")
    return "\n".join(lines)


@tool
async def stock_critico(
    empresa_id: int,
    sucursal_id: Optional[int] = None,
    limite: int = 15,
) -> str:
    """
    Lista los productos con stock igual o por debajo del mínimo configurado (en alerta).
    Útil para saber qué hay que reponer urgente.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        sucursal_id: Filtrar por sucursal (opcional).
        limite: Máximo de productos a mostrar (default 15).
    """
    try:
        data = await web_get(
            "/v1/productos",
            empresa_id,
            {"per_page": 500, "page": 1},
        )
    except Exception as exc:
        return f"Error al consultar productos: {exc}"

    if not data.get("success"):
        return f"Sin datos: {data.get('message', 'sin detalle')}"

    payload = data.get("data") or {}
    productos = payload.get("productos") or (payload if isinstance(payload, list) else [])

    criticos = []
    for p in productos:
        if not p.get("controla_stock"):
            continue
        stock = float(p.get("stock_total") or 0)
        minimo = float(p.get("stock_minimo") or 0)
        if stock <= minimo:
            criticos.append((stock, minimo, p))

    criticos.sort(key=lambda x: x[0])  # menor stock primero

    if not criticos:
        return "Todos los productos tienen stock por encima del mínimo configurado."

    lines = [f"Productos con stock crítico ({len(criticos)} encontrados):"]
    for stock, minimo, p in criticos[: max(1, min(limite, 20))]:
        code = p.get("codigo_barra_principal") or p.get("codigo") or "-"
        estado = "AGOTADO" if stock <= 0 else "BAJO MÍNIMO"
        lines.append(
            f"  [{estado}] {p.get('nombre', '?')} [{code}]"
            f" · stock: {stock:g} · mínimo: {minimo:g}"
        )
    if len(criticos) > limite:
        lines.append(f"  … y {len(criticos) - limite} más.")
    return "\n".join(lines)
