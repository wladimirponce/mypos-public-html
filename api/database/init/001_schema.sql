CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(190) NOT NULL,
    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    razon_social VARCHAR(190) NOT NULL,
    nombre_fantasia VARCHAR(190) NOT NULL,
    rut VARCHAR(20) NOT NULL,
    giro VARCHAR(190) NULL,
    email VARCHAR(190) NULL,
    telefono VARCHAR(40) NULL,
    direccion VARCHAR(255) NULL,
    comuna VARCHAR(100) NULL,
    ciudad VARCHAR(100) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empresas_rut (rut),
    KEY idx_empresas_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sucursales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    nombre VARCHAR(160) NOT NULL,
    codigo VARCHAR(50) NULL,
    direccion VARCHAR(255) NULL,
    comuna VARCHAR(100) NULL,
    ciudad VARCHAR(100) NULL,
    telefono VARCHAR(40) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sucursales_empresa_nombre (empresa_id, nombre),
    UNIQUE KEY uq_sucursales_empresa_codigo (empresa_id, codigo),
    KEY idx_sucursales_empresa_activo (empresa_id, activo),
    CONSTRAINT fk_sucursales_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email (email),
    KEY idx_usuarios_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(80) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permisos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(120) NOT NULL,
    nombre VARCHAR(160) NOT NULL,
    descripcion VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permisos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rol_permisos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rol_id BIGINT UNSIGNED NOT NULL,
    permiso_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rol_permisos_rol_permiso (rol_id, permiso_id),
    KEY idx_rol_permisos_permiso (permiso_id),
    CONSTRAINT fk_rol_permisos_rol FOREIGN KEY (rol_id) REFERENCES roles (id),
    CONSTRAINT fk_rol_permisos_permiso FOREIGN KEY (permiso_id) REFERENCES permisos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_usuarios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    rol_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empresa_usuarios_empresa_usuario (empresa_id, usuario_id),
    KEY idx_empresa_usuarios_usuario (usuario_id),
    KEY idx_empresa_usuarios_rol (rol_id),
    KEY idx_empresa_usuarios_sucursal (sucursal_id),
    CONSTRAINT fk_empresa_usuarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_empresa_usuarios_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_empresa_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles (id),
    CONSTRAINT fk_empresa_usuarios_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clientes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    rut VARCHAR(20) NULL,
    razon_social VARCHAR(190) NOT NULL,
    nombre_fantasia VARCHAR(190) NULL,
    email VARCHAR(190) NULL,
    telefono VARCHAR(40) NULL,
    direccion VARCHAR(255) NULL,
    comuna VARCHAR(100) NULL,
    ciudad VARCHAR(100) NULL,
    credito_habilitado TINYINT(1) NOT NULL DEFAULT 0,
    limite_credito BIGINT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clientes_empresa_rut (empresa_id, rut),
    KEY idx_clientes_empresa_nombre (empresa_id, razon_social),
    KEY idx_clientes_empresa_activo (empresa_id, activo),
    CONSTRAINT fk_clientes_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proveedores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    rut VARCHAR(20) NULL,
    razon_social VARCHAR(190) NOT NULL,
    nombre_fantasia VARCHAR(190) NULL,
    email VARCHAR(190) NULL,
    telefono VARCHAR(40) NULL,
    direccion VARCHAR(255) NULL,
    comuna VARCHAR(100) NULL,
    ciudad VARCHAR(100) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_proveedores_empresa_rut (empresa_id, rut),
    KEY idx_proveedores_empresa_nombre (empresa_id, razon_social),
    KEY idx_proveedores_empresa_activo (empresa_id, activo),
    CONSTRAINT fk_proveedores_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rubros (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    nombre VARCHAR(160) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rubros_empresa_nombre (empresa_id, nombre),
    KEY idx_rubros_empresa_activo (empresa_id, activo),
    CONSTRAINT fk_rubros_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS centros_costo (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(50) NOT NULL,
    nombre VARCHAR(160) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_centros_costo_empresa_codigo (empresa_id, codigo),
    KEY idx_centros_costo_empresa_activo (empresa_id, activo),
    CONSTRAINT fk_centros_costo_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS productos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    rubro_id BIGINT UNSIGNED NULL,
    centro_costo_id BIGINT UNSIGNED NULL,
    sku VARCHAR(80) NULL,
    nombre VARCHAR(190) NOT NULL,
    descripcion TEXT NULL,
    unidad_medida VARCHAR(30) NOT NULL DEFAULT 'UN',
    precio_venta BIGINT NOT NULL DEFAULT 0,
    costo_actual BIGINT NOT NULL DEFAULT 0,
    controla_stock TINYINT(1) NOT NULL DEFAULT 1,
    permite_fraccion TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_productos_empresa_sku (empresa_id, sku),
    KEY idx_productos_empresa_nombre (empresa_id, nombre),
    KEY idx_productos_empresa_activo (empresa_id, activo),
    KEY idx_productos_rubro (rubro_id),
    KEY idx_productos_centro_costo (centro_costo_id),
    CONSTRAINT fk_productos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_productos_rubro FOREIGN KEY (rubro_id) REFERENCES rubros (id),
    CONSTRAINT fk_productos_centro_costo FOREIGN KEY (centro_costo_id) REFERENCES centros_costo (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS productos_codigos_barra (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    codigo_barra VARCHAR(80) NOT NULL,
    descripcion VARCHAR(160) NULL,
    principal TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_productos_codigos_empresa_codigo (empresa_id, codigo_barra),
    KEY idx_productos_codigos_producto (producto_id),
    KEY idx_productos_codigos_busqueda (empresa_id, codigo_barra, activo),
    CONSTRAINT fk_productos_codigos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_productos_codigos_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS productos_imagenes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    producto_codigo_barra_id BIGINT UNSIGNED NULL,
    ruta VARCHAR(255) NOT NULL,
    principal TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_productos_imagenes_producto (producto_id),
    KEY idx_productos_imagenes_codigo (producto_codigo_barra_id),
    CONSTRAINT fk_productos_imagenes_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_productos_imagenes_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT fk_productos_imagenes_codigo FOREIGN KEY (producto_codigo_barra_id) REFERENCES productos_codigos_barra (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS impuestos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(80) NOT NULL,
    nombre VARCHAR(160) NOT NULL,
    tipo VARCHAR(40) NOT NULL,
    porcentaje BIGINT NOT NULL DEFAULT 0,
    monto_fijo BIGINT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_impuestos_codigo (codigo),
    KEY idx_impuestos_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS producto_impuestos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    impuesto_id BIGINT UNSIGNED NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    vigencia_desde DATE NULL,
    vigencia_hasta DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_producto_impuestos_producto_impuesto (producto_id, impuesto_id),
    KEY idx_producto_impuestos_empresa_producto (empresa_id, producto_id, activo),
    KEY idx_producto_impuestos_impuesto (impuesto_id),
    CONSTRAINT fk_producto_impuestos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_producto_impuestos_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT fk_producto_impuestos_impuesto FOREIGN KEY (impuesto_id) REFERENCES impuestos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS productos_descuentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    valor BIGINT NOT NULL,
    fecha_inicio DATETIME NULL,
    fecha_fin DATETIME NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_productos_descuentos_producto_activo (empresa_id, producto_id, activo),
    CONSTRAINT fk_productos_descuentos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_productos_descuentos_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT chk_productos_descuentos_tipo CHECK (tipo IN ('PORCENTAJE', 'MONTO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS productos_comisiones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    valor BIGINT NOT NULL,
    fecha_inicio DATETIME NULL,
    fecha_fin DATETIME NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_productos_comisiones_producto_activo (empresa_id, producto_id, activo),
    CONSTRAINT fk_productos_comisiones_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_productos_comisiones_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT chk_productos_comisiones_tipo CHECK (tipo IN ('PORCENTAJE', 'MONTO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_sucursal (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    stock_minimo DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_sucursal_producto (empresa_id, sucursal_id, producto_id),
    KEY idx_stock_sucursal_producto (empresa_id, producto_id),
    CONSTRAINT fk_stock_sucursal_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_stock_sucursal_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_stock_sucursal_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS metodos_pago (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(80) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_metodos_pago_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cajas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(50) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cajas_sucursal_codigo (empresa_id, sucursal_id, codigo),
    KEY idx_cajas_sucursal_activo (empresa_id, sucursal_id, activo),
    CONSTRAINT fk_cajas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_cajas_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS caja_aperturas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    caja_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    fecha_apertura DATETIME NOT NULL,
    monto_inicial BIGINT NOT NULL DEFAULT 0,
    estado VARCHAR(20) NOT NULL DEFAULT 'ABIERTA',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_caja_aperturas_caja_estado (empresa_id, sucursal_id, caja_id, estado),
    KEY idx_caja_aperturas_usuario (usuario_id),
    CONSTRAINT fk_caja_aperturas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_caja_aperturas_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_caja_aperturas_caja FOREIGN KEY (caja_id) REFERENCES cajas (id),
    CONSTRAINT fk_caja_aperturas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT chk_caja_aperturas_estado CHECK (estado IN ('ABIERTA', 'CERRADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS caja_cierres (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    caja_id BIGINT UNSIGNED NOT NULL,
    apertura_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    fecha_cierre DATETIME NOT NULL,
    monto_declarado BIGINT NOT NULL DEFAULT 0,
    monto_sistema BIGINT NOT NULL DEFAULT 0,
    diferencia BIGINT NOT NULL DEFAULT 0,
    observacion VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_caja_cierres_apertura (apertura_id),
    KEY idx_caja_cierres_caja_fecha (empresa_id, sucursal_id, caja_id, fecha_cierre),
    CONSTRAINT fk_caja_cierres_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_caja_cierres_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_caja_cierres_caja FOREIGN KEY (caja_id) REFERENCES cajas (id),
    CONSTRAINT fk_caja_cierres_apertura FOREIGN KEY (apertura_id) REFERENCES caja_aperturas (id),
    CONSTRAINT fk_caja_cierres_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ventas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    caja_id BIGINT UNSIGNED NOT NULL,
    apertura_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    cliente_id BIGINT UNSIGNED NULL,
    tipo_venta VARCHAR(30) NOT NULL,
    folio VARCHAR(50) NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'REGISTRADA',
    subtotal BIGINT NOT NULL DEFAULT 0,
    descuento_total BIGINT NOT NULL DEFAULT 0,
    impuesto_total BIGINT NOT NULL DEFAULT 0,
    total BIGINT NOT NULL DEFAULT 0,
    margen_total BIGINT NOT NULL DEFAULT 0,
    comision_total BIGINT NOT NULL DEFAULT 0,
    fecha_venta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ventas_empresa_fecha (empresa_id, fecha_venta),
    KEY idx_ventas_sucursal_fecha (empresa_id, sucursal_id, fecha_venta),
    KEY idx_ventas_cliente (cliente_id),
    KEY idx_ventas_usuario (usuario_id),
    KEY idx_ventas_estado (empresa_id, estado),
    CONSTRAINT fk_ventas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_ventas_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_ventas_caja FOREIGN KEY (caja_id) REFERENCES cajas (id),
    CONSTRAINT fk_ventas_apertura FOREIGN KEY (apertura_id) REFERENCES caja_aperturas (id),
    CONSTRAINT fk_ventas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_ventas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id),
    CONSTRAINT chk_ventas_tipo CHECK (tipo_venta IN ('BOLETA', 'FACTURA', 'GUIA_DESPACHO', 'NOTA_VENTA')),
    CONSTRAINT chk_ventas_estado CHECK (estado IN ('REGISTRADA', 'ANULADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS venta_detalles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    venta_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    linea SMALLINT UNSIGNED NOT NULL,
    codigo_producto VARCHAR(80) NULL,
    codigo_barra_usado VARCHAR(80) NULL,
    nombre_producto VARCHAR(190) NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL,
    precio_unitario BIGINT NOT NULL DEFAULT 0,
    costo_unitario BIGINT NOT NULL DEFAULT 0,
    subtotal BIGINT NOT NULL DEFAULT 0,
    descuento_total BIGINT NOT NULL DEFAULT 0,
    impuesto_total BIGINT NOT NULL DEFAULT 0,
    total BIGINT NOT NULL DEFAULT 0,
    margen_total BIGINT NOT NULL DEFAULT 0,
    comision_total BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_venta_detalles_linea (venta_id, linea),
    KEY idx_venta_detalles_producto (empresa_id, producto_id),
    CONSTRAINT fk_venta_detalles_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_venta_detalles_venta FOREIGN KEY (venta_id) REFERENCES ventas (id),
    CONSTRAINT fk_venta_detalles_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS venta_detalle_impuestos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    venta_id BIGINT UNSIGNED NOT NULL,
    venta_detalle_id BIGINT UNSIGNED NOT NULL,
    impuesto_id BIGINT UNSIGNED NOT NULL,
    codigo_impuesto VARCHAR(80) NOT NULL,
    nombre_impuesto VARCHAR(160) NOT NULL,
    tipo_impuesto VARCHAR(40) NOT NULL,
    porcentaje BIGINT NOT NULL DEFAULT 0,
    monto_fijo BIGINT NOT NULL DEFAULT 0,
    base_calculo BIGINT NOT NULL DEFAULT 0,
    monto_impuesto BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_venta_detalle_impuestos_detalle (venta_detalle_id),
    KEY idx_venta_detalle_impuestos_venta (empresa_id, venta_id),
    CONSTRAINT fk_venta_detalle_impuestos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_venta_detalle_impuestos_venta FOREIGN KEY (venta_id) REFERENCES ventas (id),
    CONSTRAINT fk_venta_detalle_impuestos_detalle FOREIGN KEY (venta_detalle_id) REFERENCES venta_detalles (id),
    CONSTRAINT fk_venta_detalle_impuestos_impuesto FOREIGN KEY (impuesto_id) REFERENCES impuestos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS venta_pagos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    venta_id BIGINT UNSIGNED NOT NULL,
    metodo_pago_id BIGINT UNSIGNED NOT NULL,
    metodo_pago_codigo VARCHAR(80) NOT NULL,
    monto BIGINT NOT NULL,
    referencia VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_venta_pagos_venta (venta_id),
    KEY idx_venta_pagos_metodo_fecha (empresa_id, metodo_pago_id, created_at),
    CONSTRAINT fk_venta_pagos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_venta_pagos_venta FOREIGN KEY (venta_id) REFERENCES ventas (id),
    CONSTRAINT fk_venta_pagos_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documentos_emitidos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    venta_id BIGINT UNSIGNED NULL,
    tipo_documento VARCHAR(40) NOT NULL,
    folio VARCHAR(50) NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'EMITIDO',
    total BIGINT NOT NULL DEFAULT 0,
    fecha_emision DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payload_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documentos_emitidos_empresa_tipo_folio (empresa_id, tipo_documento, folio),
    KEY idx_documentos_emitidos_venta (venta_id),
    KEY idx_documentos_emitidos_fecha (empresa_id, fecha_emision),
    CONSTRAINT fk_documentos_emitidos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_documentos_emitidos_venta FOREIGN KEY (venta_id) REFERENCES ventas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compras (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    proveedor_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    tipo_documento VARCHAR(40) NOT NULL,
    folio VARCHAR(50) NULL,
    fecha_documento DATE NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'CONFIRMADA',
    subtotal BIGINT NOT NULL DEFAULT 0,
    impuesto_total BIGINT NOT NULL DEFAULT 0,
    total BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_compras_empresa_fecha (empresa_id, fecha_documento),
    KEY idx_compras_proveedor (proveedor_id),
    KEY idx_compras_sucursal (empresa_id, sucursal_id),
    CONSTRAINT fk_compras_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_compras_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_compras_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id),
    CONSTRAINT fk_compras_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compra_detalles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    compra_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NULL,
    linea SMALLINT UNSIGNED NOT NULL,
    codigo_producto VARCHAR(80) NULL,
    codigo_barra_usado VARCHAR(80) NULL,
    nombre_producto VARCHAR(190) NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL,
    costo_unitario BIGINT NOT NULL DEFAULT 0,
    impuesto_total BIGINT NOT NULL DEFAULT 0,
    total BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_compra_detalles_linea (compra_id, linea),
    KEY idx_compra_detalles_producto (empresa_id, producto_id),
    CONSTRAINT fk_compra_detalles_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_compra_detalles_compra FOREIGN KEY (compra_id) REFERENCES compras (id),
    CONSTRAINT fk_compra_detalles_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documentos_ia (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    proveedor_id BIGINT UNSIGNED NULL,
    compra_id BIGINT UNSIGNED NULL,
    tipo_documento VARCHAR(40) NULL,
    folio VARCHAR(50) NULL,
    archivo_ruta VARCHAR(255) NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
    json_extraido JSON NULL,
    json_editado JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_documentos_ia_empresa_estado (empresa_id, estado),
    KEY idx_documentos_ia_sucursal_fecha (empresa_id, sucursal_id, created_at),
    CONSTRAINT fk_documentos_ia_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_documentos_ia_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_documentos_ia_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_documentos_ia_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id),
    CONSTRAINT fk_documentos_ia_compra FOREIGN KEY (compra_id) REFERENCES compras (id),
    CONSTRAINT chk_documentos_ia_estado CHECK (estado IN ('PENDIENTE', 'PROCESADO', 'EDITADO', 'CONFIRMADO', 'ERROR'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documentos_ia_detalles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    documento_ia_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NULL,
    linea SMALLINT UNSIGNED NOT NULL,
    codigo_detectado VARCHAR(80) NULL,
    nombre_detectado VARCHAR(190) NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    costo_unitario BIGINT NOT NULL DEFAULT 0,
    total BIGINT NOT NULL DEFAULT 0,
    confianza DECIMAL(5,4) NULL,
    requiere_revision TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documentos_ia_detalles_linea (documento_ia_id, linea),
    KEY idx_documentos_ia_detalles_producto (empresa_id, producto_id),
    CONSTRAINT fk_documentos_ia_detalles_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_documentos_ia_detalles_documento FOREIGN KEY (documento_ia_id) REFERENCES documentos_ia (id),
    CONSTRAINT fk_documentos_ia_detalles_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movimientos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    tipo_movimiento VARCHAR(40) NOT NULL,
    referencia_tipo VARCHAR(40) NULL,
    referencia_id BIGINT UNSIGNED NULL,
    cantidad DECIMAL(14,3) NOT NULL,
    stock_anterior DECIMAL(14,3) NOT NULL,
    stock_nuevo DECIMAL(14,3) NOT NULL,
    costo_unitario BIGINT NOT NULL DEFAULT 0,
    observacion VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stock_movimientos_producto_fecha (empresa_id, sucursal_id, producto_id, created_at),
    KEY idx_stock_movimientos_referencia (empresa_id, referencia_tipo, referencia_id),
    KEY idx_stock_movimientos_usuario (usuario_id),
    CONSTRAINT fk_stock_movimientos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_stock_movimientos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_stock_movimientos_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT fk_stock_movimientos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cierres_diarios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    fecha_cierre DATE NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'CERRADO',
    total_ventas BIGINT NOT NULL DEFAULT 0,
    total_descuentos BIGINT NOT NULL DEFAULT 0,
    total_impuestos BIGINT NOT NULL DEFAULT 0,
    total_margen BIGINT NOT NULL DEFAULT 0,
    total_comisiones BIGINT NOT NULL DEFAULT 0,
    cantidad_ventas INT UNSIGNED NOT NULL DEFAULT 0,
    cerrado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reabierto_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cierres_diarios_empresa_sucursal_fecha (empresa_id, sucursal_id, fecha_cierre),
    KEY idx_cierres_diarios_estado (empresa_id, estado),
    CONSTRAINT fk_cierres_diarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_cierres_diarios_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_cierres_diarios_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT chk_cierres_diarios_estado CHECK (estado IN ('CERRADO', 'REABIERTO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cierre_resumen_pagos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    cierre_diario_id BIGINT UNSIGNED NOT NULL,
    metodo_pago_id BIGINT UNSIGNED NOT NULL,
    metodo_pago_codigo VARCHAR(80) NOT NULL,
    cantidad_operaciones INT UNSIGNED NOT NULL DEFAULT 0,
    total BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cierre_resumen_pagos (cierre_diario_id, metodo_pago_id),
    CONSTRAINT fk_cierre_resumen_pagos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_cierre_resumen_pagos_cierre FOREIGN KEY (cierre_diario_id) REFERENCES cierres_diarios (id),
    CONSTRAINT fk_cierre_resumen_pagos_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cierre_resumen_productos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    cierre_diario_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    codigo_producto VARCHAR(80) NULL,
    nombre_producto VARCHAR(190) NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    total BIGINT NOT NULL DEFAULT 0,
    margen_total BIGINT NOT NULL DEFAULT 0,
    comision_total BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cierre_resumen_productos (cierre_diario_id, producto_id),
    KEY idx_cierre_resumen_productos_producto (empresa_id, producto_id),
    CONSTRAINT fk_cierre_resumen_productos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_cierre_resumen_productos_cierre FOREIGN KEY (cierre_diario_id) REFERENCES cierres_diarios (id),
    CONSTRAINT fk_cierre_resumen_productos_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cierre_resumen_rubros (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    cierre_diario_id BIGINT UNSIGNED NOT NULL,
    rubro_id BIGINT UNSIGNED NULL,
    nombre_rubro VARCHAR(160) NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    total BIGINT NOT NULL DEFAULT 0,
    margen_total BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cierre_resumen_rubros (cierre_diario_id, rubro_id),
    CONSTRAINT fk_cierre_resumen_rubros_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_cierre_resumen_rubros_cierre FOREIGN KEY (cierre_diario_id) REFERENCES cierres_diarios (id),
    CONSTRAINT fk_cierre_resumen_rubros_rubro FOREIGN KEY (rubro_id) REFERENCES rubros (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cierre_resumen_usuarios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    cierre_diario_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    nombre_usuario VARCHAR(160) NOT NULL,
    cantidad_ventas INT UNSIGNED NOT NULL DEFAULT 0,
    total BIGINT NOT NULL DEFAULT 0,
    margen_total BIGINT NOT NULL DEFAULT 0,
    comision_total BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cierre_resumen_usuarios (cierre_diario_id, usuario_id),
    CONSTRAINT fk_cierre_resumen_usuarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_cierre_resumen_usuarios_cierre FOREIGN KEY (cierre_diario_id) REFERENCES cierres_diarios (id),
    CONSTRAINT fk_cierre_resumen_usuarios_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditoria_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NULL,
    entidad VARCHAR(80) NOT NULL,
    entidad_id BIGINT UNSIGNED NULL,
    accion VARCHAR(80) NOT NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_auditoria_empresa_fecha (empresa_id, created_at),
    KEY idx_auditoria_usuario_fecha (usuario_id, created_at),
    KEY idx_auditoria_entidad (entidad, entidad_id),
    CONSTRAINT fk_auditoria_eventos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_auditoria_eventos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('001_schema');
