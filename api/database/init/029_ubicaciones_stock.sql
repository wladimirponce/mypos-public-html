CREATE TABLE IF NOT EXISTS ubicaciones_stock (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NULL,
    codigo VARCHAR(80) NOT NULL,
    nombre VARCHAR(160) NOT NULL,
    tipo ENUM('SUCURSAL_VENTA','BODEGA','TRANSITO','MERMA','VIRTUAL') NOT NULL DEFAULT 'BODEGA',
    descripcion VARCHAR(255) NULL,
    direccion VARCHAR(255) NULL,
    principal TINYINT(1) NOT NULL DEFAULT 0,
    permite_venta TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ubicaciones_stock_empresa_codigo (empresa_id, codigo),
    KEY idx_ubicaciones_stock_empresa_activo (empresa_id, activo, tipo),
    KEY idx_ubicaciones_stock_sucursal (empresa_id, sucursal_id, activo),
    CONSTRAINT fk_ubicaciones_stock_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_ubicaciones_stock_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ubicaciones_stock (
    empresa_id, sucursal_id, codigo, nombre, tipo, descripcion,
    principal, permite_venta, activo
)
SELECT s.empresa_id,
       s.id,
       CONCAT('SUC-', s.codigo),
       s.nombre,
       'SUCURSAL_VENTA',
       'Ubicacion inicial creada desde sucursal',
       1,
       1,
       s.activo
FROM sucursales s;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('029_ubicaciones_stock');
