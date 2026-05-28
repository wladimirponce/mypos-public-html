CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(190) NOT NULL,
    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ventas
    ADD COLUMN IF NOT EXISTS anulada_at DATETIME NULL AFTER deleted_at,
    ADD COLUMN IF NOT EXISTS anulada_por_usuario_id BIGINT UNSIGNED NULL AFTER anulada_at,
    ADD COLUMN IF NOT EXISTS motivo_anulacion VARCHAR(255) NULL AFTER anulada_por_usuario_id;

SET @fk_ventas_anulada_por := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE ventas ADD CONSTRAINT fk_ventas_anulada_por FOREIGN KEY (anulada_por_usuario_id) REFERENCES usuarios (id)',
        'SELECT 1'
    )
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ventas'
      AND CONSTRAINT_NAME = 'fk_ventas_anulada_por'
);
PREPARE stmt FROM @fk_ventas_anulada_por;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE compras
    ADD COLUMN IF NOT EXISTS anulada_por_usuario_id BIGINT UNSIGNED NULL AFTER anulada_at,
    ADD COLUMN IF NOT EXISTS reversada_at DATETIME NULL AFTER motivo_anulacion,
    ADD COLUMN IF NOT EXISTS reversada_por_usuario_id BIGINT UNSIGNED NULL AFTER reversada_at,
    ADD COLUMN IF NOT EXISTS motivo_reversa VARCHAR(255) NULL AFTER reversada_por_usuario_id;

SET @fk_compras_anulada_por := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE compras ADD CONSTRAINT fk_compras_anulada_por FOREIGN KEY (anulada_por_usuario_id) REFERENCES usuarios (id)',
        'SELECT 1'
    )
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'compras'
      AND CONSTRAINT_NAME = 'fk_compras_anulada_por'
);
PREPARE stmt FROM @fk_compras_anulada_por;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_compras_reversada_por := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE compras ADD CONSTRAINT fk_compras_reversada_por FOREIGN KEY (reversada_por_usuario_id) REFERENCES usuarios (id)',
        'SELECT 1'
    )
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'compras'
      AND CONSTRAINT_NAME = 'fk_compras_reversada_por'
);
PREPARE stmt FROM @fk_compras_reversada_por;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS anulaciones_operaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    tipo_operacion ENUM('VENTA', 'COMPRA') NOT NULL,
    operacion_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    estado ENUM('APLICADA', 'ERROR') NOT NULL DEFAULT 'APLICADA',
    afecta_stock TINYINT(1) NOT NULL DEFAULT 1,
    afecta_caja TINYINT(1) NOT NULL DEFAULT 0,
    referencia_stock_movimiento_id BIGINT UNSIGNED NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_anulaciones_empresa_fecha (empresa_id, created_at),
    KEY idx_anulaciones_operacion (empresa_id, tipo_operacion, operacion_id),
    KEY idx_anulaciones_sucursal (empresa_id, sucursal_id, created_at),
    CONSTRAINT fk_anulaciones_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_anulaciones_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_anulaciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_anulaciones_stock_movimiento FOREIGN KEY (referencia_stock_movimiento_id) REFERENCES stock_movimientos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS anulacion_detalles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    anulacion_operacion_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL,
    stock_movimiento_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_anulacion_detalles_operacion (anulacion_operacion_id),
    KEY idx_anulacion_detalles_producto (producto_id),
    CONSTRAINT fk_anulacion_detalles_operacion FOREIGN KEY (anulacion_operacion_id) REFERENCES anulaciones_operaciones (id),
    CONSTRAINT fk_anulacion_detalles_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT fk_anulacion_detalles_stock_movimiento FOREIGN KEY (stock_movimiento_id) REFERENCES stock_movimientos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (migration)
VALUES ('010_anulaciones_reversas')
ON DUPLICATE KEY UPDATE migration = VALUES(migration);
