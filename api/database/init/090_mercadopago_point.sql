-- ===========================================================================
-- 074_mercadopago_point.sql
--
-- Integracion de medio de pago MercadoPago Point (terminales fisicas de tarjeta),
-- parametrizada por empresa y multi-sucursal.
--
-- Modelo: cada empresa carga en Configuracion sus credenciales de MercadoPago
-- (access_token propio, por eso es multiempresa real) y da de alta 1..N
-- terminales, mapeadas a sucursal y opcionalmente a una caja. El backend crea la
-- orden Point en la terminal, recibe el webhook `orders` y consulta el estado
-- real por API antes de cerrar la venta en caja.
--
-- Flujo con el POS: se cobra PRIMERO (se crea un "intento"); cuando el intento
-- queda APROBADO, el POS registra la venta con un pago metodo 'MP_POINT' cuya
-- referencia es el external_reference del intento. El intento se valida y consume
-- dentro de la transaccion de la venta (mismo patron que el vale de credito), de
-- modo que no puede reutilizarse ni falsearse. No se agregan estados a `ventas`.
--
-- CONTENIDO:
--   1. mercadopago_config          — credenciales por empresa (token cifrado)
--   2. mercadopago_terminales      — terminales fisicas por sucursal/caja
--   3. mercadopago_intentos        — un intento de cobro (orden Point)
--   4. mercadopago_webhook_events  — bitacora idempotente de notificaciones
--   5. metodo de pago MP_POINT
--   6. permisos mercadopago.ver / mercadopago.configurar / mercadopago.cobrar
-- ===========================================================================

-- 1. Credenciales por empresa ------------------------------------------------
CREATE TABLE IF NOT EXISTS mercadopago_config (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id            BIGINT UNSIGNED NOT NULL,
    ambiente              ENUM('sandbox','produccion') NOT NULL DEFAULT 'sandbox',
    access_token_cifrado  TEXT            NULL,            -- AES-256-GCM (base64), nunca en claro
    webhook_secret        VARCHAR(255)    NULL,            -- para validar x-signature del webhook
    user_id_mp            VARCHAR(60)     NULL,            -- id de la cuenta MP (informativo)
    activo                TINYINT(1)      NOT NULL DEFAULT 1,
    created_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP       NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mp_config_empresa (empresa_id),
    CONSTRAINT fk_mp_config_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Terminales fisicas ------------------------------------------------------
CREATE TABLE IF NOT EXISTS mercadopago_terminales (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id    BIGINT UNSIGNED NOT NULL,
    sucursal_id   BIGINT UNSIGNED NOT NULL,
    caja_id       BIGINT UNSIGNED NULL,                    -- opcional: terminal ligada a una caja
    terminal_id   VARCHAR(80)     NOT NULL,                -- config.point.terminal_id de MP
    nombre        VARCHAR(120)    NOT NULL,
    mp_store_id   VARCHAR(60)     NULL,
    mp_pos_id     VARCHAR(60)     NULL,
    serial        VARCHAR(80)     NULL,
    activo        TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mp_terminal (empresa_id, terminal_id),
    KEY idx_mp_terminal_sucursal (empresa_id, sucursal_id, activo),
    CONSTRAINT fk_mp_terminal_empresa  FOREIGN KEY (empresa_id)  REFERENCES empresas(id),
    CONSTRAINT fk_mp_terminal_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
    CONSTRAINT fk_mp_terminal_caja     FOREIGN KEY (caja_id)     REFERENCES cajas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Intentos de cobro (orden Point) -----------------------------------------
CREATE TABLE IF NOT EXISTS mercadopago_intentos (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id          BIGINT UNSIGNED NOT NULL,
    sucursal_id         BIGINT UNSIGNED NOT NULL,
    terminal_id_ref     BIGINT UNSIGNED NOT NULL,          -- FK a mercadopago_terminales.id
    external_reference  VARCHAR(120)    NOT NULL,          -- referencia propia (la que va a venta_pagos.referencia)
    provider_order_id   VARCHAR(120)    NULL,              -- id de la order en MercadoPago
    idempotency_key     VARCHAR(64)     NOT NULL,
    monto               BIGINT          NOT NULL,          -- CLP entero
    estado              ENUM('PENDIENTE','APROBADO','RECHAZADO','CANCELADO','EXPIRADO') NOT NULL DEFAULT 'PENDIENTE',
    status_detail       VARCHAR(120)    NULL,
    venta_id            BIGINT UNSIGNED NULL,              -- se llena al consumir el pago en la venta
    raw_response_json   JSON            NULL,              -- ultima respuesta cruda de MP (auditoria)
    usuario_id          BIGINT UNSIGNED NOT NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mp_intento_ref (empresa_id, external_reference),
    KEY idx_mp_intento_order (provider_order_id),
    KEY idx_mp_intento_estado (empresa_id, estado),
    KEY idx_mp_intento_venta (venta_id),
    CONSTRAINT fk_mp_intento_empresa  FOREIGN KEY (empresa_id)      REFERENCES empresas(id),
    CONSTRAINT fk_mp_intento_sucursal FOREIGN KEY (sucursal_id)     REFERENCES sucursales(id),
    CONSTRAINT fk_mp_intento_terminal FOREIGN KEY (terminal_id_ref) REFERENCES mercadopago_terminales(id),
    CONSTRAINT fk_mp_intento_usuario  FOREIGN KEY (usuario_id)      REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Bitacora idempotente de webhooks ----------------------------------------
CREATE TABLE IF NOT EXISTS mercadopago_webhook_events (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id    BIGINT UNSIGNED NULL,                    -- se resuelve desde el intento notificado
    topic         VARCHAR(40)     NOT NULL,
    resource_id   VARCHAR(120)    NOT NULL,
    action        VARCHAR(60)     NULL,
    payload_json  JSON            NULL,
    x_signature   VARCHAR(255)    NULL,
    procesado_at  TIMESTAMP       NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mp_webhook (topic, resource_id, action),
    KEY idx_mp_webhook_resource (resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Medio de pago MP_POINT --------------------------------------------------
INSERT INTO metodos_pago (codigo, nombre) VALUES ('MP_POINT', 'MercadoPago Point')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), activo = 1;

-- 6. Permisos ----------------------------------------------------------------
INSERT INTO permisos (codigo, nombre, descripcion, activo)
SELECT x.codigo, x.nombre, x.descripcion, 1
FROM (
    SELECT 'mercadopago.ver' codigo, 'Ver configuracion MercadoPago' nombre, 'Permite ver credenciales y terminales MercadoPago' descripcion UNION ALL
    SELECT 'mercadopago.configurar', 'Configurar MercadoPago', 'Permite editar credenciales y terminales MercadoPago' UNION ALL
    SELECT 'mercadopago.cobrar', 'Cobrar con MercadoPago Point', 'Permite iniciar y consultar cobros en terminal Point'
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

-- Admin: todo. Cajero/Vendedor: solo cobrar (config es de administracion).
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('mercadopago.ver', 'mercadopago.configurar', 'mercadopago.cobrar')
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo = 'mercadopago.cobrar'
WHERE r.codigo IN ('CAJERO', 'VENDEDOR')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('090_mercadopago_point');
