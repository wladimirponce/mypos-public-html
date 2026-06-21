-- Migración 065: Reemplaza los códigos de producto tipo slug por códigos cortos
-- Formato: XXX-NNNNN  (3 letras del rubro + guión + secuencial 5 dígitos por empresa+rubro)
-- Ejemplos: CAR-00001, ASE-00042, GEN-00001 (sin rubro)
-- Seguro: venta_detalle.codigo_producto y compra_detalle.codigo_producto son snapshots
-- y no se ven afectados por este cambio.

-- 1. Liberar el UNIQUE KEY para evitar colisiones transitorias durante el UPDATE masivo
ALTER TABLE productos DROP INDEX IF EXISTS uq_productos_empresa_codigo;

-- 2. Normalizar acentos frecuentes en nombres de rubros antes de extraer el prefijo,
--    luego tomar los primeros 3 caracteres alfanuméricos en mayúsculas.
UPDATE productos p
JOIN (
    SELECT
        p.id,
        CONCAT(
            IFNULL(
                LEFT(
                    REGEXP_REPLACE(
                        UPPER(
                            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                                REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                                    r.nombre,
                                ' y ', ''), ' Y ', ''), ' de ', ''), ' De ', ''),
                                'á', 'A'), 'é', 'E'), 'í', 'I'), 'ó', 'O'), 'ú', 'U'),
                                'ñ', 'N'), ' ', ''
                            )
                        ),
                        '[^A-Z]', ''
                    ),
                    3
                ),
                'GEN'
            ),
            '-',
            LPAD(
                ROW_NUMBER() OVER (
                    PARTITION BY p.empresa_id, IFNULL(p.rubro_id, 0)
                    ORDER BY p.id
                ),
                5, '0'
            )
        ) AS nuevo_codigo
    FROM productos p
    LEFT JOIN rubros r ON r.id = p.rubro_id
) gen ON gen.id = p.id
SET p.codigo = gen.nuevo_codigo;

-- 3. Restaurar la restricción de unicidad
ALTER TABLE productos
    ADD UNIQUE KEY uq_productos_empresa_codigo (empresa_id, codigo);

-- 4. También regenerar el campo sku si coincide con el slug antiguo
-- (sku se usa para matching con catálogo maestro, lo dejamos intacto)

INSERT IGNORE INTO schema_migrations (migration) VALUES ('065_codigos_producto');
