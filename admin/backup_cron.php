<?php
/**
 * backup_cron.php — Genera backup .zip de archivos críticos.
 * Programar en Task Scheduler diario (o cada N horas).
 *   php.exe C:\sii\MyPOS\admin\backup_cron.php [--keep=N]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo CLI";
    exit(1);
}

require_once __DIR__ . '/api.php';

$keep = 14;
foreach ($_SERVER['argv'] as $a) {
    if (preg_match('/^--keep=(\d+)$/', $a, $m)) $keep = (int)$m[1];
}

$logFile = __DIR__ . '/tmp/backup_cron.log';
$ts = date('Y-m-d H:i:s');

try {
    $r = createBackup($keep);
    if ($r['ok']) {
        $line = "[$ts] BACKUP OK: " . basename($r['path']) . " (" . number_format($r['size']) . " bytes, " . count($r['files']) . " archivos, rotación: -{$r['deleted_old']} viejos)";
        file_put_contents($logFile, $line . "\n", FILE_APPEND);
        echo $line . "\n";
        exit(0);
    }
    $line = "[$ts] BACKUP FAIL: " . ($r['error'] ?? 'sin detalle');
    file_put_contents($logFile, $line . "\n", FILE_APPEND);
    echo $line . "\n";
    exit(1);
} catch (Throwable $e) {
    file_put_contents($logFile, "[$ts] EXCEPCION: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "EXCEPCION: " . $e->getMessage() . "\n";
    exit(1);
}
