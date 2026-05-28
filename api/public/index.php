<?php

declare(strict_types=1);

use Mypos\Config\Database;
use Mypos\Controllers\AnulacionController;
use Mypos\Controllers\AuditoriaController;
use Mypos\Controllers\AuthController;
use Mypos\Controllers\CajaController;
use Mypos\Controllers\CentroCostoController;
use Mypos\Controllers\CierreDiarioController;
use Mypos\Controllers\CompraController;
use Mypos\Controllers\ConfiguracionController;
use Mypos\Controllers\ClienteController;
use Mypos\Controllers\CreditoController;
use Mypos\Controllers\DocumentoIaController;
use Mypos\Controllers\EmpresaController;
use Mypos\Controllers\DocumentoTributarioController;
use Mypos\Controllers\DispositivoController;
use Mypos\Controllers\DteController;
use Mypos\Controllers\FolioController;
use Mypos\Controllers\IaController;
use Mypos\Controllers\LibroController;
use Mypos\Controllers\OnboardingController;
use Mypos\Controllers\PermissionController;
use Mypos\Controllers\ProductoController;
use Mypos\Controllers\ProveedorController;
use Mypos\Controllers\ReporteController;
use Mypos\Controllers\RubroController;
use Mypos\Controllers\StockController;
use Mypos\Controllers\SyncController;
use Mypos\Controllers\UploadController;
use Mypos\Controllers\VentaController;
use Mypos\Controllers\SuscripcionController;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Core\Router;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\CorsMiddleware;
use Mypos\Middleware\PermissionMiddleware;
use Mypos\Middleware\RateLimitMiddleware;
use Mypos\Middleware\SecurityHeadersMiddleware;
use Mypos\Middleware\SubscriptionMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Support\AppConfig;
use Mypos\Support\Env;
use Mypos\Support\SafeLogger;

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($vendorAutoload)) {
    require $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Mypos\\';
        $baseDir = dirname(__DIR__) . '/src/';

        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require $file;
        }
    });
}

$envDir = dirname(__DIR__);
Env::loadFile($envDir . '/.env');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    SafeLogger::warning('PHP runtime warning', [
        'severity' => $severity,
        'message' => $message,
        'file' => basename($file),
        'line' => $line,
    ]);

    return false;
});

set_exception_handler(static function (Throwable $exception): void {
    if ($exception instanceof HttpException) {
        Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
    }

    SafeLogger::error('Unhandled exception', [
        'type' => $exception::class,
        'message' => $exception->getMessage(),
        'file' => basename($exception->getFile()),
        'line' => $exception->getLine(),
    ]);

    Response::error(
        'Error interno del servidor.',
        AppConfig::debug() && !AppConfig::isProduction() ? ['exception' => [$exception->getMessage()]] : null,
        500
    );
});

(new SecurityHeadersMiddleware())->handle();
(new CorsMiddleware())->handle();
(new RateLimitMiddleware())->handle();

$router = new Router();

function protectedRoute(callable $handler, string $permission): callable
{
    return static function (array $params = []) use ($handler, $permission): void {
        $claims = (new AuthMiddleware())->handle();
        $userId = (int) $claims['user_id'];
        $empresaId = 0;

        if (isset($_GET['empresa_id'])) {
            $empresaId = (int) $_GET['empresa_id'];
        }

        if ($empresaId <= 0 && isset($_POST['empresa_id'])) {
            $empresaId = (int) $_POST['empresa_id'];
        }

        if ($empresaId <= 0) {
            $payload = Request::json();
            $empresaId = (int) ($payload['empresa_id'] ?? 0);
        }

        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        (new TenantMiddleware())->handle($userId, $empresaId);
        (new SubscriptionMiddleware())->handle();
        (new PermissionMiddleware())->handle($userId, $empresaId, $permission);

        if ($params === []) {
            $handler();
            return;
        }

        $handler($params);
    };
}

$router->get('/health', static function (): void {
    Response::success([
        'status' => 'ok',
        'app' => 'MyPOS',
        'company' => 'Agentika Ingeniería y Soluciones Inteligentes SpA',
    ]);
});

