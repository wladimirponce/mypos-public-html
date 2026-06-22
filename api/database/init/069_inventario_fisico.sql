-- Módulo Inventario Físico — toma de inventario y ajuste masivo
CREATE TABLE IF NOT EXISTS inventarios_fisicos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    sucursal_id     INT UNSIGNED NOT NULL,
    nombre          VARCHAR(120) NOT NULL,
    estado          ENUM('BORRADOR','APLICADO') NOT NULL DEFAULT 'BORRADOR',
    usuario_id      INT UNSIGNED NULL,
    total_items     INT UNSIGNED NOT NULL DEFAULT 0,
    items_contados  INT UNSIGNED NOT NULL DEFAULT 0,
    items_con_dif   INT UNSIGNED NOT NULL DEFAULT 0,
    notas           TEXT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    applied_at      TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_empresa_sucursal (empresa_id, sucursal_id),
    INDEX idx_empresa_estado (empresa_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventarios_fisicos_items (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventario_id         INT UNSIGNED NOT NULL,
    empresa_id            INT UNSIGNED NOT NULL,
    producto_id           INT UNSIGNED NOT NULL,
    nombre_producto       VARCHAR(255) NOT NULL,
    codigo_producto       VARCHAR(100) NOT NULL DEFAULT '',
    cantidad_sistema      DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    cantidad_contada      DECIMAL(10,3) NULL DEFAULT NULL,
    ajuste_movimiento_id  INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_inv_producto (inventario_id, producto_id),
    INDEX idx_empresa_inventario (empresa_id, inventario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('069_inventario_fisico');
