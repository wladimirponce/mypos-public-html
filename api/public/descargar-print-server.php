<?php
// Sirve el instalador del print server como descarga forzada.
// PHP evita las restricciones de ModSecurity que bloquean .bat estáticos en Apache/cPanel.
$file = __DIR__ . '/descargas/instalar_mypos_print_server.bat';

if (!file_exists($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Archivo no encontrado. Contacta a soporte@mypos.cl';
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="instalar_mypos_print_server.bat"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
readfile($file);
