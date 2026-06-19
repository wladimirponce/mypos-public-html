-- Migración 051: Enriquecer whatsapp_conversations para CRM
-- Agrega clasificación de contacto, pipeline de lead y vínculo a cliente

ALTER TABLE whatsapp_conversations
    ADD COLUMN IF NOT EXISTS cliente_id         BIGINT UNSIGNED NULL DEFAULT NULL AFTER user_name,
    ADD COLUMN IF NOT EXISTS tipo_contacto      ENUM('LEAD','CLIENTE_CAUTIVO','OTRO') NOT NULL DEFAULT 'LEAD' AFTER cliente_id,
    ADD COLUMN IF NOT EXISTS estado_lead        ENUM('NUEVO','CONTACTADO','CALIFICADO','CONVERTIDO','DESCARTADO') NOT NULL DEFAULT 'NUEVO' AFTER tipo_contacto,
    ADD COLUMN IF NOT EXISTS plan_interes       VARCHAR(60)  NULL DEFAULT NULL AFTER estado_lead,
    ADD COLUMN IF NOT EXISTS primer_mensaje     TEXT         NULL DEFAULT NULL AFTER plan_interes,
    ADD COLUMN IF NOT EXISTS asignado_usuario_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER primer_mensaje,
    ADD COLUMN IF NOT EXISTS notas_internas     TEXT         NULL DEFAULT NULL AFTER asignado_usuario_id;

-- Índices útiles para la bandeja CRM
DROP PROCEDURE IF EXISTS _tmp_idx_051;
DELIMITER //
CREATE PROCEDURE _tmp_idx_051()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_conversations'
          AND INDEX_NAME = 'idx_wac_tipo_estado'
    ) THEN
        ALTER TABLE whatsapp_conversations
            ADD INDEX idx_wac_tipo_estado (tipo_contacto, estado_lead, last_activity);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_conversations'
          AND INDEX_NAME = 'idx_wac_cliente'
    ) THEN
        ALTER TABLE whatsapp_conversations
            ADD INDEX idx_wac_cliente (cliente_id);
    END IF;
END //
DELIMITER ;
CALL _tmp_idx_051();
DROP PROCEDURE IF EXISTS _tmp_idx_051;
