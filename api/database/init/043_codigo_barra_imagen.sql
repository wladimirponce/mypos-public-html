-- 043_codigo_barra_imagen.sql
--
-- ProductoRepository::createBarcode inserta imagen_url en productos_codigos_barra,
-- pero ninguna migración previa creó la columna (003 la agregó solo a productos_imagenes).
-- Sin esta columna, toda creación de código de barra falla con error 500.

ALTER TABLE productos_codigos_barra
    ADD COLUMN IF NOT EXISTS imagen_url VARCHAR(255) NULL AFTER activo;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('043_codigo_barra_imagen');
