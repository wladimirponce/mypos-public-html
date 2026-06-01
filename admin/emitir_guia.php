<?php
/**
 * Emite una Guía de Despacho Electrónica (DTE 52) por CLI y la envía al SII.
 *
 * USO:
 *   php emitir_guia.php                  -> guía de prueba (traslado interno)
 *   php emitir_guia.php --folio=192313   -> fuerza un folio específico
 *
 * Imprime folio, TrackID y estado inicial. Luego consultar con:
 *   php consultar_envio.php <TrackID>
 *
 * Datos = mismos de la guía 192312 rechazada (1 ítem "curitas", traslado
 * interno IndTraslado=5, TipoDespacho=2, receptor = emisor).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI\n"); }

session_start();
$_SESSION['active_empresa_id'] = 1;          // empresa ALCAINO (producción)
require __DIR__ . '/api.php';

// --folio=NNNN opcional
$folio = 0;
foreach ($argv as $a) {
    if (preg_match('/^--folio=(\d+)$/', $a, $m)) $folio = (int)$m[1];
}

$data = [
    'tipo'         => 52,
    'folio'        => $folio,                 // 0 = autoasignar desde CAF
    'indTraslado'  => 5,                      // 5 = traslado interno
    'tipoDespacho' => 2,                      // 2 = despacho por cuenta del emisor
    'receptor'     => [
        'rut'       => '77020050-4',
        'nombre'    => 'ALCAINO Y ARAYA SPA',
        'giro'      => 'DROGUERIA, FARMACIA Y PERFUMERIA',
        'direccion' => 'Avda. Independencia 3879, Conchalí',
        'comuna'    => 'Conchalí',
        'ciudad'    => 'Santiago',
    ],
    'items' => [
        ['nombre' => 'curitas', 'descripcion' => 'curitas', 'cantidad' => 10, 'precio' => 1500],
    ],
];

try {
    $GLOBALS['SII_CERT_TIPO'] = 52;
    $gen = generateDTE($data);
    if (empty($gen['ok'])) {
        echo "GENERACION FALLIDA: " . json_encode($gen, JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }
    $folioReal = $gen['folio'];
    echo "Guía 52 generada. Folio: {$folioReal}\n";

    $env = sendDTE(['xml' => $gen['xml'], 'tipo' => 52, 'folio' => $folioReal]);
    echo json_encode([
        'folio'   => $folioReal,
        'ok'      => $env['ok']      ?? null,
        'trackId' => $env['trackId'] ?? null,
        'estado'  => $env['estado']  ?? null,
        'mensaje' => $env['mensaje'] ?? null,
        'error'   => $env['error']   ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    if (!empty($env['trackId'])) {
        echo "\nPara ver el veredicto del SII (espere ~1 min):\n";
        echo "  php consultar_envio.php {$env['trackId']}\n";
    }
} catch (Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n";
    exit(1);
}
