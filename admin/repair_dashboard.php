<?php
$file = 'c:/MyPOS/admin/dashboard.php';
$content = file_get_contents($file);

$marker = '// —— Datos del emisor';
$pos = strpos($content, $marker);
if ($pos === false) {
    die("Marker not found.");
}

$bottom = substr($content, $pos);

$top = <<<PHP
<?php
/**
 * Dashboard DTE — Layout Maestro
 * Sistema profesional de facturación electrónica
 */
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('America/Santiago');
ini_set('display_errors', '1');
error_reporting(E_ALL);

if (file_exists(__DIR__ . '/openssl_legacy.cnf')) {
    putenv('OPENSSL_CONF=' . __DIR__ . '/openssl_legacy.cnf');
}

// —— Guard de autenticación ————————————————————————————————————————————————————
// Solo operadores autenticados en admin_usuario pueden acceder al dashboard.
if (empty(\$_SESSION['admin_id'])) {
    \$redirect = urlencode(\$_SERVER['REQUEST_URI'] ?? 'dashboard.php');
    header('Location: login.php?redirect=' . \$redirect);
    exit;
}
\$adminNombre = \$_SESSION['admin_nombre'] ?? 'Admin';
\$adminRol    = \$_SESSION['admin_rol']    ?? 'operador';
// —————————————————————————————————————————————————————————————————————————————

require_once __DIR__ . '/autoload.php';
use App\Core\Database;

ob_start();

// —— Lógica de Cambio de Empresa ——
// (Ya no existe la opción "0/Legacy": solo se cambia entre empresas reales)
if (isset(\$_GET['switch_empresa'])) {
    \$newId = (int)\$_GET['switch_empresa'];
    if (\$newId > 0) {
        try {
            \$dbSwitch = Database::getInstance();
            \$stmtSwitch = \$dbSwitch->prepare("
                SELECT COUNT(*)
                FROM empresas
                WHERE id = ? AND activo = 1
            ");
            \$stmtSwitch->execute([\$newId]);
            if ((int)\$stmtSwitch->fetchColumn() > 0) {
                \$_SESSION['active_empresa_id'] = \$newId;
            } else {
                unset(\$_SESSION['active_empresa_id']);
            }
        } catch (Exception \$e) {
            unset(\$_SESSION['active_empresa_id']);
        }
    }
    // Redirigir para limpiar URL
    header("Location: dashboard.php?module=" . (\$_GET['module'] ?? 'clientes_mypos'));
    exit;
}

// —— Módulo activo ——
\$module = \$_GET['module'] ?? 'clientes_mypos';
\$allowed = ['clientes_mypos','emision','consultas','historial','libros','empresas','config','certificacion','cafs','pos_urgencia','dispositivos',];
if (!in_array(\$module, \$allowed)) \$module = 'clientes_mypos';

// —— Intentar conexión DB (opcional, no bloquea) ——
\$dbOk = false;
\$empresas = [];
try {
    \$db = Database::getInstance();
    \$dbOk = true;

    \$empresas = \$db->query("SELECT e.id, e.rut, e.razon_social, COALESCE(dc.ambiente, 'CERTIFICACION') AS ambiente_default, e.activo FROM empresas e LEFT JOIN dte_configuracion dc ON e.id = dc.empresa_id WHERE e.activo = 1 ORDER BY e.razon_social")->fetchAll(PDO::FETCH_ASSOC);

    // Nunca se selecciona una empresa automaticamente.
    \$idsValidos = array_map(fn(\$e) => (int)\$e['id'], \$empresas);
    \$sesActual  = (int)(\$_SESSION['active_empresa_id'] ?? 0);
    if (\$sesActual > 0 && !in_array(\$sesActual, \$idsValidos, true)) {
        unset(\$_SESSION['active_empresa_id']);
    }
} catch (Exception \$e) {
    // DB no disponible — modo degradado de SOLO LECTURA sobre constantes.
    // El selector NUNCA muestra "Legacy"; muestra la última empresa conocida.
}

PHP;

file_put_contents($file, $top . $bottom);
echo "dashboard.php repaired successfully.\n";
