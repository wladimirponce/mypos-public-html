-- Ambiente demo controlado para frontend.
-- Idempotente: no borra datos, no trunca tablas y no reduce stock existente.

SET @demo_empresa_rut := '76111111-1';
SET @demo_password_hash := '$2y$10$9k3Gn09cplHwGcAdI908WOUZ7I16bcpTMvkpGTrB0BiBdslenUWwW';

INSERT INTO empresas (
    rut, razon_social, nombre_fantasia, giro, email, telefono, direccion, comuna, ciudad, activo
) VALUES (
    @demo_empresa_rut,
    'MyPOS Demo Controlado SpA',
    'MyPOS Demo Controlado',
    'Comercio al por menor',
    'contacto.demo@mypos.cl',
    '+56911111111',
    'Av. Demo 123',
    'Santiago',
    'Santiago',
    1
) ON DUPLICATE KEY UPDATE
    razon_social = VALUES(razon_social),
    nombre_fantasia = VALUES(nombre_fantasia),
    giro = VALUES(giro),
    email = VALUES(email),
    telefono = VALUES(telefono),
    direccion = VALUES(direccion),
    comuna = VALUES(comuna),
    ciudad = VALUES(ciudad),
    activo = 1;

SELECT id INTO @demo_empresa_id
FROM empresas
WHERE rut = @demo_empresa_rut
LIMIT 1;

INSERT INTO sucursales (
    empresa_id, nombre, codigo, direccion, comuna, ciudad, telefono, activo
) VALUES (
    @demo_empresa_id,
    'Casa Matriz Demo',
    'DEMO_CASA_MATRIZ',
    'Av. Demo 123',
    'Santiago',
    'Santiago',
    '+56911111111',
    1
) ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    direccion = VALUES(direccion),
    comuna = VALUES(comuna),
    ciudad = VALUES(ciudad),
    telefono = VALUES(telefono),
    activo = 1;

SELECT id INTO @demo_sucursal_id
FROM sucursales
WHERE empresa_id = @demo_empresa_id
  AND codigo = 'DEMO_CASA_MATRIZ'
LIMIT 1;

INSERT INTO roles (codigo, nombre, descripcion, activo)
SELECT x.codigo, x.nombre, x.descripcion, 1
FROM (
    SELECT 'SUPER_ADMIN' AS codigo, 'Super administrador' AS nombre, 'Acceso global de plataforma' AS descripcion UNION ALL
    SELECT 'ADMIN_EMPRESA', 'Administrador empresa', 'Administracion completa de una empresa' UNION ALL
    SELECT 'CAJERO', 'Cajero', 'Operacion de caja y ventas POS' UNION ALL
    SELECT 'VENDEDOR', 'Vendedor', 'Venta y consulta comercial' UNION ALL
    SELECT 'BODEGUERO', 'Bodeguero', 'Compras, stock y documentos de bodega' UNION ALL
    SELECT 'CONTADOR', 'Contador', 'Libros, reportes y documentos tributarios' UNION ALL
    SELECT 'AUDITOR', 'Auditor', 'Consulta de auditoria y trazabilidad'
) x
WHERE NOT EXISTS (
    SELECT 1 FROM roles r WHERE r.codigo = x.codigo
);

INSERT INTO usuarios (nombre, email, password_hash, activo)
VALUES
    ('Admin Demo', 'admin.demo@mypos.cl', @demo_password_hash, 1),
    ('Cajero Demo', 'cajero.demo@mypos.cl', @demo_password_hash, 1),
    ('Vendedor Demo', 'vendedor.demo@mypos.cl', @demo_password_hash, 1),
    ('Bodega Demo', 'bodega.demo@mypos.cl', @demo_password_hash, 1),
    ('Contador Demo', 'contador.demo@mypos.cl', @demo_password_hash, 1),
    ('Auditor Demo', 'auditor.demo@mypos.cl', @demo_password_hash, 1)
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    password_hash = VALUES(password_hash),
    activo = 1;

