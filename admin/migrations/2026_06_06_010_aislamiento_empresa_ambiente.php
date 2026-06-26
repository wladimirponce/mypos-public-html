<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use App\Core\Database;

$pdo = Database::getInstance();

$columnExists = static function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
};
$indexExists = static function (string $table, string $index) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
};

if (!$columnExists('sii_certificado', 'ambiente_sii')) {
    $pdo->exec(
        "ALTER TABLE sii_certificado
         ADD COLUMN ambiente_sii ENUM('certificacion','produccion')
         NOT NULL DEFAULT 'certificacion' AFTER vigencia_hasta"
    );
}
if (!$indexExists('sii_certificado', 'idx_cert_empresa_ambiente')) {
    $pdo->exec(
        'ALTER TABLE sii_certificado
         ADD INDEX idx_cert_empresa_ambiente (empresa_id, ambiente_sii, activo)'
    );
}
if (!$columnExists('sii_folio_consumo', 'ambiente')) {
    $pdo->exec(
        "ALTER TABLE sii_folio_consumo
         ADD COLUMN ambiente ENUM('certificacion','produccion')
         NOT NULL DEFAULT 'certificacion' AFTER folio"
    );
}
$pdo->exec(
    "UPDATE sii_certificado c
       JOIN sii_empresa e ON e.id = c.empresa_id
        SET c.ambiente_sii = e.ambiente_default"
);
$pdo->exec(
    "UPDATE sii_folio_consumo f
       JOIN sii_caf c ON c.id = f.caf_id
        SET f.ambiente = c.ambiente_sii"
);
if (!$indexExists('sii_folio_consumo', 'idx_folio_empresa')) {
    $pdo->exec('ALTER TABLE sii_folio_consumo ADD INDEX idx_folio_empresa (empresa_id)');
}
if ($indexExists('sii_folio_consumo', 'uq_folio_empresa')) {
    $pdo->exec('ALTER TABLE sii_folio_consumo DROP INDEX uq_folio_empresa');
}
if (!$indexExists('sii_folio_consumo', 'uq_folio_empresa_ambiente')) {
    $pdo->exec(
        'ALTER TABLE sii_folio_consumo
         ADD UNIQUE KEY uq_folio_empresa_ambiente (empresa_id, tipo_dte, folio, ambiente)'
    );
}

echo "Migracion de aislamiento DB completada.\n";
