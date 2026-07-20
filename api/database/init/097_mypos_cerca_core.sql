-- MyPOS Cerca: presencia digital, catalogo publico, cotizaciones, reservas y pagos multipasarela.

CREATE TABLE IF NOT EXISTS presencia_digital_perfiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(190) NOT NULL,
    nombre_publico VARCHAR(190) NOT NULL,
    descripcion TEXT NULL,
    categoria VARCHAR(100) NULL,
    logo_url VARCHAR(500) NULL,
    portada_url VARCHAR(500) NULL,
    telefono_publico VARCHAR(40) NULL,
    whatsapp VARCHAR(40) NULL,
    direccion_publica VARCHAR(255) NULL,
    comuna VARCHAR(100) NULL,
    ciudad VARCHAR(100) NULL,
    latitud DECIMAL(10,7) NULL,
    longitud DECIMAL(10,7) NULL,
    permite_retiro TINYINT(1) NOT NULL DEFAULT 1,
    permite_despacho TINYINT(1) NOT NULL DEFAULT 0,
    permite_cotizaciones TINYINT(1) NOT NULL DEFAULT 1,
    permite_reservas TINYINT(1) NOT NULL DEFAULT 0,
    anticipo_tipo VARCHAR(20) NOT NULL DEFAULT 'PORCENTAJE',
    anticipo_valor BIGINT NOT NULL DEFAULT 30,
    permite_pago_superior TINYINT(1) NOT NULL DEFAULT 1,
    reserva_expira_minutos SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    publicado TINYINT(1) NOT NULL DEFAULT 0,
    cerrado_temporalmente TINYINT(1) NOT NULL DEFAULT 0,
    privacidad_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_presencia_perfil_sucursal (empresa_id, sucursal_id),
    UNIQUE KEY uq_presencia_perfil_slug (slug),
    KEY idx_presencia_geo (publicado, latitud, longitud),
    CONSTRAINT fk_presencia_perfil_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_presencia_perfil_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presencia_digital_horarios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    perfil_id BIGINT UNSIGNED NOT NULL,
    dia_semana TINYINT UNSIGNED NOT NULL,
    apertura TIME NULL,
    cierre TIME NULL,
    cerrado TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_presencia_horario (perfil_id, dia_semana),
    KEY idx_presencia_horario_empresa (empresa_id, perfil_id),
    CONSTRAINT fk_presencia_horario_perfil FOREIGN KEY (perfil_id) REFERENCES presencia_digital_perfiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presencia_digital_productos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(190) NOT NULL,
    nombre_publico VARCHAR(190) NULL,
    descripcion_publica TEXT NULL,
    imagen_url VARCHAR(500) NULL,
    palabras_busqueda VARCHAR(500) NULL,
    categoria_publica VARCHAR(100) NULL,
    mostrar_precio TINYINT(1) NOT NULL DEFAULT 1,
    precio_online BIGINT NULL,
    stock_protegido DECIMAL(14,3) NOT NULL DEFAULT 0.000,
    mostrar_agotado TINYINT(1) NOT NULL DEFAULT 0,
    permite_cotizacion TINYINT(1) NOT NULL DEFAULT 1,
    permite_reserva TINYINT(1) NOT NULL DEFAULT 0,
    exige_pago_total TINYINT(1) NOT NULL DEFAULT 0,
    regulacion VARCHAR(30) NOT NULL DEFAULT 'VENTA_DIRECTA',
    publicado TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_presencia_producto (empresa_id, sucursal_id, producto_id),
    UNIQUE KEY uq_presencia_producto_slug (slug),
    KEY idx_presencia_producto_publico (sucursal_id, publicado, categoria_publica),
    FULLTEXT KEY ft_presencia_producto_busqueda (nombre_publico, descripcion_publica, palabras_busqueda),
    CONSTRAINT fk_presencia_producto_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_presencia_producto_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
    CONSTRAINT fk_presencia_producto_producto FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presencia_stock_snapshots (
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    estado VARCHAR(20) NOT NULL,
    precio_publico BIGINT NULL,
    confianza VARCHAR(10) NOT NULL DEFAULT 'MEDIA',
    actualizado_at DATETIME NOT NULL,
    PRIMARY KEY (empresa_id, sucursal_id, producto_id),
    KEY idx_presencia_snapshot_busqueda (sucursal_id, estado, actualizado_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presencia_cotizaciones_publicas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(32) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'SOLICITADA',
    nombre_cliente VARCHAR(160) NOT NULL,
    email_cliente VARCHAR(190) NOT NULL,
    telefono_cliente VARCHAR(40) NULL,
    mensaje VARCHAR(500) NULL,
    cotizacion_id BIGINT UNSIGNED NULL,
    expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_presencia_cot_codigo (codigo),
    KEY idx_presencia_cot_empresa (empresa_id, estado, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presencia_cotizaciones_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    solicitud_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL,
    nombre_snapshot VARCHAR(190) NOT NULL,
    precio_snapshot BIGINT NULL,
    PRIMARY KEY (id),
    KEY idx_presencia_cot_items (solicitud_id),
    CONSTRAINT fk_presencia_cot_item_solicitud FOREIGN KEY (solicitud_id) REFERENCES presencia_cotizaciones_publicas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presencia_reservas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE_PAGO',
    nombre_cliente VARCHAR(160) NOT NULL,
    email_cliente VARCHAR(190) NOT NULL,
    telefono_cliente VARCHAR(40) NULL,
    subtotal BIGINT NOT NULL,
    anticipo_minimo BIGINT NOT NULL,
    pagado BIGINT NOT NULL DEFAULT 0,
    saldo_pendiente BIGINT NOT NULL,
    expires_at DATETIME NOT NULL,
    venta_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_presencia_reserva_codigo (codigo),
    UNIQUE KEY uq_presencia_reserva_token (token_hash),
    UNIQUE KEY uq_presencia_reserva_idempotencia (empresa_id, idempotency_key),
    KEY idx_presencia_reserva_empresa (empresa_id, estado, created_at),
    KEY idx_presencia_reserva_expira (estado, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presencia_reserva_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    reserva_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    ubicacion_id BIGINT UNSIGNED NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL,
    precio_unitario BIGINT NOT NULL,
    nombre_snapshot VARCHAR(190) NOT NULL,
    reserva_liberada TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_presencia_reserva_items (reserva_id),
    CONSTRAINT fk_presencia_reserva_item FOREIGN KEY (reserva_id) REFERENCES presencia_reservas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presencia_reserva_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    reserva_id BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(50) NOT NULL,
    detalle_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_presencia_reserva_evento (reserva_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pasarelas_pago_config (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    proveedor VARCHAR(30) NOT NULL,
    ambiente VARCHAR(20) NOT NULL DEFAULT 'sandbox',
    credencial_publica VARCHAR(255) NULL,
    secreto_cifrado TEXT NULL,
    access_token_cifrado TEXT NULL,
    refresh_token_cifrado TEXT NULL,
    token_expires_at DATETIME NULL,
    merchant_id VARCHAR(100) NULL,
    webhook_secret_cifrado TEXT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 0,
    orden SMALLINT NOT NULL DEFAULT 100,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pasarela_empresa (empresa_id, proveedor),
    KEY idx_pasarela_activa (empresa_id, activo, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pagos_online_intentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    reserva_id BIGINT UNSIGNED NULL,
    venta_id BIGINT UNSIGNED NULL,
    proveedor VARCHAR(30) NOT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'RESERVA',
    idempotency_key VARCHAR(100) NOT NULL,
    referencia VARCHAR(100) NOT NULL,
    proveedor_id VARCHAR(150) NULL,
    monto BIGINT NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'CREADO',
    checkout_url VARCHAR(1000) NULL,
    provider_fee BIGINT NULL,
    merchant_net_amount BIGINT NULL,
    settlement_status VARCHAR(30) NOT NULL DEFAULT 'NO_APLICA',
    expected_settlement_at DATETIME NULL,
    settled_at DATETIME NULL,
    consumed_at DATETIME NULL,
    raw_status_json JSON NULL,
    ultimo_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pago_online_idempotencia (empresa_id, idempotency_key),
    UNIQUE KEY uq_pago_online_referencia (referencia),
    KEY idx_pago_online_proveedor (proveedor, proveedor_id),
    KEY idx_pago_online_estado (empresa_id, estado, created_at),
    CONSTRAINT fk_pago_online_reserva FOREIGN KEY (reserva_id) REFERENCES presencia_reservas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pagos_online_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proveedor VARCHAR(30) NOT NULL,
    event_key VARCHAR(190) NOT NULL,
    intento_id BIGINT UNSIGNED NULL,
    payload_json JSON NULL,
    procesado_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pago_online_evento (proveedor, event_key),
    KEY idx_pago_online_evento_intento (intento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pasarelas_verificaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    proveedor VARCHAR(30) NOT NULL,
    credenciales_estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
    sandbox_estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
    pago_real_intento_id BIGINT UNSIGNED NULL,
    liquidacion_estado VARCHAR(30) NOT NULL DEFAULT 'NO_VERIFICABLE',
    credenciales_verified_at DATETIME NULL,
    sandbox_verified_at DATETIME NULL,
    live_payment_verified_at DATETIME NULL,
    settlement_verified_at DATETIME NULL,
    ultimo_error VARCHAR(500) NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pasarela_verificacion (empresa_id, proveedor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pasarelas_oauth_states (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    proveedor VARCHAR(30) NOT NULL,
    state_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pasarela_oauth_state (state_hash),
    KEY idx_pasarela_oauth_expira (proveedor, expires_at, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presencia_metricas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NULL,
    producto_id BIGINT UNSIGNED NULL,
    evento VARCHAR(50) NOT NULL,
    session_hash CHAR(64) NULL,
    origen VARCHAR(60) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_presencia_metricas_empresa (empresa_id, evento, created_at),
    KEY idx_presencia_metricas_producto (producto_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permisos (codigo,nombre,descripcion,activo)
SELECT x.codigo,x.nombre,x.descripcion,1 FROM (
    SELECT 'presencia_digital.ver' codigo,'Ver presencia digital' nombre,'Consulta perfiles, catalogo, reservas y metricas' descripcion UNION ALL
    SELECT 'presencia_digital.configurar','Configurar presencia digital','Publica perfiles, productos y pasarelas' UNION ALL
    SELECT 'presencia_digital.operar','Operar reservas digitales','Gestiona cotizaciones, reservas, pagos y reembolsos'
) x WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo=x.codigo);

INSERT INTO rol_permisos (rol_id,permiso_id)
SELECT r.id,p.id FROM roles r JOIN permisos p ON p.codigo IN ('presencia_digital.ver','presencia_digital.configurar','presencia_digital.operar')
WHERE r.codigo IN ('SUPER_ADMIN','ADMIN_EMPRESA')
AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id=r.id AND rp.permiso_id=p.id);

INSERT INTO metodos_pago (codigo,nombre,activo) VALUES
('FLOW_ONLINE','Flow online',1),('MP_ONLINE','Mercado Pago online',1)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),activo=1;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('097_mypos_cerca_core');
