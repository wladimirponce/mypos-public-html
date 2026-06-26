-- Migración 013 — Crear tabla saas_rol_matriz
-- Tabla para la matriz de permisos por rol por empresa (pendiente de la migración 008)

CREATE TABLE IF NOT EXISTS saas_rol_matriz (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    nombre_rol VARCHAR(50) NOT NULL,
    puede_anular BOOLEAN DEFAULT 0,
    puede_descuento_mayor BOOLEAN DEFAULT 0,
    puede_ver_reportes BOOLEAN DEFAULT 0,
    puede_configurar_pos BOOLEAN DEFAULT 0,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_empresa_rol (empresa_id, nombre_rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
