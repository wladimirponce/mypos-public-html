<?php
/**
 * Dashboard DTE — Layout Maestro
 * Sistema profesional de facturación electrónica
 */
if (session_status() === PHP_SESSION_NONE) {
    // Cookie de sesión endurecida (HttpOnly + SameSite=Strict, Secure en HTTPS).
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
    ]);
    session_start();
}
date_default_timezone_set('America/Santiago');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/autoload.php';
use App\Core\Database;
use App\Services\AdminMfa;
use App\Services\AdminSecurity;

if (file_exists(__DIR__ . '/openssl_legacy.cnf')) {
    putenv('OPENSSL_CONF=' . __DIR__ . '/openssl_legacy.cnf');
}

// —— Guard de autenticación ————————————————————————————————————————————————————
// Solo operadores autenticados en admin_usuario pueden acceder al dashboard.
if (empty($_SESSION['admin_id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? 'dashboard.php');
    header('Location: login.php?redirect=' . $redirect);
    exit;
}
$adminNombre = $_SESSION['admin_nombre'] ?? 'Admin';
$adminRol    = $_SESSION['admin_rol']    ?? 'operador';
AdminSecurity::validatePostCsrf();
// —————————————————————————————————————————————————————————————————————————————

ob_start();

// —— Lógica de Cambio de Empresa ——
// (Ya no existe la opción "0/Legacy": solo se cambia entre empresas reales)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['admin_action'] ?? '') === 'switch_empresa') {
    $newId = (int)($_POST['switch_empresa'] ?? 0);
    if ($newId > 0) {
        try {
            $dbSwitch = Database::getInstance();
            $stmtSwitch = $dbSwitch->prepare("
                SELECT COUNT(*)
                FROM empresas
                WHERE id = ? AND activo = 1
            ");
            $stmtSwitch->execute([$newId]);
            if ((int)$stmtSwitch->fetchColumn() > 0) {
                $_SESSION['active_empresa_id'] = $newId;
            } else {
                unset($_SESSION['active_empresa_id']);
            }
        } catch (Exception $e) {
            unset($_SESSION['active_empresa_id']);
        }
    }
    // Redirigir para limpiar URL
    $targetModule = (string) ($_POST['target_module'] ?? 'empresas');
    header("Location: dashboard.php?module=" . rawurlencode($targetModule));
    exit;
}

// —— Módulo activo ——
$defaultModule = $adminRol === 'superadmin' ? 'empresas' : 'emision';
$module = $_GET['module'] ?? $defaultModule;
$allowed = ['clientes_mypos','emision','consultas','historial','libros','rcof_auditoria','agente_consultas','empresas','config','certificacion','cafs','pos_urgencia','dispositivos','whatsapp','ventas_reset','promos',];
if (!in_array($module, $allowed)) $module = $defaultModule;
AdminSecurity::requireModuleAccess($module);

// —— Intentar conexión DB (opcional, no bloquea) ——
$dbOk = false;
$empresas = [];
try {
    $db = Database::getInstance();
    $dbOk = true;
    AdminSecurity::guard($db);

    // Enforcement MFA: con la flag activa, un rol obligado a segundo factor que
    // aún no lo tenga enrolado es enviado a enrolarse antes de usar el panel
    // (aplica también a sesiones ya activas al momento de activar la flag).
    if (AdminMfa::isGloballyEnabled()
        && AdminMfa::roleRequiresMfa((string) ($_SESSION['admin_rol'] ?? ''))
        && AdminMfa::tableAvailable($db)
        && !AdminMfa::isEnrolled($db, (int) ($_SESSION['admin_id'] ?? 0))) {
        header('Location: mfa_enroll.php');
        exit;
    }

    $empresas = $db->query("SELECT e.id, e.rut, e.razon_social, COALESCE(dc.ambiente, 'CERTIFICACION') AS ambiente_default, e.activo FROM empresas e LEFT JOIN dte_configuracion dc ON e.id = dc.empresa_id WHERE e.activo = 1 ORDER BY e.razon_social")->fetchAll(PDO::FETCH_ASSOC);

    // Nunca se selecciona una empresa automaticamente.
    $idsValidos = array_map(fn($e) => (int)$e['id'], $empresas);
    $sesActual  = (int)($_SESSION['active_empresa_id'] ?? 0);
    if ($sesActual > 0 && !in_array($sesActual, $idsValidos, true)) {
        unset($_SESSION['active_empresa_id']);
    }
} catch (Exception $e) {
    http_response_code(503);
    exit('Panel temporalmente no disponible: no fue posible revalidar la sesion.');
    // DB no disponible — modo degradado de SOLO LECTURA sobre constantes.
    // El selector NUNCA muestra "Legacy"; muestra la última empresa conocida.
}
// —— Datos del emisor (desde constantes legacy o DB) ——
define('DTE_API_BOOTSTRAP_ONLY', true);
require_once __DIR__ . '/api.php';

$ambiente = $globalContext ? $globalContext->getAmbiente() : '';
$rutEmisor = $globalContext ? $globalContext->getRut() : RUT_EMISOR;
$razonSocial = $globalContext ? $globalContext->getEmpresa()['razon_social'] : RAZON_SOCIAL;

// Contar solo archivos de la empresa activa. Sin empresa no se exponen datos legacy.
$tmpCount = $globalContext ? count(glob($actualTmpDir . '*.xml') ?: []) : 0;
$cafFiles = $globalContext ? (glob($actualCafDir . 'caf_*.xml') ?: []) : [];
$cafCount = count($cafFiles);

// Urgencia POS: nivel global para badge en sidebar
$posUrgencia = 'ok';
$posPendiente = 0;

// —— Títulos por módulo ——
$titles = [
    'empresas'      => ['Empresas', 'Gestión multi-cliente y Onboarding'],
    'clientes_mypos' => ['Clientes MyPOS', 'Estado DTE, folios y certificación por cliente'],
    'config'        => ['Configuración DTE', 'Certificados y firma electrónica'],
    'cafs'          => ['Folios CAF', 'Gestión centralizada por sucursal'],
    'dispositivos'  => ['Dispositivos POS', 'Enrolamiento de hardware'],
    'pos_urgencia'  => ['Urgencias POS', 'Cola de DTEs y stock de folios POS'],
    'emision'       => ['Emisión Manual', 'Generar documentos tributarios (Respaldo)'],
    'consultas'     => ['Consultas SII', 'Verificar estados de documentos'],
    'historial'     => ['Historial DTE', 'Documentos emitidos'],
    'libros'        => ['Libros & RCOF', 'Reportes tributarios'],
    'rcof_auditoria'=> ['Auditoria RCOF', 'Estado diario multiempresa'],
    'agente_consultas' => ['Consultas IA', 'Preguntas no resueltas por el asistente'],
    'certificacion' => ['Certificación SII', 'Pool de pruebas (Solo No-Prod)'],
    'whatsapp'      => ['WhatsApp Business', 'Asignación de números por empresa'],
    'ventas_reset'  => ['Resetear Ventas', 'Eliminar ventas de prueba sin tocar folios'],
    'promos'        => ['Links de precio', 'Precios especiales para captar clientes'],
];

$pageTitle = $titles[$module][0] ?? 'Dashboard';
$pageSubtitle = $titles[$module][1] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> – DTE Pro</title>
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

    <!-- ══════════════════════════════════════════════ -->
    <!-- SIDEBAR -->
    <!-- ══════════════════════════════════════════════ -->
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
            <form method="POST" action="dashboard.php?module=<?= htmlspecialchars($module) ?>" id="empresa-switch-form">
            <input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(AdminSecurity::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="admin_action" value="switch_empresa">
            <input type="hidden" name="target_module" value="<?= htmlspecialchars($module, ENT_QUOTES, 'UTF-8') ?>">
            <select name="switch_empresa" class="empresa-selector" onchange="if(this.value){this.form.submit()}">
                <option value="" <?= empty($_SESSION['active_empresa_id']) ? 'selected' : '' ?>>Seleccione una empresa</option>
                <?php foreach ($empresas as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= (isset($_SESSION['active_empresa_id']) && $_SESSION['active_empresa_id'] == $emp['id']) ? 'selected' : '' ?>>
                        <?= strtolower((string)$emp['ambiente_default']) === 'produccion' ? '🏢' : '🧪' ?> <?= htmlspecialchars($emp['razon_social']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            </form>
            <?php else: ?>
            <select class="empresa-selector" disabled>
                <option selected>Sin empresas registradas</option>
            </select>
            <?php endif; ?>
        </div>

        <!-- Navigation -->
        <nav class="dash-nav">
            <div class="dash-nav-section">1. Configuración del Cliente</div>
            <a href="dashboard.php?module=empresas" class="dash-nav-item <?= $module === 'empresas' ? 'active' : '' ?>">
                <i class="bi bi-buildings"></i> Empresas
                <?php if (count($empresas) > 0): ?>
                    <span class="dash-nav-badge warning"><?= count($empresas) ?></span>
                <?php endif; ?>
            </a>
            <a href="dashboard.php?module=clientes_mypos" class="dash-nav-item <?= $module === 'clientes_mypos' ? 'active' : '' ?>">
                <i class="bi bi-clipboard2-pulse"></i> Clientes MyPOS
            </a>
            <a href="dashboard.php?module=promos" class="dash-nav-item <?= $module === 'promos' ? 'active' : '' ?>">
                <i class="bi bi-tag"></i> Links de precio
            </a>
            <a href="dashboard.php?module=whatsapp" class="dash-nav-item <?= $module === 'whatsapp' ? 'active' : '' ?>"
               style="color:#25d366;">
                <i class="bi bi-whatsapp"></i> WhatsApp
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
            <a href="dashboard.php?module=rcof_auditoria" class="dash-nav-item <?= $module === 'rcof_auditoria' ? 'active' : '' ?>">
                <i class="bi bi-clipboard2-check"></i> Auditoria RCOF
            </a>
            <a href="dashboard.php?module=agente_consultas" class="dash-nav-item <?= $module === 'agente_consultas' ? 'active' : '' ?>">
                <i class="bi bi-chat-left-text"></i> Consultas IA
            </a>

            <div class="dash-nav-section">4. Zona de Certificación</div>
            <a href="dashboard.php?module=certificacion" class="dash-nav-item <?= $module === 'certificacion' ? 'active' : '' ?>">
                <i class="bi bi-shield-check"></i> Certificación SII
            </a>
            <a href="dashboard.php?module=ventas_reset" class="dash-nav-item <?= $module === 'ventas_reset' ? 'active' : '' ?>"
               style="color:#f87171;">
                <i class="bi bi-trash3"></i> Resetear Ventas
            </a>
        </nav>

        <!-- Footer -->
        <div class="dash-sidebar-footer">
            <div class="env-badge <?= $ambiente === 'PRODUCCION' ? 'prod' : 'cert' ?>">
                <i class="bi bi-circle-fill" style="font-size:.45rem"></i>
                <?= $globalContext ? ($ambiente === 'PRODUCCION' ? 'Producción' : 'Certificación') : 'Sin empresa' ?>
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

    <!-- ══════════════════════════════════════════════ -->
    <!-- MAIN -->
    <!-- ══════════════════════════════════════════════ -->
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
            $globalModules = ['clientes_mypos', 'empresas', 'whatsapp', 'rcof_auditoria', 'agente_consultas', 'ventas_reset', 'promos'];
            if (!$globalContext && !in_array($module, $globalModules, true)) {
                echo '<div class="d-alert warning"><i class="bi bi-building-exclamation"></i> Seleccione una empresa para acceder a este modulo.</div>';
            } elseif (file_exists($moduleFile)) {
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
const adminCsrfToken = <?= json_encode(AdminSecurity::csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
document.querySelectorAll('form').forEach(form => {
    if ((form.method || '').toLowerCase() !== 'post' || form.querySelector('input[name="admin_csrf_token"]')) return;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'admin_csrf_token';
    input.value = adminCsrfToken;
    form.appendChild(input);
});

function switchAdminEmpresa(empresaId, targetModule) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'dashboard.php?module=' + encodeURIComponent(targetModule);
    const values = {admin_csrf_token: adminCsrfToken, admin_action: 'switch_empresa', switch_empresa: empresaId, target_module: targetModule};
    Object.entries(values).forEach(([name, value]) => {
        const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = String(value); form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
}

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