$router->get('/api/health', static function (): void {
    Response::success([
        'status' => 'ok',
        'app' => 'MyPOS',
        'company' => 'Agentika Ingeniería y Soluciones Inteligentes SpA',
    ]);
});

$router->get('/health/db', static function (): void {
    Database::connection()->query('SELECT 1');

    Response::success([
        'status' => 'ok',
        'database' => 'connected',
    ]);
});

$router->get('/api/health/db', static function (): void {
    Database::connection()->query('SELECT 1');

    Response::success([
        'status' => 'ok',
        'database' => 'connected',
    ]);
});

$router->get('/health/config', static function (): void {
    $envPath = dirname(__DIR__) . '/.env';
    $dbHost = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '');
    $dbName = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');
    $dbUser = (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: '');
    $dbPassword = (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');

    Response::success([
        'env_file_exists' => is_file($envPath),
        'env_file_readable' => is_readable($envPath),
        'pdo_loaded' => class_exists(PDO::class),
        'pdo_mysql_loaded' => in_array('mysql', PDO::getAvailableDrivers(), true),
        'db_host' => $dbHost,
        'db_database' => $dbName,
        'db_username' => $dbUser,
        'db_password_length' => strlen($dbPassword),
        'db_password_has_hash' => str_contains($dbPassword, '#'),
    ]);
});

$router->get('/api/health/config', static function (): void {
    $envPath = dirname(__DIR__) . '/.env';
    $dbHost = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '');
    $dbName = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');
    $dbUser = (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: '');
    $dbPassword = (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');

    Response::success([
        'env_file_exists' => is_file($envPath),
        'env_file_readable' => is_readable($envPath),
        'pdo_loaded' => class_exists(PDO::class),
        'pdo_mysql_loaded' => in_array('mysql', PDO::getAvailableDrivers(), true),
        'db_host' => $dbHost,
        'db_database' => $dbName,
        'db_username' => $dbUser,
        'db_password_length' => strlen($dbPassword),
        'db_password_has_hash' => str_contains($dbPassword, '#'),
    ]);
});

$authController = new AuthController();
$router->post('/api/v1/auth/register', [$authController, 'register']);
$router->post('/api/v1/auth/login', [$authController, 'login']);
$router->get('/api/v1/auth/me', [$authController, 'me']);
$router->post('/api/v1/auth/logout', [$authController, 'logout']);

$suscripcionController = new SuscripcionController();
$router->post('/api/v1/suscripciones/order', [$suscripcionController, 'createOrder']);
$router->post('/api/v1/suscripciones/flow-webhook', [$suscripcionController, 'flowWebhook']);
$router->get('/api/v1/suscripciones/flow-return', [$suscripcionController, 'flowReturn']);
$router->get('/api/v1/suscripciones/paypal-return', [$suscripcionController, 'paypalReturn']);
$router->get('/api/v1/suscripciones/status', [$suscripcionController, 'status']);

$permissionController = new PermissionController();
$router->get('/api/v1/permisos/mis-permisos', [$permissionController, 'myPermissions']);
$router->get('/api/v1/permisos', [$permissionController, 'permissions']);
$router->get('/api/v1/roles', [$permissionController, 'roles']);
$router->get('/api/v1/roles/{id}', [$permissionController, 'showRole']);
$router->post('/api/v1/roles', [$permissionController, 'storeRole']);
$router->put('/api/v1/roles/{id}', [$permissionController, 'updateRole']);
$router->delete('/api/v1/roles/{id}', [$permissionController, 'destroyRole']);
$router->get('/api/v1/roles/{id}/permisos', [$permissionController, 'rolePermissionsList']);
$router->put('/api/v1/roles/{id}/permisos', [$permissionController, 'updateRolePermissions']);

