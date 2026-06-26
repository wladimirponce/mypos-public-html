-- =============================================================================
--  Migración: cola de DTEs locales + alertas de stock de folios POS
--  Fecha    : 2026-05-21
--
--  Descripción:
--    - dte_local_queue : DTEs generados y firmados en los APKs POS, pendientes
--                        de envío batch al SII.  El APK imprime al instante; el
--                        servidor transmite cuando hay conectividad.
--    - folios_alerta   : Reportes de stock enviados por cada APK cuando detecta
--                        nivel BAJO o CRÍTICO.  Permite al administrador ver la
--                        necesidad GLOBAL del sistema (1..N sucursales) sin
--                        depender de consultar cada máquina por separado.
--
--  Idempotente: usa CREATE TABLE IF NOT EXISTS
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
--  Cola de DTEs generados localmente en los POS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `dte_local_queue` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipo_dte`       SMALLINT UNSIGNED NOT NULL COMMENT '39=boleta, 41=exenta, 52=guía…',
    `folio`          INT UNSIGNED NOT NULL,
    `sucursal_id`    VARCHAR(40) NOT NULL,
    `maquina_id`     VARCHAR(40) NULL      COMMENT 'ID único del POS emisor',
    `fecha_emision`  DATE NOT NULL,
    `rut_receptor`   VARCHAR(12) NOT NULL DEFAULT '66666666-6',
    `mnt_total`      BIGINT NOT NULL,
    `mnt_neto`       BIGINT NOT NULL DEFAULT 0,
    `mnt_iva`        BIGINT NOT NULL DEFAULT 0,
    `ted_xml`        MEDIUMTEXT NOT NULL   COMMENT 'Bloque <TED> firmado por el APK con clave CAF',
    `items_json`     JSON NULL             COMMENT '[{NmbItem,QtyItem,PrcItem,DescuentoPct}]',
    `estado`         ENUM('pendiente','procesando','enviado','error','dropped')
                     NOT NULL DEFAULT 'pendiente',
    `track_id`       VARCHAR(50) NULL      COMMENT 'TrackID SII tras envío exitoso',
    `intentos`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `ultimo_error`   TEXT NULL,
    `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_proceso`  DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tipo_folio`  (`tipo_dte`, `folio`),
    KEY `idx_estado`            (`estado`, `fecha_creacion`),
    KEY `idx_sucursal_fecha`    (`sucursal_id`, `fecha_emision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cola de DTEs generados en POS local, pendientes de envío batch al SII';

-- ─────────────────────────────────────────────────────────────────────────────
--  Alertas de stock de folios reportadas por los APKs
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `folios_alerta` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sucursal_id`     VARCHAR(40) NOT NULL,
    `maquina_id`      VARCHAR(40) NOT NULL DEFAULT ''
                      COMMENT 'Vacío = sucursal sin ID de máquina',
    `tipo_dte`        SMALLINT UNSIGNED NOT NULL,
    `folios_locales`  INT UNSIGNED NOT NULL DEFAULT 0
                      COMMENT 'Stock actual en el APK al momento del reporte',
    `dias_estimados`  DECIMAL(6,1) NULL
                      COMMENT 'Días estimados por el APK según consumo local',
    `nivel`           ENUM('ok','bajo','critico') NOT NULL DEFAULT 'ok',
    `fecha_primera`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                      COMMENT 'Primera vez que se registró este (sucursal,maquina,tipo)',
    `actualizado`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sucursal_maquina_tipo` (`sucursal_id`, `maquina_id`, `tipo_dte`),
    KEY `idx_nivel_actualiz`  (`nivel`, `actualizado`),
    KEY `idx_sucursal`        (`sucursal_id`, `tipo_dte`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Alertas de stock de folios reportadas en tiempo real por los APKs POS';
