"""
Herramientas de folios SII — solo lectura.
Endpoints usados:
  GET /admin/api.php?action=folios_urgencia  → alerta global de folios
  GET /admin/api.php?action=caf_resumen      → resumen de CAFs por tipo
"""

from langchain_core.tools import tool

from tools.mypos_client import admin_get

_TIPO_LABEL = {39: "Boleta electrónica", 33: "Factura electrónica", 41: "Boleta no afecta", 56: "Nota de débito", 61: "Nota de crédito", 52: "Guía de despacho"}
_UMBRAL_URGENTE = 100
_UMBRAL_ALERTA = 200


@tool
async def estado_folios_sii(empresa_id: int) -> str:
    """
    Consulta el estado de los folios SII: cuántos quedan disponibles por tipo de DTE.
    Advierte si el stock de folios está en nivel de alerta o urgencia.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
    """
    try:
        urgencia = await admin_get("folios_urgencia")
        resumen = await admin_get("caf_resumen")
    except Exception as exc:
        return f"Error al consultar folios SII: {exc}"

    lines: list[str] = []

    # Estado de urgencia
    urgencia_data = urgencia.get("data") or urgencia.get("urgencias") or []
    for item in urgencia_data:
        tipo = item.get("tipo_dte")
        restantes = item.get("restantes") or item.get("folios_restantes") or 0
        label = _TIPO_LABEL.get(tipo, f"Tipo {tipo}")

        if restantes <= _UMBRAL_URGENTE:
            nivel = "URGENTE"
        elif restantes <= _UMBRAL_ALERTA:
            nivel = "ALERTA"
        else:
            nivel = "OK"

        lines.append(f"  [{nivel}] {label} (tipo {tipo}): {restantes} folios restantes")

    if not lines:
        # Fallback: usar resumen de CAFs
        resumen_data = resumen.get("data") or resumen.get("resumen") or []
        for item in resumen_data:
            tipo = item.get("tipo_dte")
            restantes = item.get("disponibles") or 0
            label = _TIPO_LABEL.get(tipo, f"Tipo {tipo}")
            lines.append(f"  • {label} (tipo {tipo}): {restantes} folios disponibles")

    if not lines:
        return "No se encontró información de folios SII para esta empresa."

    header = "Estado folios SII:"
    footer = "\nTip: Si hay alertas URGENTES solicita un CAF nuevo desde el panel de Folios."
    return header + "\n" + "\n".join(lines) + footer
