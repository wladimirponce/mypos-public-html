-- Permite reutilizar un codigo de barra que fue desactivado al corregir un
-- producto. La unicidad se mantiene estricta entre codigos activos de una
-- misma empresa.

SET @uq_barcode_legacy_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'productos_codigos_barra'
      AND index_name = 'uq_productos_codigos_empresa_codigo'
);
SET @drop_uq_barcode_legacy_sql := IF(
    @uq_barcode_legacy_exists > 0,
    'ALTER TABLE productos_codigos_barra DROP INDEX uq_productos_codigos_empresa_codigo',
    'SELECT 1'
);
PREPARE drop_uq_barcode_legacy_stmt FROM @drop_uq_barcode_legacy_sql;
EXECUTE drop_uq_barcode_legacy_stmt;
DEALLOCATE PREPARE drop_uq_barcode_legacy_stmt;

ALTER TABLE productos_codigos_barra
    ADD COLUMN IF NOT EXISTS codigo_barra_activo VARCHAR(80)
        GENERATED ALWAYS AS (CASE WHEN activo = 1 THEN codigo_barra ELSE NULL END) STORED;

CREATE UNIQUE INDEX IF NOT EXISTS uq_productos_codigos_empresa_codigo_activo
    ON productos_codigos_barra (empresa_id, codigo_barra_activo);

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('093_codigo_barra_unico_activo');
