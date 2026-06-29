<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Database;
try {
    $db = Database::getInstance();
    $stmt = $db->query('DESCRIBE sii_empresa');
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
