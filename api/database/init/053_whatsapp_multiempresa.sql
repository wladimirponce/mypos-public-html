-- Migración 053: WhatsApp multi-empresa
-- Cada empresa puede tener su propio número WhatsApp Business.
-- El webhook enruta mensajes al número correcto según phone_number_id de Meta.

CREATE TABLE IF NOT EXISTS empresa_whatsapp_config (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    phone_number_id VARCHAR(50)     NOT NULL COMMENT 'phone_number_id de Meta WhatsApp Business API',
    access_token    TEXT            NOT NULL COMMENT 'Token de acceso para enviar mensajes',
    activo          TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ewc_empresa       (empresa_id),
    UNIQUE KEY uq_ewc_phone_number  (phone_number_id),
    KEY idx_ewc_activo              (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vincular conversaciones a la empresa propietaria del número
ALTER TABLE whatsapp_conversations
    ADD COLUMN IF NOT EXISTS empresa_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER id;

-- Índice para consultas del CRM por empresa
DROP PROCEDURE IF EXISTS _tmp_idx_053;
DELIMITER //
CREATE PROCEDURE _tmp_idx_053()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'whatsapp_conversations'
          AND INDEX_NAME   = 'idx_wac_empresa_actividad'
    ) THEN
        ALTER TABLE whatsapp_conversations
            ADD INDEX idx_wac_empresa_actividad (empresa_id, last_activity);
    END IF;
END //
DELIMITER ;
CALL _tmp_idx_053();
DROP PROCEDURE IF EXISTS _tmp_idx_053;