INSERT INTO empresa_usuarios (empresa_id, usuario_id, rol_id, sucursal_id, activo)
SELECT @demo_empresa_id, u.id, r.id, @demo_sucursal_id, 1
FROM usuarios u
INNER JOIN roles r ON r.codigo = CASE u.email
    WHEN 'admin.demo@mypos.cl' THEN 'ADMIN_EMPRESA'
    WHEN 'cajero.demo@mypos.cl' THEN 'CAJERO'
    WHEN 'vendedor.demo@mypos.cl' THEN 'VENDEDOR'
    WHEN 'bodega.demo@mypos.cl' THEN 'BODEGA'
    WHEN 'contador.demo@mypos.cl' THEN 'CONTADOR'
    WHEN 'auditor.demo@mypos.cl' THEN 'AUDITOR'
END
WHERE u.email IN (
    'admin.demo@mypos.cl',
    'cajero.demo@mypos.cl',
    'vendedor.demo@mypos.cl',
    'bodega.demo@mypos.cl',
    'contador.demo@mypos.cl',
    'auditor.demo@mypos.cl'
)
ON DUPLICATE KEY UPDATE
    rol_id = VALUES(rol_id),
    sucursal_id = VALUES(sucursal_id),
    activo = 1;

SELECT id INTO @demo_admin_id
FROM usuarios
WHERE email = 'admin.demo@mypos.cl'
LIMIT 1;

INSERT INTO rubros (empresa_id, nombre, descripcion, activo)
VALUES
    (@demo_empresa_id, 'Bebidas Demo', 'Productos bebibles para POS demo', 1),
    (@demo_empresa_id, 'Abarrotes Demo', 'Abarrotes de rotacion demo', 1),
    (@demo_empresa_id, 'Accesorios Demo', 'Accesorios menores demo', 1)
ON DUPLICATE KEY UPDATE
    descripcion = VALUES(descripcion),
    activo = 1;

SELECT id INTO @rubro_bebidas_id FROM rubros WHERE empresa_id = @demo_empresa_id AND nombre = 'Bebidas Demo' LIMIT 1;
SELECT id INTO @rubro_abarrotes_id FROM rubros WHERE empresa_id = @demo_empresa_id AND nombre = 'Abarrotes Demo' LIMIT 1;
SELECT id INTO @rubro_accesorios_id FROM rubros WHERE empresa_id = @demo_empresa_id AND nombre = 'Accesorios Demo' LIMIT 1;

INSERT INTO centros_costo (empresa_id, codigo, nombre, descripcion, activo)
VALUES (
    @demo_empresa_id,
    'VENTAS_DEMO',
    'Ventas Demo',
    'Centro de costo para operaciones demo frontend',
    1
) ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    activo = 1;

SELECT id INTO @centro_ventas_demo_id
FROM centros_costo
WHERE empresa_id = @demo_empresa_id
  AND codigo = 'VENTAS_DEMO'
LIMIT 1;

INSERT INTO productos (
    empresa_id, rubro_id, centro_costo_id, sku, codigo, nombre, descripcion,
    unidad_medida, precio_costo, costo_actual, precio_venta, controla_stock,
    permite_fraccion, stock_minimo, permite_descuento, permite_comision, activo
) VALUES
    (@demo_empresa_id, @rubro_bebidas_id, @centro_ventas_demo_id, 'DEMO-BEB-001', 'DEMO-BEB-001', 'Agua mineral 500ml demo', 'Producto demo para venta POS', 'UN', 700, 700, 1490, 1, 0, 10.000, 1, 1, 1),
    (@demo_empresa_id, @rubro_abarrotes_id, @centro_ventas_demo_id, 'DEMO-CAF-001', 'DEMO-CAF-001', 'Cafe molido 250g demo', 'Producto demo para venta POS', 'UN', 2800, 2800, 4990, 1, 0, 5.000, 1, 1, 1),
    (@demo_empresa_id, @rubro_abarrotes_id, @centro_ventas_demo_id, 'DEMO-PAN-001', 'DEMO-PAN-001', 'Pan integral demo', 'Producto demo para venta POS', 'UN', 1200, 1200, 2290, 1, 0, 8.000, 1, 1, 1),
    (@demo_empresa_id, @rubro_accesorios_id, @centro_ventas_demo_id, 'DEMO-ACC-001', 'DEMO-ACC-001', 'Pack pilas AA demo', 'Producto demo para venta POS', 'UN', 2100, 2100, 3990, 1, 0, 5.000, 1, 1, 1)
