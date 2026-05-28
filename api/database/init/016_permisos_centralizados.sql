CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

CREATE PROCEDURE mypos_add_column_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_column_name VARCHAR(64),
    IN p_column_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
          AND column_name = p_column_name
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', p_table_name, ' ADD COLUMN ', p_column_sql);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL mypos_add_column_if_missing('permisos', 'activo', 'activo TINYINT(1) NOT NULL DEFAULT 1 AFTER descripcion');

DROP PROCEDURE IF EXISTS mypos_add_column_if_missing;

INSERT INTO roles (codigo, nombre, descripcion, activo)
SELECT 'AUDITOR', 'Auditor', 'Rol de consulta y auditoria', 1
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE codigo = 'AUDITOR');

INSERT INTO permisos (codigo, nombre, descripcion, activo)
SELECT x.codigo, x.nombre, x.descripcion, 1
FROM (
    SELECT 'auth.me' codigo, 'Ver sesion actual' nombre, 'Permite consultar el usuario autenticado' descripcion UNION ALL
    SELECT 'auth.logout', 'Cerrar sesion', 'Permite cerrar sesion' UNION ALL
    SELECT 'dashboard.ver', 'Ver dashboard', 'Permite consultar dashboard' UNION ALL
    SELECT 'ventas.ver', 'Ver ventas', 'Permite consultar ventas' UNION ALL
    SELECT 'compras.ver', 'Ver compras', 'Permite consultar compras' UNION ALL
    SELECT 'compras.anular', 'Anular compras', 'Permite anular compras en borrador' UNION ALL
    SELECT 'compras.reversar', 'Reversar compras', 'Permite reversar compras confirmadas' UNION ALL
    SELECT 'stock.movimientos.ver', 'Ver movimientos de stock', 'Permite consultar movimientos de stock' UNION ALL
    SELECT 'productos.eliminar', 'Eliminar productos', 'Permite desactivar productos' UNION ALL
    SELECT 'rubros.gestionar', 'Gestionar rubros', 'Permite crear, editar y desactivar rubros' UNION ALL
    SELECT 'centros_costo.gestionar', 'Gestionar centros de costo', 'Permite crear, editar y desactivar centros de costo' UNION ALL
    SELECT 'impuestos.gestionar', 'Gestionar impuestos de productos', 'Permite configurar impuestos de productos' UNION ALL
    SELECT 'descuentos.gestionar', 'Gestionar descuentos de productos', 'Permite configurar descuentos de productos' UNION ALL
    SELECT 'comisiones.gestionar', 'Gestionar comisiones de productos', 'Permite configurar comisiones de productos' UNION ALL
    SELECT 'cajas.ver', 'Ver cajas', 'Permite consultar cajas y cierres de caja' UNION ALL
    SELECT 'cajas.crear', 'Crear cajas', 'Permite crear cajas' UNION ALL
    SELECT 'cajas.abrir', 'Abrir cajas', 'Permite abrir cajas' UNION ALL
    SELECT 'cajas.movimientos', 'Movimientos de caja', 'Permite registrar movimientos de caja' UNION ALL
    SELECT 'cajas.cerrar', 'Cerrar cajas', 'Permite cerrar cajas' UNION ALL
    SELECT 'documentos_ia.ver', 'Ver documentos IA', 'Permite consultar documentos IA' UNION ALL
    SELECT 'documentos_ia.subir', 'Subir documentos IA', 'Permite registrar documentos IA' UNION ALL
    SELECT 'documentos_ia.procesar', 'Procesar documentos IA', 'Permite procesar documentos IA' UNION ALL
    SELECT 'documentos_ia.editar', 'Editar documentos IA', 'Permite editar documentos IA' UNION ALL
    SELECT 'documentos_ia.generar_compra', 'Generar compra desde IA', 'Permite generar compras desde documentos IA' UNION ALL
    SELECT 'documentos_ia.vincular_producto', 'Vincular producto IA', 'Permite vincular productos detectados por IA' UNION ALL
    SELECT 'documentos_tributarios.ver', 'Ver documentos tributarios', 'Permite consultar documentos tributarios internos' UNION ALL
    SELECT 'documentos_tributarios.crear', 'Crear documentos tributarios', 'Permite crear documentos desde ventas' UNION ALL
    SELECT 'documentos_tributarios.emitir_interno', 'Emitir documento interno', 'Permite marcar emision interna' UNION ALL
    SELECT 'documentos_tributarios.cambiar_estado_sii', 'Cambiar estado SII', 'Permite registrar estados SII simulados' UNION ALL
    SELECT 'documentos_tributarios.anular', 'Anular documento tributario', 'Permite anular documentos internos' UNION ALL
    SELECT 'documentos_tributarios.asignar_folio', 'Asignar folio a documento', 'Permite asignar folio funcional' UNION ALL
    SELECT 'folios.ver', 'Ver folios', 'Permite consultar CAF, asignaciones y consumos' UNION ALL
    SELECT 'folios.caf.crear', 'Crear CAF', 'Permite registrar CAF o rangos autorizados' UNION ALL
    SELECT 'folios.asignar', 'Asignar folios', 'Permite crear asignaciones de folios' UNION ALL
    SELECT 'folios.consumir', 'Consumir folios', 'Permite consumir folios' UNION ALL
    SELECT 'folios.alertas.ver', 'Ver alertas de folios', 'Permite ver alertas de folios y CAF' UNION ALL
    SELECT 'libros.ventas.ver', 'Ver libro de ventas', 'Permite consultar libro de ventas' UNION ALL
    SELECT 'libros.compras.ver', 'Ver libro de compras', 'Permite consultar libro de compras' UNION ALL
    SELECT 'libros.resumen_iva.ver', 'Ver resumen IVA', 'Permite consultar resumen IVA' UNION ALL
    SELECT 'clientes.ver', 'Ver clientes', 'Permite consultar clientes' UNION ALL
    SELECT 'clientes.crear', 'Crear clientes', 'Permite crear clientes' UNION ALL
    SELECT 'clientes.editar', 'Editar clientes', 'Permite editar clientes' UNION ALL
    SELECT 'clientes.eliminar', 'Eliminar clientes', 'Permite eliminar logicamente clientes' UNION ALL
    SELECT 'proveedores.ver', 'Ver proveedores', 'Permite consultar proveedores' UNION ALL
    SELECT 'proveedores.crear', 'Crear proveedores', 'Permite crear proveedores' UNION ALL
    SELECT 'proveedores.editar', 'Editar proveedores', 'Permite editar proveedores' UNION ALL
    SELECT 'proveedores.eliminar', 'Eliminar proveedores', 'Permite eliminar logicamente proveedores' UNION ALL
    SELECT 'creditos.ver', 'Ver creditos', 'Permite consultar creditos y estados de cuenta' UNION ALL
    SELECT 'creditos.pagar', 'Pagar creditos', 'Permite registrar pagos de creditos' UNION ALL
    SELECT 'auditoria.ver', 'Ver auditoria', 'Permite consultar auditoria' UNION ALL
    SELECT 'usuarios.ver', 'Ver usuarios', 'Permite consultar usuarios' UNION ALL
    SELECT 'roles.ver', 'Ver roles', 'Permite consultar roles' UNION ALL
    SELECT 'roles.gestionar', 'Gestionar roles', 'Permite administrar roles' UNION ALL
    SELECT 'permisos.ver', 'Ver permisos', 'Permite consultar permisos' UNION ALL
    SELECT 'configuracion.ver', 'Ver configuracion', 'Permite consultar configuracion' UNION ALL
    SELECT 'configuracion.editar', 'Editar configuracion', 'Permite editar configuracion'
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp
      WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
  );

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'auth.me','auth.logout','dashboard.ver','ventas.ver','ventas.crear','clientes.ver','clientes.crear',
    'productos.ver','stock.ver','cajas.ver','cajas.abrir','cajas.movimientos','cajas.cerrar',
    'documentos_tributarios.crear','documentos_tributarios.ver','documentos_tributarios.asignar_folio'
)
WHERE r.codigo = 'CAJERO'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'auth.me','auth.logout','ventas.ver','ventas.crear','clientes.ver','clientes.crear','productos.ver','stock.ver'
)
WHERE r.codigo = 'VENDEDOR'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'auth.me','auth.logout','productos.ver','stock.ver','stock.ajustar','stock.movimientos.ver',
    'compras.ver','compras.crear','compras.confirmar','documentos_ia.ver','documentos_ia.subir',
    'documentos_ia.procesar','documentos_ia.editar','documentos_ia.generar_compra'
)
WHERE r.codigo = 'BODEGA'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'auth.me','auth.logout','reportes.ver','libros.ventas.ver','libros.compras.ver','libros.resumen_iva.ver',
    'documentos_tributarios.ver','compras.ver','ventas.ver','proveedores.ver','clientes.ver'
)
WHERE r.codigo = 'CONTADOR'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'auth.me','auth.logout','auditoria.ver','reportes.ver','ventas.ver','compras.ver','stock.movimientos.ver',
    'libros.ventas.ver','libros.compras.ver','documentos_tributarios.ver'
)
WHERE r.codigo = 'AUDITOR'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('016_permisos_centralizados');

