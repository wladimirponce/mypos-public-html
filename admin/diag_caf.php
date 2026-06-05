<?php
/**
 * Diagnóstico de CAF / empresa para certificación — READ ONLY.
 * Abrir en el navegador (en el servidor, logueado):  .../admin/diag_caf.php
 * No modifica nada. Bórrelo al terminar.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
ini_set('display_errors', '0');
error_reporting(0);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/autoload.php';

use App\Core\Context;
use App\Core\Database;
use App\Repositories\EmpresaRepository;

function L($s = '') { echo $s . "\n"; }

try {
    $pdo = Database::getInstance();
    $ex  = fn(string $t) => (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' LIMIT 1")->fetchColumn();

    L("================ EMPRESAS / SESIÓN ================");
    $sesId = (int)($_SESSION['active_empresa_id'] ?? 0);
    L("active_empresa_id en sesión : " . ($sesId ?: '(vacío)'));
    L("useSiiTables (existe sii_empresa) : " . ($ex('sii_empresa') ? 'TRUE → usa sii_caf' : 'FALSE → usa caf_archivos'));
    L("");
    if ($ex('sii_empresa')) {
        L("-- sii_empresa --");
        foreach ($pdo->query("SELECT id,rut,ambiente_default,activo FROM sii_empresa ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) L("   " . json_encode($r));
    }
    if ($ex('empresas')) {
        L("-- empresas (MyPOS) --");
        foreach ($pdo->query("SELECT id,rut,activo FROM empresas ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) L("   " . json_encode($r));
    }
    L("");

    // Resolución defensiva de la empresa a diagnosticar
    $repo = new EmpresaRepository();
    $empresaId = 0; $nota = '';
    if ($sesId > 0 && $repo->getById($sesId)) { $empresaId = $sesId; $nota = "id de sesión válido"; }
    else { $e = $repo->getByAmbiente('CERTIFICACION'); if ($e) { $empresaId = (int)$e['id']; $nota = "sesión inválida → getByAmbiente(CERTIFICACION)=$empresaId"; } }
    L("Empresa a diagnosticar: " . ($empresaId ?: 'NINGUNA') . "  ($nota)");
    L("");

    if ($empresaId <= 0) { L("No hay empresa resoluble. Revise las tablas de arriba (¿activo=1? ¿id correcto?)."); exit; }

    // Cargar api.php (funciones loadCAF) en buffer descartable
    $_SESSION['active_empresa_id'] = $empresaId;
    $globalContext = new Context($empresaId);
    ob_start(); define('DTE_API_BOOTSTRAP_ONLY', true); require_once __DIR__ . '/api.php'; ob_end_clean();
    ini_set('display_errors', '0');

    $amb = $globalContext->getAmbiente();
    L("================ RESOLUCIÓN DE CAF (empresa $empresaId, amb $amb) ================");
    foreach ([33, 39, 52, 56, 61] as $tipo) {
        L("--- TIPO $tipo ---");
        try {
            $activos = $repo->getCAFsActivos($empresaId, $tipo, $amb);
            L("  getCAFsActivos: " . count($activos) . " fila(s)");
            foreach ($activos as $c) {
                L("     id={$c['id']} desde={$c['folio_desde']} hasta={$c['folio_hasta']} amb=" . ($c['ambiente_sii'] ?? '?') . " activo=" . ($c['activo'] ?? '?') . " disp=" . ($c['disponibles'] ?? '?'));
            }
        } catch (\Throwable $e) { L("  getCAFsActivos ERROR: " . $e->getMessage()); }
        try { $caf = loadCAF($tipo, 0); L("  loadCAF: OK desde={$caf['desde']} hasta={$caf['hasta']}"); }
        catch (\Throwable $e) { L("  loadCAF: " . $e->getMessage()); }
    }
    L("");

    L("================ FILAS CRUDAS DE CAF ================");
    if ($ex('sii_caf')) {
        L("-- sii_caf (TODAS las empresas) --");
        foreach ($pdo->query("SELECT id,empresa_id,tipo_dte,folio_desde,folio_hasta,ambiente_sii,activo FROM sii_caf ORDER BY empresa_id,tipo_dte")->fetchAll(PDO::FETCH_ASSOC) as $r) L("   " . json_encode($r));
    }
    if ($ex('caf_archivos')) {
        L("-- caf_archivos (TODAS las empresas) --");
        foreach ($pdo->query("SELECT id,empresa_id,tipo_documento,folio_desde,folio_hasta,estado FROM caf_archivos ORDER BY empresa_id")->fetchAll(PDO::FETCH_ASSOC) as $r) L("   " . json_encode($r));
    }
    if ($ex('v_sii_folios_disponibles')) {
        L("-- v_sii_folios_disponibles (empresa $empresaId) --");
        $st = $pdo->prepare("SELECT caf_id,tipo_dte,ambiente_sii,total_folios,consumidos,disponibles FROM v_sii_folios_disponibles WHERE empresa_id=? ORDER BY tipo_dte");
        $st->execute([$empresaId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) L("   " . json_encode($r));
    }
    L("");
    L("================ FIN ================");

} catch (\Throwable $e) {
    L("ERROR FATAL: " . $e->getMessage());
}
