<?php
/**
 * Cron de procesamiento de cola de DTEs locales.
 *
 * Ejecutar cada 5 minutos:
 *   * * * * * cd /var/www/html/admin && php dte_cola_cron.php >> /var/log/dte_cola.log 2>&1
 *
 * O como cron (si hay acceso SSH):
 *   * /5 * * * * php /path/to/admin/dte_cola_cron.php
 *
 * También se puede llamar vía API autenticada:
 *   POST api.php?action=dte_cola_procesar&max=20
 */

// Bootstrap sin ejecutar el switch/case de la API
define('DTE_API_BOOTSTRAP_ONLY', true);
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/api.php';

$max      = (int)($_SERVER['argv'][1] ?? 20);
$queueMgr = new \App\Services\DteLocalQueueManager();
$pend     = $queueMgr->getPendientes($max);

if (empty($pend)) {
    echo date('Y-m-d H:i:s') . " | Cola vacía — nada que procesar\n";
    exit(0);
}

echo date('Y-m-d H:i:s') . " | Procesando " . count($pend) . " DTEs pendientes…\n";

$ok   = 0;
$err  = 0;

foreach ($pend as $item) {
    $itemId  = (int)$item['id'];
    $tipoDte = (int)$item['tipo_dte'];
    $folio   = (int)$item['folio'];

    $queueMgr->marcarProcesando($itemId);
    try {
        $items = json_decode((string)($item['items_json'] ?? '[]'), true) ?: [];
        $dteData = [
            'tipoDTE'     => $tipoDte,
            'folio'       => $folio,
            'fecha'       => $item['fecha_emision'],
            'receptor'    => [
                'rut'    => $item['rut_receptor'],
                'nombre' => 'Consumidor Final',
            ],
            'items'       => $items,
            'indServicio' => 3,
        ];

        $gen = generateDTE($dteData);
        if (empty($gen['ok'])) {
            $queueMgr->marcarError($itemId, $gen['error'] ?? 'generateDTE falló');
            echo "  ✗ T{$tipoDte}F{$folio}: " . ($gen['error'] ?? '') . "\n";
            $err++;
            continue;
        }

        $send = sendDTE(['xml' => $gen['xml'], 'tipo' => $tipoDte, 'folio' => $folio]);
        if (!empty($send['ok']) && !empty($send['trackId'])) {
            $queueMgr->marcarEnviado($itemId, $send['trackId']);
            echo "  ✓ T{$tipoDte}F{$folio} → TrackID: {$send['trackId']}\n";
            $ok++;
        } else {
            $queueMgr->marcarError($itemId, $send['error'] ?? 'sendDTE sin trackId');
            echo "  ✗ T{$tipoDte}F{$folio} SII: " . ($send['error'] ?? '') . "\n";
            $err++;
        }
    } catch (\Throwable $e) {
        $queueMgr->marcarError($itemId, $e->getMessage());
        echo "  ! T{$tipoDte}F{$folio} EXCEPTION: " . $e->getMessage() . "\n";
        $err++;
    }
}

echo date('Y-m-d H:i:s') . " | Resultado: $ok enviados, $err errores\n";
exit($err > 0 ? 1 : 0);
