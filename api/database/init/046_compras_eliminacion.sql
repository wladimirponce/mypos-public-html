-- Eliminacion logica de compras: el registro desaparece del listado pero se
-- conserva en la base para auditoria. La reversa de stock es opcional y se
-- ejecuta antes de marcar deleted_at cuando el usuario lo solicita.

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'compras'
      AND COLUMN_NAME = 'deleted_at'
);
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE compras ADD COLUMN deleted_at DATETIME NULL AFTER updated_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documentos_ia'
      AND COLUMN_NAME = 'deleted_at'
);
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE documentos_ia ADD COLUMN deleted_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('046_compras_eliminacion');
