"""
Guía de acciones manuales.

El agente MyPOS es de SOLO LECTURA sobre los datos del negocio: nunca anula
ventas, ajusta stock, cambia precios ni cierra cajas. Cuando el operador pide
modificar algo, esta tool devuelve la guía del procedimiento manual en el
módulo correspondiente de MyPOS.

(Reemplaza a la antigua `solicitar_aprobacion_humana`, que pausaba el grafo
esperando una aprobación que nunca llegaba porque el panel de escalaciones
no se construyó. Decisión de producto 2026-07: el agente no ejecuta acciones.)
"""

from langchain_core.tools import tool

GUIA_ACCIONES = (
    "Por seguridad no puedo modificar datos del sistema: los ingresos y "
    "cambios se hacen manualmente en MyPOS. Guía rápida:\n"
    "• Anular una venta → módulo Ventas → buscar la venta → Anular\n"
    "• Emitir boleta/factura/nota de crédito → módulo Ventas (POS)\n"
    "• Ajustar stock → módulo Inventario → Ajustes de stock\n"
    "• Cambiar precios → módulo Productos → editar el producto\n"
    "• Abrir/cerrar caja → módulo Caja → Apertura / Cierre diario\n"
    "• Registrar una compra → módulo Compras\n"
    "• Configuración y usuarios → módulo Configuración\n"
    "Dime qué necesitas hacer y te explico el paso a paso."
)


@tool
def guia_accion_manual(accion_solicitada: str) -> str:
    """
    Devuelve la guía del procedimiento MANUAL para una acción que modifica datos.
    Usar SIEMPRE que el operador pida: anular ventas, emitir documentos, cerrar
    o abrir cajas, ajustar stock, cambiar precios o configuración.
    El agente NUNCA ejecuta estas acciones; solo explica dónde y cómo hacerlas.

    Args:
        accion_solicitada: Qué pidió hacer el operador (texto libre).
    """
    return GUIA_ACCIONES
