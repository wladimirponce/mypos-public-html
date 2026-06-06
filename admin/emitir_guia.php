<?php
/**
 * Emite una GuÃ­a de Despacho ElectrÃ³nica (DTE 52) por CLI y la envÃ­a al SII.
 *
 * USO:
 *   php emitir_guia.php                  -> guÃ­a de prueba (traslado interno)
 *   php emitir_guia.php --folio=192313   -> fuerza un folio especÃ­fico
 *
 * Imprime folio, TrackID y estado inicial. Luego consultar con:
 *   php consultar_envio.php <TrackID>
 *
 * Datos = mismos de la guÃ­a 192312 rechazada (1 Ã­tem "curitas", traslado
 * interno IndTraslado=5, TipoDespacho=2, receptor = emisor).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI\n"); }

$empresaId = 0;
$folio = 0;
foreach ($argv as $a) {
    if (preg_match('/^--folio=(\d+)$/', $a, $m)) $folio = (int)$m[1];
    if (preg_match('/^--empresa-id=(\d+)$/', $a, $m)) $empresaId = (int)$m[1];
}
if ($empresaId <= 0) exit("Uso: php emitir_guia.php --empresa-id=ID [--folio=NNNN]\n");
session_start();
$_SESSION['admin_id'] = 'cli';
$_SESSION['active_empresa_id'] = $empresaId;
require __DIR__ . '/api.php';

$data = [
    'tipo'         => 52,
    'folio'        => $folio,                 // 0 = autoasignar desde CAF
    'indTraslado'  => 5,                      // 5 = traslado interno
    'tipoDespacho' => 2,                      // 2 = despacho por cuenta del emisor
    'receptor'     => [
        'rut'       => RUT_EMISOR,
        'nombre'    => RAZON_SOCIAL,
        'giro'      => GIRO_EMISOR,
        'direccion' => DIRECCION,
        'comuna'    => COMUNA,
        'ciudad'    => CIUDAD,
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
    echo "GuÃ­a 52 generada. Folio: {$folioReal}\n";

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
