<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Database;
try {
    $db = Database::getInstance();
    $stmt = $db->query('SHOW DATABASES');
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
