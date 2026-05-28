<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\PermissionMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\PermissionService;
use Throwable;

final class PermissionController
{
    private PermissionService $service;

    public function __construct()
    {
        $this->service = new PermissionService();
    }

    public function myPermissions(): void
    {
        $this->respond(
            fn (int $userId, int $empresaId): array => $this->service->misPermisos($userId, $empresaId)
        );
    }

    public function permissions(): void
    {
        $this->respond(
            fn (): array => $this->service->listarPermisos(),
            'permisos.ver'
        );
    }

    public function roles(): void
    {
        $this->respond(
            fn (): array => $this->service->listarRoles(),
            'roles.ver'
        );
    }

    public function showRole(array $params): void
    {
        $this->respond(
            fn (int $userId, int $empresaId): array => $this->service->obtenerRol((int) $params['id']),
            'roles.ver'
        );
    }

    public function storeRole(): void
    {
        $this->respond(
            fn (int $userId, int $empresaId): array => $this->service->crearRol(Request::json()),
            'roles.gestionar'
        );
    }

    public function updateRole(array $params): void
    {
        $this->respond(
            fn (int $userId, int $empresaId): array => $this->service->actualizarRol((int) $params['id'], Request::json()),
            'roles.gestionar'
        );
    }

    public function destroyRole(array $params): void
    {
        $this->respond(
            function (int $userId, int $empresaId) use ($params): array {
                $this->service->eliminarRol((int) $params['id']);
                return ['success' => true];
            },
            'roles.gestionar'
        );
    }

    public function rolePermissionsList(array $params): void
    {
        $this->respond(
            fn (int $userId, int $empresaId): array => $this->service->obtenerPermisosRol((int) $params['id']),
            'roles.ver'
        );
    }

    public function updateRolePermissions(array $params): void
    {
        $this->respond(
            function (int $userId, int $empresaId) use ($params): array {
                $payload = Request::json();
                $permissionIds = is_array($payload['permission_ids'] ?? null) ? $payload['permission_ids'] : [];
                return $this->service->actualizarPermisosRol((int) $params['id'], $permissionIds, $userId, $empresaId);
            },
            'roles.gestionar'
        );
    }

    private function respond(callable $callback, ?string $permission = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);

            if ($empresaId <= 0) {
                throw new HttpException('empresa_id obligatorio', 422);
            }

            (new TenantMiddleware())->handle($userId, $empresaId);

            if ($permission !== null) {
                (new PermissionMiddleware())->handle($userId, $empresaId, $permission);
            }

            Response::success($callback($userId, $empresaId));
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}

