-- 032_regrant_admin_permisos.sql
--
-- Re-asegura que SUPER_ADMIN y ADMIN_EMPRESA tengan TODOS los permisos del
-- catalogo. La migracion 016 ya hace este grant, pero los permisos agregados
-- al catalogo despues de que 016 quedo aplicada (p.ej. stock.ubicaciones.administrar)
-- no llegan a esos roles en bases ya migradas. Este grant es idempotente:
-- solo inserta los pares rol/permiso que aun faltan.

-- 1) Asegura en el catalogo los permisos que pudieron quedar fuera por haberse
--    agregado a una migracion ya aplicada.
INSERT INTO permisos (codigo, nombre, descripcion)
SELECT x.codigo, x.nombre, x.descripcion
FROM (
    SELECT 'stock.ubicaciones.administrar' AS codigo,
           'Gestionar ubicaciones de stock' AS nombre,
           'Permite crear, actualizar y desactivar bodegas/ubicaciones' AS descripcion
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

-- 2) Re-otorga a los roles administradores todo el catalogo (idempotente).
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp
      WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
  );

INSERT IGNORE INTO schema_migrations (migration) VALUES ('032_regrant_admin_permisos');
