<?php

declare(strict_types=1);

use Mypos\Services\AnalyticsSnapshotService;
use Mypos\Support\Env;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::loadFile(dirname($root) . '/.env');
Env::loadFile($root . '/.env');
date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'America/Santiago'));

$fecha = isset($argv[1]) ? (string) $argv[1] : date('Y-m-d');
$empresaId = isset($argv[2]) && (int) $argv[2] > 0 ? (int) $argv[2] : null;
try {
    $resultado = (new AnalyticsSnapshotService())->actualizar($fecha, $empresaId);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[analytics-snapshot] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
