-- Migración para Memoria de Conversaciones de WhatsApp
-- Almacena el historial y resúmenes de chats con clientes para la IA

CREATE TABLE IF NOT EXISTS `whatsapp_conversations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` bigint(20) unsigned NULL DEFAULT NULL,
  `phone_number` varchar(20) NOT NULL,
  `user_name` varchar(100) DEFAULT NULL,
  `context_summary` text DEFAULT NULL,
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_whatsapp_phone` (`phone_number`),
  KEY `idx_whatsapp_empresa` (`empresa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
