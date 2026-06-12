-- El listado de productos une archivos_subidos por ruta_relativa (sin indice).
-- Con pocos archivos era barato; tras cargas masivas de imagenes la consulta
-- se vuelve cuadratica (~17s con 8.500 productos y 5.000 archivos).

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'archivos_subidos'
      AND INDEX_NAME = 'idx_archivos_empresa_ruta'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE archivos_subidos ADD KEY idx_archivos_empresa_ruta (empresa_id, ruta_relativa)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('047_indice_archivos_ruta');
