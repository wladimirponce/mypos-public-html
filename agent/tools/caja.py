"""
Herramientas de caja, solo lectura.
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


@tool
async def estado_cajas(empresa_id: int, sucursal_id: Optional[int] = None) -> str:
    """
    Consulta el estado de las cajas.

    Si se entrega sucursal_id, consulta el estado de esa sucursal.
    Si no se entrega, lista todas las cajas configuradas y revisa una por una.

    Args:
        empresa_id: ID de la empresa del operador autenticado.
        sucursal_id: Filtrar por sucursal (opcional).
    """
    if sucursal_id:
        try:
            response = await web_get(
                "/v1/cajas/estado",
                empresa_id,
                {"sucursal_id": sucursal_id},
            )
        except Exception as exc:
            return f"Error al consultar cajas en sucursal {sucursal_id}: {exc}"

        if not response.get("success"):
            return f"No se pudo consultar cajas: {response.get('message', 'sin detalle')}"

        data = response.get("data") or {}
        if data.get("tiene_caja_abierta"):
            return (
                f"Caja abierta en sucursal {sucursal_id}: "
                f"{data.get('caja_nombre') or data.get('nombre', '?')} "
                f"por {data.get('usuario_apertura', '?')}. "
                f"Saldo estimado: {_money(data.get('saldo_actual_estimado'))}."
            )

        if data.get("tiene_caja_configurada"):
            return f"Sucursal {sucursal_id}: tiene caja configurada, pero no hay caja abierta."

        return f"Sucursal {sucursal_id}: no hay caja configurada."

    try:
        boxes_response = await web_get("/v1/cajas", empresa_id, {"activa": 1})
    except Exception as exc:
        return f"Error al listar cajas: {exc}"

    if not boxes_response.get("success"):
        return f"No se pudo listar cajas: {boxes_response.get('message', 'sin detalle')}"

    payload = boxes_response.get("data") or {}
    boxes = payload.get("cajas") if isinstance(payload, dict) else []
    if not boxes:
        return "No hay cajas configuradas para esta empresa."

    open_boxes: list[dict] = []
    closed_boxes: list[dict] = []
    errors: list[str] = []

    for box in boxes[:25]:
        box_id = int(box.get("id") or 0)
        box_sucursal_id = int(box.get("sucursal_id") or 0)
        if box_id <= 0 or box_sucursal_id <= 0:
            continue

        try:
            status = await web_get(
                "/v1/cajas/estado",
                empresa_id,
                {"sucursal_id": box_sucursal_id, "caja_id": box_id},
            )
        except Exception as exc:
            errors.append(f"{box.get('nombre', box_id)}: {exc}")
            continue

        data = status.get("data") or {}
        if status.get("success") and data.get("tiene_caja_abierta"):
            open_boxes.append(data)
        else:
            closed_boxes.append(box)

    lines = [
        f"Cajas configuradas: {len(boxes)}. "
        f"Abiertas: {len(open_boxes)}. "
        f"Cerradas/sin apertura: {len(closed_boxes)}."
    ]

    for item in open_boxes:
        lines.append(
            f"  - Sucursal {item.get('sucursal_id', '?')}: "
            f"{item.get('caja_nombre') or item.get('nombre', '?')} "
            f"por {item.get('usuario_apertura', '?')} "
            f"(saldo estimado {_money(item.get('saldo_actual_estimado'))})"
        )

    if errors:
        lines.append(f"No se pudo revisar {len(errors)} caja(s).")

    return "\n".join(lines)
