CREATE TABLE IF NOT EXISTS catalogo_maestro_productos_empresa (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    catalogo_id BIGINT UNSIGNED NOT NULL,
    catalogo_producto_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalogo_maestro_producto_empresa (empresa_id, catalogo_producto_id),
    UNIQUE KEY uq_catalogo_maestro_producto_privado (empresa_id, producto_id),
    KEY idx_catalogo_maestro_vinculo_catalogo (catalogo_id, catalogo_producto_id),
    CONSTRAINT fk_catalogo_maestro_vinculo_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_catalogo_maestro_vinculo_catalogo FOREIGN KEY (catalogo_id) REFERENCES catalogos_maestros(id),
    CONSTRAINT fk_catalogo_maestro_vinculo_maestro FOREIGN KEY (catalogo_producto_id) REFERENCES catalogo_maestro_productos(id),
    CONSTRAINT fk_catalogo_maestro_vinculo_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_catalogo_maestro_vinculo_usuario FOREIGN KEY (created_by) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('042_catalogo_maestro_vinculos');
