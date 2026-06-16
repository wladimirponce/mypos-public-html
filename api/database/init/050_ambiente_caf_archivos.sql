-- Migración 050: columna ambiente en caf_archivos (canal pool CafCentralManager)
-- Sin esta columna, el canal de pool distribuye CAFs de cualquier ambiente a cualquier sucursal.

ALTER TABLE caf_archivos
    ADD COLUMN IF NOT EXISTS ambiente VARCHAR(20) NOT NULL DEFAULT 'CERTIFICACION' AFTER estado;

-- Índice que acelera las consultas de pool filtradas por empresa+tipo+ambiente+estado
DROP PROCEDURE IF EXISTS _tmp_add_idx_050;
DELIMITER //
CREATE PROCEDURE _tmp_add_idx_050()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'caf_archivos'
          AND INDEX_NAME   = 'idx_caf_archivos_empresa_tipo_ambiente'
    ) THEN
        ALTER TABLE caf_archivos
            ADD INDEX idx_caf_archivos_empresa_tipo_ambiente (empresa_id, tipo_documento, ambiente, estado);
    END IF;
END //
DELIMITER ;
CALL _tmp_add_idx_050();
DROP PROCEDURE IF EXISTS _tmp_add_idx_050;
