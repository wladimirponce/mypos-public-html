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

$formatCronRows = function(array $res, string $estado): string {
    $rows = array_values(array_filter($res['results'] ?? [], function($row) use ($estado) {
        return strtoupper((string)($row['estado'] ?? '')) === $estado;
    }));

    if (!$rows) {
        return '';
    }

    $lines = [];
    foreach ($rows as $row) {
        $tracking = is_array($row['tracking'] ?? null) ? $row['tracking'] : [];
        $empresa = trim((string)($row['razon_social'] ?? ''));
        $rut = trim((string)($row['rut'] ?? ''));
        $id = (int)($row['empresa_id'] ?? 0);
        $modo = (string)($tracking['modo'] ?? ($row['modo'] ?? ''));
        $dteActivo = array_key_exists('dte_activo', $tracking) ? (string)$tracking['dte_activo'] : (string)($row['dte_activo'] ?? '');
        $ambiente = (string)($tracking['ambiente'] ?? ($row['ambiente'] ?? ''));
        $detalle = (string)($row['error'] ?? $row['mensaje'] ?? '');

        $line = "- empresa_id=$id";
        if ($empresa !== '') {
            $line .= " | $empresa";
        }
        if ($rut !== '') {
            $line .= " | RUT $rut";
        }
        if ($modo !== '' || $dteActivo !== '' || $ambiente !== '') {
            $line .= " | modo=" . ($modo !== '' ? $modo : '-')
                . " | dte_activo=" . ($dteActivo !== '' ? $dteActivo : '-')
                . " | ambiente=" . ($ambiente !== '' ? $ambiente : '-');
        }
        if ($detalle !== '') {
            $line .= " | $detalle";
        }
        $lines[] = $line;
    }

    return implode("\n", $lines) . "\n";
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
        if (!empty($res['skipped_count'])) {
            echo "Empresas omitidas:\n" . $formatCronRows($res, 'NO_APLICA');
        }
        if (!empty($res['error_count'])) {
            echo "Empresas con error:\n" . $formatCronRows($res, 'ERROR');
        }
        $writeLog("FALLO: run_id=" . ($res['run_id'] ?? '') . " errores=" . ($res['error_count'] ?? 0));
        exit(1);
    }

    echo "OK: RCOF multiempresa $fecha completado. {$res['ok_count']} OK, {$res['skipped_count']} omitida(s), {$res['error_count']} error(es). Run {$res['run_id']}.\n";
    if (!empty($res['skipped_count'])) {
        echo "Empresas omitidas:\n" . $formatCronRows($res, 'NO_APLICA');
    }
    exit(0);
} catch (Throwable $e) {
    $writeLog("EXCEPCION: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
    echo "EXCEPCION: " . $e->getMessage() . "\n";
    exit(1);
}
