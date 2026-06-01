CREATE TABLE IF NOT EXISTS stock_ubicacion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    ubicacion_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    reservado DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    stock_minimo DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    stock_maximo DECIMAL(14,3) NULL,
    punto_reorden DECIMAL(14,3) NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_ubicacion_producto (empresa_id, ubicacion_id, producto_id),
    KEY idx_stock_ubicacion_producto (empresa_id, producto_id),
    CONSTRAINT fk_stock_ubicacion_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_stock_ubicacion_ubicacion FOREIGN KEY (ubicacion_id) REFERENCES ubicaciones_stock(id),
    CONSTRAINT fk_stock_ubicacion_producto FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_traslados (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    ubicacion_origen_id BIGINT UNSIGNED NOT NULL,
    ubicacion_destino_id BIGINT UNSIGNED NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL,
    estado ENUM('APLICADO','CANCELADO') NOT NULL DEFAULT 'APLICADO',
    usuario_id BIGINT UNSIGNED NULL,
    observacion VARCHAR(255) NULL,
    movimiento_salida_id BIGINT UNSIGNED NULL,
    movimiento_entrada_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stock_traslados_empresa_fecha (empresa_id, created_at),
    KEY idx_stock_traslados_producto (empresa_id, producto_id),
    CONSTRAINT fk_stock_traslados_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_stock_traslados_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_stock_traslados_origen FOREIGN KEY (ubicacion_origen_id) REFERENCES ubicaciones_stock(id),
    CONSTRAINT fk_stock_traslados_destino FOREIGN KEY (ubicacion_destino_id) REFERENCES ubicaciones_stock(id),
    CONSTRAINT fk_stock_traslados_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stock_movimientos
    ADD COLUMN IF NOT EXISTS ubicacion_id BIGINT UNSIGNED NULL AFTER sucursal_id,
    ADD COLUMN IF NOT EXISTS ubicacion_origen_id BIGINT UNSIGNED NULL AFTER ubicacion_id,
    ADD COLUMN IF NOT EXISTS ubicacion_destino_id BIGINT UNSIGNED NULL AFTER ubicacion_origen_id;

INSERT IGNORE INTO stock_ubicacion (
    empresa_id, ubicacion_id, producto_id, cantidad, reservado, stock_minimo
)
SELECT ss.empresa_id,
       u.id,
       ss.producto_id,
       ss.cantidad,
       COALESCE(ss.reservado, 0.000),
       ss.stock_minimo
FROM stock_sucursal ss
INNER JOIN ubicaciones_stock u
    ON u.empresa_id = ss.empresa_id
   AND u.sucursal_id = ss.sucursal_id
   AND u.tipo = 'SUCURSAL_VENTA'
   AND u.activo = 1;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('030_stock_por_ubicacion');
