<?php
require_once __DIR__ . '/../autoload.php';
use App\Core\Database;

try {
    $db = Database::getInstance();
    $sql = file_get_contents(__DIR__ . '/../migrations/2026_05_25_007_create_saas_tables.sql');
    $db->exec($sql);
    echo "Migración SaaS ejecutada con éxito.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
