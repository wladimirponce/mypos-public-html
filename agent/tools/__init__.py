from tools.ventas import ventas_periodo, ventas_por_producto
from tools.stock import buscar_producto, consultar_stock, stock_critico
from tools.clientes import buscar_cliente
from tools.cierres import cierres_pendientes
from tools.libros import resumen_iva
from tools.compras import compras_pendientes, sugerencias_reposicion
from tools.folios import estado_folios_sii
from tools.caja import estado_cajas
from tools.escalate import guia_accion_manual
from tools.exportar import exportar_excel_a_correo
from tools.analytics import calidad_datos, analitica_negocio, capital_inmovilizado

ALL_TOOLS = [
    # Ventas
    ventas_periodo,
    ventas_por_producto,
    # Stock y productos
    buscar_producto,
    consultar_stock,
    stock_critico,
    # Clientes
    buscar_cliente,
    # Operación
    estado_cajas,
    cierres_pendientes,
    # Compras
    compras_pendientes,
    sugerencias_reposicion,
    # Finanzas
    resumen_iva,
    calidad_datos,
    analitica_negocio,
    capital_inmovilizado,
    # SII
    estado_folios_sii,
    # Exportaciones (Excel al correo registrado de la empresa)
    exportar_excel_a_correo,
    # Acciones → guía manual (el agente es de solo lectura)
    guia_accion_manual,
]
