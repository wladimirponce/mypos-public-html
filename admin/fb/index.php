<?php
/**
 * admin/fb/index.php
 *
 * Router para los endpoints de productos/sucursales/stock (BD mypos).
 * Todos los accesos requieren el mismo X-API-KEY que usa admin/api.php.
 *
 * URL base:  https://www.mypos.cl/admin/fb/
 * Ejemplo:   GET  /admin/fb/?action=productos_buscar&q=paracetamol&sucursal_id=5
 *            POST /admin/fb/?action=venta_registrar
 *
 * Acciones disponibles:
 *   productos_buscar   – Busca productos por texto o código de barras
 *   producto_detalle   – Detalle + stock de un producto
 *   sucursales         – Lista de locales (dim_sucursal + CdgSIISucur)
 *   sucursal_detalle   – Detalle de un local
 *   stock_producto     – Stock de un producto en una sucursal
 *   stock_multiple     – Stock de varios productos (batch)
 *   venta_registrar    – Registra venta + descuenta stock (post-emisión DTE)
 */

declare(strict_types=1);

// ── Autoload ─────────────────────────────────────────────────────────────────
// App\** → admin/src/** (mismo autoloader que api.php)
require_once dirname(__DIR__) . '/autoload.php';

// Fb\** → fb/**.php  (directorios en minúscula, clase conserva mayúsculas)
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Fb\\')) return;
    $rel   = substr($class, 3);                      // quita 'Fb\'
    $parts = explode('\\', $rel);
    $name  = array_pop($parts);                      // nombre de clase (PascalCase)
    $dir   = $parts ? strtolower(implode('/', $parts)) . '/' : '';
    $file  = __DIR__ . '/' . $dir . $name . '.php';
    if (file_exists($file)) require_once $file;
});

// ── Headers ───────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Autenticación X-API-KEY ────────────────────────────────────────────────────
// Reutiliza la misma lógica de validación de API Keys que dte_php/api.php,
// incluyendo el archivo de configuración de dte_php.
function fbValidateApiKey(): bool
{
    $keyHeader = $_SERVER['HTTP_X_API_KEY']
              ?? $_SERVER['HTTP_AUTHORIZATION']
              ?? '';

    $keyHeader = trim(str_ireplace('Bearer ', '', $keyHeader));

    if ($keyHeader === '') return false;

    // Verificar contra sii_api_key usando la misma conexión que api.php
    try {
        $pdo  = \App\Core\Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT ak.id FROM sii_api_key ak
             JOIN sii_empresa e ON e.id = ak.empresa_id
             WHERE ak.clave_hash = SHA2(:k, 256)
               AND ak.activa = 1
               AND e.activo  = 1
             LIMIT 1"
        );
        $stmt->execute([':k' => $keyHeader]);
        if ($stmt->fetch()) return true;
    } catch (\Throwable $ignored) {}

    return false;
}

if (!fbValidateApiKey()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'API Key inválida o ausente']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');
$data   = [];

$raw = file_get_contents('php://input');
if ($raw !== '' && $raw !== false) {
    $json = json_decode($raw, true);
    if (is_array($json)) $data = $json;
}
$data = array_merge($_GET, $_POST, $data);
unset($data['action']);

// ── Router ────────────────────────────────────────────────────────────────────
try {
    switch ($action) {

        // ── Productos ──────────────────────────────────────────────────────────
        case 'productos_buscar':
            $ctrl = new \Fb\Controllers\ProductosController();
            echo json_encode($ctrl->buscar($data));
            break;

        case 'producto_detalle':
            $ctrl = new \Fb\Controllers\ProductosController();
            echo json_encode($ctrl->detalle($data));
            break;

        // ── Sucursales ─────────────────────────────────────────────────────────
        case 'sucursales':
            $ctrl = new \Fb\Controllers\SucursalesController();
            echo json_encode($ctrl->lista($data));
            break;

        case 'sucursal_detalle':
            $ctrl = new \Fb\Controllers\SucursalesController();
            echo json_encode($ctrl->detalle($data));
            break;

        // ── Stock ──────────────────────────────────────────────────────────────
        case 'stock_producto':
            $ctrl = new \Fb\Controllers\StockController();
            echo json_encode($ctrl->stockProducto($data));
            break;

        case 'stock_multiple':
            $ctrl = new \Fb\Controllers\StockController();
            echo json_encode($ctrl->stockMultiple($data));
            break;

        // ── Ventas / Stock decrement ────────────────────────────────────────────
        case 'venta_registrar':
            $ctrl = new \Fb\Controllers\StockController();
            echo json_encode($ctrl->registrarVenta($data));
            break;

        // ── Health ─────────────────────────────────────────────────────────────
        case 'ping':
        case 'health':
            try {
                \Fb\Database::get(); // testa la conexión
                echo json_encode(['ok' => true, 'status' => 'connected', 'ts' => date('c')]);
            } catch (\Throwable $e) {
                http_response_code(503);
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'ok'      => false,
                'error'   => "Acción '{$action}' no reconocida",
                'acciones'=> [
                    'productos_buscar', 'producto_detalle',
                    'sucursales', 'sucursal_detalle',
                    'stock_producto', 'stock_multiple',
                    'venta_registrar', 'ping',
                ],
            ]);
    }

} catch (\Throwable $e) {
    http_response_code(500);
    $msg = $e->getMessage() . ' | file:' . $e->getFile() . ':' . $e->getLine();
    error_log('fb/index.php error: ' . $msg);
    file_put_contents(__DIR__ . '/fb_debug.log',
        date('Y-m-d H:i:s') . ' | action=' . $action . ' | ' . $msg . PHP_EOL,
        FILE_APPEND
    );
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'detail' => $msg]);
}