$empresaController = new EmpresaController();
$router->get('/api/v1/empresas', [$empresaController, 'index']);
$router->get('/api/v1/empresas/{id}', [$empresaController, 'show']);
$router->post('/api/v1/empresas', [$empresaController, 'store']);
$router->put('/api/v1/empresas/{id}', [$empresaController, 'update']);
$router->delete('/api/v1/empresas/{id}', [$empresaController, 'destroy']);
$router->get('/api/v1/empresas/{id}/sucursales', [$empresaController, 'sucursales']);
$router->post('/api/v1/empresas/{id}/sucursales', [$empresaController, 'storeSucursal']);
$router->put('/api/v1/sucursales/{id}', [$empresaController, 'updateSucursal']);
$router->delete('/api/v1/sucursales/{id}', [$empresaController, 'destroySucursal']);
$router->get('/api/v1/empresas/{id}/cajas', [$empresaController, 'cajas']);
$router->post('/api/v1/sucursales/{id}/cajas', [$empresaController, 'storeCaja']);
$router->put('/api/v1/cajas/{id}', [$empresaController, 'updateCaja']);
$router->delete('/api/v1/cajas/{id}', [$empresaController, 'destroyCaja']);
$router->get('/api/v1/empresas/{id}/usuarios', [$empresaController, 'usuarios']);
$router->get('/api/v1/usuarios/buscar', [$empresaController, 'buscarUsuariosGlobales']);
$router->post('/api/v1/empresas/{id}/usuarios', [$empresaController, 'asociarUsuario']);
$router->put('/api/v1/empresas/{id}/usuarios/{usuario_id}', [$empresaController, 'actualizarUsuarioEmpresa']);
$router->delete('/api/v1/empresas/{id}/usuarios/{usuario_id}', [$empresaController, 'removerUsuarioEmpresa']);

$clienteController = new ClienteController();
$router->get('/api/v1/clientes', protectedRoute([$clienteController, 'index'], 'clientes.ver'));
$router->post('/api/v1/clientes', protectedRoute([$clienteController, 'store'], 'clientes.crear'));
$router->get('/api/v1/clientes/{id}', protectedRoute([$clienteController, 'show'], 'clientes.ver'));
$router->put('/api/v1/clientes/{id}', protectedRoute([$clienteController, 'update'], 'clientes.editar'));
$router->delete('/api/v1/clientes/{id}', protectedRoute([$clienteController, 'destroy'], 'clientes.eliminar'));
$router->get('/api/v1/clientes/{id}/estado-cuenta', protectedRoute([$clienteController, 'accountState'], 'creditos.ver'));
$router->get('/api/v1/clientes/{id}/historial', protectedRoute([$clienteController, 'history'], 'creditos.ver'));

$proveedorController = new ProveedorController();
$router->get('/api/v1/proveedores', protectedRoute([$proveedorController, 'index'], 'proveedores.ver'));
$router->post('/api/v1/proveedores', protectedRoute([$proveedorController, 'store'], 'proveedores.crear'));
$router->get('/api/v1/proveedores/{id}', protectedRoute([$proveedorController, 'show'], 'proveedores.ver'));
$router->put('/api/v1/proveedores/{id}', protectedRoute([$proveedorController, 'update'], 'proveedores.editar'));
$router->delete('/api/v1/proveedores/{id}', protectedRoute([$proveedorController, 'destroy'], 'proveedores.eliminar'));

$creditoController = new CreditoController();
$router->get('/api/v1/creditos/clientes', protectedRoute([$creditoController, 'index'], 'creditos.ver'));
$router->get('/api/v1/creditos/clientes/{id}', protectedRoute([$creditoController, 'show'], 'creditos.ver'));
$router->post('/api/v1/creditos/clientes/{id}/pagos', protectedRoute([$creditoController, 'pay'], 'creditos.pagar'));

$auditoriaController = new AuditoriaController();
$router->get('/api/v1/auditoria', protectedRoute([$auditoriaController, 'index'], 'auditoria.ver'));
$router->get('/api/v1/auditoria/{id}', protectedRoute([$auditoriaController, 'show'], 'auditoria.ver'));

