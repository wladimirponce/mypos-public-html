CREATE TABLE IF NOT EXISTS inteligencia_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    origen VARCHAR(40) NOT NULL,
    referencia_id BIGINT UNSIGNED NOT NULL,
    valor VARCHAR(20) NOT NULL,
    comentario VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_inteligencia_feedback_empresa (empresa_id, created_at),
    KEY idx_inteligencia_feedback_referencia (empresa_id, origen, referencia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('096_inteligencia_feedback');
