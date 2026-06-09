CREATE TABLE IF NOT EXISTS empleados (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    rut VARCHAR(20) NOT NULL,
    nombres VARCHAR(190) NOT NULL,
    apellidos VARCHAR(190) NOT NULL,
    cargo VARCHAR(190) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_empleados_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ventas_credito_interno (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    venta_id BIGINT UNSIGNED NOT NULL,
    empleado_id BIGINT UNSIGNED NOT NULL,
    numero_boleta VARCHAR(100) NULL,
    monto_total DECIMAL(10,2) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_vcredint_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_vcredint_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
    CONSTRAINT fk_vcredint_venta FOREIGN KEY (venta_id) REFERENCES ventas(id),
    CONSTRAINT fk_vcredint_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id),
    CONSTRAINT fk_vcredint_usuario FOREIGN KEY (created_by) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permisos (codigo, nombre, descripcion) VALUES
('empleados.ver', 'Ver empleados', 'Permite listar y ver detalles de empleados.'),
('empleados.crear', 'Crear empleado', 'Permite registrar nuevos empleados en la plataforma.'),
('empleados.editar', 'Editar empleado', 'Permite modificar los datos de empleados existentes.'),
('empleados.eliminar', 'Eliminar empleado', 'Permite desactivar empleados.'),
('rrhh.descuentos.ver', 'Ver descuentos credito', 'Permite ver el listado de descuentos por planilla (credito interno).')
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion);

-- Asignar los nuevos permisos al rol Administrador (id = 1 asumiendo seeds o buscar por id)
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p 
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA') AND p.codigo IN (
  'empleados.ver', 'empleados.crear', 'empleados.editar', 'empleados.eliminar', 'rrhh.descuentos.ver'
);
