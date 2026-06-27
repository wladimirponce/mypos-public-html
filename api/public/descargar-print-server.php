<?php
// Sirve el instalador del print server como descarga forzada.
// PHP evita restricciones de ModSecurity sobre .bat/.exe estaticos en cPanel.
$zip = __DIR__ . '/descargas/mypos_print_server_instalador.zip';
$bat = __DIR__ . '/descargas/instalar_mypos_print_server.bat';
$file = file_exists($zip) ? $zip : $bat;
$downloadName = file_exists($zip) ? 'mypos_print_server_instalador.zip' : 'instalar_mypos_print_server.bat';
$contentType = file_exists($zip) ? 'application/zip' : 'application/octet-stream';

if (!file_exists($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Archivo no encontrado. Contacta a soporte@mypos.cl';
    exit;
}

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
readfile($file);
