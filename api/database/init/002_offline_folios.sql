CREATE TABLE IF NOT EXISTS dispositivos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NULL,
    device_uuid CHAR(36) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    tipo ENUM('ANDROID','WEB_POS','CAJA','TABLET','OTRO') NOT NULL DEFAULT 'ANDROID',
    ultimo_sync_at DATETIME NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dispositivos_empresa_uuid (empresa_id, device_uuid),
    KEY idx_dispositivos_empresa_activo (empresa_id, activo),
    KEY idx_dispositivos_sucursal (sucursal_id),
    KEY idx_dispositivos_usuario (usuario_id),
    CONSTRAINT fk_dispositivos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_dispositivos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_dispositivos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS caf_archivos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    tipo_documento ENUM('BOLETA','FACTURA','GUIA_DESPACHO','NOTA_CREDITO','NOTA_DEBITO') NOT NULL,
    folio_desde BIGINT UNSIGNED NOT NULL,
    folio_hasta BIGINT UNSIGNED NOT NULL,
    fecha_autorizacion DATE NULL,
    fecha_vencimiento DATE NULL,
    caf_xml MEDIUMTEXT NULL,
    estado ENUM('ACTIVO','VENCIDO','AGOTADO','ANULADO') NOT NULL DEFAULT 'ACTIVO',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_caf_archivos_empresa_tipo_rango (empresa_id, tipo_documento, folio_desde, folio_hasta),
    KEY idx_caf_archivos_empresa_tipo_estado (empresa_id, tipo_documento, estado),
    KEY idx_caf_archivos_fecha_vencimiento (fecha_vencimiento),
    CONSTRAINT fk_caf_archivos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT chk_caf_archivos_rango CHECK (folio_desde <= folio_hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS folios_asignaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    dispositivo_id BIGINT UNSIGNED NULL,
    caf_id BIGINT UNSIGNED NOT NULL,
    tipo_documento ENUM('BOLETA','FACTURA','GUIA_DESPACHO','NOTA_CREDITO','NOTA_DEBITO') NOT NULL,
    folio_desde BIGINT UNSIGNED NOT NULL,
    folio_hasta BIGINT UNSIGNED NOT NULL,
    folio_actual BIGINT UNSIGNED NOT NULL,
    estado ENUM('ACTIVA','AGOTADA','SUSPENDIDA','ANULADA') NOT NULL DEFAULT 'ACTIVA',
    asignado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    agotado_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_folios_asignaciones_empresa_tipo_rango (empresa_id, tipo_documento, folio_desde, folio_hasta),
    KEY idx_folios_asignaciones_sucursal_estado (empresa_id, sucursal_id, tipo_documento, estado),
    KEY idx_folios_asignaciones_dispositivo (dispositivo_id),
    KEY idx_folios_asignaciones_caf (caf_id),
    CONSTRAINT fk_folios_asignaciones_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_folios_asignaciones_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_folios_asignaciones_dispositivo FOREIGN KEY (dispositivo_id) REFERENCES dispositivos (id),
    CONSTRAINT fk_folios_asignaciones_caf FOREIGN KEY (caf_id) REFERENCES caf_archivos (id),
    CONSTRAINT chk_folios_asignaciones_rango CHECK (folio_desde <= folio_hasta),
    CONSTRAINT chk_folios_asignaciones_actual CHECK (folio_actual BETWEEN folio_desde AND folio_hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS folios_consumidos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    dispositivo_id BIGINT UNSIGNED NULL,
    asignacion_id BIGINT UNSIGNED NOT NULL,
    venta_id BIGINT UNSIGNED NULL,
    documento_emitido_id BIGINT UNSIGNED NULL,
    tipo_documento ENUM('BOLETA','FACTURA','GUIA_DESPACHO','NOTA_CREDITO','NOTA_DEBITO') NOT NULL,
    folio BIGINT UNSIGNED NOT NULL,
    estado ENUM('RESERVADO','USADO_LOCAL','SINCRONIZADO','ENVIADO_SII','ACEPTADO_SII','RECHAZADO_SII','ANULADO') NOT NULL DEFAULT 'RESERVADO',
    sync_status ENUM('SYNCED','PENDING','ERROR') NOT NULL DEFAULT 'SYNCED',
    fecha_consumo DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    synced_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_folios_consumidos_empresa_tipo_folio (empresa_id, tipo_documento, folio),
    KEY idx_folios_consumidos_estado (estado),
    KEY idx_folios_consumidos_sync_status (sync_status),
    KEY idx_folios_consumidos_sucursal_fecha (sucursal_id, fecha_consumo),
    KEY idx_folios_consumidos_dispositivo (dispositivo_id),
    KEY idx_folios_consumidos_asignacion (asignacion_id),
    KEY idx_folios_consumidos_venta (venta_id),
    KEY idx_folios_consumidos_documento (documento_emitido_id),
    CONSTRAINT fk_folios_consumidos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_folios_consumidos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_folios_consumidos_dispositivo FOREIGN KEY (dispositivo_id) REFERENCES dispositivos (id),
    CONSTRAINT fk_folios_consumidos_asignacion FOREIGN KEY (asignacion_id) REFERENCES folios_asignaciones (id),
    CONSTRAINT fk_folios_consumidos_venta FOREIGN KEY (venta_id) REFERENCES ventas (id),
    CONSTRAINT fk_folios_consumidos_documento FOREIGN KEY (documento_emitido_id) REFERENCES documentos_emitidos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NULL,
    dispositivo_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NULL,
    entidad VARCHAR(80) NOT NULL,
    entidad_uuid CHAR(36) NULL,
    entidad_id BIGINT UNSIGNED NULL,
    operacion ENUM('CREATE','UPDATE','DELETE','SYNC_IN','SYNC_OUT','CONFLICT') NOT NULL,
    estado ENUM('PENDING','PROCESSED','ERROR','CONFLICT') NOT NULL DEFAULT 'PENDING',
    payload JSON NULL,
    error_mensaje TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_sync_eventos_empresa_estado_fecha (empresa_id, estado, created_at),
    KEY idx_sync_eventos_dispositivo_estado (dispositivo_id, estado),
    KEY idx_sync_eventos_entidad_uuid (entidad, entidad_uuid),
    KEY idx_sync_eventos_sucursal (sucursal_id),
    KEY idx_sync_eventos_usuario (usuario_id),
    CONSTRAINT fk_sync_eventos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_sync_eventos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_sync_eventos_dispositivo FOREIGN KEY (dispositivo_id) REFERENCES dispositivos (id),
    CONSTRAINT fk_sync_eventos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE documentos_emitidos
    ADD COLUMN IF NOT EXISTS caf_id BIGINT UNSIGNED NULL AFTER venta_id,
    ADD COLUMN IF NOT EXISTS folio_asignacion_id BIGINT UNSIGNED NULL AFTER caf_id,
    ADD COLUMN IF NOT EXISTS folio_consumido_id BIGINT UNSIGNED NULL AFTER folio_asignacion_id,
    ADD COLUMN IF NOT EXISTS dispositivo_id BIGINT UNSIGNED NULL AFTER folio_consumido_id,
    ADD COLUMN IF NOT EXISTS emision_origen ENUM('ONLINE','OFFLINE') NOT NULL DEFAULT 'ONLINE' AFTER payload_json,
    ADD COLUMN IF NOT EXISTS estado_sii ENUM('NO_ENVIADO','PENDIENTE','ACEPTADO','RECHAZADO') NOT NULL DEFAULT 'NO_ENVIADO' AFTER emision_origen,
    ADD COLUMN IF NOT EXISTS sync_status ENUM('SYNCED','PENDING','ERROR') NOT NULL DEFAULT 'SYNCED' AFTER estado_sii,
    ADD COLUMN IF NOT EXISTS synced_at DATETIME NULL AFTER sync_status;

ALTER TABLE ventas
    ADD COLUMN IF NOT EXISTS uuid CHAR(36) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS dispositivo_id BIGINT UNSIGNED NULL AFTER apertura_id,
    ADD COLUMN IF NOT EXISTS sync_status ENUM('SYNCED','PENDING','ERROR') NOT NULL DEFAULT 'SYNCED' AFTER fecha_venta,
    ADD COLUMN IF NOT EXISTS synced_at DATETIME NULL AFTER sync_status,
    ADD COLUMN IF NOT EXISTS version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER synced_at,
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER version;

ALTER TABLE venta_detalles
    ADD COLUMN IF NOT EXISTS uuid CHAR(36) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS sync_status ENUM('SYNCED','PENDING','ERROR') NOT NULL DEFAULT 'SYNCED' AFTER created_at;

ALTER TABLE venta_pagos
    ADD COLUMN IF NOT EXISTS uuid CHAR(36) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS sync_status ENUM('SYNCED','PENDING','ERROR') NOT NULL DEFAULT 'SYNCED' AFTER created_at;

ALTER TABLE stock_movimientos
    ADD COLUMN IF NOT EXISTS uuid CHAR(36) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS dispositivo_id BIGINT UNSIGNED NULL AFTER sucursal_id,
    ADD COLUMN IF NOT EXISTS sync_status ENUM('SYNCED','PENDING','ERROR') NOT NULL DEFAULT 'SYNCED' AFTER created_at,
    ADD COLUMN IF NOT EXISTS synced_at DATETIME NULL AFTER sync_status;

ALTER TABLE clientes
    ADD COLUMN IF NOT EXISTS uuid CHAR(36) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS sync_status ENUM('SYNCED','PENDING','ERROR') NOT NULL DEFAULT 'SYNCED' AFTER activo,
    ADD COLUMN IF NOT EXISTS synced_at DATETIME NULL AFTER sync_status,
    ADD COLUMN IF NOT EXISTS version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER synced_at,
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER version;

ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS uuid CHAR(36) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS sync_status ENUM('SYNCED','PENDING','ERROR') NOT NULL DEFAULT 'SYNCED' AFTER activo,
    ADD COLUMN IF NOT EXISTS synced_at DATETIME NULL AFTER sync_status,
    ADD COLUMN IF NOT EXISTS version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER synced_at,
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER version;

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

CREATE OR REPLACE PROCEDURE mypos_add_fk_if_missing(
    IN p_constraint_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE()
          AND constraint_name = p_constraint_name
    ) THEN
        SET @ddl = p_ddl;
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

DELIMITER ;

CALL mypos_add_index_if_missing('documentos_emitidos', 'idx_documentos_emitidos_estado_sii', 'ALTER TABLE documentos_emitidos ADD INDEX idx_documentos_emitidos_estado_sii (estado_sii)');
CALL mypos_add_index_if_missing('documentos_emitidos', 'idx_documentos_emitidos_sync_status', 'ALTER TABLE documentos_emitidos ADD INDEX idx_documentos_emitidos_sync_status (sync_status)');
CALL mypos_add_index_if_missing('documentos_emitidos', 'idx_documentos_emitidos_emision_origen', 'ALTER TABLE documentos_emitidos ADD INDEX idx_documentos_emitidos_emision_origen (emision_origen)');
CALL mypos_add_index_if_missing('documentos_emitidos', 'idx_documentos_emitidos_caf', 'ALTER TABLE documentos_emitidos ADD INDEX idx_documentos_emitidos_caf (caf_id)');
CALL mypos_add_index_if_missing('documentos_emitidos', 'idx_documentos_emitidos_folio_asignacion', 'ALTER TABLE documentos_emitidos ADD INDEX idx_documentos_emitidos_folio_asignacion (folio_asignacion_id)');
CALL mypos_add_index_if_missing('documentos_emitidos', 'idx_documentos_emitidos_folio_consumido', 'ALTER TABLE documentos_emitidos ADD INDEX idx_documentos_emitidos_folio_consumido (folio_consumido_id)');
CALL mypos_add_index_if_missing('documentos_emitidos', 'idx_documentos_emitidos_dispositivo', 'ALTER TABLE documentos_emitidos ADD INDEX idx_documentos_emitidos_dispositivo (dispositivo_id)');

CALL mypos_add_index_if_missing('ventas', 'uq_ventas_empresa_uuid', 'ALTER TABLE ventas ADD UNIQUE INDEX uq_ventas_empresa_uuid (empresa_id, uuid)');
CALL mypos_add_index_if_missing('ventas', 'idx_ventas_dispositivo', 'ALTER TABLE ventas ADD INDEX idx_ventas_dispositivo (dispositivo_id)');
CALL mypos_add_index_if_missing('ventas', 'idx_ventas_sync_status', 'ALTER TABLE ventas ADD INDEX idx_ventas_sync_status (sync_status)');

CALL mypos_add_index_if_missing('venta_detalles', 'idx_venta_detalles_uuid', 'ALTER TABLE venta_detalles ADD INDEX idx_venta_detalles_uuid (uuid)');
CALL mypos_add_index_if_missing('venta_detalles', 'idx_venta_detalles_sync_status', 'ALTER TABLE venta_detalles ADD INDEX idx_venta_detalles_sync_status (sync_status)');

CALL mypos_add_index_if_missing('venta_pagos', 'idx_venta_pagos_uuid', 'ALTER TABLE venta_pagos ADD INDEX idx_venta_pagos_uuid (uuid)');
CALL mypos_add_index_if_missing('venta_pagos', 'idx_venta_pagos_sync_status', 'ALTER TABLE venta_pagos ADD INDEX idx_venta_pagos_sync_status (sync_status)');

CALL mypos_add_index_if_missing('stock_movimientos', 'idx_stock_movimientos_uuid', 'ALTER TABLE stock_movimientos ADD INDEX idx_stock_movimientos_uuid (uuid)');
CALL mypos_add_index_if_missing('stock_movimientos', 'idx_stock_movimientos_dispositivo', 'ALTER TABLE stock_movimientos ADD INDEX idx_stock_movimientos_dispositivo (dispositivo_id)');
CALL mypos_add_index_if_missing('stock_movimientos', 'idx_stock_movimientos_sync_status', 'ALTER TABLE stock_movimientos ADD INDEX idx_stock_movimientos_sync_status (sync_status)');

CALL mypos_add_index_if_missing('clientes', 'uq_clientes_empresa_uuid', 'ALTER TABLE clientes ADD UNIQUE INDEX uq_clientes_empresa_uuid (empresa_id, uuid)');
CALL mypos_add_index_if_missing('clientes', 'idx_clientes_sync_status', 'ALTER TABLE clientes ADD INDEX idx_clientes_sync_status (sync_status)');

CALL mypos_add_index_if_missing('productos', 'uq_productos_empresa_uuid', 'ALTER TABLE productos ADD UNIQUE INDEX uq_productos_empresa_uuid (empresa_id, uuid)');
CALL mypos_add_index_if_missing('productos', 'idx_productos_sync_status', 'ALTER TABLE productos ADD INDEX idx_productos_sync_status (sync_status)');

CALL mypos_add_fk_if_missing('fk_documentos_emitidos_caf', 'ALTER TABLE documentos_emitidos ADD CONSTRAINT fk_documentos_emitidos_caf FOREIGN KEY (caf_id) REFERENCES caf_archivos (id)');
CALL mypos_add_fk_if_missing('fk_documentos_emitidos_folio_asignacion', 'ALTER TABLE documentos_emitidos ADD CONSTRAINT fk_documentos_emitidos_folio_asignacion FOREIGN KEY (folio_asignacion_id) REFERENCES folios_asignaciones (id)');
CALL mypos_add_fk_if_missing('fk_documentos_emitidos_folio_consumido', 'ALTER TABLE documentos_emitidos ADD CONSTRAINT fk_documentos_emitidos_folio_consumido FOREIGN KEY (folio_consumido_id) REFERENCES folios_consumidos (id)');
CALL mypos_add_fk_if_missing('fk_documentos_emitidos_dispositivo', 'ALTER TABLE documentos_emitidos ADD CONSTRAINT fk_documentos_emitidos_dispositivo FOREIGN KEY (dispositivo_id) REFERENCES dispositivos (id)');
CALL mypos_add_fk_if_missing('fk_ventas_dispositivo', 'ALTER TABLE ventas ADD CONSTRAINT fk_ventas_dispositivo FOREIGN KEY (dispositivo_id) REFERENCES dispositivos (id)');
CALL mypos_add_fk_if_missing('fk_stock_movimientos_dispositivo', 'ALTER TABLE stock_movimientos ADD CONSTRAINT fk_stock_movimientos_dispositivo FOREIGN KEY (dispositivo_id) REFERENCES dispositivos (id)');

DROP PROCEDURE IF EXISTS mypos_add_index_if_missing;
DROP PROCEDURE IF EXISTS mypos_add_fk_if_missing;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('002_offline_folios');
