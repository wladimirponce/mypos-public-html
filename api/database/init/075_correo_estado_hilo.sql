-- ============================================================
-- Migracion 075: Eje de estado por conversacion (hilo)
-- pendiente / esperando / resuelto — separado de la categoria.
-- ============================================================

ALTER TABLE correo_hilos
  ADD COLUMN IF NOT EXISTS estado ENUM('pendiente','esperando','resuelto') NOT NULL DEFAULT 'pendiente' AFTER no_leidos;

ALTER TABLE correo_hilos
  ADD INDEX IF NOT EXISTS idx_hilo_estado (empresa_id, estado);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('075_correo_estado_hilo');
