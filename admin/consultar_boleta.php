<?php
/**
 * Consulta el estado en el SII de una Boleta Electrónica (DTE 39) por folio.
 * Boletas usan la API REST (no SOAP), por eso es script aparte de las guías.
 *
 * USO:
 *   php consultar_boleta.php <folio> <monto> [fecha YYYY-MM-DD]
 *   ej: php consultar_boleta.php 12317074 1000
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI\n"); }

$folio = $argv[1] ?? '';
$monto = $argv[2] ?? '';
$fecha = $argv[3] ?? date('Y-m-d');
if (!preg_match('/^\d+$/', $folio) || !preg_match('/^\d+$/', $monto)) {
    exit("USO: php consultar_boleta.php <folio> <monto> [YYYY-MM-DD]\n");
}

session_start();
$_SESSION['active_empresa_id'] = 1;
require __DIR__ . '/api.php';

try {
    $GLOBALS['SII_CERT_TIPO'] = 39;          // boletas -> cert Cristina
    $res = validateDTE([
        'tipo'        => 39,
        'folio'       => (int)$folio,
        'trackId'     => '',
        'rutReceptor' => '66666666-6',
        'fecha'       => $fecha,
        'monto'       => (int)$monto,
    ]);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n";
    exit(1);
}
