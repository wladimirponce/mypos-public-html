<?php
declare(strict_types=1);

/**
 * Migración 014 — Corregir folios de Doña Elida para producción.
 *
 * 1. CAF 15001-16000 (tipo BOLETA): cambiar ambiente a PRODUCCION
 * 2. Anular CAFs de CERTIFICACION (1-200 de todos los tipos)
 * 3. Crear asignación del CAF de producción a la sucursal principal
 */

require_once dirname(__DIR__) . '/autoload.php';

use App\Core\Database;

$pdo = Database::getInstance();
$empresaId = 24;

echo "=== Migración 014: Folios Doña Elida → PRODUCCION ===\n\n";

// ── 1. Corregir ambiente del CAF recién subido ─────────────────────────────
$stmt = $pdo->prepare(
    "UPDATE caf_archivos SET ambiente = 'PRODUCCION'
     WHERE empresa_id = ? AND tipo_documento = 'BOLETA' AND folio_desde = 15001 AND folio_hasta = 16000 AND estado = 'ACTIVO'"
);
$stmt->execute([$empresaId]);
$affected = $stmt->rowCount();
echo "[OK] CAF BOLETA 15001-16000: ambiente→PRODUCCION (filas: {$affected})\n";

// ── 2. Anular CAFs de certificación ────────────────────────────────────────
$stmt = $pdo->prepare(
    "UPDATE caf_archivos SET estado = 'ANULADO'
     WHERE empresa_id = ? AND ambiente = 'CERTIFICACION' AND estado = 'ACTIVO'"
);
$stmt->execute([$empresaId]);
$anulados = $stmt->rowCount();
echo "[OK] CAFs de CERTIFICACION anulados: {$anulados}\n";

// ── 3. Anular asignaciones de certificación ────────────────────────────────
$stmt = $pdo->prepare(
    "UPDATE folios_asignaciones SET estado = 'AGOTADA'
     WHERE empresa_id = ? AND estado = 'ACTIVA'
       AND caf_id IN (SELECT id FROM caf_archivos WHERE empresa_id = ? AND ambiente = 'CERTIFICACION')"
);
$stmt->execute([$empresaId, $empresaId]);
$asigAnuladas = $stmt->rowCount();
echo "[OK] Asignaciones de certificación desactivadas: {$asigAnuladas}\n";

// ── 4. Crear asignación del CAF de producción ──────────────────────────────
$stmt = $pdo->prepare(
    "SELECT id, folio_desde, folio_hasta FROM caf_archivos
     WHERE empresa_id = ? AND tipo_documento = 'BOLETA' AND ambiente = 'PRODUCCION' AND estado = 'ACTIVO'
     ORDER BY folio_desde ASC LIMIT 1"
);
$stmt->execute([$empresaId]);
$caf = $stmt->fetch(PDO::FETCH_ASSOC);

if ($caf) {
    $cafId = (int)$caf['id'];
    $desde = (int)$caf['folio_desde'];
    $hasta = (int)$caf['folio_hasta'];

    // Obtener sucursal principal
    $stmt = $pdo->prepare("SELECT id FROM sucursales WHERE empresa_id = ? AND activo = 1 ORDER BY id ASC LIMIT 1");
    $stmt->execute([$empresaId]);
    $sucursalId = (int)$stmt->fetchColumn();

    if ($sucursalId > 0) {
        // Verificar que no exista ya
        $stmt = $pdo->prepare(
            "SELECT id FROM folios_asignaciones
             WHERE empresa_id = ? AND caf_id = ? AND estado = 'ACTIVA' LIMIT 1"
        );
        $stmt->execute([$empresaId, $cafId]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            echo "[SKIP] Ya existe asignación activa para CAF {$cafId}\n";
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO folios_asignaciones
                    (empresa_id, sucursal_id, caf_id, tipo_documento, folio_desde, folio_hasta,
                     folio_actual, alerta_minimo, estado, asignado_at, created_at)
                 VALUES (?, ?, ?, 'BOLETA', ?, ?, ?, 50, 'ACTIVA', NOW(), NOW())"
            );
            $stmt->execute([$empresaId, $sucursalId, $cafId, $desde, $hasta, $desde - 1]);
            echo "[OK] Asignación creada: CAF {$cafId} → sucursal {$sucursalId} (folios {$desde}-{$hasta})\n";
        }
    } else {
        echo "[WARN] No se encontró sucursal activa para empresa {$empresaId}\n";
    }
} else {
    echo "[WARN] No se encontró CAF BOLETA de PRODUCCION activo\n";
}

echo "\n=== Migración 014 completada ===\n";