$configuracionController = new ConfiguracionController();
$router->get('/api/v1/configuracion/empresa', protectedRoute([$configuracionController, 'empresa'], 'configuracion.ver'));
$router->put('/api/v1/configuracion/empresa', protectedRoute([$configuracionController, 'updateEmpresa'], 'configuracion.editar'));
$router->get('/api/v1/configuracion/operacion', protectedRoute([$configuracionController, 'operacion'], 'configuracion.ver'));
$router->put('/api/v1/configuracion/operacion', protectedRoute([$configuracionController, 'updateOperacion'], 'configuracion.editar'));
$router->get('/api/v1/configuracion/sucursales/{sucursal_id}', protectedRoute([$configuracionController, 'sucursal'], 'configuracion.ver'));
$router->put('/api/v1/configuracion/sucursales/{sucursal_id}', protectedRoute([$configuracionController, 'updateSucursal'], 'configuracion.editar'));
$router->get('/api/v1/configuracion/efectiva', protectedRoute([$configuracionController, 'efectiva'], 'configuracion.ver'));

$uploadController = new UploadController();
$router->post('/api/v1/uploads/productos', protectedRoute([$uploadController, 'producto'], 'uploads.crear'));
$router->post('/api/v1/uploads/documentos-ia', protectedRoute([$uploadController, 'documentoIa'], 'uploads.crear'));
$router->post('/api/v1/uploads/logos', protectedRoute([$uploadController, 'logo'], 'configuracion.editar'));
$router->get('/api/v1/uploads/{id}/download', protectedRoute([$uploadController, 'download'], 'uploads.ver'));
$router->get('/api/v1/uploads/{id}', protectedRoute([$uploadController, 'show'], 'uploads.ver'));
$router->delete('/api/v1/uploads/{id}', protectedRoute([$uploadController, 'destroy'], 'uploads.crear'));

$iaController = new IaController();
$router->get('/api/v1/ia/configuracion', protectedRoute([$iaController, 'configuracion'], 'ia.configuracion.ver'));

$dispositivoController = new DispositivoController();
$router->post('/api/v1/dispositivos/registrar', protectedRoute([$dispositivoController, 'register'], 'dispositivos.registrar'));
$router->get('/api/v1/dispositivos', protectedRoute([$dispositivoController, 'index'], 'dispositivos.ver'));
$router->get('/api/v1/dispositivos/{id}', protectedRoute([$dispositivoController, 'show'], 'dispositivos.ver'));
$router->put('/api/v1/dispositivos/{id}', protectedRoute([$dispositivoController, 'update'], 'dispositivos.editar'));
$router->post('/api/v1/dispositivos/{id}/bloquear', protectedRoute([$dispositivoController, 'block'], 'dispositivos.bloquear'));
$router->post('/api/v1/dispositivos/{id}/revocar', protectedRoute([$dispositivoController, 'revoke'], 'dispositivos.bloquear'));

$syncController = new SyncController();
$router->get('/api/v1/sync/estado', protectedRoute([$syncController, 'status'], 'sync.ver'));
$router->post('/api/v1/sync/eventos', protectedRoute([$syncController, 'events'], 'sync.enviar'));
$router->get('/api/v1/sync/eventos', protectedRoute([$syncController, 'listEvents'], 'sync.ver'));
$router->get('/api/v1/sync/conflictos', protectedRoute([$syncController, 'conflicts'], 'sync.conflictos.ver'));
$router->post('/api/v1/sync/conflictos/{id}/resolver', protectedRoute([$syncController, 'resolveConflict'], 'sync.conflictos.resolver'));

$rubroController = new RubroController();
$router->get('/api/v1/rubros', protectedRoute([$rubroController, 'index'], 'productos.ver'));
$router->post('/api/v1/rubros', protectedRoute([$rubroController, 'store'], 'rubros.gestionar'));
$router->put('/api/v1/rubros/{id}', protectedRoute([$rubroController, 'update'], 'rubros.gestionar'));
$router->delete('/api/v1/rubros/{id}', protectedRoute([$rubroController, 'destroy'], 'rubros.gestionar'));

