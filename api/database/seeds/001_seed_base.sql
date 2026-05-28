INSERT INTO roles (codigo, nombre, descripcion) VALUES
('SUPER_ADMIN', 'Super administrador', 'Acceso total al sistema'),
('ADMIN_EMPRESA', 'Administrador de empresa', 'Administra una empresa y su configuracion'),
('CAJERO', 'Cajero', 'Opera ventas y caja'),
('VENDEDOR', 'Vendedor', 'Gestiona ventas y clientes'),
('BODEGA', 'Bodega', 'Gestiona stock y compras'),
('CONTADOR', 'Contador', 'Revisa documentos, cierres y reportes')
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    activo = 1;

INSERT INTO permisos (codigo, nombre, descripcion) VALUES
('ventas.crear', 'Crear ventas', 'Permite registrar ventas'),
('ventas.ver', 'Ver ventas', 'Permite consultar ventas'),
('ventas.anular', 'Anular ventas', 'Permite anular ventas'),
('productos.crear', 'Crear productos', 'Permite crear productos'),
('productos.editar', 'Editar productos', 'Permite editar productos'),
('productos.ver', 'Ver productos', 'Permite consultar productos'),
('stock.ver', 'Ver stock', 'Permite consultar stock'),
('stock.ajustar', 'Ajustar stock', 'Permite realizar ajustes de stock'),
('compras.crear', 'Crear compras', 'Permite registrar compras'),
('compras.confirmar', 'Confirmar compras', 'Permite confirmar compras'),
('clientes.gestionar', 'Gestionar clientes', 'Permite crear y editar clientes'),
('proveedores.gestionar', 'Gestionar proveedores', 'Permite crear y editar proveedores'),
('caja.abrir', 'Abrir caja', 'Permite abrir caja'),
('caja.cerrar', 'Cerrar caja', 'Permite cerrar caja'),
('cierres.crear', 'Crear cierres', 'Permite generar cierres diarios'),
('cierres.reabrir', 'Reabrir cierres', 'Permite reabrir cierres diarios'),
('reportes.ver', 'Ver reportes', 'Permite consultar reportes'),
('usuarios.gestionar', 'Gestionar usuarios', 'Permite administrar usuarios'),
('configuracion.gestionar', 'Gestionar configuracion', 'Permite administrar configuracion')
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion);

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permisos p
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
JOIN permisos p ON p.codigo IN ('ventas.crear', 'ventas.ver', 'productos.ver', 'stock.ver', 'clientes.gestionar', 'caja.abrir', 'caja.cerrar')
WHERE r.codigo = 'CAJERO';

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
JOIN permisos p ON p.codigo IN ('ventas.crear', 'ventas.ver', 'productos.ver', 'stock.ver', 'clientes.gestionar')
WHERE r.codigo = 'VENDEDOR';

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
JOIN permisos p ON p.codigo IN ('productos.crear', 'productos.editar', 'productos.ver', 'stock.ver', 'stock.ajustar', 'compras.crear', 'compras.confirmar', 'proveedores.gestionar')
WHERE r.codigo = 'BODEGA';

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
JOIN permisos p ON p.codigo IN ('ventas.ver', 'compras.crear', 'cierres.crear', 'cierres.reabrir', 'reportes.ver')
WHERE r.codigo = 'CONTADOR';

INSERT INTO metodos_pago (codigo, nombre) VALUES
('EFECTIVO', 'Efectivo'),
('DEBITO', 'Tarjeta de debito'),
('CREDITO', 'Tarjeta de credito'),
('TRANSFERENCIA', 'Transferencia'),
('QR', 'Pago QR'),
('CREDITO_CLIENTE', 'Credito cliente')
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    activo = 1;

INSERT INTO impuestos (codigo, nombre, tipo, porcentaje, monto_fijo) VALUES
('IVA_19', 'IVA 19%', 'PORCENTAJE', 1900, 0),
('EXENTO', 'Exento', 'EXENTO', 0, 0),
('ILA_GENERAL', 'Impuesto Ley Alcoholes general', 'PORCENTAJE', 0, 0),
('ESPECIFICO', 'Impuesto especifico', 'MONTO', 0, 0)
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    tipo = VALUES(tipo),
    porcentaje = VALUES(porcentaje),
    monto_fijo = VALUES(monto_fijo),
    activo = 1;

INSERT INTO empresas (razon_social, nombre_fantasia, rut, activo) VALUES
('Agentika Ingeniería y Soluciones Inteligentes SpA', 'MyPOS Demo', '76000000-0', 1)
ON DUPLICATE KEY UPDATE
    razon_social = VALUES(razon_social),
    nombre_fantasia = VALUES(nombre_fantasia),
    activo = 1;

INSERT INTO sucursales (empresa_id, nombre, codigo, activo)
SELECT e.id, 'Casa Matriz', 'CASA_MATRIZ', 1
FROM empresas e
WHERE e.rut = '76000000-0'
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    activo = 1;

INSERT INTO usuarios (nombre, email, password_hash, activo) VALUES
('Administrador MyPOS', 'admin@mypos.cl', '$2y$10$9k3Gn09cplHwGcAdI908WOUZ7I16bcpTMvkpGTrB0BiBdslenUWwW', 1)
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    password_hash = VALUES(password_hash),
    activo = 1;

INSERT INTO empresa_usuarios (empresa_id, usuario_id, rol_id, sucursal_id, activo)
SELECT e.id, u.id, r.id, s.id, 1
FROM empresas e
JOIN usuarios u ON u.email = 'admin@mypos.cl'
JOIN roles r ON r.codigo = 'ADMIN_EMPRESA'
JOIN sucursales s ON s.empresa_id = e.id AND s.codigo = 'CASA_MATRIZ'
WHERE e.rut = '76000000-0'
ON DUPLICATE KEY UPDATE
    rol_id = VALUES(rol_id),
    sucursal_id = VALUES(sucursal_id),
    activo = 1;

INSERT INTO cajas (empresa_id, sucursal_id, codigo, nombre, activo)
SELECT e.id, s.id, 'CAJA_1', 'Caja 1', 1
FROM empresas e
JOIN sucursales s ON s.empresa_id = e.id AND s.codigo = 'CASA_MATRIZ'
WHERE e.rut = '76000000-0'
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    activo = 1;
