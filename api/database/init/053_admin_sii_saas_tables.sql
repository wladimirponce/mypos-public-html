-- =============================================================================
-- Init 053 — Tablas del subsistema Admin/SII/SaaS
-- Estas tablas son usadas por el panel de administración (admin/) y el
-- subsistema DTE. Están en estado final (incorporan todos los ALTERs de
-- las migraciones admin 001–013).
-- Todas las sentencias son idempotentes (IF NOT EXISTS / IF NOT EXISTS).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1. sii_config — parámetros del subsistema SII (clave-valor)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_config (
    clave       VARCHAR(100)                        NOT NULL,
    valor       TEXT                                NOT NULL,
    tipo        ENUM('string','int','bool','json')  NOT NULL DEFAULT 'string',
    descripcion VARCHAR(500)                                 NULL,
    PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO sii_config (clave, valor, tipo, descripcion) VALUES
    ('umbral_folio_critico',     '50',           'int',    'Folios restantes que disparan alerta ROJA'),
    ('umbral_folio_pre_critico', '200',          'int',    'Folios restantes que disparan alerta AMARILLA'),
    ('version_api',              '1.0',          'string', 'Versión actual de la API REST SII'),
    ('ambiente_default',         'certificacion','string', 'Ambiente por defecto: certificacion | produccion'),
    ('max_items_por_dte',        '60',           'int',    'Máximo de líneas de detalle por DTE')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);