$centroCostoController = new CentroCostoController();
$router->get('/api/v1/centros-costo', protectedRoute([$centroCostoController, 'index'], 'productos.ver'));
$router->post('/api/v1/centros-costo', protectedRoute([$centroCostoController, 'store'], 'centros_costo.gestionar'));
$router->put('/api/v1/centros-costo/{id}', protectedRoute([$centroCostoController, 'update'], 'centros_costo.gestionar'));
$router->delete('/api/v1/centros-costo/{id}', protectedRoute([$centroCostoController, 'destroy'], 'centros_costo.gestionar'));

$productoController = new ProductoController();
$router->get('/api/v1/productos/buscar', protectedRoute([$productoController, 'search'], 'productos.ver'));
$router->get('/api/v1/productos', protectedRoute([$productoController, 'index'], 'productos.ver'));
$router->post('/api/v1/productos', protectedRoute([$productoController, 'store'], 'productos.crear'));
$router->get('/api/v1/productos/{id}', protectedRoute([$productoController, 'show'], 'productos.ver'));
$router->put('/api/v1/productos/{id}', protectedRoute([$productoController, 'update'], 'productos.editar'));
$router->delete('/api/v1/productos/{id}', protectedRoute([$productoController, 'destroy'], 'productos.eliminar'));
$router->get('/api/v1/productos/{id}/codigos-barra', protectedRoute([$productoController, 'listBarcodes'], 'productos.ver'));
$router->post('/api/v1/productos/{id}/codigos-barra', protectedRoute([$productoController, 'storeBarcode'], 'productos.editar'));
$router->delete('/api/v1/productos/{id}/codigos-barra/{codigo_barra_id}', protectedRoute([$productoController, 'deleteBarcode'], 'productos.editar'));
$router->get('/api/v1/productos/{id}/imagenes', protectedRoute([$productoController, 'listImages'], 'productos.ver'));
$router->post('/api/v1/productos/{id}/imagenes', protectedRoute([$productoController, 'storeImage'], 'productos.editar'));
$router->delete('/api/v1/productos/{id}/imagenes/{imagen_id}', protectedRoute([$productoController, 'deleteImage'], 'productos.editar'));
$router->get('/api/v1/productos/{id}/impuestos', protectedRoute([$productoController, 'listTaxes'], 'productos.ver'));
$router->post('/api/v1/productos/{id}/impuestos', protectedRoute([$productoController, 'storeTax'], 'impuestos.gestionar'));
$router->delete('/api/v1/productos/{id}/impuestos/{producto_impuesto_id}', protectedRoute([$productoController, 'deleteTax'], 'impuestos.gestionar'));
$router->get('/api/v1/productos/{id}/descuentos', protectedRoute([$productoController, 'listDiscounts'], 'productos.ver'));
$router->post('/api/v1/productos/{id}/descuentos', protectedRoute([$productoController, 'storeDiscount'], 'descuentos.gestionar'));
$router->put('/api/v1/productos/{id}/descuentos/{descuento_id}', protectedRoute([$productoController, 'updateDiscount'], 'descuentos.gestionar'));
$router->delete('/api/v1/productos/{id}/descuentos/{descuento_id}', protectedRoute([$productoController, 'deleteDiscount'], 'descuentos.gestionar'));
$router->get('/api/v1/productos/{id}/comisiones', protectedRoute([$productoController, 'listCommissions'], 'productos.ver'));
$router->post('/api/v1/productos/{id}/comisiones', protectedRoute([$productoController, 'storeCommission'], 'comisiones.gestionar'));
$router->put('/api/v1/productos/{id}/comisiones/{comision_id}', protectedRoute([$productoController, 'updateCommission'], 'comisiones.gestionar'));
$router->delete('/api/v1/productos/{id}/comisiones/{comision_id}', protectedRoute([$productoController, 'deleteCommission'], 'comisiones.gestionar'));

