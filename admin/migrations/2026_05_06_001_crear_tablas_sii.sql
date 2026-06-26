-- =============================================================================
-- Migración 001 — Subsistema DTE / SII
-- Fecha    : 2026-05-06
-- Proyecto : Farmacias Belén — mini-SII
-- Ejecutar : una sola vez sobre la DB mypos (o la DB configurada en las variables de entorno)
-- =============================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -----------------------------------------------------------------------------
-- 1. sii_config — parámetros del sistema (clave-valor tipado)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_config (
    clave       VARCHAR(100)                        NOT NULL,
    valor       TEXT                                NOT NULL,
    tipo        ENUM('string','int','bool','json')  NOT NULL DEFAULT 'string',
    descripcion VARCHAR(500)                                 NULL,
    PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Parámetros globales del subsistema SII';

INSERT INTO sii_config (clave, valor, tipo, descripcion) VALUES
    ('umbral_folio_critico',     '50',  'int', 'Folios restantes que disparan alerta ROJA (crítica)'),
    ('umbral_folio_pre_critico', '200', 'int', 'Folios restantes que disparan alerta AMARILLA (pre-crítica)'),
    ('version_api',              '1.0', 'string', 'Versión actual de la API REST SII'),
    ('ambiente_default',         'certificacion', 'string', 'Ambiente por defecto: certificacion | produccion'),
    ('max_items_por_dte',        '60',  'int', 'Máximo de líneas de detalle permitidas por DTE')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);

