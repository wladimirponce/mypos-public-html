CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

CREATE PROCEDURE mypos_add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_index_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
          AND index_name = p_index_name
    ) THEN
        SET @sql = p_index_sql;
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL mypos_add_index_if_missing(
    'documentos_emitidos',
    'idx_documentos_emitidos_libro_ventas',
    'CREATE INDEX idx_documentos_emitidos_libro_ventas ON documentos_emitidos (empresa_id, sucursal_id, fecha_emision, tipo_documento, estado)'
);

CALL mypos_add_index_if_missing(
    'compras',
    'idx_compras_libro_compras',
    'CREATE INDEX idx_compras_libro_compras ON compras (empresa_id, sucursal_id, fecha_documento, estado, proveedor_id)'
);

CALL mypos_add_index_if_missing(
    'documentos_ia',
    'idx_documentos_ia_compra',
    'CREATE INDEX idx_documentos_ia_compra ON documentos_ia (compra_id)'
);

DROP PROCEDURE IF EXISTS mypos_add_index_if_missing;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('013_libros');
