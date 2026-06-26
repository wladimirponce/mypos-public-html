<?php
/**
 * =============================================================================
 * Despliegue ALCAINO (Legacy -> empresa real) — COMANDO ÚNICO
 * Fecha : 2026-05-17
 * =============================================================================
 *
 * Ejecuta, en orden y con la MISMA conexión que usa la app (sin duplicar
 * credenciales), las dos migraciones:
 *
 *   1) 2026_05_17_004_registrar_alcaino_produccion.sql   (upsert sii_empresa)
 *   2) 2026_05_17_005_migrar_legacy_a_alcaino.php         (archivos+CAF+backfill)
 *
 * USO (en el servidor, desde la raíz del proyecto):
 *   Revisión (no escribe nada):
 *     php migrations/2026_05_17_deploy_alcaino.php
 *   Aplicar:
 *     php migrations/2026_05_17_deploy_alcaino.php --apply
 *
 * Idempotente: re-ejecutable sin daño. DRY-RUN por defecto.
 * =============================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI.\n");
}

$APPLY = in_array('--apply', $argv, true);
$DIR   = __DIR__;
$ROOT  = dirname($DIR);

require_once $ROOT . '/autoload.php';

use App\Core\Database;

echo "============================================================\n";
echo "  DESPLIEGUE ALCAINO  " . ($APPLY ? '[APLICAR]' : '[DRY-RUN]') . "\n";
echo "============================================================\n\n";

// ── Paso 1: ejecutar el .sql idempotente con la conexión de la app ──────────
$sqlFile = "$DIR/2026_05_17_004_registrar_alcaino_produccion.sql";
if (!file_exists($sqlFile)) {
    exit("ABORTADO: no se encontró $sqlFile\n");
}

try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    exit('ABORTADO: sin conexión a BD: ' . $e->getMessage() . "\n");
}

echo "-- Paso 1: SQL sii_empresa / sii_config ---------------------\n";
$sqlRaw = file_get_contents($sqlFile);

// Quitar comentarios de línea (-- ...) y partir por ';'
$clean = preg_replace('/^\s*--.*$/m', '', $sqlRaw);
$stmts = array_filter(array_map('trim', explode(';', $clean)), static fn($s) => $s !== '');

foreach ($stmts as $st) {
    $head = trim(preg_split('/\s+/', $st)[0] ?? '');
    $isSelect = strtoupper($head) === 'SELECT';
    $isSet    = strtoupper($head) === 'SET';

    if ($isSelect) {
        // Verificación informativa: se ejecuta siempre (solo lectura)
        try {
            $rows = $pdo->query($st)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) echo '  -> ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        } catch (Throwable $e) {
            echo '  (verificación omitida: ' . $e->getMessage() . ")\n";
        }
        continue;
    }

    if (!$APPLY) {
        echo '  [DRY-RUN] ' . preg_replace('/\s+/', ' ', mb_substr($st, 0, 90)) . "...\n";
        continue;
    }
    try {
        $pdo->exec($st);
        echo '  OK  ' . ($isSet ? 'SET' : preg_replace('/\s+/', ' ', mb_substr($st, 0, 70))) . "...\n";
    } catch (Throwable $e) {
        exit('  ERROR ejecutando SQL: ' . $e->getMessage() . "\n");
    }
}
echo "\n";

// ── Paso 2: encadenar el script 005 (archivos + CAF + backfill) ─────────────
echo "-- Paso 2: migración archivos / CAF / DTEs ------------------\n";
$php   = PHP_BINARY;
$child = "$DIR/2026_05_17_005_migrar_legacy_a_alcaino.php";
$args  = $APPLY ? ' --apply' : '';
$cmd   = escapeshellarg($php) . ' ' . escapeshellarg($child) . $args;

passthru($cmd, $code);

echo "\n============================================================\n";
echo $APPLY
    ? "  DESPLIEGUE COMPLETO.\n"
    : "  REVISIÓN COMPLETA. Re-ejecute con --apply para aplicar.\n";
echo "============================================================\n";
exit($code ?: 0);
