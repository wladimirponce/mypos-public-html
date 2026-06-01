<?php
/**
 * retry_cron.php — Procesa la cola de reintentos automáticos al SII y
 * gestiona el modo contingencia.
 *
 * Programar en Task Scheduler cada 5-10 minutos.
 *   php.exe C:\sii\MyPOS\admin\retry_cron.php [--max=N]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo CLI";
    exit(1);
}

require_once __DIR__ . '/api.php';

$max = 20;
foreach ($_SERVER['argv'] as $a) {
    if (preg_match('/^--max=(\d+)$/', $a, $m)) $max = (int)$m[1];
}

$logFile = __DIR__ . '/tmp/retry_cron.log';
$ts = date('Y-m-d H:i:s');

try {
    // 1. Auto-toggle modo contingencia según healthcheck + cola
    $tog = autoToggleContingency();

    // 2. Procesar la cola
    $r = processRetryQueue($max);

    $line = "[$ts] queued={$r['queued']} processed={$r['processed']} success={$r['success']} still_failing={$r['still_failing']} dropped={$r['dropped']} | contingency=" . (isInContingencyMode() ? 'ON' : 'off');
    if (!empty($r['results'])) {
        $line .= " | results: " . json_encode($r['results'], JSON_UNESCAPED_UNICODE);
    }
    if (($tog['action'] ?? '') !== 'UNCHANGED') {
        $line .= " | contingency_change: " . json_encode($tog);
    }
    file_put_contents($logFile, $line . "\n", FILE_APPEND);
    echo $line . "\n";
    exit(0);
} catch (Throwable $e) {
    file_put_contents($logFile, "[$ts] EXCEPCION: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "EXCEPCION: " . $e->getMessage() . "\n";
    exit(1);
}
