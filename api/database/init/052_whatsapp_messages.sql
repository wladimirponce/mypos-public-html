-- Migración 052: Historial individual de mensajes WhatsApp
-- Guarda cada mensaje del usuario y respuesta del bot para auditoría y CRM

CREATE TABLE IF NOT EXISTS whatsapp_messages (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id  BIGINT UNSIGNED NOT NULL,
    direction        ENUM('INBOUND','OUTBOUND') NOT NULL DEFAULT 'INBOUND',
    message_text     TEXT NOT NULL,
    ai_response      TEXT NULL,
    intent           VARCHAR(80) NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wam_conversation (conversation_id, created_at),
    CONSTRAINT fk_wam_conversation FOREIGN KEY (conversation_id)
        REFERENCES whatsapp_conversations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