ON DUPLICATE KEY UPDATE
    rubro_id = VALUES(rubro_id),
    centro_costo_id = VALUES(centro_costo_id),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    unidad_medida = VALUES(unidad_medida),
    precio_costo = VALUES(precio_costo),
    costo_actual = VALUES(costo_actual),
    precio_venta = VALUES(precio_venta),
    controla_stock = VALUES(controla_stock),
    permite_fraccion = VALUES(permite_fraccion),
    stock_minimo = VALUES(stock_minimo),
    permite_descuento = VALUES(permite_descuento),
    permite_comision = VALUES(permite_comision),
    activo = 1;

INSERT INTO productos_codigos_barra (
    empresa_id, producto_id, codigo_barra, tipo_codigo, descripcion, principal, activo
)
SELECT @demo_empresa_id, p.id, b.codigo_barra, 'BARRA', 'Codigo demo frontend', 1, 1
FROM productos p
INNER JOIN (
    SELECT 'DEMO-BEB-001' AS codigo, '7800000000017' AS codigo_barra UNION ALL
    SELECT 'DEMO-CAF-001', '7800000000024' UNION ALL
    SELECT 'DEMO-PAN-001', '7800000000031' UNION ALL
    SELECT 'DEMO-ACC-001', '7800000000048'
) b ON b.codigo = p.codigo
WHERE p.empresa_id = @demo_empresa_id
ON DUPLICATE KEY UPDATE
    producto_id = VALUES(producto_id),
    tipo_codigo = VALUES(tipo_codigo),
    descripcion = VALUES(descripcion),
    principal = 1,
    activo = 1;

INSERT INTO producto_impuestos (
    empresa_id, producto_id, impuesto_id, activo, orden_aplicacion, incluido_en_precio
)
SELECT @demo_empresa_id, p.id, i.id, 1, 1, 1
FROM productos p
INNER JOIN impuestos i ON i.codigo = 'IVA_19'
WHERE p.empresa_id = @demo_empresa_id
  AND p.codigo IN ('DEMO-BEB-001', 'DEMO-CAF-001', 'DEMO-PAN-001', 'DEMO-ACC-001')
ON DUPLICATE KEY UPDATE
    activo = 1,
    orden_aplicacion = VALUES(orden_aplicacion),
    incluido_en_precio = VALUES(incluido_en_precio);

INSERT INTO stock_sucursal (
    empresa_id, sucursal_id, producto_id, cantidad, reservado, stock_minimo
)
SELECT @demo_empresa_id, @demo_sucursal_id, p.id, s.cantidad, 0.000, s.stock_minimo
FROM productos p
INNER JOIN (
    SELECT 'DEMO-BEB-001' AS codigo, 100.000 AS cantidad, 10.000 AS stock_minimo UNION ALL
    SELECT 'DEMO-CAF-001', 50.000, 5.000 UNION ALL
    SELECT 'DEMO-PAN-001', 60.000, 8.000 UNION ALL
    SELECT 'DEMO-ACC-001', 25.000, 5.000
) s ON s.codigo = p.codigo
WHERE p.empresa_id = @demo_empresa_id
ON DUPLICATE KEY UPDATE
    cantidad = CASE WHEN stock_sucursal.cantidad < VALUES(cantidad) THEN VALUES(cantidad) ELSE stock_sucursal.cantidad END,
    reservado = 0.000,
    stock_minimo = VALUES(stock_minimo);

INSERT INTO clientes (
    empresa_id, tipo_cliente, rut, nombre, razon_social, nombre_fantasia, giro,
    email, telefono, direccion, comuna, ciudad, credito_habilitado,
    permite_credito, limite_credito, activo, observacion, deleted_at
) VALUES
    (@demo_empresa_id, 'PERSONA', '11111111-1', 'Cliente Persona Demo', 'Cliente Persona Demo', 'Cliente Persona Demo', NULL, 'cliente.persona.demo@mypos.cl', '+56922222222', 'Los Clientes 100', 'Santiago', 'Santiago', 1, 1, 100000, 1, 'Cliente demo con credito habilitado', NULL),
    (@demo_empresa_id, 'EMPRESA', '76000001-9', 'Cliente Empresa Demo', 'Cliente Empresa Demo SpA', 'Cliente Empresa Demo', 'Comercio', 'cliente.empresa.demo@mypos.cl', '+56933333333', 'Los Clientes 200', 'Providencia', 'Santiago', 0, 0, 0, 1, 'Cliente demo para factura', NULL)
