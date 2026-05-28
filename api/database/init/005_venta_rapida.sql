ALTER TABLE ventas
    MODIFY COLUMN caja_id BIGINT UNSIGNED NULL;

ALTER TABLE venta_detalles
    ADD COLUMN IF NOT EXISTS descuento_tipo VARCHAR(20) NULL AFTER costo_unitario,
    ADD COLUMN IF NOT EXISTS descuento_valor BIGINT NOT NULL DEFAULT 0 AFTER descuento_tipo,
    ADD COLUMN IF NOT EXISTS neto BIGINT NOT NULL DEFAULT 0 AFTER descuento_total,
    ADD COLUMN IF NOT EXISTS impuestos_total BIGINT NOT NULL DEFAULT 0 AFTER neto,
    ADD COLUMN IF NOT EXISTS exento BIGINT NOT NULL DEFAULT 0 AFTER impuestos_total,
    ADD COLUMN IF NOT EXISTS margen_estimado BIGINT NOT NULL DEFAULT 0 AFTER margen_total,
    ADD COLUMN IF NOT EXISTS comision_vendedor BIGINT NOT NULL DEFAULT 0 AFTER comision_total;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('005_venta_rapida');
