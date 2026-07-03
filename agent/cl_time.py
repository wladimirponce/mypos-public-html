"""
Fecha "de negocio" en hora chilena.

El Python de Passenger (igual que el PHP CLI del hosting) puede correr en
UTC mientras MySQL y el negocio operan en hora de Chile: date.today() en UTC
apunta al día siguiente desde las ~20:00 de Chile, y "ventas de hoy" o una
exportación "del mes" quedarían corridas un día. Usar SIEMPRE cl_time.today()
para rangos de fechas que se comparan contra datos de MySQL.
"""

from __future__ import annotations

from datetime import date, datetime

try:
    from zoneinfo import ZoneInfo

    _CL = ZoneInfo("America/Santiago")
except Exception:  # tzdata ausente: degradar a hora local del sistema
    _CL = None


def today() -> date:
    if _CL is not None:
        return datetime.now(_CL).date()
    return date.today()
