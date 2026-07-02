"""
Perfil de empresa para el prompt del agente completo (Fase 2).

Antes el LLM solo sabía "empresa_id: 24"; ahora recibe un bloque de contexto
con nombre, rubro, sucursales, ambiente SII, folios y suscripción, así las
respuestas dejan de ser genéricas ("tu negocio") y puede advertir cosas como
folios bajos o suscripción por vencer sin gastar tools.

Fuente: GET /api/v1/agente/perfil-empresa (backend web, cuenta de servicio).
Cache en memoria por empresa con TTL de 10 min — el perfil cambia poco y el
grafo es la capa menos usada. Ante cualquier error devuelve "" y el agente
funciona igual que antes (el contexto es un extra, nunca un bloqueo).
"""

from __future__ import annotations

import time

_cache: dict[int, tuple[float, str]] = {}
_TTL_SECONDS = 600


def _formatear(data: dict) -> str:
    lineas = []

    nombre = data.get("nombre_fantasia") or data.get("razon_social") or ""
    if nombre:
        linea = f"Negocio: {nombre}"
        if data.get("razon_social") and data.get("razon_social") != nombre:
            linea += f" ({data['razon_social']})"
        lineas.append(linea)

    if data.get("giro"):
        lineas.append(f"Rubro/giro: {data['giro']}")

    sucursales = data.get("sucursales") or []
    if sucursales:
        lineas.append(f"Sucursales activas ({len(sucursales)}): {', '.join(sucursales)}")

    if data.get("ambiente_sii"):
        lineas.append(f"Ambiente SII: {data['ambiente_sii']}")

    folios = data.get("folios_disponibles") or {}
    if folios:
        partes = [f"{tipo} {int(n)}" for tipo, n in folios.items()]
        lineas.append("Folios disponibles: " + ", ".join(partes))

    susc = data.get("suscripcion") or {}
    if susc.get("estado"):
        linea = f"Suscripción MyPOS: {susc['estado']}"
        if susc.get("fecha_fin"):
            linea += f" hasta {susc['fecha_fin']}"
        lineas.append(linea)

    return "\n".join(lineas)


async def get_context(empresa_id: int) -> str:
    """Bloque de texto para el system prompt, o '' si no se pudo obtener."""
    now = time.time()
    cached = _cache.get(empresa_id)
    if cached and now - cached[0] < _TTL_SECONDS:
        return cached[1]

    try:
        from tools.mypos_client import web_get

        data = await web_get("/v1/agente/perfil-empresa", empresa_id)
        texto = _formatear(data.get("data") or {})
    except Exception:
        # Sin perfil el agente responde igual; no cacheamos el fallo para
        # reintentar en la próxima conversación.
        return ""

    _cache[empresa_id] = (now, texto)
    return texto
