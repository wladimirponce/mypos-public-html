<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\AgenteConsultasLogService;
use Throwable;

/**
 * Endpoint interno llamado por el agente IA (cuenta de servicio) para
 * registrar consultas que no pudo responder, revisables luego en el modulo
 * "Consultas IA" de admin.
 *
 * Auth+Tenant inline (sin SubscriptionMiddleware ni PermissionMiddleware a
 * proposito): el registro de aprendizaje no debe perderse porque la empresa
 * tenga la suscripcion vencida, y la cuenta de servicio no necesita un
 * permiso funcional para escribir su propio log.
 */
final class AgenteConsultasLogController
{
    private AgenteConsultasLogService $service;

    public function __construct()
    {
        $this->service = new AgenteConsultasLogService();
    }

    public function registrar(): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);
            $sucursalId = isset($_GET['sucursal_id']) ? (int) $_GET['sucursal_id'] : null;

            if ($empresaId <= 0) {
                throw new HttpException('empresa_id obligatorio', 422);
            }

            (new TenantMiddleware())->handle($userId, $empresaId, $sucursalId);

            $payload = Request::json();
            if (trim((string) ($payload['consulta'] ?? '')) === '') {
                throw new HttpException('consulta obligatoria', 422);
            }

            $resultado = $this->service->registrar($empresaId, $sucursalId, $payload);

            Response::success($resultado);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('AgenteConsultasLogController: ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
