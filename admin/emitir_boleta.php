<?php
/**
 * Emite una Boleta Electrónica (DTE 39) por CLI y la envía al SII (vía REST).
 * Sirve para confirmar que el flujo de boletas sigue OK tras los cambios
 * (certificado por tipo = Cristina, y fix de C14N en signDTE).
 *
 * USO:
 *   php emitir_boleta.php
 *
 * Imprime folio, TrackID y estado. Luego verificar con:
 *   php consultar_boleta.php <folio> <monto>
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI\n"); }

session_start();
$_SESSION['active_empresa_id'] = 1;          // empresa ALCAINO (producción)
require __DIR__ . '/api.php';

$data = [
    'tipo'     => 39,
    'folio'    => 0,                          // autoasignar desde CAF 39
    'receptor' => ['rut' => '66666666-6', 'nombre' => 'Consumidor Final'],
    'items'    => [
        ['nombre' => 'curitas', 'descripcion' => 'curitas', 'cantidad' => 1, 'precio' => 1000],
    ],
];

try {
    $GLOBALS['SII_CERT_TIPO'] = 39;          // boletas -> cert Cristina
    $gen = generateDTE($data);
    if (empty($gen['ok'])) {
        echo "GENERACION FALLIDA: " . json_encode($gen, JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }
    $folio = $gen['folio'];
    $total = $gen['montos']['mntTotal'] ?? 0;
    echo "Boleta 39 generada. Folio: {$folio} | Total: {$total}\n";

    $env = sendDTE(['xml' => $gen['xml'], 'tipo' => 39, 'folio' => $folio]);
    echo json_encode([
        'folio'   => $folio,
        'total'   => $total,
        'ok'      => $env['ok']      ?? null,
        'trackId' => $env['trackId'] ?? null,
        'estado'  => $env['estado']  ?? null,
        'mensaje' => $env['mensaje'] ?? null,
        'error'   => $env['error']   ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    if (!empty($env['trackId'])) {
        echo "\nVerificar estado en el SII (espere ~1 min):\n";
        echo "  php consultar_boleta.php {$folio} {$total}\n";
    }
} catch (Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n";
    exit(1);
}
