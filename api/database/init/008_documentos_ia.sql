CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(190) NOT NULL,
    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE documentos_ia
    DROP CONSTRAINT IF EXISTS chk_documentos_ia_estado;

ALTER TABLE documentos_ia
    ADD COLUMN IF NOT EXISTS tipo_documento_detectado VARCHAR(40) NULL AFTER tipo_documento,
    ADD COLUMN IF NOT EXISTS proveedor_detectado VARCHAR(190) NULL AFTER proveedor_id,
    ADD COLUMN IF NOT EXISTS proveedor_rut_detectado VARCHAR(20) NULL AFTER proveedor_detectado,
    ADD COLUMN IF NOT EXISTS folio_detectado VARCHAR(50) NULL AFTER folio,
    ADD COLUMN IF NOT EXISTS fecha_detectada DATE NULL AFTER folio_detectado,
    ADD COLUMN IF NOT EXISTS neto_detectado BIGINT NOT NULL DEFAULT 0 AFTER fecha_detectada,
    ADD COLUMN IF NOT EXISTS iva_detectado BIGINT NOT NULL DEFAULT 0 AFTER neto_detectado,
    ADD COLUMN IF NOT EXISTS total_detectado BIGINT NOT NULL DEFAULT 0 AFTER iva_detectado,
    ADD COLUMN IF NOT EXISTS respuesta_json JSON NULL AFTER json_editado,
    ADD COLUMN IF NOT EXISTS archivo_url VARCHAR(255) NULL AFTER archivo_ruta;

UPDATE documentos_ia
SET estado = 'SUBIDO'
WHERE estado = 'PENDIENTE';

UPDATE documentos_ia
SET archivo_url = archivo_ruta
WHERE archivo_url IS NULL;

ALTER TABLE documentos_ia
    MODIFY COLUMN estado VARCHAR(30) NOT NULL DEFAULT 'SUBIDO',
    ADD CONSTRAINT chk_documentos_ia_estado CHECK (
        estado IN ('SUBIDO', 'PROCESANDO', 'PROCESADO', 'EDITADO', 'CONFIRMADO', 'ERROR')
    );

CREATE INDEX IF NOT EXISTS idx_documentos_ia_folio_detectado
    ON documentos_ia (empresa_id, tipo_documento_detectado, folio_detectado);

ALTER TABLE documentos_ia_detalles
    ADD COLUMN IF NOT EXISTS cantidad_detectada DECIMAL(14,3) NULL AFTER nombre_detectado,
    ADD COLUMN IF NOT EXISTS costo_unitario_detectado BIGINT NULL AFTER cantidad_detectada,
    ADD COLUMN IF NOT EXISTS total_detectado BIGINT NULL AFTER costo_unitario_detectado,
    ADD COLUMN IF NOT EXISTS confirmado TINYINT(1) NOT NULL DEFAULT 0 AFTER requiere_revision,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

UPDATE documentos_ia_detalles
SET cantidad_detectada = cantidad,
    costo_unitario_detectado = costo_unitario,
    total_detectado = total
WHERE cantidad_detectada IS NULL;

CREATE INDEX IF NOT EXISTS idx_documentos_ia_detalles_documento
    ON documentos_ia_detalles (empresa_id, documento_ia_id);

INSERT INTO schema_migrations (migration)
VALUES ('008_documentos_ia')
ON DUPLICATE KEY UPDATE migration = VALUES(migration);
