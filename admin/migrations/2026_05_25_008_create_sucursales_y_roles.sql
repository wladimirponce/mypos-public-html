-- Migración: Sucursales y Matriz Avanzada de Roles (SaaS)
-- Tablas necesarias para el Super Command Center

CREATE TABLE IF NOT EXISTS saas_sucursal (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    direccion VARCHAR(255) NOT NULL,
    codigo_sii VARCHAR(20) DEFAULT NULL,
    activa BOOLEAN DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (empresa_id)
);

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
);

CREATE TABLE IF NOT EXISTS saas_broadcasts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(100) NOT NULL,
    mensaje TEXT NOT NULL,
    tipo VARCHAR(20) DEFAULT 'info', -- info, warning, success
    fecha_inicio DATETIME NOT NULL,
    fecha_fin DATETIME NOT NULL,
    activo BOOLEAN DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
