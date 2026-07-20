-- Capa analitica ligera. Se alimenta por cron y evita recalcular historicos
-- pesados sobre las tablas operacionales durante el uso del POS.

CREATE TABLE IF NOT EXISTS analytics_ventas_diarias (
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    hora TINYINT UNSIGNED NOT NULL,
    ventas INT UNSIGNED NOT NULL DEFAULT 0,
    anulaciones INT UNSIGNED NOT NULL DEFAULT 0,
    unidades DECIMAL(16,3) NOT NULL DEFAULT 0,
    total BIGINT NOT NULL DEFAULT 0,
    descuentos BIGINT NOT NULL DEFAULT 0,
    margen BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (empresa_id, sucursal_id, fecha, hora),
    KEY idx_analytics_ventas_fecha (empresa_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytics_producto_sucursal_diario (
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    unidades_vendidas DECIMAL(16,3) NOT NULL DEFAULT 0,
    venta_neta BIGINT NOT NULL DEFAULT 0,
    margen BIGINT NOT NULL DEFAULT 0,
    stock_cierre DECIMAL(16,3) NOT NULL DEFAULT 0,
    reservado_cierre DECIMAL(16,3) NOT NULL DEFAULT 0,
    valor_stock BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (empresa_id, sucursal_id, producto_id, fecha),
    KEY idx_analytics_producto_fecha (empresa_id, producto_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytics_caja_diaria (
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    cierres INT UNSIGNED NOT NULL DEFAULT 0,
    cierres_con_diferencia INT UNSIGNED NOT NULL DEFAULT 0,
    diferencia_absoluta BIGINT NOT NULL DEFAULT 0,
    diferencia_neta BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (empresa_id, sucursal_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytics_proveedor_desempeno (
    empresa_id BIGINT UNSIGNED NOT NULL,
    proveedor_id BIGINT UNSIGNED NOT NULL,
    fecha_calculo DATE NOT NULL,
    ordenes_recibidas INT UNSIGNED NOT NULL DEFAULT 0,
    entregas_atrasadas INT UNSIGNED NOT NULL DEFAULT 0,
    plazo_real_promedio DECIMAL(10,2) NULL,
    cumplimiento_pct DECIMAL(6,2) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (empresa_id, proveedor_id, fecha_calculo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('094_analytics_operativa');
