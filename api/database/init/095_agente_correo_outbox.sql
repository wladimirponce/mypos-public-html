-- Bandeja de salida asincronica para comunicaciones proactivas del agente.

CREATE TABLE IF NOT EXISTS agente_correos_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    destinatario VARCHAR(190) NOT NULL,
    razon_social VARCHAR(190) NOT NULL,
    asunto VARCHAR(190) NOT NULL,
    html LONGTEXT NOT NULL,
    intencion VARCHAR(80) NOT NULL,
    motivo VARCHAR(500) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
    proximo_intento_at DATETIME NULL,
    locked_at DATETIME NULL,
    enviado_at DATETIME NULL,
    ultimo_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_agente_correos_pendientes (estado, proximo_intento_at, id),
    KEY idx_agente_correos_empresa (empresa_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE agente_alertas_log
    ADD COLUMN IF NOT EXISTS correo_outbox_id BIGINT UNSIGNED NULL AFTER detalle_json;

CREATE INDEX IF NOT EXISTS idx_agente_alertas_correo_outbox
    ON agente_alertas_log (correo_outbox_id);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('095_agente_correo_outbox');
