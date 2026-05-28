CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE documentos_ia
    DROP CONSTRAINT IF EXISTS chk_documentos_ia_estado;

ALTER TABLE documentos_ia
    ADD COLUMN IF NOT EXISTS proveedor_nombre_detectado VARCHAR(190) NULL AFTER proveedor_rut_detectado,
    ADD COLUMN IF NOT EXISTS proveedor_confianza DECIMAL(5,4) NULL AFTER proveedor_nombre_detectado,
    ADD COLUMN IF NOT EXISTS fecha_documento_detectada DATE NULL AFTER fecha_detectada,
    ADD COLUMN IF NOT EXISTS exento_detectado BIGINT NOT NULL DEFAULT 0 AFTER iva_detectado,
    ADD COLUMN IF NOT EXISTS total_calculado BIGINT NOT NULL DEFAULT 0 AFTER total_detectado,
    ADD COLUMN IF NOT EXISTS diferencia_total BIGINT NOT NULL DEFAULT 0 AFTER total_calculado,
    ADD COLUMN IF NOT EXISTS confianza_global DECIMAL(5,4) NULL AFTER diferencia_total,
    ADD COLUMN IF NOT EXISTS requiere_revision TINYINT(1) NOT NULL DEFAULT 1 AFTER confianza_global,
    ADD COLUMN IF NOT EXISTS estado_revision VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE' AFTER requiere_revision,
    ADD COLUMN IF NOT EXISTS resumen_alertas_json LONGTEXT NULL AFTER estado_revision,
    ADD COLUMN IF NOT EXISTS normalizado_at DATETIME NULL AFTER resumen_alertas_json,
    ADD COLUMN IF NOT EXISTS revisado_at DATETIME NULL AFTER normalizado_at,
    ADD COLUMN IF NOT EXISTS revisado_por_usuario_id BIGINT UNSIGNED NULL AFTER revisado_at;

UPDATE documentos_ia
SET proveedor_nombre_detectado = proveedor_detectado
WHERE proveedor_nombre_detectado IS NULL
  AND proveedor_detectado IS NOT NULL;

UPDATE documentos_ia
SET fecha_documento_detectada = fecha_detectada
WHERE fecha_documento_detectada IS NULL
  AND fecha_detectada IS NOT NULL;

ALTER TABLE documentos_ia
    MODIFY COLUMN estado VARCHAR(30) NOT NULL DEFAULT 'SUBIDO',
    ADD CONSTRAINT chk_documentos_ia_estado CHECK (
        estado IN ('SUBIDO', 'PROCESANDO', 'PROCESADO', 'EDITADO', 'CONFIRMADO', 'COMPRA_GENERADA', 'ERROR')
    );

ALTER TABLE documentos_ia_detalles
    ADD COLUMN IF NOT EXISTS codigo_barra_detectado VARCHAR(80) NULL AFTER codigo_detectado,
    ADD COLUMN IF NOT EXISTS cantidad_normalizada DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER total_detectado,
    ADD COLUMN IF NOT EXISTS costo_unitario_normalizado BIGINT NOT NULL DEFAULT 0 AFTER cantidad_normalizada,
    ADD COLUMN IF NOT EXISTS total_normalizado BIGINT NOT NULL DEFAULT 0 AFTER costo_unitario_normalizado,
    ADD COLUMN IF NOT EXISTS metodo_match VARCHAR(30) NOT NULL DEFAULT 'SIN_MATCH' AFTER confianza,
    ADD COLUMN IF NOT EXISTS alertas_json LONGTEXT NULL AFTER requiere_revision;

UPDATE documentos_ia_detalles
SET cantidad_normalizada = COALESCE(cantidad_detectada, cantidad, 0),
    costo_unitario_normalizado = COALESCE(costo_unitario_detectado, costo_unitario, 0),
    total_normalizado = COALESCE(total_detectado, total, 0)
WHERE cantidad_normalizada = 0
  AND costo_unitario_normalizado = 0
  AND total_normalizado = 0;

