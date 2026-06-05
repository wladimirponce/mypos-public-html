<?php
/**
 * Dashboard DTE — Layout Maestro
 * Sistema profesional de facturación electrónica
 */
session_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

if (file_exists(__DIR__ . '/openssl_legacy.cnf')) {
    putenv('OPENSSL_CONF=' . __DIR__ . '/openssl_legacy.cnf');
}

// ── Guard de autenticación ────────────────────────────────────────────────────
// Solo operadores autenticados en admin_usuario pueden acceder al dashboard.
if (empty($_SESSION['admin_id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? 'dashboard.php');
    header('Location: login.php?redirect=' . $redirect);
    exit;
}
$adminNombre = $_SESSION['admin_nombre'] ?? 'Admin';
$adminRol    = $_SESSION['admin_rol']    ?? 'operador';
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/autoload.php';
use App\Core\Database;

ob_start();

// ── Lógica de Cambio de Empresa ──
// (Ya no existe la opción "0/Legacy": solo se cambia entre empresas reales)
if (isset($_GET['switch_empresa'])) {
    $newId = (int)$_GET['switch_empresa'];
    if ($newId > 0) {
        $_SESSION['active_empresa_id'] = $newId;
    }
    // Redirigir para limpiar URL
    header("Location: dashboard.php?module=" . ($_GET['module'] ?? 'clientes_mypos'));
    exit;
}

// ── Módulo activo ──
$module = $_GET['module'] ?? 'clientes_mypos';
$allowed = ['clientes_mypos','emision','consultas','historial','libros','empresas','config','certificacion','cafs','pos_urgencia','dispositivos',];
if (!in_array($module, $allowed)) $module = 'clientes_mypos';

// ── Intentar conexión DB (opcional, no bloquea) ──
$dbOk = false;
$empresas = [];
$empresaProd = null;   // empresa de producción (selección por defecto)
try {
    $db = Database::getInstance();
    $dbOk = true;

    $useSiiTables = false;
    try {
        $stmt = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sii_empresa' LIMIT 1");
        $useSiiTables = (bool)$stmt->fetchColumn();
    } catch (Exception $e) {}

    if ($useSiiTables) {
        $empresas = $db->query("SELECT id, rut, razon_social, ambiente_default, activo FROM sii_empresa WHERE activo = 1 ORDER BY razon_social")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $empresas = $db->query("
            SELECT e.id, e.rut, e.razon_social,
                   COALESCE(dc.ambiente, 'CERTIFICACION') AS ambiente_default,
                   e.activo
            FROM empresas e
            LEFT JOIN dte_configuracion dc ON e.id = dc.empresa_id
            WHERE e.activo = 1
            ORDER BY e.razon_social
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Empresa de producción: selección por defecto cuando no hay una activa.
    // Elimina el antiguo modo "Legacy": siempre operamos sobre una empresa real.
    foreach ($empresas as $e) {
        if (strtolower((string)$e['ambiente_default']) === 'produccion') {
            $empresaProd = $e;
            break;
        }
    }
    if ($empresaProd === null && !empty($empresas)) {
        $empresaProd = $empresas[0]; // sin producción definida: primera activa
    }
    // Autoseleccionar/validar la empresa activa ANTES de cargar api.php para que
    // Context se construya sobre una empresa REAL. Si la sesión apunta a un id
    // que ya no existe entre las empresas activas (sesión vieja, empresa borrada,
    // o id de otra tabla), se resetea automáticamente para evitar el fatal en
    // Context (auto-sanación de sesión).
    $idsValidos = array_map(fn($e) => (int)$e['id'], $empresas);
    $sesActual  = (int)($_SESSION['active_empresa_id'] ?? 0);
    if ($sesActual <= 0 || !in_array($sesActual, $idsValidos, true)) {
        if ($empresaProd !== null) {
            $_SESSION['active_empresa_id'] = (int)$empresaProd['id'];
        } else {
            unset($_SESSION['active_empresa_id']);
        }
    }
} catch (Exception $e) {
    // DB no disponible — modo degradado de SOLO LECTURA sobre constantes.
    // El selector NUNCA muestra "Legacy"; muestra la última empresa conocida.
}

// ── Datos del emisor (desde constantes legacy o DB) ──
define('DTE_API_BOOTSTRAP_ONLY', true);
require_once __DIR__ . '/api.php';

$ambiente = $globalContext ? $globalContext->getAmbiente() : AMBIENTE;
$rutEmisor = $globalContext ? $globalContext->getRut() : RUT_EMISOR;
$razonSocial = $globalContext ? $globalContext->getEmpresa()['razon_social'] : RAZON_SOCIAL;

// Contar archivos temporales y CAFs
$tmpCount = count(glob(TMP_DIR . '*.xml') ?: []);
$cafFiles = glob(CAF_DIR . 'caf_*.xml') ?: [];
$cafCount = count($cafFiles);

// Urgencia POS: nivel global para badge en sidebar
$posUrgencia = 'ok';
$posPendiente = 0;
if ($dbOk) {
    try {
        $alertaMgr  = new \App\Services\FoliosAlertaManager();
        $queueMgr   = new \App\Services\DteLocalQueueManager();
        $urgGlobal  = $alertaMgr->urgenciaGlobal();
        $posUrgencia = $urgGlobal['urgencia_global'] ?? 'ok';
        $colaResumen = $queueMgr->estadoCola();
        $posPendiente = (int)($colaResumen['resumen']['pendiente'] ?? 0);
    } catch (\Exception $e) {
        // silencioso — no bloquear el dashboard
    }
}

// ── Títulos por módulo ──
$titles = [
    'clientes_mypos' => ['Clientes MyPOS', 'Estado DTE, folios y certificacion por cliente'],
    'empresas'      => ['Empresas', 'Gestión multi-cliente y Onboarding'],
    'config'        => ['Configuración DTE', 'Certificados y firma electrónica'],
    'cafs'          => ['Folios CAF', 'Gestión centralizada por sucursal'],
    'dispositivos'  => ['Dispositivos POS', 'Enrolamiento de hardware'],
    'pos_urgencia'  => ['Urgencias POS', 'Cola de DTEs y stock de folios POS'],
    'emision'       => ['Emisión Manual', 'Generar documentos tributarios (Respaldo)'],
    'consultas'     => ['Consultas SII', 'Verificar estados de documentos'],
    'historial'     => ['Historial DTE', 'Documentos emitidos'],
    'libros'        => ['Libros & RCOF', 'Reportes tributarios'],
    'certificacion' => ['Certificación SII', 'Pool de pruebas (Solo No-Prod)'],
];

$pageTitle = $titles[$module][0] ?? 'Dashboard';
$pageSubtitle = $titles[$module][1] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — DTE Pro</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Override Bootstrap donde sea necesario */
        .card { border: 1px solid var(--c-border); border-radius: var(--radius-lg); box-shadow: var(--shadow); }
        .card-header { background: var(--c-surface); border-bottom: 1px solid var(--c-border); font-weight: 600; }
        .empresa-selector {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #fff;
            font-size: 0.75rem;
            border-radius: 8px;
            padding: 8px 12px;
            width: 100%;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23ffffff'%3e%3cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 10px 10px;
        }
        .empresa-selector:hover { background: rgba(255, 255, 255, 0.12); border-color: rgba(255, 255, 255, 0.3); }
        .empresa-selector:focus { border-color: var(--c-primary); box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); }
        .empresa-selector option { background: #1e293b; color: #fff; padding: 12px; }
    </style>
</head>
<body>

<div class="dash-layout">

    <!-- ══════════════════════════════════════ -->
    <!-- SIDEBAR -->
    <!-- ══════════════════════════════════════ -->
    <aside class="dash-sidebar" id="sidebar">
        <!-- Brand -->
        <div class="dash-sidebar-brand">
            <div class="brand-icon">
                <i class="bi bi-lightning-charge-fill"></i>
            </div>
            <div class="brand-text">
                DTE Pro
                <small>Facturación Electrónica</small>
            </div>
        </div>
        
        <!-- Empresa Selector -->
        <div style="padding: 10px 20px;">
            <label style="font-size: 0.62rem; color: var(--c-sidebar-text); text-transform: uppercase; font-weight: 700; letter-spacing: 0.08em; display: block; margin-bottom: 6px; opacity: 0.6;">Cambiar Empresa</label>
            <?php if ($dbOk && !empty($empresas)): ?>
            <select class="empresa-selector" onchange="location.href='dashboard.php?module=<?= $module ?>&switch_empresa=' + this.value">
                <?php foreach ($empresas as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= (isset($_SESSION['active_empresa_id']) && $_SESSION['active_empresa_id'] == $emp['id']) ? 'selected' : '' ?>>
                        <?= strtolower((string)$emp['ambiente_default']) === 'produccion' ? '🏢' : '🧪' ?> <?= htmlspecialchars($emp['razon_social']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <select class="empresa-selector" disabled title="Base de datos no disponible — modo solo lectura">
                <option selected>🏢 <?= htmlspecialchars($razonSocial) ?> (solo lectura)</option>
            </select>
            <?php endif; ?>
        </div>

        <!-- Navigation -->
        <nav class="dash-nav">
            <div class="dash-nav-section">1. Configuración del Cliente</div>
            <a href="dashboard.php?module=clientes_mypos" class="dash-nav-item <?= $module === 'clientes_mypos' ? 'active' : '' ?>">
                <i class="bi bi-clipboard2-pulse"></i> Clientes MyPOS
            </a>
            <a href="dashboard.php?module=empresas" class="dash-nav-item <?= $module === 'empresas' ? 'active' : '' ?>">
                <i class="bi bi-buildings"></i> Empresas
                <?php if (count($empresas) > 0): ?>
                    <span class="dash-nav-badge warning"><?= count($empresas) ?></span>
                <?php endif; ?>
            </a>

            <div class="dash-nav-section">2. Operación y Hardware</div>
            <a href="dashboard.php?module=config" class="dash-nav-item <?= $module === 'config' ? 'active' : '' ?>">
                <i class="bi bi-gear"></i> Configuración DTE
            </a>
            <a href="dashboard.php?module=cafs" class="dash-nav-item <?= $module === 'cafs' ? 'active' : '' ?>">
                <i class="bi bi-collection"></i> Folios CAF
            </a>
            <a href="dashboard.php?module=dispositivos" class="dash-nav-item <?= $module === 'dispositivos' ? 'active' : '' ?>">
                <i class="bi bi-phone-fill"></i> Dispositivos POS
                <?php
                $dispPendientes = 0;
                if ($dbOk) {
                    try {
                        $empresaId = $_SESSION['active_empresa_id'] ?? null;
                        if ($empresaId) {
                            $stPend = $db->prepare(
                                "SELECT COUNT(*) FROM sii_dispositivo
                                  WHERE empresa_id = ? AND token_usado = 0
                                    AND token_expira > NOW() AND activo = 1"
                            );
                            $stPend->execute([$empresaId]);
                            $dispPendientes = (int) $stPend->fetchColumn();
                        }
                    } catch (\Exception $e) {}
                }
                if ($dispPendientes > 0): ?>
                    <span class="dash-nav-badge warning" title="<?= $dispPendientes ?> token(s) pendiente(s) de enrolamiento">
                        <?= $dispPendientes ?>
                    </span>
                <?php endif; ?>
            </a>

            <div class="dash-nav-section">3. Monitoreo Diario</div>
            <a href="dashboard.php?module=pos_urgencia" class="dash-nav-item <?= $module === 'pos_urgencia' ? 'active' : '' ?>">
                <i class="bi bi-exclamation-triangle"></i> Urgencias POS
                <?php if ($posUrgencia === 'critico'): ?>
                    <span class="dash-nav-badge danger" title="Stock crítico en algún POS">!</span>
                <?php elseif ($posUrgencia === 'bajo' || $posPendiente > 0): ?>
                    <span class="dash-nav-badge warning" title="<?= $posPendiente > 0 ? "$posPendiente DTEs pendientes" : 'Stock bajo' ?>">
                        <?= $posPendiente > 0 ? $posPendiente : '~' ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="dashboard.php?module=emision" class="dash-nav-item <?= $module === 'emision' ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-plus"></i> Emisión Manual
            </a>
            <a href="dashboard.php?module=consultas" class="dash-nav-item <?= $module === 'consultas' ? 'active' : '' ?>">
                <i class="bi bi-search"></i> Consultas SII
            </a>
            <a href="dashboard.php?module=historial" class="dash-nav-item <?= $module === 'historial' ? 'active' : '' ?>">
                <i class="bi bi-clock-history"></i> Historial
                <?php if ($tmpCount > 0): ?>
                    <span class="dash-nav-badge info"><?= $tmpCount ?></span>
                <?php endif; ?>
            </a>
            <a href="dashboard.php?module=libros" class="dash-nav-item <?= $module === 'libros' ? 'active' : '' ?>">
                <i class="bi bi-journal-text"></i> Libros & RCOF
            </a>

            <div class="dash-nav-section">4. Zona de Certificación</div>
            <a href="dashboard.php?module=certificacion" class="dash-nav-item <?= $module === 'certificacion' ? 'active' : '' ?>">
                <i class="bi bi-shield-check"></i> Certificación SII
            </a>
        </nav>

        <!-- Footer -->
        <div class="dash-sidebar-footer">
            <div class="env-badge <?= $ambiente === 'PRODUCCION' ? 'prod' : 'cert' ?>">
                <i class="bi bi-circle-fill" style="font-size:.45rem"></i>
                <?= $ambiente === 'PRODUCCION' ? 'Producción' : 'Certificación' ?>
            </div>
            <div style="color:var(--c-sidebar-text); font-size:.65rem; margin-top:6px;">
                <?= $razonSocial ?><br>RUT <?= $rutEmisor ?>
            </div>
            <div style="margin-top:10px; padding-top:10px; border-top:1px solid rgba(255,255,255,0.08);">
                <div style="color:var(--c-sidebar-text); font-size:.65rem; opacity:.7; margin-bottom:6px;">
                    <i class="bi bi-person-circle"></i>
                    <?= htmlspecialchars($adminNombre) ?>
                    <span style="opacity:.6">(<?= htmlspecialchars($adminRol) ?>)</span>
                </div>
                <a href="logout.php"
                   style="display:flex;align-items:center;gap:6px;font-size:.7rem;color:#f87171;text-decoration:none;padding:5px 8px;border-radius:6px;transition:background .15s;"
                   onmouseover="this.style.background='rgba(248,113,113,.12)'"
                   onmouseout="this.style.background='transparent'">
                    <i class="bi bi-box-arrow-right"></i> Cerrar sesión
                </a>
            </div>
        </div>
    </aside>

    <!-- ══════════════════════════════════════ -->
    <!-- MAIN -->
    <!-- ══════════════════════════════════════ -->
    <main class="dash-main">
        <!-- Header -->
        <header class="dash-header">
            <button class="dash-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                <i class="bi bi-list"></i>
            </button>
            <div class="dash-header-title">
                <?= $pageTitle ?>
                <small><?= $pageSubtitle ?></small>
            </div>
            <div class="dash-header-actions">
                <?php if ($dbOk): ?>
                    <span class="d-badge prod"><i class="bi bi-database-check"></i> DB OK</span>
                <?php else: ?>
                    <span class="d-badge danger"><i class="bi bi-database-x"></i> DB Off</span>
                <?php endif; ?>
                <span class="d-badge info"><i class="bi bi-calendar3"></i> <?= date('d/m/Y') ?></span>
            </div>
        </header>

        <!-- Content -->
        <div class="dash-content">
            <?php
            $moduleFile = __DIR__ . "/modules/{$module}.php";
            if (file_exists($moduleFile)) {
                include $moduleFile;
            } else {
                echo '<div class="d-alert warning"><i class="bi bi-exclamation-triangle"></i> Módulo <strong>' . htmlspecialchars($module) . '</strong> aún no implementado.</div>';
            }
            ?>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bwip-js"></script>
<script src="jscript.js?v=<?= filemtime(__DIR__ . '/jscript.js') ?>"></script>
<script>
// Cerrar sidebar en móvil al hacer clic fuera
document.addEventListener('click', e => {
    const sb = document.getElementById('sidebar');
    if (window.innerWidth <= 991 && sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('.dash-toggle')) {
        sb.classList.remove('open');
    }
});
</script>
<!-- Zona de Impresión (Bypass de Dash containers) -->
<div id="zona-impresion" style="display:none"></div>

</body>
</html>
