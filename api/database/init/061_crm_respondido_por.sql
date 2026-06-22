-- Registrar qué agente humano respondió cada mensaje (para métricas de tiempo de respuesta)
ALTER TABLE whatsapp_messages
  ADD COLUMN IF NOT EXISTS respondido_por INT NULL DEFAULT NULL AFTER direction;

CREATE INDEX IF NOT EXISTS idx_wm_respondido ON whatsapp_messages (respondido_por);