-- -----------------------------------------------------------------------------
-- 2. sii_empresa — datos del emisor (solo una empresa en esta versión MVP)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_empresa (
    id                      INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    rut                     VARCHAR(12)         NOT NULL COMMENT 'Ej: 77020050-4',
    razon_social            VARCHAR(200)        NOT NULL,
    giro                    VARCHAR(200)        NOT NULL,
    acteco                  VARCHAR(500)        NOT NULL COMMENT 'JSON array: ["107300","463020"]',
    direccion_origen        VARCHAR(200)        NOT NULL,
    comuna_origen           VARCHAR(100)        NOT NULL,
    ciudad_origen           VARCHAR(100)                 NULL,
    telefono                VARCHAR(30)                  NULL,
    email_sii               VARCHAR(150)                 NULL COMMENT 'Email registrado en SII para notificaciones',
    fecha_resolucion        DATE                NOT NULL COMMENT 'Fecha de resolución SII autorización',
    numero_resolucion       VARCHAR(20)         NOT NULL COMMENT 'Número de resolución SII (ej: 80)',
    ambiente_default        ENUM('certificacion','produccion') NOT NULL DEFAULT 'certificacion',
    activo                  TINYINT(1)          NOT NULL DEFAULT 1,
    creado_en               DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empresa_rut (rut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Datos del contribuyente emisor de DTE';

-- -----------------------------------------------------------------------------
-- 3. sii_certificado — certificados PFX asociados a la empresa
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_certificado (
    id                  INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    empresa_id          INT UNSIGNED        NOT NULL,
    nombre_archivo      VARCHAR(300)        NOT NULL COMMENT 'Nombre original del PFX subido',
    ruta_pfx            VARCHAR(500)        NOT NULL COMMENT 'Ruta absoluta en el servidor (private/pfx/...)',
    clave_enc           TEXT                NOT NULL COMMENT 'Clave AES-256-CBC cifrada con SII_MASTER_KEY',
    rut_titular         VARCHAR(12)                  NULL COMMENT 'RUT extraído del certificado X.509',
    nombre_titular      VARCHAR(200)                 NULL,
    vigencia_desde      DATE                         NULL,
    vigencia_hasta      DATE                         NULL,
    ambiente_sii        ENUM('certificacion','produccion') NOT NULL DEFAULT 'certificacion',
    activo              TINYINT(1)          NOT NULL DEFAULT 1,
    subido_por          VARCHAR(100)                 NULL COMMENT 'Usuario o API key que subió el cert',
    creado_en           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cert_empresa_ambiente (empresa_id, ambiente_sii, activo),
    CONSTRAINT fk_cert_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Certificados digitales PFX para firma de DTE';

-- -----------------------------------------------------------------------------
-- 4. sii_caf — Códigos de Autorización de Folios por tipo DTE
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_caf (
    id              INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    empresa_id      INT UNSIGNED        NOT NULL,
    tipo_dte        SMALLINT            NOT NULL COMMENT '33, 34, 39, 41, 52, 56, 61',
    folio_desde     BIGINT UNSIGNED     NOT NULL,
    folio_hasta     BIGINT UNSIGNED     NOT NULL,
    total_folios    INT GENERATED ALWAYS AS (CAST(folio_hasta AS SIGNED) - CAST(folio_desde AS SIGNED) + 1) STORED,
    xml_path        VARCHAR(500)        NOT NULL COMMENT 'Ruta al XML del CAF en private/caf/',
    ambiente_sii    ENUM('certificacion','produccion') NOT NULL DEFAULT 'certificacion',
    fecha_autorizacion DATE             NOT NULL COMMENT 'FA del XML del CAF',
    activo          TINYINT(1)          NOT NULL DEFAULT 1,
    subido_por      VARCHAR(100)                 NULL,
    creado_en       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_caf_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT,
    KEY idx_caf_tipo_ambiente (empresa_id, tipo_dte, ambiente_sii, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Rangos de folios autorizados por el SII (CAF)';

-- -----------------------------------------------------------------------------
-- 5. sii_folio_consumo — registro de folios usados (uno por DTE emitido)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_folio_consumo (
    id          BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    caf_id      INT UNSIGNED        NOT NULL,
    empresa_id  INT UNSIGNED        NOT NULL,
    tipo_dte    SMALLINT            NOT NULL,
    folio       BIGINT UNSIGNED     NOT NULL,
    ambiente    ENUM('certificacion','produccion') NOT NULL DEFAULT 'certificacion',
    estado      ENUM('emitido','enviado_sii','aceptado','rechazado_sii','anulado') NOT NULL DEFAULT 'emitido',
    dte_id      BIGINT UNSIGNED              NULL COMMENT 'FK a sii_dte.id (se actualiza al emitir)',
    creado_en   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_folio_empresa_ambiente (empresa_id, tipo_dte, folio, ambiente),
    CONSTRAINT fk_folcons_caf     FOREIGN KEY (caf_id)    REFERENCES sii_caf(id)     ON DELETE RESTRICT,
    CONSTRAINT fk_folcons_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Trazabilidad de cada folio consumido';

-- -----------------------------------------------------------------------------
-- 6. sii_dte — documentos tributarios emitidos
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_dte (
    id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    empresa_id      INT UNSIGNED        NOT NULL,
    tipo_dte        SMALLINT            NOT NULL,
    folio           BIGINT UNSIGNED     NOT NULL,
    ambiente        ENUM('certificacion','produccion') NOT NULL DEFAULT 'certificacion',
    fecha_emision   DATE                NOT NULL,
    rut_receptor        VARCHAR(12)                  NULL,
    razon_receptor      VARCHAR(200)                 NULL,
    giro_receptor       VARCHAR(200)                 NULL,
    direccion_receptor  VARCHAR(200)                 NULL,
    comuna_receptor     VARCHAR(100)                 NULL,
    ciudad_receptor     VARCHAR(100)                 NULL,
    monto_neto      DECIMAL(14,0)       NOT NULL DEFAULT 0,
    monto_iva       DECIMAL(14,0)       NOT NULL DEFAULT 0,
    monto_exento    DECIMAL(14,0)       NOT NULL DEFAULT 0,
    monto_total     DECIMAL(14,0)       NOT NULL DEFAULT 0,
    xml_firmado     MEDIUMTEXT                   NULL COMMENT 'XML DTE firmado (comprimido o referencia a archivo)',
    track_id        VARCHAR(50)                  NULL COMMENT 'TrackID SII devuelto al enviar sobre',
    estado_sii      VARCHAR(20)                  NULL COMMENT 'EPR | 11 | -11 | etc.',
    glosa_sii       VARCHAR(500)                 NULL,
    estado_local    ENUM('borrador','firmado','enviado','aceptado','rechazado','anulado') NOT NULL DEFAULT 'borrador',
    referencia_doc  VARCHAR(50)                  NULL COMMENT 'Nro documento interno origen si viene de POS',
    ind_traslado    TINYINT                      NULL COMMENT 'Solo DTE 52: 1-6',
    tipo_despacho   TINYINT                      NULL COMMENT 'Solo DTE 52: 1-3',
    patente         VARCHAR(20)                  NULL COMMENT 'Solo DTE 52',
    rut_chofer      VARCHAR(12)                  NULL COMMENT 'Solo DTE 52',
    rut_envia       VARCHAR(12)                  NULL COMMENT 'RUT del firmante',
    creado_en       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dte_empresa_tipo_folio (empresa_id, tipo_dte, folio, ambiente),
    CONSTRAINT fk_dte_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT,
    KEY idx_dte_fecha     (empresa_id, fecha_emision),
    KEY idx_dte_trackid   (track_id),
    KEY idx_dte_receptor  (rut_receptor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Documentos tributarios electrónicos emitidos';

-- -----------------------------------------------------------------------------
-- 7. sii_dte_detalle — líneas de detalle de cada DTE
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_dte_detalle (
    id          BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    dte_id      BIGINT UNSIGNED     NOT NULL,
    nro_linea   SMALLINT            NOT NULL,
    codigo      VARCHAR(50)                  NULL,
    descripcion VARCHAR(500)        NOT NULL,
    cantidad    DECIMAL(12,4)       NOT NULL DEFAULT 1,
    precio_unit DECIMAL(14,4)       NOT NULL DEFAULT 0,
    descuento   DECIMAL(12,4)                NULL COMMENT 'Porcentaje de descuento',
    monto       DECIMAL(14,0)       NOT NULL DEFAULT 0,
    exento      TINYINT(1)          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT fk_detalle_dte FOREIGN KEY (dte_id) REFERENCES sii_dte(id) ON DELETE CASCADE,
    KEY idx_detalle_dte (dte_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Líneas de detalle de cada DTE emitido';

-- -----------------------------------------------------------------------------
-- 8. sii_api_key — claves de acceso a la API REST
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_api_key (
    id          INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    empresa_id  INT UNSIGNED        NOT NULL,
    nombre      VARCHAR(100)        NOT NULL COMMENT 'Etiqueta descriptiva (ej: POS-Local1)',
    clave_hash  CHAR(64)            NOT NULL COMMENT 'SHA-256 hex de la API key (la key plana se muestra solo una vez)',
    permisos    TEXT                         NULL COMMENT 'JSON array: ["emitir","consultar","config"]',
    activa      TINYINT(1)          NOT NULL DEFAULT 1,
    ultimo_uso  DATETIME                     NULL,
    creado_en   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_key_hash (clave_hash),
    CONSTRAINT fk_apikey_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API keys para autenticación en la API REST SII';

-- -----------------------------------------------------------------------------
-- 9. Vista: folios disponibles por empresa / tipo DTE / ambiente
-- -----------------------------------------------------------------------------
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_sii_folios_disponibles AS
SELECT
    c.empresa_id,
    c.tipo_dte,
    c.ambiente_sii,
    c.id                                                        AS caf_id,
    c.folio_desde,
    c.folio_hasta,
    c.total_folios,
    COUNT(fc.id)                                               AS consumidos,
    c.total_folios - COUNT(fc.id)                              AS disponibles,
    COALESCE(
        (SELECT cfg_c.valor FROM sii_config cfg_c WHERE cfg_c.clave = 'umbral_folio_critico'),
        '50'
    ) + 0                                                      AS umbral_critico,
    COALESCE(
        (SELECT cfg_p.valor FROM sii_config cfg_p WHERE cfg_p.clave = 'umbral_folio_pre_critico'),
        '200'
    ) + 0                                                      AS umbral_pre_critico,
    CASE
        WHEN (c.total_folios - COUNT(fc.id)) <= COALESCE(
            (SELECT cfg_c2.valor FROM sii_config cfg_c2 WHERE cfg_c2.clave = 'umbral_folio_critico'), '50'
        ) + 0 THEN 'critico'
        WHEN (c.total_folios - COUNT(fc.id)) <= COALESCE(
            (SELECT cfg_p2.valor FROM sii_config cfg_p2 WHERE cfg_p2.clave = 'umbral_folio_pre_critico'), '200'
        ) + 0 THEN 'pre_critico'
        ELSE 'normal'
    END                                                        AS nivel_alerta
FROM sii_caf c
LEFT JOIN sii_folio_consumo fc
    ON  fc.caf_id    = c.id
    AND fc.estado   != 'rechazado_sii'
WHERE c.activo = 1
GROUP BY c.id;

-- -----------------------------------------------------------------------------
-- FIN
-- -----------------------------------------------------------------------------
SET foreign_key_checks = 1;
