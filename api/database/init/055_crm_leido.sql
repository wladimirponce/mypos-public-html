-- Migración 055: Columna leido en whatsapp_conversations
-- Permite saber cuáles conversaciones tienen mensajes sin revisar por el agente.

ALTER TABLE whatsapp_conversations
    ADD COLUMN IF NOT EXISTS leido TINYINT(1) NOT NULL DEFAULT 0 AFTER estado_lead;

-- Marcar históricas como leídas (ya fueron atendidas)
UPDATE whatsapp_conversations SET leido = 1 WHERE leido = 0;

DROP PROCEDURE IF EXISTS AddIdx055;
DELIMITER //
CREATE PROCEDURE AddIdx055()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME  = 'whatsapp_conversations'
          AND INDEX_NAME  = 'idx_wac_leido'
    ) THEN
        CREATE INDEX idx_wac_leido ON whatsapp_conversations(empresa_id, leido);
    END IF;
END //
DELIMITER ;
CALL AddIdx055();
DROP PROCEDURE IF EXISTS AddIdx055;
