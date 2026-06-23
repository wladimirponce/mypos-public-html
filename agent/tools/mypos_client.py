"""
Cliente HTTP base para las APIs de MyPOS.

APIs disponibles:
  web_get()   → /api/v1/*          JWT Bearer  (ventas, stock, cajas, cierres, folios)
  admin_get() → /admin/api.php     X-API-KEY   (SII, DTE, CAFs)
  fb_get()    → /admin/fb/         X-API-KEY   (productos, stock por sucursal)

El JWT pertenece a la cuenta de servicio (mismo usuario para todas las empresas),
así que se cachea una sola vez globalmente — no por empresa_id.
"""

import time
import httpx
from config import settings

# BUG FIX: la cuenta de servicio es una sola → un único token para todos los empresa_id.
# Antes se cacheaba por empresa_id pero las credenciales no cambian, solo el empresa_id
# se pasa como query param a cada request individual.
_jwt_token: str = ""
_jwt_expires: float = 0.0

_JWT_TTL = 23 * 3600  # 23 horas (los JWT de MyPOS duran 24h)


async def _get_jwt() -> str:
    global _jwt_token, _jwt_expires
    if _jwt_token and time.time() < _jwt_expires:
        return _jwt_token

    async with httpx.AsyncClient(timeout=10.0) as client:
        resp = await client.post(
            f"{settings.mypos_web_url}/v1/auth/login",
            json={
                "email": settings.mypos_service_email,
                "password": settings.mypos_service_password,
            },
        )
        resp.raise_for_status()
        data = resp.json()

    _jwt_token = data["data"]["token"]
    _jwt_expires = time.time() + _JWT_TTL
    return _jwt_token


async def web_get(path: str, empresa_id: int, params: dict | None = None) -> dict:
    """GET autenticado al backend web (JWT Bearer)."""
    token = await _get_jwt()
    merged = {"empresa_id": empresa_id, **(params or {})}

    async with httpx.AsyncClient(timeout=15.0) as client:
        resp = await client.get(
            f"{settings.mypos_web_url}{path}",
            params=merged,
            headers={"Authorization": f"Bearer {token}"},
        )
        resp.raise_for_status()
        return resp.json()


async def admin_get(action: str, params: dict | None = None) -> dict:
    """GET a admin/api.php (X-API-KEY)."""
    merged = {"action": action, **(params or {})}

    async with httpx.AsyncClient(timeout=15.0) as client:
        resp = await client.get(
            settings.mypos_admin_url,
            params=merged,
            headers={"X-API-KEY": settings.mypos_api_key},
        )
        resp.raise_for_status()
        return resp.json()


async def fb_get(action: str, params: dict | None = None) -> dict:
    """GET a admin/fb (X-API-KEY)."""
    merged = {"action": action, **(params or {})}

    async with httpx.AsyncClient(timeout=15.0) as client:
        resp = await client.get(
            f"{settings.mypos_fb_url}/",
            params=merged,
            headers={"X-API-KEY": settings.mypos_api_key},
        )
        resp.raise_for_status()
        return resp.json()