ON DUPLICATE KEY UPDATE
    tipo_cliente = VALUES(tipo_cliente),
    nombre = VALUES(nombre),
    razon_social = VALUES(razon_social),
    nombre_fantasia = VALUES(nombre_fantasia),
    giro = VALUES(giro),
    email = VALUES(email),
    telefono = VALUES(telefono),
    direccion = VALUES(direccion),
    comuna = VALUES(comuna),
    ciudad = VALUES(ciudad),
    credito_habilitado = VALUES(credito_habilitado),
    permite_credito = VALUES(permite_credito),
    limite_credito = VALUES(limite_credito),
    activo = 1,
    observacion = VALUES(observacion),
    deleted_at = NULL;

INSERT INTO proveedores (
    empresa_id, rut, nombre, razon_social, nombre_fantasia, giro, email,
    telefono, direccion, comuna, ciudad, activo, observacion, deleted_at
) VALUES
    (@demo_empresa_id, '76123456-7', 'Proveedor Demo Controlado', 'Proveedor Demo Controlado SpA', 'Proveedor Demo', 'Distribuidora', 'proveedor.demo@mypos.cl', '+56944444444', 'Los Proveedores 100', 'Santiago', 'Santiago', 1, 'Proveedor demo para compras', NULL),
    (@demo_empresa_id, '76987654-3', 'Distribuidora Demo Norte', 'Distribuidora Demo Norte SpA', 'Demo Norte', 'Mayorista', 'norte.demo@mypos.cl', '+56955555555', 'Los Proveedores 200', 'Santiago', 'Santiago', 1, 'Proveedor demo secundario', NULL)
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    razon_social = VALUES(razon_social),
    nombre_fantasia = VALUES(nombre_fantasia),
    giro = VALUES(giro),
    email = VALUES(email),
    telefono = VALUES(telefono),
    direccion = VALUES(direccion),
    comuna = VALUES(comuna),
    ciudad = VALUES(ciudad),
    activo = 1,
    observacion = VALUES(observacion),
    deleted_at = NULL;

INSERT INTO cajas (empresa_id, sucursal_id, codigo, nombre, activo)
VALUES (@demo_empresa_id, @demo_sucursal_id, 'CAJA_DEMO_POS_1', 'Caja Demo POS 1', 1)
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    activo = 1;

SELECT id INTO @demo_caja_id
FROM cajas
WHERE empresa_id = @demo_empresa_id
  AND sucursal_id = @demo_sucursal_id
  AND codigo = 'CAJA_DEMO_POS_1'
LIMIT 1;

INSERT INTO dispositivos (
    empresa_id, sucursal_id, usuario_id, device_uuid, uuid_dispositivo,
    nombre, tipo, estado, activo, metadata_json
) VALUES (
    @demo_empresa_id,
    @demo_sucursal_id,
    @demo_admin_id,
    '00000000-0000-4000-8000-000000000001',
    'demo-pos-001',
    'POS Demo 001',
    'POS',
    'ACTIVO',
    1,
    JSON_OBJECT('app_version', 'demo', 'platform', 'frontend')
) ON DUPLICATE KEY UPDATE
    sucursal_id = VALUES(sucursal_id),
    usuario_id = VALUES(usuario_id),
    uuid_dispositivo = VALUES(uuid_dispositivo),
    nombre = VALUES(nombre),
    tipo = VALUES(tipo),
    estado = 'ACTIVO',
    activo = 1,
    metadata_json = VALUES(metadata_json);

SELECT id INTO @demo_dispositivo_id
FROM dispositivos
WHERE empresa_id = @demo_empresa_id
  AND uuid_dispositivo = 'demo-pos-001'
LIMIT 1;

