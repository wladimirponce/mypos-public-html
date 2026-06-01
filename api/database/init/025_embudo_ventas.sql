CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schema_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comunicaciones_ventas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    canal VARCHAR(40) NOT NULL DEFAULT 'formulario',
    area VARCHAR(40) NOT NULL DEFAULT 'ventas',
    nombre VARCHAR(160) NULL,
    email VARCHAR(180) NULL,
    telefono VARCHAR(60) NULL,
    empresa VARCHAR(180) NULL,
    plan_interes VARCHAR(80) NULL,
    motivo VARCHAR(120) NULL,
    mensaje TEXT NULL,
    origen VARCHAR(160) NULL,
    estado VARCHAR(40) NOT NULL DEFAULT 'nuevo',
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_comunicaciones_ventas_estado_fecha (estado, created_at),
    INDEX idx_comunicaciones_ventas_area_fecha (area, created_at),
    INDEX idx_comunicaciones_ventas_plan_fecha (plan_interes, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('025_embudo_ventas');
