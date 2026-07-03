"""
Exportaciones a Excel — el agente genera la planilla y la envía por correo.

Llama a POST /api/v1/agente/exportar (backend web): registry FIJO de tipos,
solo lectura, y el archivo sale ÚNICAMENTE al correo registrado de la
empresa — nunca a una dirección dictada en el chat (anti-exfiltración).
"""

from __future__ import annotations

from datetime import timedelta
from typing import Optional

from langchain_core.tools import tool

import cl_time
from tools.mypos_client import web_post

TIPOS_EXPORT = (
    "productos", "clientes", "proveedores", "stock", "ventas", "compras", "cierres",
)


def _rango_fechas(periodo: str) -> tuple[str, str]:
    """(desde, hasta) para tipos con fechas, en hora chilena. Default: mes en curso."""
    today = cl_time.today()
    if periodo == "hoy":
        return str(today), str(today)
    if periodo == "ayer":
        d = today - timedelta(days=1)
        return str(d), str(d)
    if periodo == "semana":
        return str(today - timedelta(days=today.weekday())), str(today)
    if periodo == "mes_anterior":
        primero = today.replace(day=1)
        ultimo_ant = primero - timedelta(days=1)
        return str(ultimo_ant.replace(day=1)), str(ultimo_ant)
    if periodo == "ultimos_30":
        return str(today - timedelta(days=29)), str(today)
    return str(today.replace(day=1)), str(today)


async def ejecutar_export(
    empresa_id: int,
    tipo: str,
    periodo: str = "",
) -> str:
    tipo = (tipo or "").strip().lower()
    if tipo not in TIPOS_EXPORT:
        return (
            "Puedo exportar a Excel y enviar al correo registrado: "
            + ", ".join(TIPOS_EXPORT)
            + ". Ejemplo: 'envíame el maestro de productos al correo'."
        )

    desde, hasta = _rango_fechas(periodo or "mes")

    try:
        data = await web_post(
            "/v1/agente/exportar",
            empresa_id,
            json_body={"tipo": tipo, "fecha_desde": desde, "fecha_hasta": hasta},
        )
    except Exception as exc:
        detail = str(exc)
        if "422" in detail and "correo" in detail.lower():
            return (
                "La empresa no tiene un correo registrado para recibir archivos. "
                "Complétalo en Configuración → Empresa y vuelve a pedirme la planilla."
            )
        return (
            "No pude generar o enviar la planilla en este momento. "
            "Intenta de nuevo en unos minutos."
        )

    result = data.get("data") or {}
    titulo = result.get("titulo") or tipo
    filas = result.get("filas")
    destino = result.get("destino") or "el correo registrado de la empresa"

    detalle_filas = f" ({filas} filas)" if isinstance(filas, int) else ""
    return (
        f"Listo ✅ Envié \"{titulo}\"{detalle_filas} en Excel a {destino}. "
        "Por seguridad solo envío archivos al correo registrado de la empresa."
    )


@tool
async def exportar_excel_a_correo(
    tipo: str,
    empresa_id: int,
    periodo: Optional[str] = None,
) -> str:
    """
    Genera una planilla Excel y la envía al correo REGISTRADO de la empresa
    (nunca a otra dirección). Usar cuando pidan "envíame un excel/planilla
    de..." o "exporta ... al correo".

    Args:
        tipo: uno de: productos, clientes, proveedores, stock, ventas, compras, cierres.
        empresa_id: ID de la empresa del operador (del contexto, sin modificar).
        periodo: solo para ventas/compras/cierres: hoy, ayer, semana, mes,
            mes_anterior, ultimos_30 (default: mes en curso).
    """
    return await ejecutar_export(empresa_id, tipo, periodo or "")
