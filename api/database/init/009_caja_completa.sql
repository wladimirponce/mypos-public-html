CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(190) NOT NULL,
    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE caja_aperturas
    ADD COLUMN IF NOT EXISTS observacion_apertura VARCHAR(255) NULL AFTER estado,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

CREATE TABLE IF NOT EXISTS caja_movimientos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    caja_apertura_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    tipo ENUM('INGRESO', 'RETIRO') NOT NULL,
    concepto VARCHAR(160) NOT NULL,
    monto BIGINT NOT NULL,
    observacion VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_caja_movimientos_apertura (empresa_id, caja_apertura_id, tipo),
    KEY idx_caja_movimientos_sucursal_fecha (empresa_id, sucursal_id, created_at),
    CONSTRAINT fk_caja_movimientos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_caja_movimientos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_caja_movimientos_apertura FOREIGN KEY (caja_apertura_id) REFERENCES caja_aperturas (id),
    CONSTRAINT fk_caja_movimientos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT chk_caja_movimientos_monto CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE caja_cierres
    ADD COLUMN IF NOT EXISTS caja_apertura_id BIGINT UNSIGNED NULL AFTER caja_id,
    ADD COLUMN IF NOT EXISTS monto_inicial BIGINT NOT NULL DEFAULT 0 AFTER fecha_cierre,
    ADD COLUMN IF NOT EXISTS total_ventas_efectivo BIGINT NOT NULL DEFAULT 0 AFTER monto_inicial,
    ADD COLUMN IF NOT EXISTS total_ventas_tarjeta BIGINT NOT NULL DEFAULT 0 AFTER total_ventas_efectivo,
    ADD COLUMN IF NOT EXISTS total_ventas_transferencia BIGINT NOT NULL DEFAULT 0 AFTER total_ventas_tarjeta,
    ADD COLUMN IF NOT EXISTS total_ventas_otros BIGINT NOT NULL DEFAULT 0 AFTER total_ventas_transferencia,
    ADD COLUMN IF NOT EXISTS total_ingresos BIGINT NOT NULL DEFAULT 0 AFTER total_ventas_otros,
    ADD COLUMN IF NOT EXISTS total_retiros BIGINT NOT NULL DEFAULT 0 AFTER total_ingresos,
    ADD COLUMN IF NOT EXISTS monto_esperado BIGINT NOT NULL DEFAULT 0 AFTER total_retiros,
    ADD COLUMN IF NOT EXISTS monto_contado BIGINT NOT NULL DEFAULT 0 AFTER monto_esperado,
    ADD COLUMN IF NOT EXISTS observacion_cierre VARCHAR(255) NULL AFTER observacion,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

UPDATE caja_cierres
SET caja_apertura_id = apertura_id
WHERE caja_apertura_id IS NULL;

UPDATE caja_cierres
SET monto_contado = monto_declarado,
    monto_esperado = monto_sistema
WHERE monto_contado = 0 AND monto_esperado = 0;

SET @fk_caja_cierres_caja_apertura := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE caja_cierres ADD CONSTRAINT fk_caja_cierres_caja_apertura FOREIGN KEY (caja_apertura_id) REFERENCES caja_aperturas (id)',
        'SELECT 1'
    )
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'caja_cierres'
      AND CONSTRAINT_NAME = 'fk_caja_cierres_caja_apertura'
);
PREPARE stmt FROM @fk_caja_cierres_caja_apertura;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE INDEX IF NOT EXISTS idx_caja_cierres_apertura_id
    ON caja_cierres (empresa_id, caja_apertura_id);

ALTER TABLE ventas
    ADD COLUMN IF NOT EXISTS caja_apertura_id BIGINT UNSIGNED NULL AFTER apertura_id;

UPDATE ventas
SET caja_apertura_id = apertura_id
WHERE caja_apertura_id IS NULL AND apertura_id IS NOT NULL;

SET @fk_ventas_caja_apertura := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE ventas ADD CONSTRAINT fk_ventas_caja_apertura FOREIGN KEY (caja_apertura_id) REFERENCES caja_aperturas (id)',
        'SELECT 1'
    )
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ventas'
      AND CONSTRAINT_NAME = 'fk_ventas_caja_apertura'
);
PREPARE stmt FROM @fk_ventas_caja_apertura;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE INDEX IF NOT EXISTS idx_ventas_caja_apertura
    ON ventas (empresa_id, caja_apertura_id);

INSERT INTO schema_migrations (migration)
VALUES ('009_caja_completa')
ON DUPLICATE KEY UPDATE migration = VALUES(migration);
