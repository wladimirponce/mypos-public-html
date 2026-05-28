CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(190) NOT NULL,
    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE caf_archivos
    ADD COLUMN IF NOT EXISTS rut_emisor VARCHAR(20) NULL AFTER tipo_documento,
    ADD COLUMN IF NOT EXISTS razon_social_emisor VARCHAR(190) NULL AFTER rut_emisor,
    ADD COLUMN IF NOT EXISTS archivo_path VARCHAR(255) NULL AFTER fecha_vencimiento,
    ADD COLUMN IF NOT EXISTS created_by_usuario_id BIGINT UNSIGNED NULL AFTER estado;

ALTER TABLE folios_asignaciones
    ADD COLUMN IF NOT EXISTS caja_id BIGINT UNSIGNED NULL AFTER sucursal_id,
    ADD COLUMN IF NOT EXISTS alerta_minimo BIGINT UNSIGNED NOT NULL DEFAULT 10 AFTER folio_actual,
    MODIFY COLUMN estado ENUM('ACTIVA', 'AGOTADA', 'SUSPENDIDA', 'VENCIDA', 'ANULADA') NOT NULL DEFAULT 'ACTIVA',
    ADD COLUMN IF NOT EXISTS created_by_usuario_id BIGINT UNSIGNED NULL AFTER agotado_at,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by_usuario_id,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE folios_consumidos
    ADD COLUMN IF NOT EXISTS caja_id BIGINT UNSIGNED NULL AFTER sucursal_id,
    ADD COLUMN IF NOT EXISTS caf_archivo_id BIGINT UNSIGNED NULL AFTER dispositivo_id,
    MODIFY COLUMN estado ENUM('RESERVADO', 'USADO_LOCAL', 'USADO_INTERNO', 'SINCRONIZADO', 'ENVIADO_SII', 'ACEPTADO_SII', 'RECHAZADO_SII', 'ANULADO') NOT NULL DEFAULT 'RESERVADO',
    ADD COLUMN IF NOT EXISTS origen ENUM('ONLINE', 'OFFLINE') NOT NULL DEFAULT 'ONLINE' AFTER estado,
    ADD COLUMN IF NOT EXISTS reservado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER sync_status,
    ADD COLUMN IF NOT EXISTS usado_at DATETIME NULL AFTER reservado_at,
    ADD COLUMN IF NOT EXISTS created_by_usuario_id BIGINT UNSIGNED NULL AFTER synced_at,
    ADD COLUMN IF NOT EXISTS metadata_json JSON NULL AFTER created_by_usuario_id,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER metadata_json,
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

DROP PROCEDURE IF EXISTS mypos_drop_constraint_if_exists;
DELIMITER //
CREATE PROCEDURE mypos_drop_constraint_if_exists(
    IN p_table_name VARCHAR(64),
    IN p_constraint_name VARCHAR(64)
)
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND CONSTRAINT_NAME = p_constraint_name
    ) THEN
        SET @stmt_sql = CONCAT('ALTER TABLE ', p_table_name, ' DROP CONSTRAINT ', p_constraint_name);
        PREPARE stmt FROM @stmt_sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

CALL mypos_drop_constraint_if_exists('folios_asignaciones', 'chk_folios_asignaciones_actual');
ALTER TABLE folios_asignaciones
    ADD CONSTRAINT chk_folios_asignaciones_actual CHECK (folio_actual BETWEEN (folio_desde - 1) AND folio_hasta);

CALL mypos_add_index_if_missing('caf_archivos', 'idx_caf_archivos_created_by', 'ALTER TABLE caf_archivos ADD INDEX idx_caf_archivos_created_by (created_by_usuario_id)');
CALL mypos_add_index_if_missing('folios_asignaciones', 'idx_folios_asignaciones_caja', 'ALTER TABLE folios_asignaciones ADD INDEX idx_folios_asignaciones_caja (caja_id)');
CALL mypos_add_index_if_missing('folios_asignaciones', 'idx_folios_asignaciones_created_by', 'ALTER TABLE folios_asignaciones ADD INDEX idx_folios_asignaciones_created_by (created_by_usuario_id)');
CALL mypos_add_index_if_missing('folios_consumidos', 'idx_folios_consumidos_caja', 'ALTER TABLE folios_consumidos ADD INDEX idx_folios_consumidos_caja (caja_id)');
CALL mypos_add_index_if_missing('folios_consumidos', 'idx_folios_consumidos_caf_archivo', 'ALTER TABLE folios_consumidos ADD INDEX idx_folios_consumidos_caf_archivo (caf_archivo_id)');
CALL mypos_add_index_if_missing('folios_consumidos', 'idx_folios_consumidos_created_by', 'ALTER TABLE folios_consumidos ADD INDEX idx_folios_consumidos_created_by (created_by_usuario_id)');
CALL mypos_add_index_if_missing('folios_consumidos', 'idx_folios_consumidos_origen', 'ALTER TABLE folios_consumidos ADD INDEX idx_folios_consumidos_origen (origen)');

CALL mypos_add_fk_if_missing('fk_caf_archivos_created_by', 'ALTER TABLE caf_archivos ADD CONSTRAINT fk_caf_archivos_created_by FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios (id)');
CALL mypos_add_fk_if_missing('fk_folios_asignaciones_caja', 'ALTER TABLE folios_asignaciones ADD CONSTRAINT fk_folios_asignaciones_caja FOREIGN KEY (caja_id) REFERENCES cajas (id)');
CALL mypos_add_fk_if_missing('fk_folios_asignaciones_created_by', 'ALTER TABLE folios_asignaciones ADD CONSTRAINT fk_folios_asignaciones_created_by FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios (id)');
CALL mypos_add_fk_if_missing('fk_folios_consumidos_caja', 'ALTER TABLE folios_consumidos ADD CONSTRAINT fk_folios_consumidos_caja FOREIGN KEY (caja_id) REFERENCES cajas (id)');
CALL mypos_add_fk_if_missing('fk_folios_consumidos_caf_archivo', 'ALTER TABLE folios_consumidos ADD CONSTRAINT fk_folios_consumidos_caf_archivo FOREIGN KEY (caf_archivo_id) REFERENCES caf_archivos (id)');
CALL mypos_add_fk_if_missing('fk_folios_consumidos_created_by', 'ALTER TABLE folios_consumidos ADD CONSTRAINT fk_folios_consumidos_created_by FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios (id)');

DROP PROCEDURE IF EXISTS mypos_add_index_if_missing;
DROP PROCEDURE IF EXISTS mypos_add_fk_if_missing;
DROP PROCEDURE IF EXISTS mypos_drop_constraint_if_exists;

INSERT INTO schema_migrations (migration)
VALUES ('012_folios_funcionales')
ON DUPLICATE KEY UPDATE migration = VALUES(migration);
