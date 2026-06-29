<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/autoload.php';
use App\Core\Database;

try {
    $db = Database::getInstance();
    
    // Verificar si existe la tabla sii_empresa
    $stmt = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sii_empresa'");
    if ((int)$stmt->fetchColumn() === 0) {
        die("La tabla sii_empresa no existe. No se requiere migración.");
    }
    
    // Obtener columnas actuales
    $stmtCols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sii_empresa'");
    $existingCols = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
    
    $alterStatements = [];
    
    // Definir columnas esperadas y su definición SQL
    $expectedCols = [
        'acteco' => "VARCHAR(500) NULL AFTER giro",
        'direccion_origen' => "VARCHAR(200) NULL AFTER acteco",
        'comuna_origen' => "VARCHAR(100) NULL AFTER direccion_origen",
        'ciudad_origen' => "VARCHAR(100) NULL AFTER comuna_origen",
        'unidad_sii' => "VARCHAR(100) NULL AFTER ciudad_origen",
        'telefono' => "VARCHAR(30) NULL AFTER unidad_sii",
        'email_sii' => "VARCHAR(150) NULL AFTER telefono",
        'fecha_resolucion' => "DATE NULL AFTER email_sii",
        'numero_resolucion' => "VARCHAR(20) NULL AFTER fecha_resolucion",
        'ambiente_default' => "ENUM('certificacion','produccion') NOT NULL DEFAULT 'certificacion' AFTER numero_resolucion",
        'activo' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER ambiente_default"
    ];
    
    foreach ($expectedCols as $col => $definition) {
        if (!in_array($col, $existingCols)) {
            $alterStatements[] = "ADD COLUMN `$col` $definition";
        }
    }
    
    if (count($alterStatements) > 0) {
        $sql = "ALTER TABLE `sii_empresa` " . implode(", ", $alterStatements);
        $db->exec($sql);
        echo "<h3>Migración completada con éxito.</h3>";
        echo "<p>Se agregaron las siguientes columnas: " . implode(", ", array_keys($expectedCols)) . "</p>";
    } else {
        echo "<h3>La tabla sii_empresa ya está actualizada.</h3>";
    }
    
} catch (Exception $e) {
    echo "<h3>Error durante la migración:</h3><pre>" . $e->getMessage() . "</pre>";
}
