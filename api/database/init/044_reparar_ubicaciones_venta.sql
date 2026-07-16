INSERT INTO ubicaciones_stock (
    empresa_id, sucursal_id, codigo, nombre, tipo, descripcion,
    principal, permite_venta, activo
)
SELECT s.empresa_id,
       s.id,
       CONCAT('SUC-', s.id),
       s.nombre,
       'SUCURSAL_VENTA',
       'Ubicacion de venta reparada desde sucursal',
       1,
       1,
       s.activo
FROM sucursales s
WHERE s.activo = 1
  AND NOT EXISTS (
      SELECT 1
      FROM ubicaciones_stock u
      WHERE u.empresa_id = s.empresa_id
        AND u.sucursal_id = s.id
        AND u.tipo = 'SUCURSAL_VENTA'
        AND u.activo = 1
  );

INSERT IGNORE INTO schema_migrations (migration) VALUES ('044_reparar_ubicaciones_venta');
