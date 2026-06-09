-- =============================================================================
-- Fase D: Precio por peso y merma operativa
-- =============================================================================
-- Agrega soporte para productos vendidos por peso (ej: carnes, quesos, granel)
-- y para registro de merma operativa como tipo de movimiento propio.
-- Solo activo cuando la empresa tiene PRECIO_POR_PESO o MERMA_OPERATIVA.
-- =============================================================================

-- Indica que el precio se calcula por kg. La 'cantidad' en la venta es el peso en kg.
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS es_producto_peso TINYINT(1) NOT NULL DEFAULT 0 AFTER requiere_lote;

-- Precio de venta por kg. Se usa en lugar de precio_venta cuando es_producto_peso = 1.
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS precio_por_kg DECIMAL(12, 2) NULL AFTER es_producto_peso;

-- Porcentaje de merma operativa esperada (informativo; se usa en reportes Fase F).
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS merma_porcentaje DECIMAL(5, 2) NULL AFTER precio_por_kg;

-- Indice para consultas de productos por peso en reportes
CREATE INDEX IF NOT EXISTS idx_peso ON productos (empresa_id, es_producto_peso);
