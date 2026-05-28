CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(190) NOT NULL,
    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE documentos_emitidos
    ADD COLUMN IF NOT EXISTS sucursal_id BIGINT UNSIGNED NULL AFTER empresa_id,
    ADD COLUMN IF NOT EXISTS cliente_id BIGINT UNSIGNED NULL AFTER venta_id,
    MODIFY COLUMN folio VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS folio_origen ENUM('MANUAL', 'INTERNO', 'SII', 'OFFLINE') NULL AFTER folio,
    MODIFY COLUMN estado VARCHAR(40) NOT NULL DEFAULT 'PENDIENTE_EMISION',
    ADD COLUMN IF NOT EXISTS rut_receptor VARCHAR(20) NULL AFTER fecha_emision,
    ADD COLUMN IF NOT EXISTS razon_social_receptor VARCHAR(190) NULL AFTER rut_receptor,
    ADD COLUMN IF NOT EXISTS giro_receptor VARCHAR(190) NULL AFTER razon_social_receptor,
    ADD COLUMN IF NOT EXISTS direccion_receptor VARCHAR(255) NULL AFTER giro_receptor,
    ADD COLUMN IF NOT EXISTS comuna_receptor VARCHAR(100) NULL AFTER direccion_receptor,
    ADD COLUMN IF NOT EXISTS ciudad_receptor VARCHAR(100) NULL AFTER comuna_receptor,
    ADD COLUMN IF NOT EXISTS neto BIGINT NOT NULL DEFAULT 0 AFTER ciudad_receptor,
    ADD COLUMN IF NOT EXISTS exento BIGINT NOT NULL DEFAULT 0 AFTER neto,
    ADD COLUMN IF NOT EXISTS impuestos BIGINT NOT NULL DEFAULT 0 AFTER exento,
    ADD COLUMN IF NOT EXISTS xml_path VARCHAR(255) NULL AFTER payload_json,
    ADD COLUMN IF NOT EXISTS pdf_path VARCHAR(255) NULL AFTER xml_path,
    ADD COLUMN IF NOT EXISTS track_id VARCHAR(120) NULL AFTER pdf_path,
    ADD COLUMN IF NOT EXISTS respuesta_sii_json JSON NULL AFTER track_id,
    ADD COLUMN IF NOT EXISTS error_sii TEXT NULL AFTER respuesta_sii_json,
    ADD COLUMN IF NOT EXISTS referencia_documento_id BIGINT UNSIGNED NULL AFTER error_sii,
    ADD COLUMN IF NOT EXISTS motivo_anulacion VARCHAR(255) NULL AFTER referencia_documento_id,
    ADD COLUMN IF NOT EXISTS metadata_json JSON NULL AFTER motivo_anulacion,
    ADD COLUMN IF NOT EXISTS created_by_usuario_id BIGINT UNSIGNED NULL AFTER metadata_json,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

DROP PROCEDURE IF EXISTS mypos_add_index_if_missing;
DELIMITER //
CREATE PROCEDURE mypos_add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND INDEX_NAME = p_index_name
    ) THEN
        SET @stmt_sql = p_sql;
        PREPARE stmt FROM @stmt_sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS mypos_add_fk_if_missing;
DELIMITER //
CREATE PROCEDURE mypos_add_fk_if_missing(
    IN p_constraint_name VARCHAR(64),
    IN p_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = p_constraint_name
    ) THEN
        SET @stmt_sql = p_sql;
        PREPARE stmt FROM @stmt_sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

