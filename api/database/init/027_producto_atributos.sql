CREATE TABLE IF NOT EXISTS producto_atributos_definicion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(80) NOT NULL,
    nombre VARCHAR(160) NOT NULL,
    descripcion VARCHAR(255) NULL,
    tipo_dato ENUM('TEXTO','NUMERO','DECIMAL','FECHA','BOOLEANO','OPCION','MULTIOPCION') NOT NULL,
    requerido TINYINT(1) NOT NULL DEFAULT 0,
    filtrable TINYINT(1) NOT NULL DEFAULT 0,
    visible_en_listado TINYINT(1) NOT NULL DEFAULT 0,
    visible_en_pos TINYINT(1) NOT NULL DEFAULT 0,
    orden INT UNSIGNED NOT NULL DEFAULT 1,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_producto_atributos_empresa_codigo (empresa_id, codigo),
    KEY idx_producto_atributos_empresa_activo (empresa_id, activo, orden),
    CONSTRAINT fk_producto_atributos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS producto_atributos_opciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    atributo_id BIGINT UNSIGNED NOT NULL,
    valor VARCHAR(120) NOT NULL,
    etiqueta VARCHAR(160) NOT NULL,
    orden INT UNSIGNED NOT NULL DEFAULT 1,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_producto_atributo_opciones_valor (atributo_id, valor),
    KEY idx_producto_atributo_opciones_empresa (empresa_id, atributo_id, activo),
    CONSTRAINT fk_producto_atributo_opciones_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_producto_atributo_opciones_atributo FOREIGN KEY (atributo_id) REFERENCES producto_atributos_definicion(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS producto_atributos_valores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    atributo_id BIGINT UNSIGNED NOT NULL,
    valor_texto TEXT NULL,
    valor_numero BIGINT NULL,
    valor_decimal DECIMAL(14,4) NULL,
    valor_fecha DATE NULL,
    valor_booleano TINYINT(1) NULL,
    valor_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_producto_atributo_valor (empresa_id, producto_id, atributo_id),
    KEY idx_producto_atributo_valores_producto (empresa_id, producto_id),
    KEY idx_producto_atributo_valores_atributo (empresa_id, atributo_id),
    CONSTRAINT fk_producto_atributo_valores_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_producto_atributo_valores_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_producto_atributo_valores_atributo FOREIGN KEY (atributo_id) REFERENCES producto_atributos_definicion(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('027_producto_atributos');
