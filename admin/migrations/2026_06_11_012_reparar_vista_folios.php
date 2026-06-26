<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use App\Core\Database;

$pdo = Database::getInstance();

$requiredColumns = [
    'sii_caf' => ['id', 'empresa_id', 'tipo_dte', 'ambiente_sii', 'folio_desde', 'folio_hasta', 'activo'],
    'sii_folio_consumo' => ['id', 'caf_id', 'estado'],
    'sii_config' => ['clave', 'valor'],
];

foreach ($requiredColumns as $table => $columns) {
    foreach ($columns as $column) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            throw new RuntimeException("No se puede reparar la vista: falta {$table}.{$column}");
        }
    }
}

$pdo->exec('DROP VIEW IF EXISTS v_sii_folios_disponibles');
$pdo->exec(
    "CREATE SQL SECURITY INVOKER VIEW v_sii_folios_disponibles AS
     SELECT
         c.empresa_id,
         c.tipo_dte,
         c.ambiente_sii,
         c.id AS caf_id,
         c.folio_desde,
         c.folio_hasta,
         (CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) AS total_folios,
         COUNT(fc.id) AS consumidos,
         GREATEST((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COUNT(fc.id), 0) AS disponibles,
         COALESCE(
             (SELECT cfg_c.valor FROM sii_config cfg_c WHERE cfg_c.clave = 'umbral_folio_critico' LIMIT 1),
             '50'
         ) + 0 AS umbral_critico,
         COALESCE(
             (SELECT cfg_p.valor FROM sii_config cfg_p WHERE cfg_p.clave = 'umbral_folio_pre_critico' LIMIT 1),
             '200'
         ) + 0 AS umbral_pre_critico,
         CASE
             WHEN ((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COUNT(fc.id)) <= COALESCE(
                 (SELECT cfg_c2.valor FROM sii_config cfg_c2 WHERE cfg_c2.clave = 'umbral_folio_critico' LIMIT 1),
                 '50'
             ) + 0 THEN 'critico'
             WHEN ((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COUNT(fc.id)) <= COALESCE(
                 (SELECT cfg_p2.valor FROM sii_config cfg_p2 WHERE cfg_p2.clave = 'umbral_folio_pre_critico' LIMIT 1),
                 '200'
             ) + 0 THEN 'pre_critico'
             ELSE 'normal'
         END AS nivel_alerta
     FROM sii_caf c
     LEFT JOIN sii_folio_consumo fc
       ON fc.caf_id = c.id
      AND fc.estado != 'rechazado_sii'
     WHERE c.activo = 1
     GROUP BY c.id, c.empresa_id, c.tipo_dte, c.ambiente_sii, c.folio_desde, c.folio_hasta"
);

echo "Vista v_sii_folios_disponibles reparada con SQL SECURITY INVOKER.\n";
