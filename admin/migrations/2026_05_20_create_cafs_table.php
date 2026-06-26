<?php
/**
 * =============================================================================
 *  Migración: tabla `cafs` + `caf_consumos`  (gestión centralizada de folios)
 *  Fecha    : 2026-05-20
 * =============================================================================
 *
 *  Modelo de dos sub-flujos:
 *
 *    1. BOOTSTRAP (CAFs legacy físicos en cada POS):
 *       - Cada máquina sube su(s) CAF(s) locales vía app → caf_subir
 *       - Quedan registrados con sucursal_id asignada (la sucursal que los subió)
 *       - La máquina sigue consumiéndolos como antes hasta agotar
 *
 *    2. OPERACIÓN NORMAL (CAFs centralizados):
 *       - Admin sube CAFs grandes solicitados al SII vía dashboard
 *       - dte_php los divide en sub-rangos por sucursal según consumo medio
 *         (insert múltiples filas con mismo rut+tipo pero distinto sucursal_id)
 *       - Cada POS consulta caf_disponibles periódicamente y descarga lo suyo
 *
 *  USO:
 *    Revisión:   php migrations/2026_05_20_create_cafs_table.php
 *    Aplicar:    php migrations/2026_05_20_create_cafs_table.php --apply
 *
 *  Idempotente: usa CREATE TABLE IF NOT EXISTS.
 * =============================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI.\n");
}

$APPLY = in_array('--apply', $argv, true);
require_once dirname(__DIR__) . '/autoload.php';

use App\Core\Database;

echo "================================================================\n";
echo "  CAFs centralizados  " . ($APPLY ? '[APLICAR]' : '[DRY-RUN]') . "\n";
echo "================================================================\n\n";

try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    exit('ABORTADO: sin conexión a BD: ' . $e->getMessage() . "\n");
}

// ─────────────────────────────────────────────────────────────────────────────
//  DDL — Tabla principal de CAFs
// ─────────────────────────────────────────────────────────────────────────────
$ddlCafs = <<<SQL
CREATE TABLE IF NOT EXISTS `cafs` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipo_dte`        SMALLINT UNSIGNED NOT NULL COMMENT '33,39,41,52,56,61',
    `rut_emisor`      VARCHAR(12) NOT NULL,
    `razon_social`    VARCHAR(120) NOT NULL DEFAULT '',
    `folio_desde`     INT UNSIGNED NOT NULL,
    `folio_hasta`     INT UNSIGNED NOT NULL,
    `folio_actual`    INT UNSIGNED NOT NULL COMMENT 'próximo folio a entregar (>= folio_desde)',
    `sucursal_id`     VARCHAR(40) NULL COMMENT 'NULL = pool central no asignado',
    `xml_content`     LONGTEXT NOT NULL COMMENT 'XML completo del CAF (con RSASK)',
    `fecha_autorizacion` DATE NULL COMMENT 'FA del CAF',
    `fecha_subida`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `origen`          ENUM('LEGACY','CENTRAL') NOT NULL DEFAULT 'CENTRAL'
                      COMMENT 'LEGACY = subido desde POS físico; CENTRAL = subido por admin',
    `agotado`         TINYINT(1) NOT NULL DEFAULT 0,
    `observaciones`   VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_busqueda` (`rut_emisor`, `tipo_dte`, `sucursal_id`, `agotado`),
    KEY `idx_rango`    (`tipo_dte`, `folio_desde`, `folio_hasta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='CAFs SII registrados centralmente para distribución a sucursales';
SQL;

// ─────────────────────────────────────────────────────────────────────────────
//  DDL — Auditoría de consumos (cada folio usado por una sucursal)
// ─────────────────────────────────────────────────────────────────────────────
$ddlConsumos = <<<SQL
CREATE TABLE IF NOT EXISTS `caf_consumos` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `caf_id`        INT UNSIGNED NOT NULL,
    `tipo_dte`     SMALLINT UNSIGNED NOT NULL,
    `folio`         INT UNSIGNED NOT NULL,
    `sucursal_id`   VARCHAR(40) NOT NULL,
    `maquina_id`    VARCHAR(40) NULL COMMENT 'ID único de la máquina POS si aplica',
    `dte_id`        BIGINT UNSIGNED NULL COMMENT 'FK a dte si se subió XML completo',
    `fecha_consumo` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_folio_tipo` (`tipo_dte`, `folio`),
    KEY `idx_caf` (`caf_id`),
    KEY `idx_sucursal_fecha` (`sucursal_id`, `fecha_consumo`),
    CONSTRAINT `fk_consumo_caf` FOREIGN KEY (`caf_id`)
        REFERENCES `cafs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auditoría de cada folio entregado a una sucursal';
SQL;

// ─────────────────────────────────────────────────────────────────────────────
//  DDL — Cache de consumo medio por sucursal/tipo (para distribución)
// ─────────────────────────────────────────────────────────────────────────────
$ddlConsumoMedio = <<<SQL
CREATE TABLE IF NOT EXISTS `caf_consumo_medio` (
    `sucursal_id`     VARCHAR(40) NOT NULL,
    `tipo_dte`        SMALLINT UNSIGNED NOT NULL,
    `folios_30d`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'consumo últimos 30 días',
    `folios_dia_prom` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `actualizado`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`sucursal_id`, `tipo_dte`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Estadística rodante de consumo de folios por sucursal';
SQL;

$statements = [
    'cafs'                => $ddlCafs,
    'caf_consumos'        => $ddlConsumos,
    'caf_consumo_medio'   => $ddlConsumoMedio,
];

foreach ($statements as $name => $sql) {
    echo "── Tabla `$name` ──────────────────────────────────────────────\n";
    if (!$APPLY) {
        // Verificar si existe
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
        $exists = (bool) $stmt->fetch();
        echo "  " . ($exists ? "ya existe" : "se creará") . "\n\n";
        continue;
    }
    try {
        $pdo->exec($sql);
        echo "  ✓ OK\n\n";
    } catch (PDOException $e) {
        echo "  ✗ ERROR: " . $e->getMessage() . "\n\n";
        exit(1);
    }
}

if (!$APPLY) {
    echo "\nDry-run completo. Ejecuta con --apply para crear las tablas.\n";
} else {
    echo "\n✓ Migración completada.\n";
}
