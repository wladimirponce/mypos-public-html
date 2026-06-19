-- Guardar el WhatsApp Business Account ID para sincronización de templates
ALTER TABLE empresa_whatsapp_config
  ADD COLUMN IF NOT EXISTS waba_id VARCHAR(30) NULL DEFAULT NULL AFTER phone_number_id;
