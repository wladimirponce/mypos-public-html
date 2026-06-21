-- Agrega margen de ganancia (markup %) al maestro de productos.
-- precio_venta_sugerido = costo_actual * (1 + margen_ganancia / 100)
-- NULL = sin control automático de precio.
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS margen_ganancia DECIMAL(5,2) NULL
        COMMENT 'Markup % sobre costo. NULL = sin control automatico'
    AFTER precio_venta;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('064_margen_ganancia');
