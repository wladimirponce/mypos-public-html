<?php
/**
 * =============================================================================
 * Migración 005 — Migrar entorno "Legacy" → empresa ALCAINO Y ARAYA SPA
 * Fecha   : 2026-05-17
 * =============================================================================
 *
 * Complementa a 2026_05_17_004_registrar_alcaino_produccion.sql.
 *
 * Qué hace (TODO idempotente — re-ejecutable sin daño):
 *   1. Localiza la empresa ALCAINO (RUT 77020050-4) ya insertada por el .sql.
 *   2. Copia (NO mueve) los archivos planos legacy a las rutas por-RUT que
 *      espera la clase Context, dejando los originales intactos para que el
 *      modo legacy siga operando durante la transición:
 *        cert/firma.pfx   -> cert/77020050-4/firma.pfx
 *        cert/cert.conf   -> cert/77020050-4/cert.conf   (clave del PFX)
 *        cert/cacert.pem  -> cert/77020050-4/cacert.pem
 *        caf/caf_39.xml   -> caf/77020050-4/caf_39.xml
 *        caf/caf_52.xml   -> caf/77020050-4/caf_52.xml
 *        tmp/             -> crea tmp/77020050-4/
 *   3. Registra/actualiza los CAF (39 y 52) en sii_caf apuntando a la ruta
 *      por-RUT (necesario porque loadCAF() en modo Context lee de la BD).
 *   4. Backfill de los 5 DTEs ya emitidos (boletas 12317071/72/73 y guías
 *      119706/119707) en sii_dte + sii_folio_consumo, asociados a ALCAINO,
 *      para que aparezcan en el Historial y MAX(folio) avance correctamente.
 *
 * USO (en el servidor):
 *   Revisión (no escribe nada):   php migrations/2026_05_17_005_migrar_legacy_a_alcaino.php
 *   Aplicar cambios:              php migrations/2026_05_17_005_migrar_legacy_a_alcaino.php --apply
 *
 * Por seguridad el modo por defecto es DRY-RUN.
 * =============================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde la línea de comandos (CLI).\n");
}

$APPLY = in_array('--apply', $argv, true);
$ROOT  = dirname(__DIR__);
$RUT   = '77020050-4';

require_once $ROOT . '/autoload.php';

use App\Core\Database;
use App\Repositories\EmpresaRepository;

function logLine(string $msg): void { echo $msg . "\n"; }
function tag(bool $apply): string { return $apply ? '[APLICAR]' : '[DRY-RUN]'; }

logLine('============================================================');
logLine('  Migración Legacy -> ALCAINO Y ARAYA SPA  ' . tag($APPLY));
logLine('============================================================');
if (!$APPLY) {
    logLine('  Modo REVISIÓN. No se escribirá nada. Use --apply para ejecutar.');
}
logLine('');

// ── Conexión a BD ────────────────────────────────────────────────────────────
try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    logLine('ABORTADO: no se pudo conectar a la base de datos: ' . $e->getMessage());
    exit(1);
}

// ── 1. Localizar empresa ALCAINO ─────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM sii_empresa WHERE rut = ? LIMIT 1');
$stmt->execute([$RUT]);
$empresa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$empresa) {
    logLine("ABORTADO: no existe la empresa RUT $RUT en sii_empresa.");
    logLine("          Ejecute primero el SQL:");
    logLine("          mysql ... < migrations/2026_05_17_004_registrar_alcaino_produccion.sql");
    exit(1);
}
$empresaId = (int)$empresa['id'];
$ambiente  = strtolower($empresa['ambiente_default']); // 'produccion'
logLine("Empresa encontrada: #$empresaId  {$empresa['razon_social']}  ({$RUT})  ambiente=$ambiente");
logLine('');

// ── 2. Copiar archivos planos a rutas por-RUT ────────────────────────────────
logLine('-- Paso 2: archivos por-RUT --------------------------------');
$copies = [
    "$ROOT/cert/firma.pfx"   => "$ROOT/cert/$RUT/firma.pfx",
    "$ROOT/cert/cert.conf"   => "$ROOT/cert/$RUT/cert.conf",
    "$ROOT/cert/cacert.pem"  => "$ROOT/cert/$RUT/cacert.pem",
    "$ROOT/caf/caf_39.xml"   => "$ROOT/caf/$RUT/caf_39.xml",
    "$ROOT/caf/caf_52.xml"   => "$ROOT/caf/$RUT/caf_52.xml",
];
$dirsNeeded = ["$ROOT/cert/$RUT", "$ROOT/caf/$RUT", "$ROOT/tmp/$RUT"];

foreach ($dirsNeeded as $d) {
    if (is_dir($d)) {
        logLine("  dir OK     $d");
    } else {
        logLine("  dir CREAR  $d");
        if ($APPLY && !@mkdir($d, 0755, true) && !is_dir($d)) {
            logLine("  ERROR: no se pudo crear $d");
            exit(1);
        }
    }
}
foreach ($copies as $src => $dst) {
    if (!file_exists($src)) {
        logLine("  SKIP (origen no existe)  $src");
        continue;
    }
    if (file_exists($dst)) {
        logLine("  YA EXISTE  $dst");
        continue;
    }
    logLine("  COPIAR     $src  ->  $dst");
    if ($APPLY && !@copy($src, $dst)) {
        logLine("  ERROR: no se pudo copiar a $dst");
        exit(1);
    }
}
logLine('');

// ── 3. Registrar CAF 39 y 52 en sii_caf ──────────────────────────────────────
logLine('-- Paso 3: CAF en sii_caf ----------------------------------');
$repo = new EmpresaRepository();
foreach ([39, 52] as $tipo) {
    $cafPath = "$ROOT/caf/$RUT/caf_$tipo.xml";
    if (!file_exists($cafPath)) {
        logLine("  SKIP tipo $tipo: no existe $cafPath");
        continue;
    }
    $xml = @simplexml_load_file($cafPath);
    if (!$xml) {
        logLine("  ERROR tipo $tipo: CAF XML inválido en $cafPath");
        continue;
    }
    $desde = (int)$xml->CAF->DA->RNG->D;
    $hasta = (int)$xml->CAF->DA->RNG->H;
    $fa    = (string)$xml->CAF->DA->FA;

    $existing = $repo->getCAFConEstado($empresaId, $tipo, $ambiente);
    $needs = !$existing
        || (int)$existing['folio_desde'] !== $desde
        || (int)$existing['folio_hasta'] !== $hasta
        || ($existing['xml_path'] ?? '') !== $cafPath;

    if (!$needs) {
        logLine("  CAF $tipo OK (ya registrado: rango $desde-$hasta)");
        continue;
    }
    logLine("  CAF $tipo REGISTRAR rango $desde-$hasta FA=$fa path=$cafPath");
    if ($APPLY) {
        $repo->registrarCAF([
            'empresa_id' => $empresaId,
            'tipo_dte'   => $tipo,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'xml_path'   => $cafPath,
            'ambiente'   => $ambiente,
            'fecha_auth' => $fa,
        ]);
    }
}
logLine('');

// ── 4. Backfill de DTEs históricos en sii_dte + sii_folio_consumo ────────────
logLine('-- Paso 4: backfill DTEs históricos ------------------------');
$archiveDir = "$ROOT/archive";
$indexFile  = "$archiveDir/index.ndjson";
if (!file_exists($indexFile)) {
    logLine("  SKIP: no existe $indexFile (nada que migrar)");
} else {
    // CAF id por tipo (para sii_folio_consumo)
    $cafIdPorTipo = [];
    foreach ([39, 52] as $tipo) {
        $c = $repo->getCAFConEstado($empresaId, $tipo, $ambiente);
        if ($c) $cafIdPorTipo[$tipo] = (int)$c['id'];
    }

    $get = static function (string $xml, string $tagName): ?string {
        if (preg_match('#<' . $tagName . '>(.*?)</' . $tagName . '>#s', $xml, $m)) {
            return trim($m[1]);
        }
        return null;
    };

    $lines = file($indexFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $seen  = [];
    foreach ($lines as $ln) {
        $rec = json_decode($ln, true);
        if (!is_array($rec) || empty($rec['tipo']) || empty($rec['folio'])) continue;

        $tipo  = (int)$rec['tipo'];
        $folio = (int)$rec['folio'];
        $key   = "$tipo:$folio";
        if (isset($seen[$key])) continue;     // index puede tener duplicados
        $seen[$key] = true;

        $relPath = str_replace('\\', '/', (string)$rec['path']);
        $xmlPath = "$archiveDir/$relPath";
        if (!file_exists($xmlPath)) {
            logLine("  SKIP T$tipo F$folio: no existe $xmlPath");
            continue;
        }
        $doc = file_get_contents($xmlPath);

        $rutRecep = $get($doc, 'RUTRecep')    ?? null;
        $rznRecep = $get($doc, 'RznSocRecep') ?? null;
        $fchEmis  = $get($doc, 'FchEmis')     ?? ($rec['fch_emis'] ?? null);
        $mntNeto  = (int)($get($doc, 'MntNeto')  ?? 0);
        $mntIva   = (int)($get($doc, 'IVA')      ?? 0);
        $mntExe   = (int)($get($doc, 'MntExe')   ?? 0);
        $mntTotal = (int)($get($doc, 'MntTotal') ?? 0);
        $trackId  = $rec['trackId'] ?? null;

        // ¿Ya está en sii_dte?
        $chk = $pdo->prepare(
            'SELECT id FROM sii_dte WHERE empresa_id=? AND tipo_dte=? AND folio=? AND ambiente=?'
        );
        $chk->execute([$empresaId, $tipo, $folio, $ambiente]);
        if ($chk->fetchColumn()) {
            logLine("  YA EXISTE  T$tipo F$folio");
            continue;
        }

        logLine("  INSERTAR   T$tipo F$folio  fch=$fchEmis  total=$mntTotal  recep=$rutRecep  track=$trackId");
        if ($APPLY) {
            $ins = $pdo->prepare(
                'INSERT INTO sii_dte
                    (empresa_id, tipo_dte, folio, ambiente, fecha_emision,
                     rut_receptor, razon_receptor, monto_neto, monto_iva,
                     monto_exento, monto_total, xml_firmado, track_id,
                     estado_local)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $empresaId, $tipo, $folio, $ambiente, $fchEmis,
                $rutRecep, $rznRecep, $mntNeto, $mntIva,
                $mntExe, $mntTotal, $doc, $trackId,
                'enviado',
            ]);
            $dteId = (int)$pdo->lastInsertId();

            // sii_folio_consumo (idempotente: UNIQUE uq_folio_empresa)
            if (isset($cafIdPorTipo[$tipo])) {
                $fc = $pdo->prepare(
                    'INSERT IGNORE INTO sii_folio_consumo
                        (caf_id, empresa_id, tipo_dte, folio, estado, dte_id)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $fc->execute([
                    $cafIdPorTipo[$tipo], $empresaId, $tipo, $folio,
                    'enviado_sii', $dteId,
                ]);
            }
        }
    }
}
logLine('');
logLine('============================================================');
logLine($APPLY
    ? '  Migración APLICADA. Verifique el Historial en el dashboard.'
    : '  Revisión completa. Re-ejecute con --apply para aplicar.');
logLine('============================================================');
