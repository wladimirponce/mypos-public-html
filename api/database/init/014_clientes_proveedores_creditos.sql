CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
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

CREATE PROCEDURE mypos_add_fk_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_fk_name VARCHAR(64),
    IN p_fk_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = p_table_name
          AND constraint_name = p_fk_name
          AND constraint_type = 'FOREIGN KEY'
    ) THEN
        SET @sql = p_fk_sql;
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL mypos_add_column_if_missing('clientes', 'tipo_cliente', "tipo_cliente ENUM('PERSONA','EMPRESA') NOT NULL DEFAULT 'PERSONA' AFTER empresa_id");
CALL mypos_add_column_if_missing('clientes', 'nombre', "nombre VARCHAR(190) NULL AFTER rut");
CALL mypos_add_column_if_missing('clientes', 'giro', "giro VARCHAR(190) NULL AFTER razon_social");
CALL mypos_add_column_if_missing('clientes', 'permite_credito', "permite_credito TINYINT(1) NOT NULL DEFAULT 0 AFTER credito_habilitado");
CALL mypos_add_column_if_missing('clientes', 'observacion', "observacion VARCHAR(255) NULL AFTER limite_credito");

UPDATE clientes
SET nombre = COALESCE(nombre, nombre_fantasia, razon_social),
    permite_credito = CASE WHEN permite_credito = 1 OR credito_habilitado = 1 THEN 1 ELSE 0 END
WHERE nombre IS NULL OR permite_credito <> credito_habilitado;

CALL mypos_add_column_if_missing('proveedores', 'nombre', "nombre VARCHAR(190) NULL AFTER rut");
CALL mypos_add_column_if_missing('proveedores', 'giro', "giro VARCHAR(190) NULL AFTER razon_social");
CALL mypos_add_column_if_missing('proveedores', 'observacion', "observacion VARCHAR(255) NULL AFTER ciudad");
CALL mypos_add_column_if_missing('proveedores', 'deleted_at', "deleted_at DATETIME NULL AFTER updated_at");

UPDATE proveedores
SET nombre = COALESCE(nombre, nombre_fantasia, razon_social)
WHERE nombre IS NULL;

CREATE TABLE IF NOT EXISTS creditos_clientes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    cliente_id BIGINT UNSIGNED NOT NULL,
    venta_id BIGINT UNSIGNED NOT NULL,
    monto_original BIGINT NOT NULL,
    monto_pagado BIGINT NOT NULL DEFAULT 0,
    saldo_pendiente BIGINT NOT NULL,
    estado ENUM('PENDIENTE','PARCIAL','PAGADO','ANULADO') NOT NULL DEFAULT 'PENDIENTE',
    fecha_credito DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_vencimiento DATE NULL,
    observacion VARCHAR(255) NULL,
    created_by_usuario_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_creditos_clientes_venta (venta_id),
    KEY idx_creditos_clientes_empresa_estado (empresa_id, estado),
    KEY idx_creditos_clientes_cliente_estado (empresa_id, cliente_id, estado),
    KEY idx_creditos_clientes_sucursal_fecha (empresa_id, sucursal_id, fecha_credito),
    CONSTRAINT fk_creditos_clientes_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_creditos_clientes_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
    CONSTRAINT fk_creditos_clientes_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_creditos_clientes_venta FOREIGN KEY (venta_id) REFERENCES ventas(id),
    CONSTRAINT fk_creditos_clientes_created_by FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS creditos_pagos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    credito_cliente_id BIGINT UNSIGNED NOT NULL,
    cliente_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    caja_apertura_id BIGINT UNSIGNED NULL,
    metodo_pago_id BIGINT UNSIGNED NOT NULL,
    monto BIGINT NOT NULL,
    fecha_pago DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    observacion VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_creditos_pagos_credito (credito_cliente_id),
    KEY idx_creditos_pagos_cliente_fecha (empresa_id, cliente_id, fecha_pago),
    KEY idx_creditos_pagos_caja (caja_apertura_id),
    CONSTRAINT fk_creditos_pagos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_creditos_pagos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
    CONSTRAINT fk_creditos_pagos_credito FOREIGN KEY (credito_cliente_id) REFERENCES creditos_clientes(id),
    CONSTRAINT fk_creditos_pagos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_creditos_pagos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_creditos_pagos_caja_apertura FOREIGN KEY (caja_apertura_id) REFERENCES caja_aperturas(id),
    CONSTRAINT fk_creditos_pagos_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL mypos_add_column_if_missing('ventas', 'condicion_pago', "condicion_pago ENUM('CONTADO','CREDITO') NOT NULL DEFAULT 'CONTADO' AFTER tipo_venta");
CALL mypos_add_column_if_missing('ventas', 'credito_cliente_id', "credito_cliente_id BIGINT UNSIGNED NULL AFTER condicion_pago");

CALL mypos_add_index_if_missing('clientes', 'idx_clientes_busqueda', 'CREATE INDEX idx_clientes_busqueda ON clientes (empresa_id, nombre, rut, telefono, email)');
CALL mypos_add_index_if_missing('clientes', 'idx_clientes_credito', 'CREATE INDEX idx_clientes_credito ON clientes (empresa_id, permite_credito, activo)');
CALL mypos_add_index_if_missing('proveedores', 'idx_proveedores_busqueda', 'CREATE INDEX idx_proveedores_busqueda ON proveedores (empresa_id, nombre, rut, telefono, email)');
CALL mypos_add_index_if_missing('ventas', 'idx_ventas_credito', 'CREATE INDEX idx_ventas_credito ON ventas (empresa_id, condicion_pago, credito_cliente_id)');

CALL mypos_add_fk_if_missing(
    'ventas',
    'fk_ventas_credito_cliente',
    'ALTER TABLE ventas ADD CONSTRAINT fk_ventas_credito_cliente FOREIGN KEY (credito_cliente_id) REFERENCES creditos_clientes(id)'
);

DROP PROCEDURE IF EXISTS mypos_add_column_if_missing;
DROP PROCEDURE IF EXISTS mypos_add_index_if_missing;
DROP PROCEDURE IF EXISTS mypos_add_fk_if_missing;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('014_clientes_proveedores_creditos');