$stockController = new StockController();
$router->get('/api/v1/stock', protectedRoute([$stockController, 'index'], 'stock.ver'));
$router->get('/api/v1/stock/producto/{producto_id}', protectedRoute([$stockController, 'showProduct'], 'stock.ver'));
$router->post('/api/v1/stock/ajustes', protectedRoute([$stockController, 'ajuste'], 'stock.ajustar'));
$router->get('/api/v1/stock/movimientos', protectedRoute([$stockController, 'movimientos'], 'stock.movimientos.ver'));

$cajaController = new CajaController();
$router->get('/api/v1/cajas/estado', protectedRoute([$cajaController, 'status'], 'cajas.ver'));
$router->get('/api/v1/cajas/cierres', protectedRoute([$cajaController, 'closures'], 'cajas.ver'));
$router->get('/api/v1/cajas/cierres/{id}', protectedRoute([$cajaController, 'closureDetail'], 'cajas.ver'));
$router->get('/api/v1/cajas', protectedRoute([$cajaController, 'index'], 'cajas.ver'));
$router->post('/api/v1/cajas', protectedRoute([$cajaController, 'store'], 'cajas.crear'));
$router->post('/api/v1/cajas/{id}/abrir', protectedRoute([$cajaController, 'open'], 'cajas.abrir'));
$router->post('/api/v1/cajas/movimientos', protectedRoute([$cajaController, 'movement'], 'cajas.movimientos'));
$router->get('/api/v1/cajas/{id}/movimientos', protectedRoute([$cajaController, 'movements'], 'cajas.ver'));
$router->post('/api/v1/cajas/aperturas/{id}/cerrar', protectedRoute([$cajaController, 'close'], 'cajas.cerrar'));

$ventaController = new VentaController();
$router->post('/api/v1/ventas', protectedRoute([$ventaController, 'store'], 'ventas.crear'));

$anulacionController = new AnulacionController();
$router->post('/api/v1/ventas/{id}/anular', protectedRoute([$anulacionController, 'cancelSale'], 'ventas.anular'));
$router->post('/api/v1/compras/{id}/reversar', protectedRoute([$anulacionController, 'reversePurchase'], 'compras.reversar'));
$router->get('/api/v1/anulaciones', protectedRoute([$anulacionController, 'index'], 'ventas.ver'));
$router->get('/api/v1/anulaciones/{id}', protectedRoute([$anulacionController, 'show'], 'ventas.ver'));

$cierreDiarioController = new CierreDiarioController();
$router->get('/api/v1/cierres-diarios', protectedRoute([$cierreDiarioController, 'index'], 'reportes.ver'));
$router->post('/api/v1/cierres-diarios', protectedRoute([$cierreDiarioController, 'store'], 'cierres.crear'));
$router->get('/api/v1/cierres-diarios/{id}', protectedRoute([$cierreDiarioController, 'show'], 'reportes.ver'));

$compraController = new CompraController();
$router->get('/api/v1/compras', protectedRoute([$compraController, 'index'], 'compras.ver'));
$router->post('/api/v1/compras', protectedRoute([$compraController, 'store'], 'compras.crear'));
$router->get('/api/v1/compras/{id}', protectedRoute([$compraController, 'show'], 'compras.ver'));
$router->post('/api/v1/compras/{id}/confirmar', protectedRoute([$compraController, 'confirm'], 'compras.confirmar'));
$router->post('/api/v1/compras/{id}/anular', protectedRoute([$compraController, 'cancel'], 'compras.anular'));

