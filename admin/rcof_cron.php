<?php
/**
 * rcof_cron.php — Envío automático del RCOF (Resumen Consumo de Folios) al SII.
 *
 * Uso desde Task Scheduler de Windows:
 *   php.exe C:\sii\MyPOS\admin\rcof_cron.php
 *
 * Sin argumentos: envía el RCOF del día ANTERIOR (lo habitual, ejecutado de madrugada).
 *   --fecha=YYYY-MM-DD    Sobrescribe la fecha a reportar.
 *   --force               Reenvía aunque ya exista un RCOF exitoso para esa fecha
 *                         (incrementa SecEnvio).
 *   --today               Reporta el día de hoy (no ayer).
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
foreach ($args as $a) {
    if (preg_match('/^--fecha=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $fecha = $m[1];
    if ($a === '--force') $force = true;
    if ($a === '--today') $fecha = date('Y-m-d');
}
if ($fecha === null) {
    $fecha = date('Y-m-d', strtotime('-1 day'));
}

$logFile = __DIR__ . '/tmp/rcof_cron.log';
$tsLog = date('Y-m-d H:i:s');

$writeLog = function(string $line) use ($logFile, $tsLog) {
    file_put_contents($logFile, "[$tsLog] $line\n", FILE_APPEND);
};

$writeLog("=== Inicio RCOF cron | fecha=$fecha | force=" . ($force ? 'yes' : 'no') . " ===");

try {
    $res = submitDailyRCOF($fecha, $force);
    $writeLog("Resultado: " . json_encode($res, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

    if (!empty($res['skipped'])) {
        echo "RCOF ya enviado para $fecha. " . ($res['mensaje'] ?? '') . "\n";
        exit(0);
    }

    if (empty($res['ok'])) {
        echo "FALLO: " . ($res['mensaje'] ?? 'sin detalle') . "\n";
        $writeLog("FALLO: " . ($res['error'] ?? 'sin detalle'));
        exit(1);
    }

    echo "OK: RCOF $fecha seq {$res['secuencia']} enviado vía {$res['via']}. TrackID {$res['trackId']}.\n";
    exit(0);

} catch (Throwable $e) {
    $writeLog("EXCEPCION: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
    echo "EXCEPCION: " . $e->getMessage() . "\n";
    exit(1);
}
