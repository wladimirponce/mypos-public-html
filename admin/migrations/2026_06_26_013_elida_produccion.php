<?php
declare(strict_types=1);

/**
 * Migración 013 — Pasar Doña Elida (empresa 24, RUT 78442495-2) a producción.
 *
 * 1. dte_configuracion.ambiente → PRODUCCION, modo → REAL
 * 2. empresa_configuracion.metadata_json → numero_resolucion=80, fecha_resolucion=2014-08-22
 * 3. Copiar certificado de CERTIFICACION/ a PRODUCCION/ (si existe)
 */

require_once dirname(__DIR__) . '/autoload.php';

use App\Core\Database;

$pdo = Database::getInstance();
$empresaId = 24;
$rut = '78442495-2';
$resolNum = '80';
$resolFch = '2014-08-22';

echo "=== Migración 013: Doña Elida → PRODUCCION ===\n\n";

// ── 1. dte_configuracion ────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, ambiente, modo FROM dte_configuracion WHERE empresa_id = ?");
$stmt->execute([$empresaId]);
$dteCfg = $stmt->fetch(PDO::FETCH_ASSOC);

if ($dteCfg) {
    $pdo->prepare("UPDATE dte_configuracion SET ambiente = 'PRODUCCION', modo = 'REAL' WHERE empresa_id = ?")
        ->execute([$empresaId]);
    echo "[OK] dte_configuracion: ambiente={$dteCfg['ambiente']}→PRODUCCION, modo={$dteCfg['modo']}→REAL\n";
} else {
    $pdo->prepare("INSERT INTO dte_configuracion (empresa_id, modo, ambiente, activo) VALUES (?, 'REAL', 'PRODUCCION', 1)")
        ->execute([$empresaId]);
    echo "[OK] dte_configuracion: creada con PRODUCCION/REAL\n";
}

// ── 2. empresa_configuracion.metadata_json ──────────────────────────────────
$stmt = $pdo->prepare("SELECT metadata_json FROM empresa_configuracion WHERE empresa_id = ?");
$stmt->execute([$empresaId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $meta = json_decode((string)$row['metadata_json'], true) ?: [];
    $meta['numero_resolucion'] = $resolNum;
    $meta['fecha_resolucion'] = $resolFch;
    $pdo->prepare("UPDATE empresa_configuracion SET metadata_json = ? WHERE empresa_id = ?")
        ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $empresaId]);
    echo "[OK] empresa_configuracion.metadata_json: resolución {$resolNum} de {$resolFch}\n";
} else {
    echo "[WARN] No existe empresa_configuracion para empresa {$empresaId}\n";
}

// ── 3. Copiar certificado CERTIFICACION → PRODUCCION ────────────────────────
$baseCert = dirname(__DIR__) . '/cert/' . $rut;
$srcDir  = $baseCert . '/CERTIFICACION';
$destDir = $baseCert . '/PRODUCCION';

if (is_dir($srcDir)) {
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $copied = 0;
    foreach (scandir($srcDir) as $file) {
        if ($file === '.' || $file === '..') continue;
        $src = $srcDir . '/' . $file;
        $dst = $destDir . '/' . $file;
        if (is_file($src) && !is_file($dst)) {
            copy($src, $dst);
            $copied++;
            echo "[OK] cert copiado: {$file}\n";
        } elseif (is_file($dst)) {
            echo "[SKIP] cert ya existe en PRODUCCION: {$file}\n";
        }
    }
    if ($copied === 0) {
        echo "[INFO] Todos los archivos de cert ya existían en PRODUCCION\n";
    }
} else {
    echo "[WARN] No existe {$srcDir} — copiar certificado manualmente\n";
}

// ── 4. Crear directorios de producción para CAF y tmp ───────────────────────
$cafDir = dirname(__DIR__) . '/caf/' . $rut . '/PRODUCCION';
$tmpDir = dirname(__DIR__) . '/tmp/' . $rut . '/PRODUCCION';

foreach ([$cafDir, $tmpDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "[OK] Directorio creado: " . basename(dirname($dir)) . '/' . basename($dir) . "\n";
    }
}

// ── 5. Habilitar documentos_tributarios en configuración operativa ───────────
$stmt = $pdo->prepare("SELECT documentos_tributarios_habilitados FROM empresa_configuracion_operativa WHERE empresa_id = ?");
$stmt->execute([$empresaId]);
$opRow = $stmt->fetch(PDO::FETCH_ASSOC);

if ($opRow && !((int)$opRow['documentos_tributarios_habilitados'])) {
    $pdo->prepare("UPDATE empresa_configuracion_operativa SET documentos_tributarios_habilitados = 1 WHERE empresa_id = ?")
        ->execute([$empresaId]);
    echo "[OK] documentos_tributarios_habilitados = 1\n";
} elseif ($opRow) {
    echo "[OK] documentos_tributarios_habilitados ya estaba activo\n";
} else {
    echo "[WARN] No existe empresa_configuracion_operativa para empresa {$empresaId}\n";
}

echo "\n=== Migración 013 completada ===\n";
echo "PENDIENTE: Subir CAFs de PRODUCCION (tipo 39 boleta) desde el SII real.\n";
echo "           SII → Factura electrónica → Solicitar folios → Boleta electrónica.\n";
