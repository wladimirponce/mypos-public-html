-- ============================================================
-- Migración 072: Flags de notificación DTE por empresa
-- Agrega notif_email_activo y notif_whatsapp_activo a la
-- tabla empresa_configuracion_operativa (default 0 = inactivo).
-- ============================================================

ALTER TABLE empresa_configuracion_operativa
    ADD COLUMN IF NOT EXISTS notif_email_activo      TINYINT(1) NOT NULL DEFAULT 0 AFTER modo_offline_habilitado,
    ADD COLUMN IF NOT EXISTS notif_whatsapp_activo   TINYINT(1) NOT NULL DEFAULT 0 AFTER notif_email_activo;