$documentoIaController = new DocumentoIaController();
$router->get('/api/v1/documentos-ia', protectedRoute([$documentoIaController, 'index'], 'documentos_ia.ver'));
$router->post('/api/v1/documentos-ia', protectedRoute([$documentoIaController, 'store'], 'documentos_ia.subir'));
$router->get('/api/v1/documentos-ia/{id}', protectedRoute([$documentoIaController, 'show'], 'documentos_ia.ver'));
$router->post('/api/v1/documentos-ia/{id}/procesar', protectedRoute([$documentoIaController, 'process'], 'documentos_ia.procesar'));
$router->post('/api/v1/documentos-ia/{id}/procesar-gemini', protectedRoute([$documentoIaController, 'processGemini'], 'documentos_ia.procesar_real'));
$router->post('/api/v1/documentos-ia/{id}/normalizar', protectedRoute([$documentoIaController, 'normalize'], 'documentos_ia.normalizar'));
$router->get('/api/v1/documentos-ia/{id}/revision', protectedRoute([$documentoIaController, 'revision'], 'documentos_ia.revisar'));
$router->put('/api/v1/documentos-ia/{id}/revision/cabecera', protectedRoute([$documentoIaController, 'updateRevisionHeader'], 'documentos_ia.revisar'));
$router->put('/api/v1/documentos-ia/detalles/{detalle_id}/revision', protectedRoute([$documentoIaController, 'updateRevisionDetail'], 'documentos_ia.revisar'));
$router->get('/api/v1/documentos-ia/{id}/alertas', protectedRoute([$documentoIaController, 'alerts'], 'documentos_ia.alertas.ver'));
$router->post('/api/v1/documentos-ia/alertas/{alerta_id}/resolver', protectedRoute([$documentoIaController, 'resolveAlert'], 'documentos_ia.alertas.resolver'));
$router->post('/api/v1/documentos-ia/{id}/aprobar', protectedRoute([$documentoIaController, 'approve'], 'documentos_ia.aprobar'));
$router->post('/api/v1/documentos-ia/{id}/vincular-proveedor', protectedRoute([$documentoIaController, 'linkProvider'], 'documentos_ia.revisar'));
$router->put('/api/v1/documentos-ia/{id}/editar', protectedRoute([$documentoIaController, 'edit'], 'documentos_ia.editar'));
$router->post('/api/v1/documentos-ia/{id}/generar-compra', protectedRoute([$documentoIaController, 'generatePurchase'], 'documentos_ia.generar_compra'));
$router->post('/api/v1/documentos-ia/{id}/vincular-producto', protectedRoute([$documentoIaController, 'linkProduct'], 'documentos_ia.vincular_producto'));

$documentoTributarioController = new DocumentoTributarioController();
$router->post('/api/v1/documentos-tributarios/desde-venta', protectedRoute([$documentoTributarioController, 'storeFromSale'], 'documentos_tributarios.crear'));
$router->get('/api/v1/documentos-tributarios', protectedRoute([$documentoTributarioController, 'index'], 'documentos_tributarios.ver'));
$router->get('/api/v1/documentos-tributarios/{id}', protectedRoute([$documentoTributarioController, 'show'], 'documentos_tributarios.ver'));
$router->post('/api/v1/documentos-tributarios/{id}/emitir-dte', protectedRoute([$documentoTributarioController, 'emitDte'], 'dte.emitir'));
$router->post('/api/v1/documentos-tributarios/{id}/asignar-folio', protectedRoute([$documentoTributarioController, 'assignFolio'], 'documentos_tributarios.asignar_folio'));
$router->post('/api/v1/documentos-tributarios/{id}/marcar-emitido-interno', protectedRoute([$documentoTributarioController, 'markInternalIssued'], 'documentos_tributarios.emitir_interno'));
$router->post('/api/v1/documentos-tributarios/{id}/marcar-enviado-sii', protectedRoute([$documentoTributarioController, 'markSentSii'], 'documentos_tributarios.cambiar_estado_sii'));
$router->post('/api/v1/documentos-tributarios/{id}/marcar-aceptado-sii', protectedRoute([$documentoTributarioController, 'markAcceptedSii'], 'documentos_tributarios.cambiar_estado_sii'));
$router->post('/api/v1/documentos-tributarios/{id}/marcar-rechazado-sii', protectedRoute([$documentoTributarioController, 'markRejectedSii'], 'documentos_tributarios.cambiar_estado_sii'));
$router->post('/api/v1/documentos-tributarios/{id}/anular', protectedRoute([$documentoTributarioController, 'cancel'], 'documentos_tributarios.anular'));

