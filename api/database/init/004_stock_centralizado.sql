ALTER TABLE stock_sucursal
    ADD COLUMN IF NOT EXISTS reservado DECIMAL(14,3) NOT NULL DEFAULT 0.000 AFTER cantidad;

ALTER TABLE stock_movimientos
    MODIFY COLUMN usuario_id BIGINT UNSIGNED NULL;

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

CALL mypos_add_index_if_missing('stock_sucursal', 'idx_stock_sucursal_empresa_sucursal', 'ALTER TABLE stock_sucursal ADD INDEX idx_stock_sucursal_empresa_sucursal (empresa_id, sucursal_id)');
CALL mypos_add_index_if_missing('stock_movimientos', 'idx_stock_movimientos_tipo_fecha', 'ALTER TABLE stock_movimientos ADD INDEX idx_stock_movimientos_tipo_fecha (empresa_id, tipo_movimiento, created_at)');

DROP PROCEDURE IF EXISTS mypos_add_index_if_missing;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('004_stock_centralizado');
