-- Registrar qué agente humano respondió cada mensaje (para métricas de tiempo de respuesta)
ALTER TABLE whatsapp_messages
  ADD COLUMN respondido_por INT NULL DEFAULT NULL AFTER direction;

ALTER TABLE whatsapp_messages
  ADD INDEX idx_wm_respondido (respondido_por);
