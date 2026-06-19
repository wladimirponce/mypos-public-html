-- Migración 059: Broadcast / Campañas masivas WhatsApp
-- Permite enviar un template HSM a múltiples contactos del CRM.

CREATE TABLE IF NOT EXISTS crm_broadcasts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    template_id     BIGINT UNSIGNED NOT NULL,
    titulo          VARCHAR(255)    NOT NULL,
    variables_json  JSON            NULL DEFAULT NULL COMMENT 'Valores fijos de variables: {"1":"MyPOS","2":"30%"}',
    filtro_estado   VARCHAR(50)     NULL DEFAULT NULL COMMENT 'Filtrar destinatarios por estado_lead',
    filtro_tipo     VARCHAR(50)     NULL DEFAULT NULL COMMENT 'Filtrar por tipo_contacto',
    estado          ENUM('BORRADOR','ENVIANDO','COMPLETADO','ERROR') NOT NULL DEFAULT 'BORRADOR',
    total           INT UNSIGNED    NOT NULL DEFAULT 0,
    enviados        INT UNSIGNED    NOT NULL DEFAULT 0,
    errores         INT UNSIGNED    NOT NULL DEFAULT 0,
    creado_por      BIGINT UNSIGNED NULL DEFAULT NULL,
    iniciado_at     TIMESTAMP       NULL DEFAULT NULL,
    completado_at   TIMESTAMP       NULL DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cb_empresa (empresa_id),
    CONSTRAINT fk_cb_empresa  FOREIGN KEY (empresa_id)  REFERENCES empresas           (id),
    CONSTRAINT fk_cb_template FOREIGN KEY (template_id) REFERENCES whatsapp_templates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_broadcast_recipients (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    broadcast_id    BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    estado          ENUM('PENDIENTE','ENVIADO','ERROR') NOT NULL DEFAULT 'PENDIENTE',
    error_detalle   VARCHAR(255)    NULL DEFAULT NULL,
    enviado_at      TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cbr_broadcast_conv (broadcast_id, conversation_id),
    KEY idx_cbr_broadcast (broadcast_id),
    CONSTRAINT fk_cbr_broadcast FOREIGN KEY (broadcast_id)    REFERENCES crm_broadcasts          (id) ON DELETE CASCADE,
    CONSTRAINT fk_cbr_conv      FOREIGN KEY (conversation_id) REFERENCES whatsapp_conversations  (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
