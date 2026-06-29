<?php
/**
 * rcof_cron.php - Envio automatico multiempresa del RCOF al SII.
 *
 * Uso desde Task Scheduler de Windows:
 *   php.exe C:\sii\MyPOS\admin\rcof_cron.php
 *
 * Sin argumentos envia el RCOF del dia anterior para todas las empresas activas
 * que esten en DTE activo, modo REAL y ambiente PRODUCCION.
 *
 * Opciones:
 *   --fecha=YYYY-MM-DD    Sobrescribe la fecha a reportar.
 *   --force               Reenvia aunque ya exista un RCOF exitoso para esa fecha.
 *   --today               Reporta el dia de hoy.
 *   --empresa_id=N        Limita la ejecucion a una empresa.
 *   --dry-run             Escanea y registra auditoria, sin enviar al SII.
 *
 * Salida: log a tmp/rcof_cron.log y exit code 0 si OK, 1 si falla.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo CLI";
    exit(1);
}

require_once __DIR__ . '/api.php';

$args = $_SERVER['argv'];
$fecha = null;
$force = false;
$empresaId = null;
$dryRun = false;

foreach ($args as $a) {
    if (preg_match('/^--fecha=(\d{4}-\d{2}-\d{2})$/', $a, $m)) {
        $fecha = $m[1];
    }
    if (preg_match('/^--empresa_id=(\d+)$/', $a, $m)) {
        $empresaId = (int)$m[1];
    }
    if ($a === '--force') {
        $force = true;
    }
    if ($a === '--today') {
        $fecha = date('Y-m-d');
    }
    if ($a === '--dry-run') {
        $dryRun = true;
    }
}

if ($fecha === null) {
    $fecha = date('Y-m-d', strtotime('-1 day'));
}

$logFile = __DIR__ . '/tmp/rcof_cron.log';
$tsLog = date('Y-m-d H:i:s');

$writeLog = function(string $line) use ($logFile, $tsLog): void {
    file_put_contents($logFile, "[$tsLog] $line\n", FILE_APPEND);
};

$writeLog(
    "=== Inicio RCOF cron multiempresa | fecha=$fecha | force=" . ($force ? 'yes' : 'no')
    . " | dry_run=" . ($dryRun ? 'yes' : 'no')
    . " | empresa_id=" . ($empresaId ?: 'ALL') . " ==="
);

try {
    $res = runRcofMultiTenant($fecha, $force, $empresaId, $dryRun);
    $writeLog("Resultado: " . json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

    if (empty($res['ok'])) {
        echo "FALLO RCOF multiempresa $fecha: {$res['error_count']} error(es), {$res['ok_count']} OK, {$res['skipped_count']} omitida(s). Run {$res['run_id']}.\n";
        $writeLog("FALLO: run_id=" . ($res['run_id'] ?? '') . " errores=" . ($res['error_count'] ?? 0));
        exit(1);
    }

    echo "OK: RCOF multiempresa $fecha completado. {$res['ok_count']} OK, {$res['skipped_count']} omitida(s), {$res['error_count']} error(es). Run {$res['run_id']}.\n";
    exit(0);
} catch (Throwable $e) {
    $writeLog("EXCEPCION: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
    echo "EXCEPCION: " . $e->getMessage() . "\n";
    exit(1);
}
