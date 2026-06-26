-- =============================================================================
--  Migración: tabla `sii_dispositivo`  (enrolamiento de máquinas POS)
--  Fecha    : 2026-05-23
-- =============================================================================
--
--  Flujo de enrolamiento:
--    1. Admin genera un código de activación (6 chars) desde el dashboard.
--    2. Técnico instala el APK, ingresa el código en la pantalla de enrolamiento.
--    3. El servidor valida el código, genera una API key permanente y la devuelve.
--    4. El dispositivo queda configurado y puede emitir boletas.
--
--  La API key permanente se inserta también en `sii_api_key` para que sea
--  reconocida por el sistema de autenticación existente.
--
--  Idempotente: usa CREATE TABLE IF NOT EXISTS.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `sii_dispositivo` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id`         INT UNSIGNED NOT NULL                 COMMENT 'FK sii_empresa.id',
    `sucursal_id`        VARCHAR(40)  NOT NULL                 COMMENT 'Sucursal asignada (dim_sucursal.id_sucursal)',
    `nombre`             VARCHAR(120) NOT NULL DEFAULT ''      COMMENT 'Nombre descriptivo (ej: POS Farmacia 1)',
    `tipo`               ENUM('POS_ANDROID','POS_WEB','APK_MOVIL','OTRO')
                                      NOT NULL DEFAULT 'POS_ANDROID',

    -- Enrolamiento (token de uso único, válido 24 h)
    `token_activacion`   VARCHAR(20)  NOT NULL                 COMMENT 'Código legible generado por admin (ej: A3F7K2)',
    `token_hash`         VARCHAR(64)  NOT NULL                 COMMENT 'SHA-256 del token',
    `token_expira`       DATETIME     NOT NULL                 COMMENT 'Expiración del token',
    `token_usado`        TINYINT(1)   NOT NULL DEFAULT 0       COMMENT '1 = ya consumido por el APK',

    -- Post-enrolamiento
    `api_key_hash`       VARCHAR(64)  NULL                     COMMENT 'SHA-256 de la API key permanente emitida',
    `device_android_id`  VARCHAR(64)  NULL                     COMMENT 'Settings.Secure.ANDROID_ID del dispositivo',
    `device_modelo`      VARCHAR(100) NULL                     COMMENT 'Build.MODEL del dispositivo',
    `device_version_app` VARCHAR(20)  NULL                     COMMENT 'BuildConfig.VERSION_NAME del APK',
    `enrolado_en`        DATETIME     NULL                     COMMENT 'Timestamp del enrolamiento exitoso',
    `ultimo_contacto`    DATETIME     NULL                     COMMENT 'Último heartbeat del dispositivo',

    -- Estado
    `activo`             TINYINT(1)   NOT NULL DEFAULT 1       COMMENT '0 = desactivado por admin',
    `notas`              VARCHAR(255) NULL,
    `creado_en`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token_hash`  (`token_hash`),
    KEY `idx_empresa`           (`empresa_id`),
    KEY `idx_sucursal`          (`sucursal_id`),
    KEY `idx_activo`            (`activo`),
    KEY `idx_api_key_hash`      (`api_key_hash`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Dispositivos POS enrolados: tokens de activación y API keys por máquina';