$folioController = new FolioController();
$router->post('/api/v1/folios/caf', protectedRoute([$folioController, 'storeCaf'], 'folios.caf.crear'));
$router->get('/api/v1/folios/caf', protectedRoute([$folioController, 'listCafs'], 'folios.ver'));
$router->post('/api/v1/folios/asignaciones', protectedRoute([$folioController, 'storeAssignment'], 'folios.asignar'));
$router->get('/api/v1/folios/asignaciones', protectedRoute([$folioController, 'listAssignments'], 'folios.ver'));
$router->get('/api/v1/folios/disponibles', protectedRoute([$folioController, 'availability'], 'folios.ver'));
$router->post('/api/v1/folios/consumir', protectedRoute([$folioController, 'consume'], 'folios.consumir'));
$router->get('/api/v1/folios/consumidos', protectedRoute([$folioController, 'consumed'], 'folios.ver'));
$router->get('/api/v1/folios/alertas', protectedRoute([$folioController, 'alerts'], 'folios.alertas.ver'));

$dteController = new DteController();
$router->get('/api/v1/dte/configuracion', protectedRoute([$dteController, 'config'], 'dte.configuracion.ver'));
$router->put('/api/v1/dte/configuracion', protectedRoute([$dteController, 'updateConfig'], 'dte.configuracion.editar'));
$router->get('/api/v1/dte/emisiones', protectedRoute([$dteController, 'emissions'], 'dte.ver'));
$router->get('/api/v1/dte/emisiones/{id}', protectedRoute([$dteController, 'emissionDetail'], 'dte.ver'));
$router->post('/api/v1/dte/emisiones/{id}/reintentar', protectedRoute([$dteController, 'retry'], 'dte.reintentar'));
$router->post('/api/v1/dte/emisiones/{id}/marcar-aceptado', protectedRoute([$dteController, 'markAccepted'], 'dte.emitir'));
$router->post('/api/v1/dte/emisiones/{id}/marcar-rechazado', protectedRoute([$dteController, 'markRejected'], 'dte.emitir'));

$libroController = new LibroController();
$router->get('/api/v1/libros/ventas', protectedRoute([$libroController, 'ventas'], 'libros.ventas.ver'));
$router->get('/api/v1/libros/compras', protectedRoute([$libroController, 'compras'], 'libros.compras.ver'));
$router->get('/api/v1/libros/resumen-iva', protectedRoute([$libroController, 'resumenIva'], 'libros.resumen_iva.ver'));
$router->get('/api/v1/libros/ventas/resumen-tipo-documento', protectedRoute([$libroController, 'ventasResumenTipoDocumento'], 'libros.ventas.ver'));
$router->get('/api/v1/libros/compras/resumen-proveedor', protectedRoute([$libroController, 'comprasResumenProveedor'], 'libros.compras.ver'));

$reporteController = new ReporteController();
$router->get('/api/v1/reportes/resumen-ventas', protectedRoute([$reporteController, 'resumenVentas'], 'reportes.ver'));
$router->get('/api/v1/reportes/ventas-por-dia', protectedRoute([$reporteController, 'ventasPorDia'], 'reportes.ver'));
$router->get('/api/v1/reportes/ventas-por-metodo-pago', protectedRoute([$reporteController, 'ventasPorMetodoPago'], 'reportes.ver'));
$router->get('/api/v1/reportes/ventas-por-producto', protectedRoute([$reporteController, 'ventasPorProducto'], 'reportes.ver'));
$router->get('/api/v1/reportes/ventas-por-rubro', protectedRoute([$reporteController, 'ventasPorRubro'], 'reportes.ver'));
$router->get('/api/v1/reportes/ventas-por-usuario', protectedRoute([$reporteController, 'ventasPorUsuario'], 'reportes.ver'));
$router->get('/api/v1/reportes/dashboard', protectedRoute([$reporteController, 'dashboard'], 'dashboard.ver'));

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
