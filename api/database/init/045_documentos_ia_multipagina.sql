CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documentos_ia_archivos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    documento_ia_id BIGINT UNSIGNED NOT NULL,
    archivo_subido_id BIGINT UNSIGNED NOT NULL,
    orden INT UNSIGNED NOT NULL DEFAULT 1,
    estado ENUM('ACTIVO','ELIMINADO') NOT NULL DEFAULT 'ACTIVO',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documento_ia_archivo (documento_ia_id, archivo_subido_id),
    KEY idx_documento_ia_archivos_orden (empresa_id, documento_ia_id, estado, orden),
    CONSTRAINT fk_documento_ia_archivos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_documento_ia_archivos_documento FOREIGN KEY (documento_ia_id) REFERENCES documentos_ia(id),
    CONSTRAINT fk_documento_ia_archivos_archivo FOREIGN KEY (archivo_subido_id) REFERENCES archivos_subidos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO documentos_ia_archivos (empresa_id, documento_ia_id, archivo_subido_id, orden)
SELECT empresa_id, id, archivo_subido_id, 1
FROM documentos_ia
WHERE archivo_subido_id IS NOT NULL;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('045_documentos_ia_multipagina');
