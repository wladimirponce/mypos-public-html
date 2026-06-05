-- Migración para control de tokens consumidos por usuario
-- Se agrega la columna a la tabla recién creada en la migración 029

ALTER TABLE `whatsapp_conversations`
ADD COLUMN `total_tokens_used` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `context_summary`;
