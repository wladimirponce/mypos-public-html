-- Migración 065: Reemplaza los códigos de producto tipo slug por códigos cortos
-- Formato: XXX-NNNNN  (3 letras del rubro + guión + secuencial 5 dígitos por empresa+prefijo)
-- Ejemplos: CAR-00001, ASE-00042, GEN-00001 (sin rubro)
--
-- FIX: la secuencia se particiona por el PREFIJO CALCULADO (no por rubro_id).
-- Esto evita colisiones cuando dos rubros distintos producen el mismo prefijo de
-- 3 letras (ej: "Bebidas" y "Bebidas Alcohólicas" → ambas "BEB").
-- Con esta lógica todos los productos que comparten prefijo se numeran juntos.
--
-- Seguro: venta_detalle.codigo_producto y compra_detalle.codigo_producto son
-- snapshots y no se ven afectados por este cambio.

-- 1. Liberar el UNIQUE KEY para evitar colisiones transitorias durante el UPDATE masivo
ALTER TABLE productos DROP INDEX IF EXISTS uq_productos_empresa_codigo;

-- 2. Calcular prefijo en subconsulta y particionar ROW_NUMBER por (empresa, prefijo)
UPDATE productos p
INNER JOIN (
    SELECT
        sub.id,
        CONCAT(
            sub.prefijo,
            '-',
            LPAD(
                ROW_NUMBER() OVER (
                    PARTITION BY sub.empresa_id, sub.prefijo
                    ORDER BY sub.id
                ),
                5, '0'
            )
        ) AS nuevo_codigo
    FROM (
        SELECT
            p.id,
            p.empresa_id,
            IFNULL(
                LEFT(
                    REGEXP_REPLACE(
                        UPPER(
                            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                                COALESCE(r.nombre, ''),
                            ' y ', ''), ' Y ', ''), ' de ', ''), ' De ', ''),
                            'á', 'A'), 'é', 'E'), 'í', 'I'), 'ó', 'O'), 'ú', 'U'),
                            'ñ', 'N'), ' ', '')
                        ),
                        '[^A-Z]', ''
                    ),
                    3
                ),
                'GEN'
            ) AS prefijo
        FROM productos p
        LEFT JOIN rubros r ON r.id = p.rubro_id
    ) sub
) gen ON gen.id = p.id
SET p.codigo = gen.nuevo_codigo;

-- 3. Restaurar la restricción de unicidad
ALTER TABLE productos
    ADD UNIQUE KEY uq_productos_empresa_codigo (empresa_id, codigo);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('065_codigos_producto');
