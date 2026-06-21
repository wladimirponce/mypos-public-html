-- Corrige inconsistencia entre constraint CHECK y código PHP.
-- El constraint decía ('CERRADO','REABIERTO') pero el código escribe 'ABIERTO'.
-- Se normaliza a ('CERRADO','ABIERTO') para alinear con el código.
-- También hace que reopenClosure() actualice reabierto_at, que existía pero no se usaba.

ALTER TABLE cierres_diarios
    DROP CONSTRAINT IF EXISTS chk_cierres_diarios_estado;

ALTER TABLE cierres_diarios
    ADD CONSTRAINT chk_cierres_diarios_estado CHECK (estado IN ('CERRADO', 'ABIERTO'));

-- Normalizar filas históricas que pudieran tener 'REABIERTO' (estado previo al bug)
UPDATE cierres_diarios SET estado = 'ABIERTO' WHERE estado = 'REABIERTO';

INSERT IGNORE INTO schema_migrations (migration) VALUES ('066_fix_cierre_reabrir');