INSERT INTO caf_archivos (
    empresa_id, tipo_documento, rut_emisor, razon_social_emisor,
    folio_desde, folio_hasta, fecha_autorizacion, fecha_vencimiento,
    archivo_path, caf_xml, estado, created_by_usuario_id
) VALUES
    (@demo_empresa_id, 'BOLETA', @demo_empresa_rut, 'MyPOS Demo Controlado SpA', 900000, 900999, '2026-01-01', '2026-12-31', NULL, NULL, 'ACTIVO', @demo_admin_id),
    (@demo_empresa_id, 'FACTURA', @demo_empresa_rut, 'MyPOS Demo Controlado SpA', 910000, 910199, '2026-01-01', '2026-12-31', NULL, NULL, 'ACTIVO', @demo_admin_id),
    (@demo_empresa_id, 'GUIA_DESPACHO', @demo_empresa_rut, 'MyPOS Demo Controlado SpA', 920000, 920199, '2026-01-01', '2026-12-31', NULL, NULL, 'ACTIVO', @demo_admin_id)
ON DUPLICATE KEY UPDATE
    rut_emisor = VALUES(rut_emisor),
    razon_social_emisor = VALUES(razon_social_emisor),
    fecha_autorizacion = VALUES(fecha_autorizacion),
    fecha_vencimiento = VALUES(fecha_vencimiento),
    estado = CASE WHEN estado = 'ANULADO' THEN estado ELSE 'ACTIVO' END,
    created_by_usuario_id = VALUES(created_by_usuario_id);

SELECT id INTO @caf_boleta_id FROM caf_archivos WHERE empresa_id = @demo_empresa_id AND tipo_documento = 'BOLETA' AND folio_desde = 900000 AND folio_hasta = 900999 LIMIT 1;
SELECT id INTO @caf_factura_id FROM caf_archivos WHERE empresa_id = @demo_empresa_id AND tipo_documento = 'FACTURA' AND folio_desde = 910000 AND folio_hasta = 910199 LIMIT 1;
SELECT id INTO @caf_guia_id FROM caf_archivos WHERE empresa_id = @demo_empresa_id AND tipo_documento = 'GUIA_DESPACHO' AND folio_desde = 920000 AND folio_hasta = 920199 LIMIT 1;

INSERT INTO folios_asignaciones (
    empresa_id, sucursal_id, caja_id, dispositivo_id, caf_id,
    tipo_documento, folio_desde, folio_hasta, folio_actual, alerta_minimo,
    estado, created_by_usuario_id
) VALUES
    (@demo_empresa_id, @demo_sucursal_id, @demo_caja_id, NULL, @caf_boleta_id, 'BOLETA', 900000, 900199, 899999, 20, 'ACTIVA', @demo_admin_id),
    (@demo_empresa_id, @demo_sucursal_id, @demo_caja_id, NULL, @caf_factura_id, 'FACTURA', 910000, 910049, 909999, 10, 'ACTIVA', @demo_admin_id),
    (@demo_empresa_id, @demo_sucursal_id, NULL, @demo_dispositivo_id, @caf_boleta_id, 'BOLETA', 900200, 900299, 900199, 10, 'ACTIVA', @demo_admin_id),
    (@demo_empresa_id, @demo_sucursal_id, NULL, NULL, @caf_guia_id, 'GUIA_DESPACHO', 920000, 920049, 919999, 10, 'ACTIVA', @demo_admin_id)
ON DUPLICATE KEY UPDATE
    caja_id = VALUES(caja_id),
    dispositivo_id = VALUES(dispositivo_id),
    caf_id = VALUES(caf_id),
    folio_actual = GREATEST(folios_asignaciones.folio_actual, VALUES(folio_actual)),
    alerta_minimo = VALUES(alerta_minimo),
    estado = CASE
        WHEN folios_asignaciones.estado IN ('ANULADA', 'AGOTADA') THEN folios_asignaciones.estado
        ELSE 'ACTIVA'
    END,
    created_by_usuario_id = VALUES(created_by_usuario_id);

