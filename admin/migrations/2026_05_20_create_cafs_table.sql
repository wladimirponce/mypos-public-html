-- =============================================================================
--  Migración: tabla `cafs` + `caf_consumos` + `caf_consumo_medio`
--  Fecha    : 2026-05-20
--  Modelo   : Gestión centralizada multi-sucursal de folios CAF SII
--
--  USO:
--    Opción A — phpMyAdmin / cliente SQL:
--      Pegar este archivo completo en la pestaña SQL de la BD `mypos`
--    Opción B — CLI:
--      mysql -u USER -p mypos < migrations/2026_05_20_create_cafs_table.sql
--
--  Idempotente: usa CREATE TABLE IF NOT EXISTS.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
--  Tabla principal de CAFs
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cafs` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipo_dte`        SMALLINT UNSIGNED NOT NULL COMMENT '33,39,41,52,56,61',
    `rut_emisor`      VARCHAR(12) NOT NULL,
    `razon_social`    VARCHAR(120) NOT NULL DEFAULT '',
    `folio_desde`     INT UNSIGNED NOT NULL,
    `folio_hasta`     INT UNSIGNED NOT NULL,
    `folio_actual`    INT UNSIGNED NOT NULL COMMENT 'proximo folio a entregar (>= folio_desde)',
    `sucursal_id`     VARCHAR(40) NULL COMMENT 'NULL = pool central no asignado',
    `xml_content`     LONGTEXT NOT NULL COMMENT 'XML completo del CAF (con RSASK)',
    `fecha_autorizacion` DATE NULL COMMENT 'FA del CAF',
    `fecha_subida`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `origen`          ENUM('LEGACY','CENTRAL') NOT NULL DEFAULT 'CENTRAL'
                      COMMENT 'LEGACY = subido desde POS fisico; CENTRAL = subido por admin',
    `agotado`         TINYINT(1) NOT NULL DEFAULT 0,
    `observaciones`   VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_busqueda` (`rut_emisor`, `tipo_dte`, `sucursal_id`, `agotado`),
    KEY `idx_rango`    (`tipo_dte`, `folio_desde`, `folio_hasta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='CAFs SII registrados centralmente para distribucion a sucursales';

-- ─────────────────────────────────────────────────────────────────────────────
--  Auditoria de cada folio entregado a una sucursal
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `caf_consumos` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `caf_id`        INT UNSIGNED NOT NULL,
    `tipo_dte`      SMALLINT UNSIGNED NOT NULL,
    `folio`         INT UNSIGNED NOT NULL,
    `sucursal_id`   VARCHAR(40) NOT NULL,
    `maquina_id`    VARCHAR(40) NULL COMMENT 'ID unico de la maquina POS si aplica',
    `dte_id`        BIGINT UNSIGNED NULL COMMENT 'FK a dte si se subio XML completo',
    `fecha_consumo` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_folio_tipo` (`tipo_dte`, `folio`),
    KEY `idx_caf` (`caf_id`),
    KEY `idx_sucursal_fecha` (`sucursal_id`, `fecha_consumo`),
    CONSTRAINT `fk_consumo_caf` FOREIGN KEY (`caf_id`)
        REFERENCES `cafs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auditoria de cada folio entregado a una sucursal';

-- ─────────────────────────────────────────────────────────────────────────────
--  Cache de consumo medio por sucursal/tipo (para distribucion inteligente)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `caf_consumo_medio` (
    `sucursal_id`     VARCHAR(40) NOT NULL,
    `tipo_dte`        SMALLINT UNSIGNED NOT NULL,
    `folios_30d`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'consumo ultimos 30 dias',
    `folios_dia_prom` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `actualizado`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`sucursal_id`, `tipo_dte`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Estadistica rodante de consumo de folios por sucursal';