CALL mypos_add_index_if_missing('documentos_emitidos', 'idx_documentos_emitidos_sucursal_fecha', 'ALTER TABLE documentos_emitidos ADD INDEX idx_documentos_emitidos_sucursal_fecha (empresa_id, sucursal_id, fecha_emision)');
CALL mypos_add_index_if_missing('documentos_emitidos', 'idx_documentos_emitidos_tipo_estado', 'ALTER TABLE documentos_emitidos ADD INDEX idx_documentos_emitidos_tipo_estado (empresa_id, tipo_documento, estado)');
CALL mypos_add_index_if_missing('documentos_emitidos', 'idx_documentos_emitidos_cliente', 'ALTER TABLE documentos_emitidos ADD INDEX idx_documentos_emitidos_cliente (cliente_id)');
CALL mypos_add_index_if_missing('documentos_emitidos', 'idx_documentos_emitidos_ref', 'ALTER TABLE documentos_emitidos ADD INDEX idx_documentos_emitidos_ref (referencia_documento_id)');
CALL mypos_add_index_if_missing('documentos_emitidos', 'idx_documentos_emitidos_created_by', 'ALTER TABLE documentos_emitidos ADD INDEX idx_documentos_emitidos_created_by (created_by_usuario_id)');

CALL mypos_add_fk_if_missing('fk_documentos_emitidos_sucursal', 'ALTER TABLE documentos_emitidos ADD CONSTRAINT fk_documentos_emitidos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id)');
CALL mypos_add_fk_if_missing('fk_documentos_emitidos_cliente', 'ALTER TABLE documentos_emitidos ADD CONSTRAINT fk_documentos_emitidos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id)');
CALL mypos_add_fk_if_missing('fk_documentos_emitidos_ref', 'ALTER TABLE documentos_emitidos ADD CONSTRAINT fk_documentos_emitidos_ref FOREIGN KEY (referencia_documento_id) REFERENCES documentos_emitidos (id)');
CALL mypos_add_fk_if_missing('fk_documentos_emitidos_created_by', 'ALTER TABLE documentos_emitidos ADD CONSTRAINT fk_documentos_emitidos_created_by FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios (id)');

CREATE TABLE IF NOT EXISTS documento_emitido_detalles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    documento_emitido_id BIGINT UNSIGNED NOT NULL,
    venta_detalle_id BIGINT UNSIGNED NULL,
    producto_id BIGINT UNSIGNED NULL,
    codigo_producto VARCHAR(80) NULL,
    nombre_producto VARCHAR(190) NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL,
    precio_unitario BIGINT NOT NULL DEFAULT 0,
    descuento BIGINT NOT NULL DEFAULT 0,
    neto BIGINT NOT NULL DEFAULT 0,
    exento BIGINT NOT NULL DEFAULT 0,
    impuestos BIGINT NOT NULL DEFAULT 0,
    total BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_doc_emitido_detalles_doc (documento_emitido_id),
    KEY idx_doc_emitido_detalles_venta_detalle (venta_detalle_id),
    KEY idx_doc_emitido_detalles_producto (producto_id),
    CONSTRAINT fk_doc_emitido_detalles_doc FOREIGN KEY (documento_emitido_id) REFERENCES documentos_emitidos (id),
    CONSTRAINT fk_doc_emitido_detalles_venta_detalle FOREIGN KEY (venta_detalle_id) REFERENCES venta_detalles (id),
    CONSTRAINT fk_doc_emitido_detalles_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documento_emitido_impuestos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    documento_emitido_id BIGINT UNSIGNED NOT NULL,
    impuesto_id BIGINT UNSIGNED NULL,
    codigo_impuesto VARCHAR(80) NOT NULL,
    nombre_impuesto VARCHAR(160) NOT NULL,
    tasa_base_10000 BIGINT NOT NULL DEFAULT 0,
    monto BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_doc_emitido_impuestos_doc (documento_emitido_id),
    KEY idx_doc_emitido_impuestos_impuesto (impuesto_id),
    CONSTRAINT fk_doc_emitido_impuestos_doc FOREIGN KEY (documento_emitido_id) REFERENCES documentos_emitidos (id),
    CONSTRAINT fk_doc_emitido_impuestos_impuesto FOREIGN KEY (impuesto_id) REFERENCES impuestos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS mypos_add_index_if_missing;
DROP PROCEDURE IF EXISTS mypos_add_fk_if_missing;

INSERT INTO schema_migrations (migration)
VALUES ('011_documentos_tributarios_internos')
ON DUPLICATE KEY UPDATE migration = VALUES(migration);