-- ---------------------------------------------------------------------------
-- 2. sii_empresa — empresas emisoras de DTE (subsistema admin)
--    Incluye columna unidad_sii (migración 009)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_empresa (
    id                  INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    rut                 VARCHAR(12)         NOT NULL,
    razon_social        VARCHAR(200)        NOT NULL,
    giro                VARCHAR(200)        NOT NULL,
    acteco              VARCHAR(500)        NOT NULL,
    direccion_origen    VARCHAR(200)        NOT NULL,
    comuna_origen       VARCHAR(100)        NOT NULL,
    ciudad_origen       VARCHAR(100)                 NULL,
    unidad_sii          VARCHAR(100)                 NULL,
    telefono            VARCHAR(30)                  NULL,
    email_sii           VARCHAR(150)                 NULL,
    fecha_resolucion    DATE                NOT NULL,
    numero_resolucion   VARCHAR(20)         NOT NULL,
    ambiente_default    ENUM('certificacion','produccion') NOT NULL DEFAULT 'certificacion',
    activo              TINYINT(1)          NOT NULL DEFAULT 1,
    creado_en           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empresa_rut (rut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 3. sii_certificado — certificados PFX por empresa
--    Incluye columna ambiente_sii (migración 010)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_certificado (
    id              INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    empresa_id      INT UNSIGNED        NOT NULL,
    nombre_archivo  VARCHAR(300)        NOT NULL,
    ruta_pfx        VARCHAR(500)        NOT NULL,
    clave_enc       TEXT                NOT NULL,
    rut_titular     VARCHAR(12)                  NULL,
    nombre_titular  VARCHAR(200)                 NULL,
    vigencia_desde  DATE                         NULL,
    vigencia_hasta  DATE                         NULL,
    ambiente_sii    ENUM('certificacion','produccion') NOT NULL DEFAULT 'certificacion',
    activo          TINYINT(1)          NOT NULL DEFAULT 1,
    subido_por      VARCHAR(100)                 NULL,
    creado_en       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cert_empresa_ambiente (empresa_id, ambiente_sii, activo),
    CONSTRAINT fk_cert_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 4. sii_caf — rangos de folios autorizados (CAF)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_caf (
    id                  INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    empresa_id          INT UNSIGNED        NOT NULL,
    tipo_dte            SMALLINT            NOT NULL,
    folio_desde         BIGINT UNSIGNED     NOT NULL,
    folio_hasta         BIGINT UNSIGNED     NOT NULL,
    total_folios        INT GENERATED ALWAYS AS (CAST(folio_hasta AS SIGNED) - CAST(folio_desde AS SIGNED) + 1) STORED,
    xml_path            VARCHAR(500)        NOT NULL,
    ambiente_sii        ENUM('certificacion','produccion') NOT NULL DEFAULT 'certificacion',
    fecha_autorizacion  DATE                NOT NULL,
    activo              TINYINT(1)          NOT NULL DEFAULT 1,
    subido_por          VARCHAR(100)                 NULL,
    creado_en           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_caf_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT,
    KEY idx_caf_tipo_ambiente (empresa_id, tipo_dte, ambiente_sii, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 5. sii_folio_consumo — trazabilidad de cada folio consumido
--    Incluye columna ambiente + unique key correcta (migración 010)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_folio_consumo (
    id          BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    caf_id      INT UNSIGNED        NOT NULL,
    empresa_id  INT UNSIGNED        NOT NULL,
    tipo_dte    SMALLINT            NOT NULL,
    folio       BIGINT UNSIGNED     NOT NULL,
    ambiente    ENUM('certificacion','produccion') NOT NULL DEFAULT 'certificacion',
    estado      ENUM('emitido','enviado_sii','aceptado','rechazado_sii','anulado') NOT NULL DEFAULT 'emitido',
    dte_id      BIGINT UNSIGNED              NULL,
    creado_en   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_folio_empresa_ambiente (empresa_id, tipo_dte, folio, ambiente),
    KEY idx_folio_empresa (empresa_id),
    CONSTRAINT fk_folcons_caf     FOREIGN KEY (caf_id)     REFERENCES sii_caf(id)     ON DELETE RESTRICT,
    CONSTRAINT fk_folcons_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 6. sii_dte — documentos tributarios emitidos
--    Incluye columnas extra de migración 002 y sucursal_id de migración 003
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_dte (
    id                  BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    empresa_id          INT UNSIGNED        NOT NULL,
    sucursal_id         INT UNSIGNED                 NULL,
    tipo_dte            SMALLINT            NOT NULL,
    folio               BIGINT UNSIGNED     NOT NULL,
    ambiente            ENUM('certificacion','produccion') NOT NULL DEFAULT 'certificacion',
    fecha_emision       DATE                NOT NULL,
    rut_receptor        VARCHAR(12)                  NULL,
    razon_receptor      VARCHAR(200)                 NULL,
    giro_receptor       VARCHAR(200)                 NULL,
    direccion_receptor  VARCHAR(200)                 NULL,
    comuna_receptor     VARCHAR(100)                 NULL,
    ciudad_receptor     VARCHAR(100)                 NULL,
    monto_neto          DECIMAL(14,0)       NOT NULL DEFAULT 0,
    monto_iva           DECIMAL(14,0)       NOT NULL DEFAULT 0,
    monto_exento        DECIMAL(14,0)       NOT NULL DEFAULT 0,
    monto_total         DECIMAL(14,0)       NOT NULL DEFAULT 0,
    xml_firmado         MEDIUMTEXT                   NULL,
    track_id            VARCHAR(50)                  NULL,
    estado_sii          VARCHAR(20)                  NULL,
    glosa_sii           VARCHAR(500)                 NULL,
    estado_local        ENUM('borrador','firmado','enviado','aceptado','rechazado','anulado') NOT NULL DEFAULT 'borrador',
    referencia_doc      VARCHAR(50)                  NULL,
    ind_traslado        TINYINT                      NULL,
    tipo_despacho       TINYINT                      NULL,
    patente             VARCHAR(20)                  NULL,
    rut_chofer          VARCHAR(12)                  NULL,
    rut_envia           VARCHAR(12)                  NULL,
    creado_en           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dte_empresa_tipo_folio (empresa_id, tipo_dte, folio, ambiente),
    CONSTRAINT fk_dte_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT,
    KEY idx_dte_fecha    (empresa_id, fecha_emision),
    KEY idx_dte_trackid  (track_id),
    KEY idx_dte_receptor (rut_receptor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 7. sii_dte_detalle — líneas de cada DTE
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_dte_detalle (
    id          BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    dte_id      BIGINT UNSIGNED     NOT NULL,
    nro_linea   SMALLINT            NOT NULL,
    codigo      VARCHAR(50)                  NULL,
    descripcion VARCHAR(500)        NOT NULL,
    cantidad    DECIMAL(12,4)       NOT NULL DEFAULT 1,
    precio_unit DECIMAL(14,4)       NOT NULL DEFAULT 0,
    descuento   DECIMAL(12,4)                NULL,
    monto       DECIMAL(14,0)       NOT NULL DEFAULT 0,
    exento      TINYINT(1)          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT fk_detalle_dte FOREIGN KEY (dte_id) REFERENCES sii_dte(id) ON DELETE CASCADE,
    KEY idx_detalle_dte (dte_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 8. sii_api_key — claves de acceso a la API REST del admin
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_api_key (
    id          INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    empresa_id  INT UNSIGNED        NOT NULL,
    nombre      VARCHAR(100)        NOT NULL,
    clave_hash  CHAR(64)            NOT NULL,
    permisos    TEXT                         NULL,
    activa      TINYINT(1)          NOT NULL DEFAULT 1,
    ultimo_uso  DATETIME                     NULL,
    creado_en   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_key_hash (clave_hash),
    CONSTRAINT fk_apikey_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 9. sii_sucursal — sucursales del emisor (subsistema admin)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_sucursal (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id  INT UNSIGNED    NOT NULL,
    nombre      VARCHAR(100)    NOT NULL,
    direccion   VARCHAR(200)    NOT NULL,
    comuna      VARCHAR(100)    NOT NULL,
    ciudad      VARCHAR(100)             NULL,
    telefono    VARCHAR(30)              NULL,
    activa      TINYINT(1)      NOT NULL DEFAULT 1,
    creado_en   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_suc_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT,
    KEY idx_suc_empresa (empresa_id, activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 10. sii_dispositivo — dispositivos POS enrolados
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_dispositivo (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id          INT UNSIGNED    NOT NULL,
    sucursal_id         VARCHAR(40)     NOT NULL,
    nombre              VARCHAR(120)    NOT NULL DEFAULT '',
    tipo                ENUM('POS_ANDROID','POS_WEB','APK_MOVIL','OTRO') NOT NULL DEFAULT 'POS_ANDROID',
    token_activacion    VARCHAR(20)     NOT NULL,
    token_hash          VARCHAR(64)     NOT NULL,
    token_expira        DATETIME        NOT NULL,
    token_usado         TINYINT(1)      NOT NULL DEFAULT 0,
    api_key_hash        VARCHAR(64)              NULL,
    device_android_id   VARCHAR(64)              NULL,
    device_modelo       VARCHAR(100)             NULL,
    device_version_app  VARCHAR(20)              NULL,
    enrolado_en         DATETIME                 NULL,
    ultimo_contacto     DATETIME                 NULL,
    activo              TINYINT(1)      NOT NULL DEFAULT 1,
    notas               VARCHAR(255)             NULL,
    creado_en           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token_hash (token_hash),
    KEY idx_empresa     (empresa_id),
    KEY idx_sucursal    (sucursal_id),
    KEY idx_activo      (activo),
    KEY idx_api_key_hash (api_key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 11. dte_local_queue — DTEs generados en POS, pendientes de envío al SII
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dte_local_queue (
    id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    tipo_dte        SMALLINT UNSIGNED   NOT NULL,
    folio           INT UNSIGNED        NOT NULL,
    sucursal_id     VARCHAR(40)         NOT NULL,
    maquina_id      VARCHAR(40)                  NULL,
    fecha_emision   DATE                NOT NULL,
    rut_receptor    VARCHAR(12)         NOT NULL DEFAULT '66666666-6',
    mnt_total       BIGINT              NOT NULL,
    mnt_neto        BIGINT              NOT NULL DEFAULT 0,
    mnt_iva         BIGINT              NOT NULL DEFAULT 0,
    ted_xml         MEDIUMTEXT          NOT NULL,
    items_json      JSON                         NULL,
    estado          ENUM('pendiente','procesando','enviado','error','dropped') NOT NULL DEFAULT 'pendiente',
    track_id        VARCHAR(50)                  NULL,
    intentos        TINYINT UNSIGNED    NOT NULL DEFAULT 0,
    ultimo_error    TEXT                         NULL,
    fecha_creacion  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_proceso   DATETIME                     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tipo_folio  (tipo_dte, folio),
    KEY idx_estado            (estado, fecha_creacion),
    KEY idx_sucursal_fecha    (sucursal_id, fecha_emision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 12. folios_alerta — reportes de stock de folios de los APKs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS folios_alerta (
    id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    sucursal_id     VARCHAR(40)         NOT NULL,
    maquina_id      VARCHAR(40)         NOT NULL DEFAULT '',
    tipo_dte        SMALLINT UNSIGNED   NOT NULL,
    folios_locales  INT UNSIGNED        NOT NULL DEFAULT 0,
    dias_estimados  DECIMAL(6,1)                 NULL,
    nivel           ENUM('ok','bajo','critico')  NOT NULL DEFAULT 'ok',
    fecha_primera   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sucursal_maquina_tipo (sucursal_id, maquina_id, tipo_dte),
    KEY idx_nivel_actualiz (nivel, actualizado),
    KEY idx_sucursal       (sucursal_id, tipo_dte)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 13. cafs — pool centralizado legacy de CAFs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cafs (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tipo_dte            SMALLINT UNSIGNED NOT NULL,
    rut_emisor          VARCHAR(12)     NOT NULL,
    razon_social        VARCHAR(120)    NOT NULL DEFAULT '',
    folio_desde         INT UNSIGNED    NOT NULL,
    folio_hasta         INT UNSIGNED    NOT NULL,
    folio_actual        INT UNSIGNED    NOT NULL,
    sucursal_id         VARCHAR(40)              NULL,
    xml_content         LONGTEXT        NOT NULL,
    fecha_autorizacion  DATE                     NULL,
    fecha_subida        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    origen              ENUM('LEGACY','CENTRAL') NOT NULL DEFAULT 'CENTRAL',
    agotado             TINYINT(1)      NOT NULL DEFAULT 0,
    observaciones       VARCHAR(255)             NULL,
    PRIMARY KEY (id),
    KEY idx_busqueda (rut_emisor, tipo_dte, sucursal_id, agotado),
    KEY idx_rango    (tipo_dte, folio_desde, folio_hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 14. caf_consumos — auditoría de folios entregados a sucursales (legacy)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS caf_consumos (
    id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    caf_id          INT UNSIGNED        NOT NULL,
    tipo_dte        SMALLINT UNSIGNED   NOT NULL,
    folio           INT UNSIGNED        NOT NULL,
    sucursal_id     VARCHAR(40)         NOT NULL,
    maquina_id      VARCHAR(40)                  NULL,
    dte_id          BIGINT UNSIGNED              NULL,
    fecha_consumo   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_folio_tipo (tipo_dte, folio),
    KEY idx_caf              (caf_id),
    KEY idx_sucursal_fecha   (sucursal_id, fecha_consumo),
    CONSTRAINT fk_consumo_caf FOREIGN KEY (caf_id) REFERENCES cafs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 15. caf_consumo_medio — estadística de consumo de folios por sucursal
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS caf_consumo_medio (
    sucursal_id     VARCHAR(40)         NOT NULL,
    tipo_dte        SMALLINT UNSIGNED   NOT NULL,
    folios_30d      INT UNSIGNED        NOT NULL DEFAULT 0,
    folios_dia_prom DECIMAL(10,2)       NOT NULL DEFAULT 0,
    actualizado     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sucursal_id, tipo_dte)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 16. sii_usuario — usuarios POS (sistema SII legacy, distinto de `usuarios`)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_usuario (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id      INT UNSIGNED    NOT NULL,
    sucursal_id     INT UNSIGNED             NULL,
    nombre          VARCHAR(150)    NOT NULL,
    email           VARCHAR(150)    NOT NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    rol             VARCHAR(50)     NOT NULL DEFAULT 'cajero',
    pin_caja        VARCHAR(10)              NULL,
    activo          TINYINT(1)      NOT NULL DEFAULT 1,
    creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuario_email (email),
    CONSTRAINT fk_usu_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT,
    CONSTRAINT fk_usu_sucursal FOREIGN KEY (sucursal_id) REFERENCES sii_sucursal(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 17. saas_suscripcion — suscripciones SaaS por empresa
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saas_suscripcion (
    id              INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    empresa_id      INT UNSIGNED        NOT NULL,
    plan            VARCHAR(50)         NOT NULL DEFAULT 'basico',
    cuota_mensual   DECIMAL(10,0)       NOT NULL DEFAULT 0,
    dia_corte       TINYINT             NOT NULL DEFAULT 5,
    estado_pago     ENUM('al_dia','moroso','suspendido') NOT NULL DEFAULT 'al_dia',
    fecha_ultimo_pago DATE                       NULL,
    notas           TEXT                         NULL,
    creado_en       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suscripcion_empresa (empresa_id),
    CONSTRAINT fk_susc_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 18. saas_feature_toggles — feature flags por empresa
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saas_feature_toggles (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id  INT UNSIGNED    NOT NULL,
    feature     VARCHAR(100)    NOT NULL,
    valor       VARCHAR(255)    NOT NULL DEFAULT '1',
    PRIMARY KEY (id),
    UNIQUE KEY uq_toggle_empresa_feature (empresa_id, feature),
    CONSTRAINT fk_feat_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 19. saas_contacto — CRM de contactos por empresa
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saas_contacto (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id      INT UNSIGNED    NOT NULL,
    nombre          VARCHAR(150)    NOT NULL,
    telefono        VARCHAR(50)              NULL,
    email           VARCHAR(150)             NULL,
    es_tecnico      TINYINT(1)      NOT NULL DEFAULT 0,
    notas_internas  TEXT                     NULL,
    creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_cont_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 20. saas_ticket_soporte — tickets de soporte
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saas_ticket_soporte (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id  INT UNSIGNED    NOT NULL,
    usuario_id  INT UNSIGNED             NULL,
    asunto      VARCHAR(200)    NOT NULL,
    estado      ENUM('abierto','en_progreso','resuelto','cerrado') NOT NULL DEFAULT 'abierto',
    prioridad   ENUM('baja','media','alta','critica')              NOT NULL DEFAULT 'media',
    creado_en   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_tkt_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE CASCADE,
    CONSTRAINT fk_tkt_usuario FOREIGN KEY (usuario_id) REFERENCES sii_usuario(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 21. saas_ticket_mensaje — mensajes de tickets de soporte
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saas_ticket_mensaje (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id   INT UNSIGNED    NOT NULL,
    usuario_id  INT UNSIGNED             NULL,
    mensaje     TEXT            NOT NULL,
    adjunto_url VARCHAR(500)             NULL,
    creado_en   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_msg_ticket  FOREIGN KEY (ticket_id)  REFERENCES saas_ticket_soporte(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_usuario FOREIGN KEY (usuario_id) REFERENCES sii_usuario(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 22. saas_sucursal — sucursales (modelo admin antiguo, sin FK estricta)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saas_sucursal (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id  INT NOT NULL,
    nombre      VARCHAR(100)    NOT NULL,
    direccion   VARCHAR(255)    NOT NULL,
    codigo_sii  VARCHAR(20)              DEFAULT NULL,
    activa      BOOLEAN                  DEFAULT 1,
    creado_en   TIMESTAMP                DEFAULT CURRENT_TIMESTAMP,
    INDEX (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 23. saas_rol_matriz — matriz de permisos por rol por empresa
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saas_rol_matriz (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id              INT NOT NULL,
    nombre_rol              VARCHAR(50) NOT NULL,
    puede_anular            BOOLEAN DEFAULT 0,
    puede_descuento_mayor   BOOLEAN DEFAULT 0,
    puede_ver_reportes      BOOLEAN DEFAULT 0,
    puede_configurar_pos    BOOLEAN DEFAULT 0,
    creado_en               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_empresa_rol (empresa_id, nombre_rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 24. saas_broadcasts — mensajes broadcast del sistema
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saas_broadcasts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    titulo      VARCHAR(100)    NOT NULL,
    mensaje     TEXT            NOT NULL,
    tipo        VARCHAR(20)              DEFAULT 'info',
    fecha_inicio DATETIME       NOT NULL,
    fecha_fin   DATETIME        NOT NULL,
    activo      BOOLEAN                  DEFAULT 1,
    creado_en   TIMESTAMP                DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 25. admin_usuario — operadores del dashboard central
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_usuario (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(150)    NOT NULL,
    email           VARCHAR(150)    NOT NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    rol             ENUM('superadmin','operador') NOT NULL DEFAULT 'operador',
    activo          TINYINT(1)      NOT NULL DEFAULT 1,
    ultimo_login    DATETIME                 NULL,
    creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 26. v_sii_folios_disponibles — vista de disponibilidad de folios
--    Versión SQL SECURITY INVOKER (sin DEFINER) para compatibilidad multiservidor
-- ---------------------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_sii_folios_disponibles AS
SELECT
    c.empresa_id,
    c.tipo_dte,
    c.ambiente_sii,
    c.id AS caf_id,
    c.folio_desde,
    c.folio_hasta,
    (CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) AS total_folios,
    COUNT(fc.id) AS consumidos,
    GREATEST((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COUNT(fc.id), 0) AS disponibles,
    COALESCE(
        (SELECT cfg_c.valor FROM sii_config cfg_c WHERE cfg_c.clave = 'umbral_folio_critico' LIMIT 1), '50'
    ) + 0 AS umbral_critico,
    COALESCE(
        (SELECT cfg_p.valor FROM sii_config cfg_p WHERE cfg_p.clave = 'umbral_folio_pre_critico' LIMIT 1), '200'
    ) + 0 AS umbral_pre_critico,
    CASE
        WHEN ((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COUNT(fc.id)) <=
             COALESCE((SELECT cfg_c2.valor FROM sii_config cfg_c2 WHERE cfg_c2.clave = 'umbral_folio_critico' LIMIT 1), '50') + 0
             THEN 'critico'
        WHEN ((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COUNT(fc.id)) <=
             COALESCE((SELECT cfg_p2.valor FROM sii_config cfg_p2 WHERE cfg_p2.clave = 'umbral_folio_pre_critico' LIMIT 1), '200') + 0
             THEN 'pre_critico'
        ELSE 'normal'
    END AS nivel_alerta
FROM sii_caf c
LEFT JOIN sii_folio_consumo fc
    ON fc.caf_id = c.id
   AND fc.estado != 'rechazado_sii'
WHERE c.activo = 1
GROUP BY c.id, c.empresa_id, c.tipo_dte, c.ambiente_sii, c.folio_desde, c.folio_hasta;

SET FOREIGN_KEY_CHECKS = 1;
