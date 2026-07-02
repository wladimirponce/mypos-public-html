<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\Agente\PerfilEmpresaService;
use Throwable;

/**
 * GET /api/v1/agente/perfil-empresa — perfil compacto para el prompt del
 * agente IA. Auth+tenant inline y SIN SubscriptionMiddleware a proposito:
 * si la suscripcion esta vencida, el agente igual debe poder saberlo para
 * decirselo al operador (el estado viene en el propio perfil).
 */
final class AgentePerfilController
{
    private PerfilEmpresaService $service;

    public function __construct()
    {
        $this->service = new PerfilEmpresaService();
    }

    public function ver(): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);

            if ($empresaId <= 0) {
                throw new HttpException('empresa_id obligatorio', 422);
            }

            (new TenantMiddleware())->handle($userId, $empresaId);

            Response::success($this->service->perfil($empresaId));
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('AgentePerfilController: ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
