<?php

declare(strict_types=1);

use Mypos\Services\Agente\AgenteCorreoOutboxService;
use Mypos\Support\Env;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::loadFile(dirname($root) . '/.env');
Env::loadFile($root . '/.env');
date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'America/Santiago'));

try {
    $r = (new AgenteCorreoOutboxService())->procesarPendientes(50);
    printf("[%s] procesados=%d enviados=%d reintentos=%d fallidos=%d\n", date('Y-m-d H:i:s'), $r['procesados'], $r['enviados'], $r['reintentados'], $r['fallidos']);
    exit($r['fallidos'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '[agente-correos] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
