-- Seed base de roles y permisos.
--
-- PROBLEMA QUE CIERRA
-- `999_seed_base.sql` quedo vacio y `016_permisos_centralizados.sql` solo crea
-- el rol AUDITOR. Un `docker compose up` sobre un volumen limpio dejaba:
--
--   * la tabla `roles` sin SUPER_ADMIN, asi que POST /api/v1/auth/register
--     respondia 500 "Rol SUPER_ADMIN no encontrado en el sistema" y era
--     imposible crear la primera cuenta;
--   * 12 permisos que las rutas de `public/index.php` exigen pero que ninguna
--     migracion insertaba nunca, entre ellos `productos.ver` y `stock.ver`.
--     Sin `productos.ver` no aparecia Productos ni Rubros en el menu y esas
--     rutas devolvian 403 para CUALQUIER rol, incluido SUPER_ADMIN.
--
-- POR QUE VA DESPUES Y NO DENTRO DE 016
-- Los bloques de otorgamiento de 016 corren cuando los roles todavia no
-- existen, asi que ahi no otorgan nada. Esta migracion crea lo que falta y
-- vuelve a aplicar los mismos otorgamientos, con la misma forma idempotente
-- (`NOT EXISTS`), de modo que sirve tanto para una base nueva como para una
-- base existente a la que solo hay que rellenarle los huecos.

-- ---------------------------------------------------------------------------
-- 1. Roles de sistema
--    Los siete codigos de PermissionService::ROLES_SISTEMA. AUDITOR ya lo crea
--    016; se repite aqui por si esa migracion no llego a correr.
-- ---------------------------------------------------------------------------
INSERT INTO roles (codigo, nombre, descripcion, activo)
SELECT x.codigo, x.nombre, x.descripcion, 1
FROM (
    SELECT 'SUPER_ADMIN'   AS codigo, 'Super administrador'      AS nombre, 'Acceso total al sistema'                    AS descripcion UNION ALL
    SELECT 'ADMIN_EMPRESA', 'Administrador de empresa', 'Administra una empresa y su configuracion' UNION ALL
    SELECT 'CAJERO',        'Cajero',                   'Opera ventas y caja'                       UNION ALL
    SELECT 'VENDEDOR',      'Vendedor',                 'Gestiona ventas y clientes'                UNION ALL
    SELECT 'BODEGA',        'Bodega',                   'Gestiona stock y compras'                  UNION ALL
    SELECT 'CONTADOR',      'Contador',                 'Revisa documentos, cierres y reportes'     UNION ALL
    SELECT 'AUDITOR',       'Auditor',                  'Rol de consulta y auditoria'
) x
WHERE NOT EXISTS (SELECT 1 FROM roles r WHERE r.codigo = x.codigo);

-- ---------------------------------------------------------------------------
-- 2. Permisos que las rutas exigen y que faltaban en la tabla
--    Obtenidos comparando los `protectedRoute(..., 'permiso')` de
--    backend/public/index.php contra el contenido real de `permisos`.
-- ---------------------------------------------------------------------------
INSERT INTO permisos (codigo, nombre, descripcion, activo)
SELECT x.codigo, x.nombre, x.descripcion, 1
FROM (
    SELECT 'productos.ver'     AS codigo, 'Ver productos'      AS nombre, 'Permite consultar el catalogo de productos'   AS descripcion UNION ALL
    SELECT 'productos.crear',   'Crear productos',    'Permite crear productos'                       UNION ALL
    SELECT 'productos.editar',  'Editar productos',   'Permite editar productos'                      UNION ALL
    SELECT 'stock.ver',         'Ver stock',          'Permite consultar existencias'                 UNION ALL
    SELECT 'stock.editar',      'Editar stock',       'Permite editar existencias'                    UNION ALL
    SELECT 'stock.ajustar',     'Ajustar stock',      'Permite registrar ajustes de stock'            UNION ALL
    SELECT 'ventas.crear',      'Crear ventas',       'Permite registrar ventas'                      UNION ALL
    SELECT 'ventas.anular',     'Anular ventas',      'Permite anular ventas'                         UNION ALL
    SELECT 'compras.crear',     'Crear compras',      'Permite crear compras'                         UNION ALL
    SELECT 'compras.confirmar', 'Confirmar compras',  'Permite confirmar compras y afectar el stock'  UNION ALL
    SELECT 'reportes.ver',      'Ver reportes',       'Permite consultar reportes'                    UNION ALL
    SELECT 'cierres.crear',     'Crear cierres',      'Permite generar cierres diarios'
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

-- ---------------------------------------------------------------------------
-- 3. Otorgamientos
--    Mismas listas que 016_permisos_centralizados.sql, reaplicadas ahora que
--    los roles y los permisos ya existen.
-- ---------------------------------------------------------------------------

-- SUPER_ADMIN y ADMIN_EMPRESA: todo el catalogo de permisos.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
  );

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'auth.me','auth.logout','dashboard.ver','ventas.ver','ventas.crear','clientes.ver','clientes.crear',
    'productos.ver','stock.ver','cajas.ver','cajas.abrir','cajas.movimientos','cajas.cerrar',
    'documentos_tributarios.crear','documentos_tributarios.ver','documentos_tributarios.asignar_folio',
    'farmacia.ver'
)
WHERE r.codigo = 'CAJERO'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'auth.me','auth.logout','ventas.ver','ventas.crear','clientes.ver','clientes.crear','productos.ver','stock.ver',
    'farmacia.ver'
)
WHERE r.codigo = 'VENDEDOR'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'auth.me','auth.logout','productos.ver','stock.ver','stock.ajustar','stock.movimientos.ver',
    'compras.ver','compras.crear','compras.confirmar','documentos_ia.ver','documentos_ia.subir',
    'documentos_ia.procesar','documentos_ia.editar','documentos_ia.generar_compra',
    'stock.ubicaciones.administrar','compras_inteligentes.ver','compras_inteligentes.crear','farmacia.ver'
)
WHERE r.codigo = 'BODEGA'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'auth.me','auth.logout','reportes.ver','libros.ventas.ver','libros.compras.ver','libros.resumen_iva.ver',
    'documentos_tributarios.ver','compras.ver','ventas.ver','proveedores.ver','clientes.ver',
    'compras_inteligentes.ver','farmacia.ver'
)
WHERE r.codigo = 'CONTADOR'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'auth.me','auth.logout','auditoria.ver','reportes.ver','ventas.ver','compras.ver','stock.movimientos.ver',
    'libros.ventas.ver','libros.compras.ver','documentos_tributarios.ver',
    'compras_inteligentes.ver','farmacia.ver'
)
WHERE r.codigo = 'AUDITOR'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('100_seed_roles_permisos_base');
