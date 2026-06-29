<?php
require_once __DIR__ . '/../autoload.php';
use App\Core\Database;

try {
    $db = Database::getInstance();
    $sql = file_get_contents(__DIR__ . '/../migrations/2026_06_18_013_create_saas_rol_matriz.sql');
    $db->exec($sql);
    echo "Migración 013 ejecutada con éxito: tabla saas_rol_matriz creada.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
