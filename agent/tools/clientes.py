"""
Herramientas de clientes — solo lectura.
Endpoints:
  GET /v1/clientes?q=texto&empresa_id=X → buscar por nombre/RUT/email
"""

from __future__ import annotations

import re
from typing import Optional

from langchain_core.tools import tool

from tools.mypos_client import web_get


def _clean_rut(text: str) -> str:
    """Normaliza RUT: elimina puntos, guiones, espacios → '12345678k'."""
    return re.sub(r"[.\-\s]", "", text).lower()


def _looks_like_rut(text: str) -> bool:
    """Detecta si el texto parece un RUT chileno (dígitos + dígito verificador)."""
    cleaned = _clean_rut(text)
    return bool(re.match(r"^\d{7,8}[0-9k]$", cleaned))


def _cliente_line(c: dict) -> str:
    nombre = c.get("nombre") or c.get("razon_social") or "?"
    rut = c.get("rut") or ""
    email = c.get("email") or ""
    telefono = c.get("telefono") or ""
    parts = [f"  · {nombre}"]
    if rut:
        parts.append(f" · RUT {rut}")
    if email:
        parts.append(f" · {email}")
    if telefono:
        parts.append(f" · {telefono}")
    return "".join(parts)


@tool
async def buscar_cliente(
    empresa_id: int,
    query: str,
    limite: int = 5,
) -> str:
    """
    Busca un cliente por nombre, RUT o email.
    Retorna nombre, RUT, email y teléfono de los primeros resultados.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        query: Nombre parcial, RUT (con o sin puntos/guión) o email del cliente.
        limite: Máximo de resultados a mostrar (default 5).
    """
    query = query.strip()
    if not query:
        return "Indica el nombre, RUT o email del cliente a buscar."

    q = _clean_rut(query) if _looks_like_rut(query) else query

    try:
        data = await web_get(
            "/v1/clientes",
            empresa_id,
            {"q": q, "per_page": max(1, min(limite, 10)), "page": 1},
        )
    except Exception as exc:
        return f"Error al buscar cliente: {exc}"

    if not data.get("success"):
        return f"Sin resultados: {data.get('message', 'sin detalle')}"

    payload = data.get("data") or {}
    clientes = payload.get("clientes") or (payload if isinstance(payload, list) else [])
    clientes = clientes[: max(1, min(limite, 10))]

    if not clientes:
        return f"No se encontraron clientes para '{query}'."

    lines = [f"Clientes encontrados para '{query}':"]
    for c in clientes:
        lines.append(_cliente_line(c))

    saldo = clientes[0].get("saldo_cuenta") if len(clientes) == 1 else None
    if saldo is not None:
        lines.append(f"  Saldo en cuenta: ${float(saldo):,.0f}")

    return "\n".join(lines)