INSERT INTO empresa_configuracion (
    empresa_id, rut_empresa, razon_social, nombre_fantasia, giro, email_contacto,
    telefono_contacto, direccion, comuna, ciudad, region, logo_url, sitio_web, metadata_json
) VALUES (
    @demo_empresa_id,
    @demo_empresa_rut,
    'MyPOS Demo Controlado SpA',
    'MyPOS Demo Controlado',
    'Comercio al por menor',
    'contacto.demo@mypos.cl',
    '+56911111111',
    'Av. Demo 123',
    'Santiago',
    'Santiago',
    'Metropolitana',
    NULL,
    'https://demo.mypos.cl',
    JSON_OBJECT('seed', '002_seed_demo_controlado')
) ON DUPLICATE KEY UPDATE
    rut_empresa = VALUES(rut_empresa),
    razon_social = VALUES(razon_social),
    nombre_fantasia = VALUES(nombre_fantasia),
    giro = VALUES(giro),
    email_contacto = VALUES(email_contacto),
    telefono_contacto = VALUES(telefono_contacto),
    direccion = VALUES(direccion),
    comuna = VALUES(comuna),
    ciudad = VALUES(ciudad),
    region = VALUES(region),
    sitio_web = VALUES(sitio_web),
    metadata_json = VALUES(metadata_json);

INSERT INTO empresa_configuracion_operativa (
    empresa_id, permitir_stock_negativo, exigir_caja_abierta_para_vender,
    permitir_venta_sin_cliente, permitir_credito_clientes, exigir_cliente_en_factura,
    tipo_documento_default, metodo_pago_default_id, alerta_stock_bajo_default,
    alerta_folios_bajos_default, dias_alerta_vencimiento_caf,
    ia_documentos_habilitada, documentos_tributarios_habilitados, modo_offline_habilitado
) SELECT
    @demo_empresa_id, 0, 0, 1, 1, 1, 'BOLETA', mp.id, 5.000, 10, 30, 1, 1, 1
FROM metodos_pago mp
WHERE mp.codigo = 'EFECTIVO'
LIMIT 1
ON DUPLICATE KEY UPDATE
    permitir_stock_negativo = VALUES(permitir_stock_negativo),
    exigir_caja_abierta_para_vender = VALUES(exigir_caja_abierta_para_vender),
    permitir_venta_sin_cliente = VALUES(permitir_venta_sin_cliente),
    permitir_credito_clientes = VALUES(permitir_credito_clientes),
    exigir_cliente_en_factura = VALUES(exigir_cliente_en_factura),
    tipo_documento_default = VALUES(tipo_documento_default),
    metodo_pago_default_id = VALUES(metodo_pago_default_id),
    alerta_stock_bajo_default = VALUES(alerta_stock_bajo_default),
    alerta_folios_bajos_default = VALUES(alerta_folios_bajos_default),
    dias_alerta_vencimiento_caf = VALUES(dias_alerta_vencimiento_caf),
    ia_documentos_habilitada = VALUES(ia_documentos_habilitada),
    documentos_tributarios_habilitados = VALUES(documentos_tributarios_habilitados),
    modo_offline_habilitado = VALUES(modo_offline_habilitado);

INSERT INTO sucursal_configuracion (
    empresa_id, sucursal_id, direccion, comuna, ciudad, telefono, email, activa,
    exigir_caja_abierta_para_vender, permitir_stock_negativo, tipo_documento_default,
    metadata_json
) VALUES (
    @demo_empresa_id,
    @demo_sucursal_id,
    'Av. Demo 123',
    'Santiago',
    'Santiago',
    '+56911111111',
    'sucursal.demo@mypos.cl',
    1,
    NULL,
    NULL,
    NULL,
    JSON_OBJECT('seed', '002_seed_demo_controlado')
) ON DUPLICATE KEY UPDATE
    direccion = VALUES(direccion),
    comuna = VALUES(comuna),
    ciudad = VALUES(ciudad),
    telefono = VALUES(telefono),
    email = VALUES(email),
    activa = 1,
    metadata_json = VALUES(metadata_json);

INSERT INTO dte_configuracion (
    empresa_id, modo, sistema_path, endpoint_cli, endpoint_http,
    salida_xml_dir, salida_pdf_dir, ambiente, activo, metadata_json
) VALUES (
    @demo_empresa_id,
    'SIMULADO',
    '',
    NULL,
    NULL,
    NULL,
    NULL,
    'CERTIFICACION',
    1,
    JSON_OBJECT('seed', '002_seed_demo_controlado')
) ON DUPLICATE KEY UPDATE
    modo = 'SIMULADO',
    ambiente = 'CERTIFICACION',
    activo = 1,
    metadata_json = VALUES(metadata_json);
