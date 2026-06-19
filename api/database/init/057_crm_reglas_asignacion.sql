-- Migración 057: Reglas de asignación automática CRM
-- El webhook consulta esta tabla después de que Gemini clasifica el intent/tipo_contacto
-- y asigna la conversación al usuario correspondiente.

CREATE TABLE IF NOT EXISTS crm_reglas_asignacion (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id   BIGINT UNSIGNED NOT NULL,
    criterio     ENUM('intent','tipo_contacto') NOT NULL,
    valor        VARCHAR(50)     NOT NULL,
    usuario_id   BIGINT UNSIGNED NOT NULL,
    prioridad    TINYINT         NOT NULL DEFAULT 10,
    activo       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cra_empresa_criterio (empresa_id, criterio, valor, activo),
    CONSTRAINT fk_cra_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_cra_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
