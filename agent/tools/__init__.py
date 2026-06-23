from tools.ventas import resumen_ventas_hoy, ventas_por_producto
from tools.stock import consultar_stock, buscar_producto
from tools.folios import estado_folios_sii
from tools.caja import estado_cajas
from tools.escalate import solicitar_aprobacion_humana

ALL_TOOLS = [
    resumen_ventas_hoy,
    ventas_por_producto,
    consultar_stock,
    buscar_producto,
    estado_folios_sii,
    estado_cajas,
    solicitar_aprobacion_humana,
]
