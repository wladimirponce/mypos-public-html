ALTER TABLE compras
    MODIFY COLUMN estado VARCHAR(30) NOT NULL DEFAULT 'BORRADOR',
    ADD COLUMN IF NOT EXISTS fecha_ingreso DATE NULL AFTER fecha_documento,
    ADD COLUMN IF NOT EXISTS observacion VARCHAR(255) NULL AFTER total,
    ADD COLUMN IF NOT EXISTS anulada_at DATETIME NULL AFTER observacion,
    ADD COLUMN IF NOT EXISTS motivo_anulacion VARCHAR(255) NULL AFTER anulada_at;

ALTER TABLE compra_detalles
    ADD COLUMN IF NOT EXISTS neto BIGINT NOT NULL DEFAULT 0 AFTER costo_unitario,
    ADD COLUMN IF NOT EXISTS iva BIGINT NOT NULL DEFAULT 0 AFTER neto;

DELIMITER //

CREATE OR REPLACE PROCEDURE mypos_add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
          AND index_name = p_index_name
    ) THEN
        SET @ddl = p_ddl;
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

DELIMITER ;

CALL mypos_add_index_if_missing('compras', 'idx_compras_estado', 'ALTER TABLE compras ADD INDEX idx_compras_estado (empresa_id, estado)');
CALL mypos_add_index_if_missing('compras', 'idx_compras_tipo_documento', 'ALTER TABLE compras ADD INDEX idx_compras_tipo_documento (empresa_id, tipo_documento)');

DROP PROCEDURE IF EXISTS mypos_add_index_if_missing;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('007_compras');
