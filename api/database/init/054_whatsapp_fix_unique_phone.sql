-- Migración 054: Reemplazar índice único simple en phone_number
-- por índice compuesto (phone_number, empresa_id) para soporte multi-empresa.
--
-- El índice anterior impedía que el mismo número tuviera conversaciones
-- en distintas empresas (empresa_id diferente). Con el compuesto, la
-- unicidad se evalúa por (número, empresa), que es lo correcto.

ALTER TABLE whatsapp_conversations DROP INDEX IF EXISTS idx_whatsapp_phone;

-- Índice compuesto: un número puede tener UNA conversación por empresa.
-- MySQL permite múltiples NULLs en UNIQUE, por lo que las conversaciones
-- legacy (empresa_id IS NULL) quedan protegidas por la lógica SELECT-antes-INSERT
-- del webhook, no por la BD.
ALTER TABLE whatsapp_conversations
    ADD UNIQUE KEY IF NOT EXISTS uq_wac_phone_empresa (phone_number, empresa_id);
