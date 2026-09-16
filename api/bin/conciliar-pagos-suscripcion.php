<?php

declare(strict_types=1);

/**
 * Conciliador de pagos de suscripcion (Flow).
 *
 * MyPOS cobra de forma manual: el cliente paga cada mes con Flow. Un pago se
 * acredita normalmente por el webhook, y si ese no llega, por la reconciliacion
 * que ocurre cuando el cliente vuelve al SPA. Este script es la tercera red:
 * atrapa al que pago y cerro el navegador antes de volver, cuando ademas el
 * webhook fallo. Sin el, ese cliente pierde el acceso a los 5 dias pese a haber
 * pagado.
 *
 * Uso:
 *   php bin/conciliar-pagos-suscripcion.php [horas]
 *
 * `horas` es la ventana hacia atras que se revisa (por defecto 72). Pensado
 * para cron cada 15-30 minutos.
 */

use Mypos\Services\SuscripcionService;
use Mypos\Support\Env;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::loadFile(dirname($root) . '/.env');
Env::loadFile($root . '/.env');
date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'America/Santiago'));

$horas = isset($argv[1]) && (int) $argv[1] > 0 ? (int) $argv[1] : 72;

try {
    $resumen = (new SuscripcionService())->reconciliarPagosFlow($horas);
    echo json_encode($resumen, JSON_UNESCAPED_UNICODE) . PHP_EOL;

    // Sale distinto de 0 si algo no se pudo verificar, para que el cron avise.
    exit($resumen['errores'] > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '[conciliar-pagos-suscripcion] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