CREATE TABLE IF NOT EXISTS documentos_ia_alertas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    documento_ia_id BIGINT UNSIGNED NOT NULL,
    documento_ia_detalle_id BIGINT UNSIGNED NULL,
    tipo_alerta VARCHAR(80) NOT NULL,
    severidad VARCHAR(20) NOT NULL,
    mensaje VARCHAR(255) NOT NULL,
    metadata_json LONGTEXT NULL,
    resuelta TINYINT(1) NOT NULL DEFAULT 0,
    resuelta_por_usuario_id BIGINT UNSIGNED NULL,
    resuelta_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_doc_ia_alertas_documento (empresa_id, documento_ia_id, resuelta),
    KEY idx_doc_ia_alertas_tipo (tipo_alerta, severidad),
    KEY idx_doc_ia_alertas_detalle (documento_ia_detalle_id),
    CONSTRAINT fk_doc_ia_alertas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_doc_ia_alertas_documento FOREIGN KEY (documento_ia_id) REFERENCES documentos_ia(id),
    CONSTRAINT fk_doc_ia_alertas_detalle FOREIGN KEY (documento_ia_detalle_id) REFERENCES documentos_ia_detalles(id),
    CONSTRAINT fk_doc_ia_alertas_usuario FOREIGN KEY (resuelta_por_usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documentos_ia_correcciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    documento_ia_id BIGINT UNSIGNED NOT NULL,
    documento_ia_detalle_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    campo VARCHAR(120) NOT NULL,
    valor_anterior TEXT NULL,
    valor_nuevo TEXT NULL,
    motivo VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_doc_ia_correcciones_documento (empresa_id, documento_ia_id),
    KEY idx_doc_ia_correcciones_detalle (documento_ia_detalle_id),
    CONSTRAINT fk_doc_ia_correcciones_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_doc_ia_correcciones_documento FOREIGN KEY (documento_ia_id) REFERENCES documentos_ia(id),
    CONSTRAINT fk_doc_ia_correcciones_detalle FOREIGN KEY (documento_ia_detalle_id) REFERENCES documentos_ia_detalles(id),
    CONSTRAINT fk_doc_ia_correcciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_documentos_ia_revision
    ON documentos_ia (empresa_id, estado_revision, estado);

CREATE INDEX IF NOT EXISTS idx_documentos_ia_proveedor_folio
    ON documentos_ia (empresa_id, proveedor_rut_detectado, tipo_documento_detectado, folio_detectado);

INSERT INTO permisos (codigo, nombre, descripcion, activo)
SELECT x.codigo, x.nombre, x.descripcion, 1
FROM (
    SELECT 'documentos_ia.normalizar' codigo, 'Normalizar documentos IA' nombre, 'Permite normalizar y conciliar documentos IA' descripcion UNION ALL
    SELECT 'documentos_ia.revisar', 'Revisar documentos IA', 'Permite revisar y corregir documentos IA' UNION ALL
    SELECT 'documentos_ia.aprobar', 'Aprobar documentos IA', 'Permite aprobar documentos IA antes de generar compra' UNION ALL
    SELECT 'documentos_ia.alertas.ver', 'Ver alertas documentos IA', 'Permite consultar alertas de documentos IA' UNION ALL
    SELECT 'documentos_ia.alertas.resolver', 'Resolver alertas documentos IA', 'Permite resolver alertas de documentos IA'
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'documentos_ia.normalizar',
    'documentos_ia.revisar',
    'documentos_ia.aprobar',
    'documentos_ia.alertas.ver',
    'documentos_ia.alertas.resolver'
)
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA', 'BODEGA')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'documentos_ia.revisar',
    'documentos_ia.aprobar',
    'documentos_ia.alertas.ver',
    'documentos_ia.alertas.resolver'
)
WHERE r.codigo = 'CONTADOR'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('documentos_ia.alertas.ver')
WHERE r.codigo = 'AUDITOR'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('020_ia_normalizacion_conciliacion');
