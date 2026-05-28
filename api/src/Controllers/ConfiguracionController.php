<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\PermissionMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\ConfiguracionService;
use Throwable;

final class ConfiguracionController
{
    private ConfiguracionService $service;

    public function __construct()
    {
        $this->service = new ConfiguracionService();
    }

    public function empresa(): void
    {
        $this->respond(fn (int $empresaId): array => $this->service->empresa($empresaId), 'configuracion.ver');
    }

    public function updateEmpresa(): void
    {
        $this->respond(fn (int $empresaId, int $userId): array => $this->service->actualizarEmpresa($userId, Request::json()), 'configuracion.editar');
    }

    public function operacion(): void
    {
        $this->respond(fn (int $empresaId): array => $this->service->operacion($empresaId), 'configuracion.ver');
    }

    public function updateOperacion(): void
    {
        $this->respond(fn (int $empresaId, int $userId): array => $this->service->actualizarOperacion($userId, Request::json()), 'configuracion.editar');
    }

    public function sucursal(array $params): void
    {
        $this->respond(fn (int $empresaId): array => $this->service->sucursal($empresaId, (int) $params['sucursal_id']), 'configuracion.ver');
    }

    public function updateSucursal(array $params): void
    {
        $this->respond(fn (int $empresaId, int $userId): array => $this->service->actualizarSucursal($userId, (int) $params['sucursal_id'], Request::json()), 'configuracion.editar');
    }

    public function efectiva(): void
    {
        $this->respond(function (int $empresaId): array {
            $sucursalId = isset($_GET['sucursal_id']) && $_GET['sucursal_id'] !== '' ? (int) $_GET['sucursal_id'] : null;

            return $this->service->efectiva($empresaId, $sucursalId);
        }, 'configuracion.ver');
    }

    private function respond(callable $callback, string $permission): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = $this->requestEmpresaId();
            if ($empresaId <= 0) {
                throw new HttpException('empresa_id obligatorio', 422);
            }
            (new TenantMiddleware())->handle($userId, $empresaId);
            (new PermissionMiddleware())->handle($userId, $empresaId, $permission);
            Response::success($callback($empresaId, $userId));
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function requestEmpresaId(): int
    {
        if (isset($_GET['empresa_id'])) {
            return (int) $_GET['empresa_id'];
        }

        $payload = Request::json();

        return (int) ($payload['empresa_id'] ?? 0);
    }
}

