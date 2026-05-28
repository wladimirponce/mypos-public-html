CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS archivos_subidos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    modulo ENUM('PRODUCTOS','DOCUMENTOS_IA','CONFIGURACION','DTE','OTRO') NOT NULL DEFAULT 'OTRO',
    entidad VARCHAR(80) NOT NULL,
    entidad_id BIGINT UNSIGNED NULL,
    nombre_original VARCHAR(255) NOT NULL,
    nombre_storage VARCHAR(190) NOT NULL,
    ruta_relativa VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    extension VARCHAR(20) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    hash_sha256 CHAR(64) NOT NULL,
    estado ENUM('ACTIVO','ELIMINADO','ERROR') NOT NULL DEFAULT 'ACTIVO',
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_archivos_empresa_modulo_entidad (empresa_id, modulo, entidad, entidad_id),
    KEY idx_archivos_hash (hash_sha256),
    KEY idx_archivos_created_at (created_at),
    CONSTRAINT fk_archivos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_archivos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
    CONSTRAINT fk_archivos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ia_procesamientos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    documento_ia_id BIGINT UNSIGNED NOT NULL,
    archivo_subido_id BIGINT UNSIGNED NULL,
    proveedor VARCHAR(40) NOT NULL DEFAULT 'GEMINI',
    modelo VARCHAR(120) NOT NULL,
    estado ENUM('PENDIENTE','PROCESANDO','PROCESADO','ERROR') NOT NULL DEFAULT 'PENDIENTE',
    request_json LONGTEXT NULL,
    response_json LONGTEXT NULL,
    error_mensaje TEXT NULL,
    tokens_input INT NULL,
    tokens_output INT NULL,
    created_by_usuario_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ia_proc_empresa_documento (empresa_id, documento_ia_id),
    KEY idx_ia_proc_archivo (archivo_subido_id),
    KEY idx_ia_proc_estado (estado),
    CONSTRAINT fk_ia_proc_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_ia_proc_documento FOREIGN KEY (documento_ia_id) REFERENCES documentos_ia(id),
    CONSTRAINT fk_ia_proc_archivo FOREIGN KEY (archivo_subido_id) REFERENCES archivos_subidos(id),
    CONSTRAINT fk_ia_proc_usuario FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documentos_ia'
      AND COLUMN_NAME = 'archivo_subido_id'
);
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE documentos_ia ADD COLUMN archivo_subido_id BIGINT UNSIGNED NULL AFTER usuario_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documentos_ia'
      AND INDEX_NAME = 'idx_documentos_ia_archivo_subido'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE documentos_ia ADD KEY idx_documentos_ia_archivo_subido (archivo_subido_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documentos_ia'
      AND CONSTRAINT_NAME = 'fk_documentos_ia_archivo_subido'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE documentos_ia ADD CONSTRAINT fk_documentos_ia_archivo_subido FOREIGN KEY (archivo_subido_id) REFERENCES archivos_subidos(id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO permisos (codigo, nombre, descripcion, activo)
SELECT x.codigo, x.nombre, x.descripcion, 1
FROM (
    SELECT 'uploads.crear' codigo, 'Crear uploads' nombre, 'Permite subir archivos controlados' descripcion UNION ALL
    SELECT 'uploads.ver', 'Ver uploads', 'Permite consultar y descargar archivos subidos' UNION ALL
    SELECT 'documentos_ia.procesar_real', 'Procesar IA real', 'Permite procesar documentos IA con motor real' UNION ALL
    SELECT 'ia.configuracion.ver', 'Ver configuracion IA', 'Permite consultar configuracion IA sin exponer secretos' UNION ALL
    SELECT 'ia.configuracion.editar', 'Editar configuracion IA', 'Reservado para configuracion IA futura'
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('uploads.crear', 'uploads.ver', 'documentos_ia.procesar_real', 'ia.configuracion.ver', 'ia.configuracion.editar')
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('uploads.crear', 'uploads.ver', 'documentos_ia.procesar_real')
WHERE r.codigo = 'BODEGA'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('uploads.ver', 'documentos_ia.procesar_real', 'ia.configuracion.ver')
WHERE r.codigo = 'CONTADOR'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('uploads.ver', 'ia.configuracion.ver')
WHERE r.codigo = 'AUDITOR'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('019_uploads_gemini');
