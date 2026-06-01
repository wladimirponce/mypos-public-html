<?php
/**
 * Consulta el estado de un envío DTE (guía/factura, vía SOAP palena) por TrackID.
 *
 * USO:
 *   php consultar_envio.php 12046636764
 *
 * Interpretación de ESTADO:
 *   REC / EPR / 0   -> recepcionado / en proceso (OK, esperar)
 *   DOK / SOK       -> aceptado y validado
 *   RSC             -> rechazado por error de esquema
 *   RFR / RCT       -> rechazado (firma / contenido)
 *   106             -> usuario sin permiso en empresa (trámite SII)
 *   -11             -> TrackID inválido / inexistente
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI\n"); }

$trackId = $argv[1] ?? '';
if (!preg_match('/^\d+$/', $trackId)) {
    exit("USO: php consultar_envio.php <TrackID numérico>\n");
}

session_start();
$_SESSION['active_empresa_id'] = 1;
require __DIR__ . '/api.php';

try {
    $GLOBALS['SII_CERT_TIPO'] = 52;            // guías/facturas -> cert David
    [$c, $k] = loadCertificate(52);
    $tok = getToken(getSemilla(), $c, $k);
    $res = queryEstadoEnvio($trackId, $tok);
    echo json_encode([
        'trackId' => $trackId,
        'estado'  => $res['estado'] ?? null,
        'glosa'   => $res['glosa']  ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo "EXCEPCION: " . $e->getMessage() . "\n";
    exit(1);
}
