CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_configuracion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    rut_empresa VARCHAR(20) NULL,
    razon_social VARCHAR(190) NULL,
    nombre_fantasia VARCHAR(190) NULL,
    giro VARCHAR(190) NULL,
    email_contacto VARCHAR(190) NULL,
    telefono_contacto VARCHAR(40) NULL,
    direccion VARCHAR(255) NULL,
    comuna VARCHAR(100) NULL,
    ciudad VARCHAR(100) NULL,
    region VARCHAR(100) NULL,
    logo_url VARCHAR(255) NULL,
    sitio_web VARCHAR(255) NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empresa_configuracion_empresa (empresa_id),
    CONSTRAINT fk_empresa_configuracion_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_configuracion_operativa (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    permitir_stock_negativo TINYINT(1) NOT NULL DEFAULT 0,
    exigir_caja_abierta_para_vender TINYINT(1) NOT NULL DEFAULT 0,
    permitir_venta_sin_cliente TINYINT(1) NOT NULL DEFAULT 1,
    permitir_credito_clientes TINYINT(1) NOT NULL DEFAULT 1,
    exigir_cliente_en_factura TINYINT(1) NOT NULL DEFAULT 1,
    tipo_documento_default VARCHAR(50) NOT NULL DEFAULT 'BOLETA',
    metodo_pago_default_id BIGINT UNSIGNED NULL,
    alerta_stock_bajo_default DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    alerta_folios_bajos_default INT NOT NULL DEFAULT 10,
    dias_alerta_vencimiento_caf INT NOT NULL DEFAULT 30,
    ia_documentos_habilitada TINYINT(1) NOT NULL DEFAULT 1,
    documentos_tributarios_habilitados TINYINT(1) NOT NULL DEFAULT 1,
    modo_offline_habilitado TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empresa_configuracion_operativa_empresa (empresa_id),
    KEY idx_empresa_configuracion_operativa_metodo_pago (metodo_pago_default_id),
    CONSTRAINT fk_empresa_configuracion_operativa_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_empresa_configuracion_operativa_metodo_pago FOREIGN KEY (metodo_pago_default_id) REFERENCES metodos_pago(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sucursal_configuracion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    direccion VARCHAR(255) NULL,
    comuna VARCHAR(100) NULL,
    ciudad VARCHAR(100) NULL,
    telefono VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    exigir_caja_abierta_para_vender TINYINT(1) NULL,
    permitir_stock_negativo TINYINT(1) NULL,
    tipo_documento_default VARCHAR(50) NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sucursal_configuracion_sucursal (sucursal_id),
    KEY idx_sucursal_configuracion_empresa (empresa_id),
    CONSTRAINT fk_sucursal_configuracion_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_sucursal_configuracion_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO empresa_configuracion (
    empresa_id, rut_empresa, razon_social, nombre_fantasia, giro, email_contacto,
    telefono_contacto, direccion, comuna, ciudad
)
SELECT e.id, e.rut, e.razon_social, e.nombre_fantasia, e.giro, e.email,
       e.telefono, e.direccion, e.comuna, e.ciudad
FROM empresas e
WHERE NOT EXISTS (
    SELECT 1 FROM empresa_configuracion ec WHERE ec.empresa_id = e.id
);

INSERT INTO empresa_configuracion_operativa (empresa_id)
SELECT e.id
FROM empresas e
WHERE NOT EXISTS (
    SELECT 1 FROM empresa_configuracion_operativa eco WHERE eco.empresa_id = e.id
);

INSERT INTO sucursal_configuracion (
    empresa_id, sucursal_id, direccion, comuna, ciudad, telefono, activa
)
SELECT s.empresa_id, s.id, s.direccion, s.comuna, s.ciudad, s.telefono, s.activo
FROM sucursales s
WHERE NOT EXISTS (
    SELECT 1 FROM sucursal_configuracion sc WHERE sc.sucursal_id = s.id
);

INSERT INTO permisos (codigo, nombre, descripcion, activo)
SELECT x.codigo, x.nombre, x.descripcion, 1
FROM (
    SELECT 'configuracion.ver' codigo, 'Ver configuracion' nombre, 'Permite consultar configuracion de empresa y sucursal' descripcion UNION ALL
    SELECT 'configuracion.editar', 'Editar configuracion', 'Permite editar configuracion de empresa y sucursal'
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('configuracion.ver', 'configuracion.editar')
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo = 'configuracion.ver'
WHERE r.codigo IN ('CONTADOR', 'AUDITOR')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('017_configuracion_empresa');

