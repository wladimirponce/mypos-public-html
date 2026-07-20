-- MyPOS Cerca: consumo único de pagos online y trazabilidad con la venta.
SET @has_venta_id := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pagos_online_intentos' AND COLUMN_NAME='venta_id');
SET @sql := IF(@has_venta_id=0, 'ALTER TABLE pagos_online_intentos ADD COLUMN venta_id BIGINT UNSIGNED NULL AFTER reserva_id, ADD KEY idx_pago_online_venta (empresa_id,venta_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_consumed_at := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pagos_online_intentos' AND COLUMN_NAME='consumed_at');
SET @sql := IF(@has_consumed_at=0, 'ALTER TABLE pagos_online_intentos ADD COLUMN consumed_at DATETIME NULL AFTER settled_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
INSERT IGNORE INTO schema_migrations (migration) VALUES ('098_mypos_cerca_payment_hardening');
