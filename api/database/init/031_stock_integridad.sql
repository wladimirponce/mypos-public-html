-- 031_stock_integridad.sql
--
-- Fase: integridad de stock (regla de oro).
--
-- A partir de esta migracion, stock_ubicacion es la UNICA fuente de verdad del
-- saldo. stock_sucursal pasa a ser una vista materializada (saldo derivado) que
-- StockService recalcula como SUMA de las ubicaciones de cada sucursal en cada
-- movimiento. Esta migracion alinea los datos existentes con ese modelo:
--
--   1) Garantiza una fila stock_sucursal para todo (empresa, sucursal, producto)
--      que ya tenga saldo en alguna ubicacion asociada a esa sucursal.
--   2) Recalcula stock_sucursal.cantidad desde stock_ubicacion, corrigiendo
--      cualquier desfase historico (p.ej. traslados que solo tocaban ubicaciones).

-- 1) Filas faltantes en stock_sucursal derivadas de ubicaciones con sucursal.
INSERT IGNORE INTO stock_sucursal (empresa_id, sucursal_id, producto_id, cantidad, reservado, stock_minimo)
SELECT u.empresa_id, u.sucursal_id, su.producto_id, 0.000, 0.000, 0.000
FROM stock_ubicacion su
INNER JOIN ubicaciones_stock u
        ON u.id = su.ubicacion_id AND u.empresa_id = su.empresa_id
WHERE u.sucursal_id IS NOT NULL;

-- 2) Recalculo del saldo derivado para todas las filas de stock_sucursal.
UPDATE stock_sucursal ss
SET ss.cantidad = (
        SELECT COALESCE(SUM(su.cantidad), 0.000)
        FROM stock_ubicacion su
        INNER JOIN ubicaciones_stock u
                ON u.id = su.ubicacion_id AND u.empresa_id = su.empresa_id
        WHERE su.empresa_id = ss.empresa_id
          AND su.producto_id = ss.producto_id
          AND u.sucursal_id = ss.sucursal_id
    ),
    ss.updated_at = CURRENT_TIMESTAMP;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('031_stock_integridad');
