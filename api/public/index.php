<?php
declare(strict_types=1);
// Por defecto seguro para producción: NO mostrar errores al cliente.
// Se reactiva más abajo solo cuando el entorno es de depuración.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
// El log de errores vive FUERA del docroot público (public/): no es accesible por web.
ini_set('error_log', dirname(__DIR__) . '/storage/logs/php_errors.log');

use Mypos\Config\Database;
use Mypos\Controllers\AnulacionController;
use Mypos\Controllers\AgentController;
use Mypos\Controllers\ConfiguracionSiiController;
use Mypos\Controllers\AuditoriaController;
use Mypos\Controllers\AuthController;
use Mypos\Controllers\CajaController;
use Mypos\Controllers\CatalogoMaestroController;
use Mypos\Controllers\CentroCostoController;
use Mypos\Controllers\CierreDiarioController;
use Mypos\Controllers\CompraController;
use Mypos\Controllers\CompraInteligenteController;
use Mypos\Controllers\OrdenCompraController;
use Mypos\Controllers\CotizacionController;
use Mypos\Controllers\FarmaciaController;
use Mypos\Controllers\PerfilNegocioController;
use Mypos\Controllers\ReporteCapacidadController;
use Mypos\Controllers\VarianteController;
use Mypos\Controllers\ComunicacionVentasController;
use Mypos\Controllers\ConfiguracionController;
use Mypos\Controllers\ClienteController;
use Mypos\Controllers\CorreoController;
use Mypos\Controllers\CreditoController;
use Mypos\Controllers\DocumentoIaController;
use Mypos\Controllers\EmpresaController;
use Mypos\Controllers\EmpleadoController;
use Mypos\Controllers\DocumentoTributarioController;
use Mypos\Controllers\DispositivoController;
use Mypos\Controllers\DteController;
use Mypos\Controllers\EgresoController;
use Mypos\Controllers\FolioController;
use Mypos\Controllers\IaController;
use Mypos\Controllers\ImportacionCatalogoController;
use Mypos\Controllers\ProductoImportController;
use Mypos\Controllers\F29Controller;
use Mypos\Controllers\InventarioFisicoController;
use Mypos\Controllers\LibroController;
use Mypos\Controllers\OnboardingController;
use Mypos\Controllers\PermissionController;
use Mypos\Controllers\PublicController;
use Mypos\Controllers\ProductoAtributoController;
use Mypos\Controllers\ProductoController;
use Mypos\Controllers\ProveedorController;
use Mypos\Controllers\AgenteAlertasConfigController;
use Mypos\Controllers\AgenteConsultaAdhocController;
use Mypos\Controllers\AgenteConsultaFlexibleController;
use Mypos\Controllers\AgenteConsultasLogController;
use Mypos\Controllers\AgenteExportController;
use Mypos\Controllers\AgentePerfilController;
use Mypos\Controllers\ReporteController;
use Mypos\Controllers\RrhhController;
use Mypos\Controllers\RubroController;
use Mypos\Controllers\StockController;
use Mypos\Controllers\SyncController;
use Mypos\Controllers\UploadController;
use Mypos\Controllers\VentaController;
use Mypos\Controllers\ValeCreditoController;
use Mypos\Controllers\DevolucionController;
use Mypos\Controllers\SuscripcionController;
use Mypos\Controllers\MercadoPagoController;
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
use Mypos\Services\AuditoriaService;
use Mypos\Services\PermissionService;
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
Env::loadFile(dirname($envDir) . '/.env');
Env::loadFile($envDir . '/.env');

