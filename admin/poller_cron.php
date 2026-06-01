<?php
/**
 * poller_cron.php — Reconsulta el SII por DTEs en estado intermedio (REC, EPR…)
 *                   y actualiza a estado terminal (DOK/DNK/RSC/etc.)
 *
 * Uso desde Task Scheduler de Windows (recomendado: cada 15 min):
 *   php.exe C:\sii\MyPOS\admin\poller_cron.php [--max=N]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo CLI";
    exit(1);
}

require_once __DIR__ . '/api.php';

$max = 50;
foreach ($_SERVER['argv'] as $a) {
    if (preg_match('/^--max=(\d+)$/', $a, $m)) $max = (int)$m[1];
}

$logFile = __DIR__ . '/tmp/poller_cron.log';
$ts = date('Y-m-d H:i:s');

try {
    $r = pollEstadoDTEs($max);
    $line = "[$ts] scanned={$r['scanned']} updated={$r['updated']} skipped_final={$r['skipped_final']} unchanged={$r['unchanged']} errors={$r['errors']}";
    if (!empty($r['changes'])) {
        $line .= " | changes: " . json_encode($r['changes'], JSON_UNESCAPED_UNICODE);
    }
    file_put_contents($logFile, $line . "\n", FILE_APPEND);
    echo $line . "\n";
    exit(0);
} catch (Throwable $e) {
    file_put_contents($logFile, "[$ts] EXCEPCION: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "EXCEPCION: " . $e->getMessage() . "\n";
    exit(1);
}
