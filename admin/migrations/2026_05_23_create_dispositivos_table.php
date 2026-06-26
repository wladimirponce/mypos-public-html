<?php
/**
 * =============================================================================
 *  Migración: tabla `sii_dispositivo`  (enrolamiento de máquinas POS)
 *  Fecha    : 2026-05-23
 * =============================================================================
 *
 *  Modelo de enrolamiento:
 *
 *    1. Admin genera un código de activación desde el dashboard → se guarda
 *       el hash SHA-256 del código y un timestamp de expiración (24 h).
 *
 *    2. Técnico instala el APK en la máquina POS, abre la pantalla de enrolamiento
 *       e ingresa el código de activación.
 *
 *    3. El APK llama a `api.php?action=dispositivo_enrolar` (sin API key).
 *       El servidor valida el código, genera una API key permanente y la devuelve
 *       junto con sucursal_id, nombre de sucursal y datos de la empresa.
 *
 *    4. El APK guarda la API key y queda listo para operar.
 *       El token queda marcado como usado y nunca se puede reutilizar.
 *
 *  La API key permanente generada aquí se inserta TAMBIÉN en `sii_api_key`
 *  para que sea reconocida por el sistema de autenticación existente.
 *
 *  USO:
 *    Revisión:   php migrations/2026_05_23_create_dispositivos_table.php
 *    Aplicar:    php migrations/2026_05_23_create_dispositivos_table.php --apply
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
echo "  Enrolamiento de Dispositivos  " . ($APPLY ? '[APLICAR]' : '[DRY-RUN]') . "\n";
echo "================================================================\n\n";

try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    exit('ABORTADO: sin conexión a BD: ' . $e->getMessage() . "\n");
}

// ─────────────────────────────────────────────────────────────────────────────
//  DDL — Tabla principal de dispositivos enrolados
// ─────────────────────────────────────────────────────────────────────────────
$ddlDispositivos = <<<SQL
CREATE TABLE IF NOT EXISTS `sii_dispositivo` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id`         INT UNSIGNED NOT NULL COMMENT 'FK sii_empresa.id',
    `sucursal_id`        VARCHAR(40) NOT NULL   COMMENT 'Sucursal asignada (dim_sucursal.id_sucursal)',
    `nombre`             VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'Nombre descriptivo (ej: POS Farmacia 1)',
    `tipo`               ENUM('POS_ANDROID','POS_WEB','APK_MOVIL','OTRO') NOT NULL DEFAULT 'POS_ANDROID',

    -- ── Enrolamiento (uso único, 24 h) ───────────────────────────────────────
    `token_activacion`   VARCHAR(20) NOT NULL  COMMENT 'Código legible generado por admin (p.ej.: A3F7K2)',
    `token_hash`         VARCHAR(64) NOT NULL  COMMENT 'SHA-256 del token (para búsqueda segura)',
    `token_expira`       DATETIME NOT NULL     COMMENT 'El token pierde validez pasada esta fecha',
    `token_usado`        TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ya consumido por el APK',

    -- ── Post-enrolamiento ────────────────────────────────────────────────────
    `api_key_hash`       VARCHAR(64) NULL      COMMENT 'SHA-256 de la API key permanente emitida',
    `device_android_id`  VARCHAR(64) NULL      COMMENT 'android.provider.Settings.Secure.ANDROID_ID',
    `device_modelo`      VARCHAR(100) NULL     COMMENT 'Build.MODEL del dispositivo',
    `device_version_app` VARCHAR(20) NULL      COMMENT 'BuildConfig.VERSION_NAME del APK',
    `enrolado_en`        DATETIME NULL         COMMENT 'Timestamp del enrolamiento exitoso',
    `ultimo_contacto`    DATETIME NULL         COMMENT 'Último heartbeat / sync del dispositivo',

    -- ── Estado ───────────────────────────────────────────────────────────────
    `activo`             TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = desactivado por admin',
    `notas`              VARCHAR(255) NULL,
    `creado_en`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token_hash` (`token_hash`),
    KEY `idx_empresa`  (`empresa_id`),
    KEY `idx_sucursal` (`sucursal_id`),
    KEY `idx_activo`   (`activo`),
    KEY `idx_api_key_hash` (`api_key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Dispositivos POS enrolados: generación de tokens y API keys por máquina';
SQL;

echo "── Tabla `sii_dispositivo` ─────────────────────────────────────────────\n";
if (!$APPLY) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'sii_dispositivo'");
    $exists = (bool) $stmt->fetch();
    echo "  " . ($exists ? "ya existe" : "se creará") . "\n\n";
} else {
    try {
        $pdo->exec($ddlDispositivos);
        echo "  ✓ OK\n\n";
    } catch (PDOException $e) {
        echo "  ✗ ERROR: " . $e->getMessage() . "\n\n";
        exit(1);
    }
}

if (!$APPLY) {
    echo "\nDry-run completo. Ejecuta con --apply para crear la tabla.\n";
    echo "\nEjemplo de uso:\n";
    echo "  php migrations/2026_05_23_create_dispositivos_table.php --apply\n";
} else {
    echo "\n✓ Migración completada.\n";
    echo "\nPróximo paso: desde el dashboard, ve a Gestión → Dispositivos\n";
    echo "y genera el primer código de activación para una máquina POS.\n";
}
