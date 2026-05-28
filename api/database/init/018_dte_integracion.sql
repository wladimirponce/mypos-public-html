CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dte_configuracion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    modo ENUM('SIMULADO','REAL') NOT NULL DEFAULT 'SIMULADO',
    sistema_path VARCHAR(255) NOT NULL DEFAULT 'C:\\sii\\dte_php',
    endpoint_cli VARCHAR(255) NULL,
    endpoint_http VARCHAR(255) NULL,
    salida_xml_dir VARCHAR(255) NULL,
    salida_pdf_dir VARCHAR(255) NULL,
    ambiente ENUM('CERTIFICACION','PRODUCCION') NOT NULL DEFAULT 'CERTIFICACION',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dte_configuracion_empresa (empresa_id),
    CONSTRAINT fk_dte_configuracion_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dte_emisiones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NULL,
    documento_emitido_id BIGINT UNSIGNED NOT NULL,
    tipo_documento VARCHAR(40) NOT NULL,
    folio BIGINT UNSIGNED NOT NULL,
    modo ENUM('SIMULADO','REAL') NOT NULL DEFAULT 'SIMULADO',
    estado ENUM('PENDIENTE','EN_PROCESO','EMITIDO','ENVIADO','ACEPTADO','RECHAZADO','ERROR') NOT NULL DEFAULT 'PENDIENTE',
    request_json LONGTEXT NULL,
    response_json LONGTEXT NULL,
    xml_path VARCHAR(255) NULL,
    pdf_path VARCHAR(255) NULL,
    track_id VARCHAR(120) NULL,
    error_mensaje TEXT NULL,
    intentos INT NOT NULL DEFAULT 0,
    ultimo_intento_at DATETIME NULL,
    created_by_usuario_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dte_emisiones_empresa_fecha (empresa_id, created_at),
    KEY idx_dte_emisiones_documento (documento_emitido_id),
    KEY idx_dte_emisiones_estado (estado),
    KEY idx_dte_emisiones_tipo_folio (empresa_id, tipo_documento, folio),
    CONSTRAINT fk_dte_emisiones_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_dte_emisiones_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
    CONSTRAINT fk_dte_emisiones_documento FOREIGN KEY (documento_emitido_id) REFERENCES documentos_emitidos(id),
    CONSTRAINT fk_dte_emisiones_usuario FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dte_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    dte_emision_id BIGINT UNSIGNED NULL,
    documento_emitido_id BIGINT UNSIGNED NULL,
    tipo_evento VARCHAR(80) NOT NULL,
    descripcion TEXT NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dte_eventos_empresa_fecha (empresa_id, created_at),
    KEY idx_dte_eventos_emision (dte_emision_id),
    KEY idx_dte_eventos_documento (documento_emitido_id),
    CONSTRAINT fk_dte_eventos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_dte_eventos_emision FOREIGN KEY (dte_emision_id) REFERENCES dte_emisiones(id),
    CONSTRAINT fk_dte_eventos_documento FOREIGN KEY (documento_emitido_id) REFERENCES documentos_emitidos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO dte_configuracion (empresa_id)
SELECT e.id
FROM empresas e
WHERE NOT EXISTS (
    SELECT 1 FROM dte_configuracion dc WHERE dc.empresa_id = e.id
);

INSERT INTO permisos (codigo, nombre, descripcion, activo)
SELECT x.codigo, x.nombre, x.descripcion, 1
FROM (
    SELECT 'dte.configuracion.ver' codigo, 'Ver configuracion DTE' nombre, 'Permite consultar configuracion de integracion DTE' descripcion UNION ALL
    SELECT 'dte.configuracion.editar', 'Editar configuracion DTE', 'Permite editar configuracion de integracion DTE' UNION ALL
    SELECT 'dte.emitir', 'Emitir DTE', 'Permite emitir documentos via integracion DTE' UNION ALL
    SELECT 'dte.reintentar', 'Reintentar DTE', 'Permite reintentar emisiones DTE con error o rechazo' UNION ALL
    SELECT 'dte.ver', 'Ver DTE', 'Permite consultar emisiones y eventos DTE'
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('dte.configuracion.ver', 'dte.configuracion.editar', 'dte.emitir', 'dte.reintentar', 'dte.ver')
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('dte.ver', 'dte.emitir', 'dte.reintentar')
WHERE r.codigo = 'CONTADOR'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo = 'dte.ver'
WHERE r.codigo = 'AUDITOR'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('dte.ver', 'dte.emitir')
WHERE r.codigo = 'CAJERO'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('018_dte_integracion');