// Mostrar errores en pantalla SOLO en entornos de depuración (jamás en producción).
$appEnv = (string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production');
$appDebug = filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
if ($appDebug && $appEnv !== 'production') {
    ini_set('display_errors', '1');
}

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

function protectedAnyRoute(callable $handler, array $permissions): callable
{
    return static function (array $params = []) use ($handler, $permissions): void {
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

        $permissionService = new PermissionService();
        foreach ($permissions as $permission) {
            if ($permissionService->userHasPermission($userId, $empresaId, (string) $permission)) {
                if ($params === []) {
                    $handler();
                    return;
                }

                $handler($params);
                return;
            }
        }

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'seguridad',
            'accion' => 'permiso_denegado',
            'entidad' => 'permisos',
            'descripcion' => 'Acceso denegado por permiso insuficiente',
            'metadata' => [
                'permisos_requeridos' => $permissions,
                'ruta' => $_SERVER['REQUEST_URI'] ?? null,
                'metodo' => $_SERVER['REQUEST_METHOD'] ?? null,
            ],
            'severidad' => 'WARNING',
            'resultado' => 'ERROR',
        ]);

        throw new HttpException('No autorizado para realizar esta accion', 403);
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

// NOTA DE SEGURIDAD: Se eliminaron los endpoints de diagnóstico
// /health/config, /api/health/config y /api/health/auth. Exponían sin
// autenticación información sensible (host/usuario de BD, longitud del
// JWT_SECRET y si la cuenta admin seguía usando la contraseña por defecto).
// Para diagnósticos use herramientas internas autenticadas, nunca un
// endpoint público. Los health checks de liveness viven arriba (/health,
// /api/health, /health/db).

$authController = new AuthController();
$router->post('/api/v1/auth/register', [$authController, 'register']);
$router->post('/api/v1/auth/login', [$authController, 'login']);
$router->get('/api/v1/auth/me', [$authController, 'me']);
$router->post('/api/v1/auth/logout', [$authController, 'logout']);
$router->post('/api/v1/auth/verify-email', [$authController, 'verifyEmail']);
$router->post('/api/v1/auth/resend-verification', [$authController, 'resendVerificationEmail']);

// Verificación pública de boletas (sin autenticación) — mypos.cl/boleta
$publicController = new PublicController();
$router->get('/api/v1/public/boleta', [$publicController, 'boleta']);
$router->get('/api/v1/public/boleta/pdf', [$publicController, 'boletaPdf']);

$onboardingController = new OnboardingController();
$router->post('/api/v1/onboarding/simulate-payment', [$onboardingController, 'simulatePayment']);

// Webhook de MercadoPago (público, sin autenticación — la seguridad es la firma
// x-signature + la consulta de la order con el token propio de la empresa).
$mercadoPagoController = new MercadoPagoController();
$router->post('/api/v1/public/webhooks/mercadopago', [$mercadoPagoController, 'webhook']);

$crmController = new \Mypos\Controllers\CrmController();
$router->get('/api/v1/crm/leads',                          [$crmController, 'index']);
$router->get('/api/v1/crm/count',                          [$crmController, 'count']);
$router->get('/api/v1/crm/stats',                          [$crmController, 'stats']);
$router->get('/api/v1/crm/leads/{id}/mensajes',            [$crmController, 'messages']);
$router->get('/api/v1/crm/leads/{id}/cliente',             [$crmController, 'clienteInfo']);
$router->get('/api/v1/crm/leads/{id}/tareas',              [$crmController, 'tareas']);
$router->put('/api/v1/crm/leads/{id}',                     [$crmController, 'update']);
$router->post('/api/v1/crm/leads/{id}/reply',              [$crmController, 'reply']);
$router->post('/api/v1/crm/leads/{id}/convertir',          [$crmController, 'convertir']);
$router->post('/api/v1/crm/leads/{id}/tareas',             [$crmController, 'crearTarea']);
$router->put('/api/v1/crm/tareas/{tarea_id}/completar',    [$crmController, 'completarTarea']);
$router->get('/api/v1/crm/agentes',                        [$crmController, 'agentes']);
$router->get('/api/v1/crm/etapas',                         [$crmController, 'etapas']);
$router->post('/api/v1/crm/etapas',                        [$crmController, 'saveEtapa']);
$router->put('/api/v1/crm/etapas/{etapa_id}',              [$crmController, 'saveEtapa']);
$router->get('/api/v1/crm/templates',                      [$crmController, 'templates']);
$router->post('/api/v1/crm/templates/sync',                [$crmController, 'syncTemplates']);
$router->post('/api/v1/crm/templates',                     [$crmController, 'saveTemplate']);
$router->put('/api/v1/crm/templates/{id}',                 [$crmController, 'saveTemplate']);
$router->post('/api/v1/crm/leads/{id}/template',           [$crmController, 'sendTemplate']);
$router->get('/api/v1/crm/broadcast',                      [$crmController, 'broadcasts']);
$router->post('/api/v1/crm/broadcast',                     [$crmController, 'createBroadcast']);
$router->post('/api/v1/crm/conversations/iniciar',         [$crmController, 'iniciarConversacion']);

$whatsappController = new \Mypos\Controllers\WhatsappController();
$router->post('/api/v1/whatsapp/token', [$whatsappController, 'generateToken']);
$router->get('/api/v1/whatsapp/status', [$whatsappController, 'status']);

$agentController = new AgentController();
$router->post('/api/v1/agent/chat', [$agentController, 'chat']);

$suscripcionController = new SuscripcionController();
$comunicacionVentasController = new ComunicacionVentasController();
$router->post('/api/v1/suscripciones/order', [$suscripcionController, 'createOrder']);
$router->post('/api/v1/suscripciones/flow-webhook', [$suscripcionController, 'flowWebhook']);
$router->get('/api/v1/suscripciones/flow-return', [$suscripcionController, 'flowReturn']);
$router->get('/api/v1/suscripciones/paypal-return', [$suscripcionController, 'paypalReturn']);
$router->get('/api/v1/suscripciones/status', [$suscripcionController, 'status']);
$router->get('/api/v1/suscripciones/order-status', [$suscripcionController, 'orderStatus']);
$router->get('/api/v1/suscripciones/payment-config', [$suscripcionController, 'paymentConfig']);

// Links de precio especial (promo). resolve es público; el resto es del dueño de plataforma.
$promoLinkController = new \Mypos\Controllers\PromoLinkController();
$router->get('/api/v1/promos/resolve',        [$promoLinkController, 'resolve']);
$router->get('/api/v1/promos',                [$promoLinkController, 'index']);
$router->post('/api/v1/promos',               [$promoLinkController, 'store']);
$router->put('/api/v1/promos/{id}/estado',    [$promoLinkController, 'toggle']);

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

$empleadoController = new EmpleadoController();
$router->get('/api/v1/empleados', protectedRoute([$empleadoController, 'index'], 'empleados.ver'));
$router->post('/api/v1/empleados', protectedRoute([$empleadoController, 'store'], 'empleados.crear'));
$router->put('/api/v1/empleados/{id}', protectedRoute([$empleadoController, 'update'], 'empleados.editar'));
$router->get('/api/v1/empleados/buscar', protectedRoute([$empleadoController, 'search'], 'ventas.crear'));

$rrhhController = new RrhhController();
$router->get('/api/v1/rrhh/descuentos-credito', protectedRoute([$rrhhController, 'descuentosCredito'], 'rrhh.descuentos.ver'));

$proveedorController = new ProveedorController();
$router->get('/api/v1/proveedores', protectedRoute([$proveedorController, 'index'], 'proveedores.ver'));
$router->post('/api/v1/proveedores', protectedRoute([$proveedorController, 'store'], 'proveedores.crear'));
$router->get('/api/v1/proveedores/{id}/productos', protectedRoute([$proveedorController, 'providerProducts'], 'proveedores.ver'));
$router->get('/api/v1/proveedores/{id}/precios', protectedRoute([$proveedorController, 'providerPrices'], 'proveedores.ver'));
$router->post('/api/v1/proveedores/{id}/listas-precios/importaciones', protectedRoute([$proveedorController, 'storePriceListImport'], 'proveedores.editar'));
$router->get('/api/v1/proveedores/{id}/listas-precios/importaciones/{importacion_id}', protectedRoute([$proveedorController, 'showPriceListImport'], 'proveedores.ver'));
$router->post('/api/v1/proveedores/{id}/listas-precios/importaciones/{importacion_id}/validar', protectedRoute([$proveedorController, 'validatePriceListImport'], 'proveedores.editar'));
$router->post('/api/v1/proveedores/{id}/listas-precios/importaciones/{importacion_id}/aplicar', protectedRoute([$proveedorController, 'applyPriceListImport'], 'proveedores.editar'));
$router->get('/api/v1/proveedores/{id}', protectedRoute([$proveedorController, 'show'], 'proveedores.ver'));
$router->put('/api/v1/proveedores/{id}', protectedRoute([$proveedorController, 'update'], 'proveedores.editar'));
$router->delete('/api/v1/proveedores/{id}', protectedRoute([$proveedorController, 'destroy'], 'proveedores.eliminar'));

$creditoController = new CreditoController();
$router->get('/api/v1/creditos/clientes', protectedRoute([$creditoController, 'index'], 'creditos.ver'));
$router->get('/api/v1/creditos/clientes/{id}', protectedRoute([$creditoController, 'show'], 'creditos.ver'));
$router->post('/api/v1/creditos/clientes/{id}/pagos', protectedRoute([$creditoController, 'pay'], 'creditos.pagar'));
$router->post('/api/v1/creditos/abonos', protectedRoute([$creditoController, 'abonoLibre'], 'creditos.pagar'));
$router->get('/api/v1/creditos/antiguedad', protectedRoute([$creditoController, 'antiguedad'], 'creditos.ver'));

$devolucionController = new DevolucionController();
$router->get('/api/v1/devoluciones', protectedRoute([$devolucionController, 'index'], 'devoluciones.ver'));
$router->post('/api/v1/devoluciones', protectedRoute([$devolucionController, 'store'], 'devoluciones.crear'));
$router->get('/api/v1/devoluciones/venta/{venta_id}', protectedRoute([$devolucionController, 'resumenVenta'], 'devoluciones.ver'));
$router->get('/api/v1/devoluciones/{id}', protectedRoute([$devolucionController, 'show'], 'devoluciones.ver'));

$valeCreditoController = new ValeCreditoController();
$router->get('/api/v1/vales', protectedRoute([$valeCreditoController, 'index'], 'vales.ver'));
$router->post('/api/v1/vales', protectedRoute([$valeCreditoController, 'store'], 'vales.emitir'));
$router->get('/api/v1/vales/codigo/{codigo}', protectedRoute([$valeCreditoController, 'porCodigo'], 'vales.ver'));
$router->get('/api/v1/vales/{id}', protectedRoute([$valeCreditoController, 'show'], 'vales.ver'));
$router->post('/api/v1/vales/{id}/anular', protectedRoute([$valeCreditoController, 'anular'], 'vales.anular'));

// MercadoPago Point: configuración (credenciales + terminales) y cobro en terminal.
// $mercadoPagoController ya fue instanciado junto al webhook público (arriba).
$router->get('/api/v1/mercadopago/config', protectedRoute([$mercadoPagoController, 'getConfig'], 'mercadopago.ver'));
$router->put('/api/v1/mercadopago/config', protectedRoute([$mercadoPagoController, 'putConfig'], 'mercadopago.configurar'));
$router->get('/api/v1/mercadopago/terminales', protectedRoute([$mercadoPagoController, 'indexTerminales'], 'mercadopago.ver'));
$router->post('/api/v1/mercadopago/terminales', protectedRoute([$mercadoPagoController, 'storeTerminal'], 'mercadopago.configurar'));
$router->put('/api/v1/mercadopago/terminales/{id}', protectedRoute([$mercadoPagoController, 'updateTerminal'], 'mercadopago.configurar'));
$router->put('/api/v1/mercadopago/terminales/{id}/estado', protectedRoute([$mercadoPagoController, 'estadoTerminal'], 'mercadopago.configurar'));
$router->post('/api/v1/mercadopago/cobros', protectedRoute([$mercadoPagoController, 'iniciarCobro'], 'mercadopago.cobrar'));
$router->get('/api/v1/mercadopago/cobros/{id}', protectedRoute([$mercadoPagoController, 'estadoCobro'], 'mercadopago.cobrar'));

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

$correoController = new CorreoController();
$router->get('/api/v1/correo/configuracion', protectedAnyRoute([$correoController, 'configuracion'], ['correo.ver', 'configuracion.ver']));
$router->put('/api/v1/correo/configuracion', protectedAnyRoute([$correoController, 'guardarConfiguracion'], ['correo.configurar', 'configuracion.editar']));
$router->post('/api/v1/correo/probar', protectedAnyRoute([$correoController, 'probar'], ['correo.configurar', 'configuracion.editar']));
$router->get('/api/v1/correo/inbox', protectedAnyRoute([$correoController, 'inbox'], ['correo.ver', 'configuracion.ver']));
$router->get('/api/v1/correo/bandeja', protectedAnyRoute([$correoController, 'bandeja'], ['correo.ver', 'configuracion.ver']));
$router->post('/api/v1/correo/sincronizar', protectedAnyRoute([$correoController, 'sincronizar'], ['correo.ver', 'configuracion.ver']));
$router->get('/api/v1/correo/mensajes/{uid}', protectedAnyRoute([$correoController, 'mensaje'], ['correo.ver', 'configuracion.ver']));
$router->get('/api/v1/correo/mensajes-bd/{id}', protectedAnyRoute([$correoController, 'mensajeBd'], ['correo.ver', 'configuracion.ver']));
$router->delete('/api/v1/correo/mensajes-bd/{id}', protectedAnyRoute([$correoController, 'eliminar'], ['correo.enviar', 'configuracion.editar']));
$router->post('/api/v1/correo/reenviar', protectedAnyRoute([$correoController, 'reenviar'], ['correo.enviar', 'configuracion.editar']));
$router->get('/api/v1/correo/hilos', protectedAnyRoute([$correoController, 'hilos'], ['correo.ver', 'configuracion.ver']));
$router->get('/api/v1/correo/hilos/{id}', protectedAnyRoute([$correoController, 'hilo'], ['correo.ver', 'configuracion.ver']));
$router->post('/api/v1/correo/hilos/reconstruir', protectedAnyRoute([$correoController, 'reconstruirHilos'], ['correo.ver', 'configuracion.ver']));
$router->post('/api/v1/correo/hilos/{id}/estado', protectedAnyRoute([$correoController, 'estadoHilo'], ['correo.enviar', 'configuracion.editar']));
$router->get('/api/v1/correo/hilos/{id}/resumen', protectedAnyRoute([$correoController, 'resumenHilo'], ['correo.ver', 'configuracion.ver']));
$router->post('/api/v1/correo/buscar-ia', protectedAnyRoute([$correoController, 'buscarIa'], ['correo.ver', 'configuracion.ver']));
$router->get('/api/v1/correo/contactos', protectedAnyRoute([$correoController, 'contactos'], ['correo.ver', 'configuracion.ver']));
$router->post('/api/v1/correo/contactos/reconstruir', protectedAnyRoute([$correoController, 'reconstruirContactos'], ['correo.ver', 'configuracion.ver']));
$router->post('/api/v1/correo/enviar', protectedAnyRoute([$correoController, 'enviar'], ['correo.enviar', 'configuracion.editar']));

$uploadController = new UploadController();
$router->post('/api/v1/uploads/productos', protectedRoute([$uploadController, 'producto'], 'uploads.crear'));
$router->post('/api/v1/uploads/documentos-ia', protectedRoute([$uploadController, 'documentoIa'], 'uploads.crear'));
$router->post('/api/v1/uploads/logos', protectedRoute([$uploadController, 'logo'], 'configuracion.editar'));
$router->post('/api/v1/uploads/certificado-sii', protectedRoute([$uploadController, 'certificadoSii'], 'configuracion.editar'));
$router->get('/api/v1/uploads/certificado-sii/vigencia', protectedRoute([$uploadController, 'vigenciaCertificadoSii'], 'configuracion.ver'));
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
$productoAtributoController = new ProductoAtributoController();
$importacionCatalogoController = new ImportacionCatalogoController();
$productoImportController = new ProductoImportController();
$catalogoMaestroController = new CatalogoMaestroController();
$router->get('/api/v1/catalogos-maestros/buscar', protectedRoute([$catalogoMaestroController, 'search'], 'productos.ver'));
$router->get('/api/v1/catalogos-maestros/buscar-codigo', protectedRoute([$catalogoMaestroController, 'barcode'], 'productos.ver'));
$router->get('/api/v1/catalogos-maestros/metricas', protectedRoute([$catalogoMaestroController, 'metrics'], 'productos.ver'));
$router->get('/api/v1/catalogos-maestros/productos/{id}/vinculo', protectedRoute([$catalogoMaestroController, 'link'], 'productos.ver'));
$router->post('/api/v1/catalogos-maestros/productos/{id}/incorporar', protectedRoute([$catalogoMaestroController, 'incorporate'], 'productos.crear'));
$router->get('/api/v1/importaciones/catalogo', protectedRoute([$importacionCatalogoController, 'index'], 'productos.ver'));
$router->post('/api/v1/importaciones/catalogo', protectedRoute([$importacionCatalogoController, 'store'], 'productos.editar'));
$router->get('/api/v1/importaciones/catalogo/{id}', protectedRoute([$importacionCatalogoController, 'show'], 'productos.ver'));
$router->post('/api/v1/importaciones/catalogo/{id}/validar', protectedRoute([$importacionCatalogoController, 'validate'], 'productos.editar'));
$router->post('/api/v1/importaciones/catalogo/{id}/aplicar', protectedRoute([$importacionCatalogoController, 'apply'], 'productos.editar'));
$router->post('/api/v1/importaciones/maestro', protectedRoute([$productoImportController, 'store'], 'productos.editar'));
$router->get('/api/v1/importaciones/maestro/{id}', protectedRoute([$productoImportController, 'show'], 'productos.ver'));
$router->post('/api/v1/importaciones/maestro/{id}/aplicar', protectedRoute([$productoImportController, 'apply'], 'productos.editar'));
$router->get('/api/v1/productos/buscar', protectedRoute([$productoController, 'search'], 'productos.ver'));
$router->get('/api/v1/productos', protectedRoute([$productoController, 'index'], 'productos.ver'));
$router->post('/api/v1/productos', protectedRoute([$productoController, 'store'], 'productos.crear'));
$router->get('/api/v1/productos/atributos', protectedRoute([$productoAtributoController, 'index'], 'productos.ver'));
$router->post('/api/v1/productos/atributos', protectedRoute([$productoAtributoController, 'store'], 'productos.editar'));
$router->put('/api/v1/productos/atributos/{id}', protectedRoute([$productoAtributoController, 'update'], 'productos.editar'));
$router->delete('/api/v1/productos/atributos/{id}', protectedRoute([$productoAtributoController, 'destroy'], 'productos.editar'));
$router->get('/api/v1/productos/{producto_id}/proveedores', protectedRoute([$proveedorController, 'productProviders'], 'productos.ver'));
$router->post('/api/v1/productos/{producto_id}/proveedores', protectedRoute([$proveedorController, 'attachProduct'], 'proveedores.editar'));
$router->put('/api/v1/productos/{producto_id}/proveedores/{relacion_id}', protectedRoute([$proveedorController, 'updateProductProvider'], 'proveedores.editar'));
$router->delete('/api/v1/productos/{producto_id}/proveedores/{relacion_id}', protectedRoute([$proveedorController, 'deleteProductProvider'], 'proveedores.editar'));
$router->get('/api/v1/productos/{producto_id}/precios-proveedor', protectedRoute([$proveedorController, 'productPrices'], 'productos.ver'));
$router->post('/api/v1/productos/{producto_id}/precios-proveedor', protectedRoute([$proveedorController, 'storeProductPrice'], 'proveedores.editar'));
$router->get('/api/v1/productos/{id}', protectedRoute([$productoController, 'show'], 'productos.ver'));
$router->put('/api/v1/productos/{id}', protectedRoute([$productoController, 'update'], 'productos.editar'));
$router->delete('/api/v1/productos/{id}', protectedRoute([$productoController, 'destroy'], 'productos.eliminar'));
$router->post('/api/v1/productos/{id}/activar', protectedRoute([$productoController, 'activate'], 'productos.editar'));
$router->delete('/api/v1/productos/{id}/hard', protectedRoute([$productoController, 'hardDestroy'], 'productos.eliminar'));
$router->get('/api/v1/productos/{id}/atributos', protectedRoute([$productoAtributoController, 'productValues'], 'productos.ver'));
$router->put('/api/v1/productos/{id}/atributos', protectedRoute([$productoAtributoController, 'updateProductValues'], 'productos.editar'));
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
$router->get('/api/v1/stock/ubicaciones', protectedRoute([$stockController, 'ubicaciones'], 'stock.ver'));
$router->post('/api/v1/stock/ubicaciones', protectedRoute([$stockController, 'crearUbicacion'], 'stock.ubicaciones.administrar'));
$router->put('/api/v1/stock/ubicaciones/{id}', protectedRoute([$stockController, 'actualizarUbicacion'], 'stock.ubicaciones.administrar'));
$router->delete('/api/v1/stock/ubicaciones/{id}', protectedRoute([$stockController, 'desactivarUbicacion'], 'stock.ubicaciones.administrar'));
$router->delete('/api/v1/stock/ubicaciones/{id}/eliminar', protectedRoute([$stockController, 'eliminarUbicacion'], 'stock.ubicaciones.administrar'));
$router->get('/api/v1/stock/ubicaciones/{ubicacion_id}/productos', protectedRoute([$stockController, 'porUbicacion'], 'stock.ver'));
$router->post('/api/v1/stock/traslados', protectedRoute([$stockController, 'traslado'], 'stock.ajustar'));
$router->get('/api/v1/stock', protectedRoute([$stockController, 'index'], 'stock.ver'));
$router->get('/api/v1/stock/producto/{producto_id}', protectedRoute([$stockController, 'showProduct'], 'stock.ver'));
$router->post('/api/v1/stock/ajustes', protectedRoute([$stockController, 'ajuste'], 'stock.ajustar'));
$router->post('/api/v1/stock/merma', protectedRoute([$stockController, 'merma'], 'stock.ajustar'));
$router->get('/api/v1/stock/movimientos', protectedRoute([$stockController, 'movimientos'], 'stock.movimientos.ver'));
$router->get('/api/v1/stock/integridad', protectedRoute([$stockController, 'integridad'], 'stock.movimientos.ver'));
$router->get('/api/v1/stock/alertas', protectedRoute([$stockController, 'alertas'], 'stock.ver'));

$inventarioFisicoController = new InventarioFisicoController();
$router->get('/api/v1/inventario-fisico', protectedRoute([$inventarioFisicoController, 'index'], 'stock.ver'));
$router->post('/api/v1/inventario-fisico', protectedRoute([$inventarioFisicoController, 'create'], 'stock.ajustar'));
$router->get('/api/v1/inventario-fisico/{id}', protectedRoute([$inventarioFisicoController, 'show'], 'stock.ver'));
$router->put('/api/v1/inventario-fisico/{id}/conteos', protectedRoute([$inventarioFisicoController, 'saveConteos'], 'stock.ajustar'));
$router->post('/api/v1/inventario-fisico/{id}/aplicar', protectedRoute([$inventarioFisicoController, 'apply'], 'stock.ajustar'));

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

$egresoController = new EgresoController();
$router->get('/api/v1/egresos', protectedRoute([$egresoController, 'index'], 'cajas.ver'));
$router->post('/api/v1/egresos', protectedRoute([$egresoController, 'store'], 'cajas.movimientos'));
$router->post('/api/v1/egresos/{id}/anular', protectedRoute([$egresoController, 'cancel'], 'cajas.cerrar'));

$ventaController = new VentaController();
$router->post('/api/v1/ventas', protectedRoute([$ventaController, 'store'], 'ventas.crear'));

$anulacionController = new AnulacionController();
$router->post('/api/v1/ventas/{id}/anular', protectedRoute([$anulacionController, 'cancelSale'], 'ventas.anular'));
$router->post('/api/v1/compras/{id}/reversar', protectedRoute([$anulacionController, 'reversePurchase'], 'compras.reversar'));
$router->get('/api/v1/anulaciones', protectedRoute([$anulacionController, 'index'], 'ventas.ver'));
$router->get('/api/v1/anulaciones/{id}', protectedRoute([$anulacionController, 'show'], 'ventas.ver'));

$cierreDiarioController = new CierreDiarioController();
$router->get('/api/v1/cierres-diarios', protectedRoute([$cierreDiarioController, 'index'], 'reportes.ver'));
$router->get('/api/v1/cierres-diarios/pendientes', protectedRoute([$cierreDiarioController, 'pending'], 'cierres.crear'));
$router->post('/api/v1/cierres-diarios', protectedRoute([$cierreDiarioController, 'store'], 'cierres.crear'));
$router->post('/api/v1/cierres-diarios/{id}/reabrir', protectedRoute([$cierreDiarioController, 'reopen'], 'cierres.reabrir'));
$router->get('/api/v1/cierres-diarios/{id}', protectedRoute([$cierreDiarioController, 'show'], 'reportes.ver'));

$compraController = new CompraController();
$router->get('/api/v1/compras', protectedRoute([$compraController, 'index'], 'compras.ver'));
$router->post('/api/v1/compras', protectedRoute([$compraController, 'store'], 'compras.crear'));
$router->get('/api/v1/compras/{id}', protectedRoute([$compraController, 'show'], 'compras.ver'));
$router->post('/api/v1/compras/{id}/confirmar', protectedRoute([$compraController, 'confirm'], 'compras.confirmar'));
$router->post('/api/v1/compras/{id}/actualizar-precios', protectedRoute([$compraController, 'actualizarPrecios'], 'compras.confirmar'));
$router->post('/api/v1/compras/{id}/anular', protectedRoute([$compraController, 'cancel'], 'compras.anular'));
$router->delete('/api/v1/compras/{id}', protectedRoute([$compraController, 'destroy'], 'compras.anular'));

$ordenCompraController = new OrdenCompraController();
// sugerencias ANTES de /{id} para evitar conflicto de rutas
$router->get('/api/v1/ordenes-compra/sugerencias', protectedRoute([$ordenCompraController, 'sugerencias'], 'compras.ver'));
$router->get('/api/v1/ordenes-compra',              protectedRoute([$ordenCompraController, 'index'],       'compras.ver'));
$router->post('/api/v1/ordenes-compra',             protectedRoute([$ordenCompraController, 'store'],       'compras.crear'));
$router->get('/api/v1/ordenes-compra/{id}',         protectedRoute([$ordenCompraController, 'show'],        'compras.ver'));
$router->post('/api/v1/ordenes-compra/{id}/enviar', protectedRoute([$ordenCompraController, 'enviar'],      'compras.crear'));
$router->post('/api/v1/ordenes-compra/{id}/recibir',protectedRoute([$ordenCompraController, 'recibir'],     'compras.confirmar'));
$router->post('/api/v1/ordenes-compra/{id}/cerrar', protectedRoute([$ordenCompraController, 'cerrar'],      'compras.crear'));
$router->post('/api/v1/ordenes-compra/{id}/cancelar',protectedRoute([$ordenCompraController,'cancelar'],    'compras.anular'));

$cotizacionController = new CotizacionController();
$router->get('/api/v1/cotizaciones',                      protectedRoute([$cotizacionController, 'index'],     'cotizaciones.ver'));
$router->post('/api/v1/cotizaciones',                     protectedRoute([$cotizacionController, 'store'],     'cotizaciones.crear'));
$router->get('/api/v1/cotizaciones/{id}',                 protectedRoute([$cotizacionController, 'show'],      'cotizaciones.ver'));
$router->get('/api/v1/cotizaciones/{id}/pdf',             protectedRoute([$cotizacionController, 'pdf'],       'cotizaciones.ver'));
$router->post('/api/v1/cotizaciones/{id}/enviar',         protectedRoute([$cotizacionController, 'enviar'],    'cotizaciones.crear'));
$router->post('/api/v1/cotizaciones/{id}/aprobar',        protectedRoute([$cotizacionController, 'aprobar'],   'cotizaciones.aprobar'));
$router->post('/api/v1/cotizaciones/{id}/rechazar',       protectedRoute([$cotizacionController, 'rechazar'],  'cotizaciones.aprobar'));
$router->post('/api/v1/cotizaciones/{id}/convertir',      protectedRoute([$cotizacionController, 'convertir'], 'cotizaciones.convertir'));
$router->post('/api/v1/cotizaciones/{id}/duplicar',       protectedRoute([$cotizacionController, 'duplicar'],  'cotizaciones.crear'));

$compraInteligenteController = new CompraInteligenteController();
$router->get('/api/v1/compras-inteligentes/sugerencias', protectedRoute([$compraInteligenteController, 'sugerencias'], 'compras_inteligentes.ver'));
$router->post('/api/v1/compras-inteligentes/borrador', protectedRoute([$compraInteligenteController, 'generarBorrador'], 'compras_inteligentes.crear'));

$farmaciaController = new FarmaciaController();
$router->get('/api/v1/farmacia/atributos', protectedRoute([$farmaciaController, 'atributos'], 'farmacia.ver'));
$router->get('/api/v1/farmacia/buscar', protectedRoute([$farmaciaController, 'buscar'], 'farmacia.ver'));
$router->get('/api/v1/farmacia/productos-con-receta', protectedRoute([$farmaciaController, 'productosConReceta'], 'farmacia.ver'));
$router->get('/api/v1/farmacia/productos/{id}/ficha', protectedRoute([$farmaciaController, 'ficha'], 'farmacia.ver'));

$perfilNegocioController = new PerfilNegocioController();
$router->get('/api/v1/perfil-negocio/perfiles', protectedRoute([$perfilNegocioController, 'perfiles'], 'configuracion.ver'));
$router->get('/api/v1/perfil-negocio/capacidades', protectedRoute([$perfilNegocioController, 'capacidades'], 'configuracion.ver'));
$router->post('/api/v1/perfil-negocio/activar', protectedRoute([$perfilNegocioController, 'activar'], 'configuracion.editar'));
$router->post('/api/v1/perfil-negocio/toggle-capacidad', protectedRoute([$perfilNegocioController, 'toggleCapacidad'], 'configuracion.editar'));

$varianteController = new VarianteController();
$router->get('/api/v1/productos/{id}/variantes', protectedRoute([$varianteController, 'listar'], 'productos.ver'));
$router->get('/api/v1/productos/{id}/ejes-variante', protectedRoute([$varianteController, 'ejes'], 'productos.ver'));
$router->post('/api/v1/productos/{id}/variantes', protectedRoute([$varianteController, 'generar'], 'productos.editar'));

$loteController = new \Mypos\Controllers\LoteController();
$router->post('/api/v1/lotes', protectedRoute([$loteController, 'registrar'], 'stock.editar'));
$router->get('/api/v1/lotes/stock', protectedRoute([$loteController, 'stock'], 'stock.ver'));
$router->get('/api/v1/lotes/alertas', protectedRoute([$loteController, 'alertas'], 'stock.ver'));
$router->get('/api/v1/lotes/fefo', protectedRoute([$loteController, 'fefo'], 'stock.ver'));

$reporteCapacidadController = new ReporteCapacidadController();
$router->get('/api/v1/reportes/merma', protectedRoute([$reporteCapacidadController, 'merma'], 'reportes.ver'));
$router->get('/api/v1/reportes/lotes/vencimientos', protectedRoute([$reporteCapacidadController, 'lotesPorVencer'], 'reportes.ver'));
$router->get('/api/v1/reportes/lotes/vencidos', protectedRoute([$reporteCapacidadController, 'lotesVencidos'], 'reportes.ver'));
$router->get('/api/v1/reportes/variantes/stock', protectedRoute([$reporteCapacidadController, 'stockVariantes'], 'reportes.ver'));
$router->get('/api/v1/reportes/variantes/ventas', protectedRoute([$reporteCapacidadController, 'ventasVariantes'], 'reportes.ver'));

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
$router->delete('/api/v1/documentos-ia/{id}', protectedRoute([$documentoIaController, 'destroy'], 'documentos_ia.editar'));

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
$router->post('/api/v1/folios/caf/upload', protectedRoute([$folioController, 'uploadCaf'], 'folios.caf.crear'));
$router->get('/api/v1/folios/caf', protectedRoute([$folioController, 'listCafs'], 'folios.ver'));
$router->post('/api/v1/folios/asignaciones', protectedRoute([$folioController, 'storeAssignment'], 'folios.asignar'));
$router->get('/api/v1/folios/asignaciones', protectedRoute([$folioController, 'listAssignments'], 'folios.ver'));
$router->get('/api/v1/folios/disponibles', protectedRoute([$folioController, 'availability'], 'folios.ver'));
$router->post('/api/v1/folios/consumir', protectedRoute([$folioController, 'consume'], 'folios.consumir'));
$router->get('/api/v1/folios/consumidos', protectedRoute([$folioController, 'consumed'], 'folios.ver'));
$router->get('/api/v1/folios/alertas', protectedRoute([$folioController, 'alerts'], 'folios.alertas.ver'));

$configuracionSiiController = new ConfiguracionSiiController();
$router->get('/api/v1/configuracion-sii/estado', protectedRoute([$configuracionSiiController, 'estado'], 'configuracion.ver'));
$router->post('/api/v1/configuracion-sii/solicitar-certificacion', protectedRoute([$configuracionSiiController, 'solicitarCertificacion'], 'configuracion.editar'));

$dteController = new DteController();
$router->get('/api/v1/dte/configuracion', protectedRoute([$dteController, 'config'], 'dte.configuracion.ver'));
$router->put('/api/v1/dte/configuracion', protectedRoute([$dteController, 'updateConfig'], 'dte.configuracion.editar'));
$router->get('/api/v1/dte/emisiones', protectedRoute([$dteController, 'emissions'], 'dte.ver'));
$router->get('/api/v1/dte/emisiones/{id}', protectedRoute([$dteController, 'emissionDetail'], 'dte.ver'));
$router->post('/api/v1/dte/emisiones/{id}/reintentar', protectedRoute([$dteController, 'retry'], 'dte.reintentar'));
$router->post('/api/v1/dte/emisiones/{id}/marcar-aceptado', protectedRoute([$dteController, 'markAccepted'], 'dte.emitir'));
$router->post('/api/v1/dte/emisiones/{id}/marcar-rechazado', protectedRoute([$dteController, 'markRejected'], 'dte.emitir'));
$router->get('/api/v1/dte/emisiones/{id}/pdf', protectedRoute([$dteController, 'downloadPdf'], 'dte.ver'));
$router->post('/api/v1/dte/provisionar-credenciales', protectedRoute([$dteController, 'provisionarCredenciales'], 'dte.configuracion.editar'));

$f29Controller = new F29Controller();
$router->get('/api/v1/f29/calcular', protectedRoute([$f29Controller, 'calcular'], 'libros.resumen_iva.ver'));
$router->get('/api/v1/f29/historial', protectedRoute([$f29Controller, 'historial'], 'libros.resumen_iva.ver'));
$router->post('/api/v1/f29', protectedRoute([$f29Controller, 'guardar'], 'libros.resumen_iva.ver'));
$router->post('/api/v1/f29/{periodo}/declarar', protectedRoute([$f29Controller, 'declarar'], 'libros.resumen_iva.ver'));

$libroController = new LibroController();
$router->get('/api/v1/libros/ventas', protectedRoute([$libroController, 'ventas'], 'libros.ventas.ver'));
$router->get('/api/v1/libros/compras', protectedRoute([$libroController, 'compras'], 'libros.compras.ver'));
$router->get('/api/v1/libros/resumen-iva', protectedRoute([$libroController, 'resumenIva'], 'libros.resumen_iva.ver'));
$router->get('/api/v1/libros/ventas/resumen-tipo-documento', protectedRoute([$libroController, 'ventasResumenTipoDocumento'], 'libros.ventas.ver'));
$router->get('/api/v1/libros/compras/resumen-proveedor', protectedRoute([$libroController, 'comprasResumenProveedor'], 'libros.compras.ver'));

$reporteController = new ReporteController();
$router->get('/api/v1/reportes/salud-financiera', protectedRoute([$reporteController, 'saludFinanciera'], 'reportes.ver'));
$router->get('/api/v1/reportes/resumen-ventas', protectedRoute([$reporteController, 'resumenVentas'], 'reportes.ver'));
$router->get('/api/v1/reportes/ventas-por-dia', protectedRoute([$reporteController, 'ventasPorDia'], 'reportes.ver'));
$router->get('/api/v1/reportes/ventas-por-metodo-pago', protectedRoute([$reporteController, 'ventasPorMetodoPago'], 'reportes.ver'));
$router->get('/api/v1/reportes/ventas-por-producto', protectedRoute([$reporteController, 'ventasPorProducto'], 'reportes.ver'));
$router->get('/api/v1/reportes/ventas-por-rubro', protectedRoute([$reporteController, 'ventasPorRubro'], 'reportes.ver'));
$router->get('/api/v1/reportes/ventas-por-usuario', protectedRoute([$reporteController, 'ventasPorUsuario'], 'reportes.ver'));
$router->get('/api/v1/reportes/dashboard', protectedRoute([$reporteController, 'dashboard'], 'dashboard.ver'));

$agenteConsultaFlexibleController = new AgenteConsultaFlexibleController();
$router->post('/api/v1/agente/consulta-flexible', protectedRoute([$agenteConsultaFlexibleController, 'ejecutar'], 'reportes.ver'));

// Capa 2.5: consultas SQL dinamicas generadas en linea por el LLM (mismo
// validador y cadena de tenant que consulta-flexible; flag por empresa +
// cuota diaria dentro del controller).
$agenteConsultaAdhocController = new AgenteConsultaAdhocController();
$router->post('/api/v1/agente/consulta-adhoc', protectedRoute([$agenteConsultaAdhocController, 'ejecutar'], 'reportes.ver'));

// Bandeja de consultas no resueltas del agente IA (auth+tenant inline en el
// controller, sin SubscriptionMiddleware: el log de aprendizaje no debe
// perderse por suscripcion vencida).
$agenteConsultasLogController = new AgenteConsultasLogController();
$router->post('/api/v1/agente/consultas-log', [$agenteConsultasLogController, 'registrar']);

// Exportaciones a Excel del agente IA (registry fijo de tipos, solo lectura;
// el archivo se envía SOLO al correo registrado de la empresa).
$agenteExportController = new AgenteExportController();
$router->post('/api/v1/agente/exportar', protectedRoute([$agenteExportController, 'exportar'], 'reportes.ver'));

// Perfil compacto de empresa para el prompt del agente IA (auth+tenant
// inline, sin SubscriptionMiddleware: el agente debe poder informar una
// suscripcion vencida).
$agentePerfilController = new AgentePerfilController();
$router->get('/api/v1/agente/perfil-empresa', [$agentePerfilController, 'ver']);

// Preferencias de alertas proactivas del agente (Configuración → Alertas).
$agenteAlertasConfigController = new AgenteAlertasConfigController();
$router->get('/api/v1/agente/alertas-config', protectedRoute([$agenteAlertasConfigController, 'ver'], 'configuracion.ver'));
$router->put('/api/v1/agente/alertas-config', protectedRoute([$agenteAlertasConfigController, 'guardar'], 'configuracion.editar'));

$router->post('/api/v1/comunicaciones-ventas', [$comunicacionVentasController, 'store']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
