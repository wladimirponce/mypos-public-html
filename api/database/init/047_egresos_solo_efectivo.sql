ALTER TABLE egresos
    ADD COLUMN IF NOT EXISTS quien_recibe VARCHAR(160) NULL AFTER fecha_egreso,
    ADD COLUMN IF NOT EXISTS motivo VARCHAR(255) NULL AFTER quien_recibe,
    MODIFY COLUMN categoria_id BIGINT UNSIGNED NULL;

UPDATE egresos
SET motivo = descripcion
WHERE motivo IS NULL;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('047_egresos_solo_efectivo');
