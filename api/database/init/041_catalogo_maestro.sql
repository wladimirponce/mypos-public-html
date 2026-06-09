CREATE TABLE IF NOT EXISTS catalogos_maestros (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(80) NOT NULL,
    nombre VARCHAR(160) NOT NULL,
    perfil VARCHAR(80) NOT NULL,
    version VARCHAR(40) NOT NULL DEFAULT '1.0',
    estado ENUM('BORRADOR','ACTIVO','INACTIVO') NOT NULL DEFAULT 'BORRADOR',
    descripcion VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalogos_maestros_codigo (codigo),
    KEY idx_catalogos_maestros_perfil_estado (perfil, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogo_maestro_productos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    catalogo_id BIGINT UNSIGNED NOT NULL,
    producto_externo_id VARCHAR(120) NOT NULL,
    codigo_referencia VARCHAR(120) NULL,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT NULL,
    unidad_medida VARCHAR(50) NULL,
    categoria VARCHAR(190) NULL,
    familia VARCHAR(190) NULL,
    laboratorio VARCHAR(190) NULL,
    principio_activo VARCHAR(500) NULL,
    dosis VARCHAR(190) NULL,
    titular VARCHAR(190) NULL,
    presentacion VARCHAR(190) NULL,
    bioequivalente VARCHAR(20) NULL,
    cenabast TINYINT(1) NOT NULL DEFAULT 0,
    estado_revision ENUM('VALIDO','OBSERVADO','PENDIENTE_REVISION','INACTIVO') NOT NULL DEFAULT 'PENDIENTE_REVISION',
    metadata_json LONGTEXT NULL,
    source_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalogo_maestro_producto_externo (catalogo_id, producto_externo_id),
    KEY idx_catalogo_maestro_producto_nombre (catalogo_id, nombre),
    KEY idx_catalogo_maestro_producto_principio (catalogo_id, principio_activo),
    KEY idx_catalogo_maestro_producto_estado (catalogo_id, estado_revision),
    CONSTRAINT fk_catalogo_maestro_producto_catalogo FOREIGN KEY (catalogo_id) REFERENCES catalogos_maestros(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogo_maestro_codigos_barra (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    catalogo_id BIGINT UNSIGNED NOT NULL,
    catalogo_producto_id BIGINT UNSIGNED NOT NULL,
    codigo_barra VARCHAR(120) NOT NULL,
    tipo_codigo VARCHAR(30) NOT NULL DEFAULT 'OTRO',
    principal TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    descripcion VARCHAR(255) NULL,
    source_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalogo_maestro_codigo_barra (catalogo_id, codigo_barra),
    KEY idx_catalogo_maestro_codigo_producto (catalogo_producto_id, activo),
    CONSTRAINT fk_catalogo_maestro_codigo_catalogo FOREIGN KEY (catalogo_id) REFERENCES catalogos_maestros(id),
    CONSTRAINT fk_catalogo_maestro_codigo_producto FOREIGN KEY (catalogo_producto_id) REFERENCES catalogo_maestro_productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogo_maestro_aliases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    catalogo_id BIGINT UNSIGNED NOT NULL,
    catalogo_producto_id BIGINT UNSIGNED NOT NULL,
    tipo ENUM('NOMBRE','CODIGO_EXTERNO','CODIGO_BARRA') NOT NULL,
    valor VARCHAR(500) NOT NULL,
    origen VARCHAR(160) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalogo_maestro_alias (catalogo_id, tipo, valor),
    KEY idx_catalogo_maestro_alias_producto (catalogo_producto_id, activo),
    CONSTRAINT fk_catalogo_maestro_alias_catalogo FOREIGN KEY (catalogo_id) REFERENCES catalogos_maestros(id),
    CONSTRAINT fk_catalogo_maestro_alias_producto FOREIGN KEY (catalogo_producto_id) REFERENCES catalogo_maestro_productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogo_maestro_importaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    catalogo_id BIGINT UNSIGNED NOT NULL,
    origen VARCHAR(190) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    estado ENUM('INICIADA','APLICADA','CON_ERRORES') NOT NULL DEFAULT 'INICIADA',
    productos_procesados INT UNSIGNED NOT NULL DEFAULT 0,
    productos_creados INT UNSIGNED NOT NULL DEFAULT 0,
    productos_actualizados INT UNSIGNED NOT NULL DEFAULT 0,
    codigos_procesados INT UNSIGNED NOT NULL DEFAULT 0,
    codigos_creados INT UNSIGNED NOT NULL DEFAULT 0,
    codigos_actualizados INT UNSIGNED NOT NULL DEFAULT 0,
    errores INT UNSIGNED NOT NULL DEFAULT 0,
    metadata_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_catalogo_maestro_importacion_catalogo (catalogo_id, created_at),
    KEY idx_catalogo_maestro_importacion_hash (source_hash),
    CONSTRAINT fk_catalogo_maestro_importacion_catalogo FOREIGN KEY (catalogo_id) REFERENCES catalogos_maestros(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('041_catalogo_maestro');
