ALTER TABLE ventas DROP CONSTRAINT IF EXISTS chk_ventas_estado;

UPDATE ventas SET estado = 'EMITIDA' WHERE estado = 'REGISTRADA';

ALTER TABLE ventas
    MODIFY COLUMN estado VARCHAR(20) NOT NULL DEFAULT 'EMITIDA',
    ADD CONSTRAINT chk_ventas_estado CHECK (estado IN ('EMITIDA', 'ANULADA'));

INSERT IGNORE INTO schema_migrations (migration) VALUES ('006_cierre_diario');
