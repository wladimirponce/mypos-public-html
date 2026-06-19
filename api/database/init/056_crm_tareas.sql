-- Migración 056: Tabla crm_tareas
-- Tareas y recordatorios asociados a una conversación/lead del CRM.

CREATE TABLE IF NOT EXISTS crm_tareas (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id     BIGINT UNSIGNED NOT NULL,
    titulo              VARCHAR(255)    NOT NULL,
    fecha_vencimiento   DATETIME        NULL DEFAULT NULL,
    completada          TINYINT(1)      NOT NULL DEFAULT 0,
    completada_en       DATETIME        NULL DEFAULT NULL,
    creado_por          BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ct_conversation (conversation_id),
    KEY idx_ct_completada   (completada),
    KEY idx_ct_vencimiento  (fecha_vencimiento),
    CONSTRAINT fk_ct_conversation
        FOREIGN KEY (conversation_id)
        REFERENCES whatsapp_conversations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
