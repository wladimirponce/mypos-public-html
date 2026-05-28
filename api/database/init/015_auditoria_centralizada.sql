CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditoria_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NULL,
    sucursal_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NULL,
    modulo VARCHAR(80) NOT NULL,
    accion VARCHAR(80) NOT NULL,
    entidad VARCHAR(80) NOT NULL,
    entidad_id BIGINT UNSIGNED NULL,
    descripcion VARCHAR(255) NULL,
    datos_anteriores_json LONGTEXT NULL,
    datos_nuevos_json LONGTEXT NULL,
    metadata_json LONGTEXT NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    dispositivo_id BIGINT UNSIGNED NULL,
    severidad ENUM('INFO','WARNING','ERROR','CRITICAL') NOT NULL DEFAULT 'INFO',
    resultado ENUM('OK','ERROR') NOT NULL DEFAULT 'OK',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_auditoria_empresa_fecha (empresa_id, created_at),
    KEY idx_auditoria_usuario_fecha (usuario_id, created_at),
    KEY idx_auditoria_modulo_accion (modulo, accion),
    KEY idx_auditoria_entidad (entidad, entidad_id),
    KEY idx_auditoria_severidad_resultado (severidad, resultado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

CREATE PROCEDURE mypos_add_column_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_column_name VARCHAR(64),
    IN p_column_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
          AND column_name = p_column_name
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', p_table_name, ' ADD COLUMN ', p_column_sql);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

CREATE PROCEDURE mypos_add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_index_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
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

CALL mypos_add_column_if_missing('auditoria_eventos', 'sucursal_id', 'sucursal_id BIGINT UNSIGNED NULL AFTER empresa_id');
CALL mypos_add_column_if_missing('auditoria_eventos', 'modulo', "modulo VARCHAR(80) NOT NULL DEFAULT 'sistema' AFTER usuario_id");
CALL mypos_add_column_if_missing('auditoria_eventos', 'descripcion', 'descripcion VARCHAR(255) NULL AFTER entidad_id');
CALL mypos_add_column_if_missing('auditoria_eventos', 'datos_anteriores_json', 'datos_anteriores_json LONGTEXT NULL AFTER descripcion');
CALL mypos_add_column_if_missing('auditoria_eventos', 'datos_nuevos_json', 'datos_nuevos_json LONGTEXT NULL AFTER datos_anteriores_json');
CALL mypos_add_column_if_missing('auditoria_eventos', 'metadata_json', 'metadata_json LONGTEXT NULL AFTER datos_nuevos_json');
CALL mypos_add_column_if_missing('auditoria_eventos', 'metadata', 'metadata LONGTEXT NULL AFTER metadata_json');
CALL mypos_add_column_if_missing('auditoria_eventos', 'dispositivo_id', 'dispositivo_id BIGINT UNSIGNED NULL AFTER user_agent');
CALL mypos_add_column_if_missing('auditoria_eventos', 'severidad', "severidad ENUM('INFO','WARNING','ERROR','CRITICAL') NOT NULL DEFAULT 'INFO' AFTER dispositivo_id");
CALL mypos_add_column_if_missing('auditoria_eventos', 'resultado', "resultado ENUM('OK','ERROR') NOT NULL DEFAULT 'OK' AFTER severidad");

UPDATE auditoria_eventos
SET metadata_json = COALESCE(metadata_json, metadata),
    modulo = CASE WHEN modulo = 'sistema' THEN entidad ELSE modulo END
WHERE metadata IS NOT NULL OR modulo = 'sistema';

CALL mypos_add_index_if_missing('auditoria_eventos', 'idx_auditoria_modulo_accion', 'CREATE INDEX idx_auditoria_modulo_accion ON auditoria_eventos (modulo, accion)');
CALL mypos_add_index_if_missing('auditoria_eventos', 'idx_auditoria_severidad_resultado', 'CREATE INDEX idx_auditoria_severidad_resultado ON auditoria_eventos (severidad, resultado)');
CALL mypos_add_index_if_missing('auditoria_eventos', 'idx_auditoria_sucursal_fecha', 'CREATE INDEX idx_auditoria_sucursal_fecha ON auditoria_eventos (sucursal_id, created_at)');

DROP PROCEDURE IF EXISTS mypos_add_column_if_missing;
DROP PROCEDURE IF EXISTS mypos_add_index_if_missing;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('015_auditoria_centralizada');
