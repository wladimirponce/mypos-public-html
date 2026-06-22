-- Tablas para importar/actualizar el maestro de productos por CSV/Excel
CREATE TABLE IF NOT EXISTS importaciones_maestro (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id      INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,
    estado          ENUM('PENDIENTE','VALIDADO','APLICADO') NOT NULL DEFAULT 'PENDIENTE',
    total_filas     INT UNSIGNED NOT NULL DEFAULT 0,
    filas_ok        INT UNSIGNED NOT NULL DEFAULT 0,
    filas_nuevas    INT UNSIGNED NOT NULL DEFAULT 0,
    filas_actualizar INT UNSIGNED NOT NULL DEFAULT 0,
    filas_error     INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at      TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_empresa_estado (empresa_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS importaciones_maestro_items (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id          INT UNSIGNED NOT NULL,
    importacion_id      INT UNSIGNED NOT NULL,
    linea               INT UNSIGNED NOT NULL,
    codigo              VARCHAR(100) NOT NULL,
    nombre              VARCHAR(255) NOT NULL,
    precio_costo        INT UNSIGNED NOT NULL DEFAULT 0,
    precio_venta        INT UNSIGNED NOT NULL DEFAULT 0,
    rubro               VARCHAR(100) NULL,
    rubro_id            INT UNSIGNED NULL,
    stock_minimo        DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    activo              TINYINT(1) NOT NULL DEFAULT 1,
    producto_id         INT UNSIGNED NULL,
    accion              ENUM('CREAR','ACTUALIZAR') NULL,
    estado              ENUM('OK','ERROR') NULL,
    errores_json        TEXT NULL,
    raw_json            TEXT NOT NULL,
    INDEX idx_importacion (empresa_id, importacion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
