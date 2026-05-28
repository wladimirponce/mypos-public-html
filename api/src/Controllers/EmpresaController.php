<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\PermissionMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\EmpresaService;
use Throwable;

final class EmpresaController
{
    private EmpresaService $service;

    public function __construct()
    {
        $this->service = new EmpresaService();
    }

    public function index(): void
    {
        $this->respond(
            fn (): array => $this->service->listarEmpresas(),
            'configuracion.ver',
            false
        );
    }

    public function show(array $params): void
    {
        $this->respond(
            fn (): array => $this->service->obtenerEmpresa((int) $params['id']),
            'configuracion.ver',
            false
        );
    }

    public function store(): void
    {
        $this->respond(
            fn (): array => $this->service->crearEmpresa(Request::json()),
            'configuracion.editar',
            false
        );
    }

    public function update(array $params): void
    {
        $this->respond(
            fn (): array => $this->service->actualizarEmpresa((int) $params['id'], Request::json()),
            'configuracion.editar',
            false
        );
    }

    public function destroy(array $params): void
    {
        $this->respond(
            function (int $empresaId) use ($params): array {
                $this->service->desactivarEmpresa((int) $params['id'], $empresaId);
                return ['success' => true];
            },
            'configuracion.editar'
        );
    }

    public function sucursales(array $params): void
    {
        $this->respond(
            fn (): array => $this->service->listarSucursales((int) $params['id']),
            'configuracion.ver',
            false
        );
    }

    public function storeSucursal(array $params): void
    {
        $this->respond(
            fn (): array => $this->service->crearSucursal((int) $params['id'], Request::json()),
            'configuracion.editar',
            false
        );
    }

    public function updateSucursal(array $params): void
    {
        $this->respond(
            fn (): array => $this->service->actualizarSucursal((int) $params['id'], Request::json()),
            'configuracion.editar',
            false
        );
    }

    public function destroySucursal(array $params): void
    {
        $this->respond(
            function () use ($params): array {
                $this->service->desactivarSucursal((int) $params['id']);
                return ['success' => true];
            },
            'configuracion.editar',
            false
        );
    }

    public function cajas(array $params): void
    {
        $this->respond(
            function () use ($params): array {
                $sucursalId = isset($_GET['sucursal_id']) && $_GET['sucursal_id'] !== '' ? (int) $_GET['sucursal_id'] : null;
                return $this->service->listarCajas((int) $params['id'], $sucursalId);
            },
            'cajas.ver',
            false
        );
    }

    public function storeCaja(array $params): void
    {
        $this->respond(
            fn (int $empresaId): array => $this->service->crearCaja($empresaId, Request::json() + ['sucursal_id' => (int) $params['id']]),
            'cajas.crear'
        );
    }

    public function updateCaja(array $params): void
    {
        $this->respond(
            fn (): array => $this->service->actualizarCaja((int) $params['id'], Request::json()),
            'cajas.crear',
            false
        );
    }

    public function destroyCaja(array $params): void
    {
        $this->respond(
            function () use ($params): array {
                $this->service->desactivarCaja((int) $params['id']);
                return ['success' => true];
            },
            'cajas.crear',
            false
        );
    }

    public function usuarios(array $params): void
    {
        $this->respond(
            fn (): array => $this->service->listarUsuarios((int) $params['id']),
            'usuarios.ver',
            false
        );
    }

    public function buscarUsuariosGlobales(): void
    {
        $this->respond(
            fn (): array => $this->service->buscarUsuariosGlobales($_GET['q'] ?? ''),
            'usuarios.ver',
            false
        );
    }

    public function asociarUsuario(array $params): void
    {
        $this->respond(
            fn (): array => $this->service->asociarUsuario((int) $params['id'], Request::json()),
            'configuracion.editar',
            false
        );
    }

    public function actualizarUsuarioEmpresa(array $params): void
    {
        $this->respond(
            fn (): array => $this->service->actualizarUsuario((int) $params['id'], (int) $params['usuario_id'], Request::json()),
            'configuracion.editar',
            false
        );
    }

    public function removerUsuarioEmpresa(array $params): void
    {
        $this->respond(
            function (int $empresaId, int $userId) use ($params): array {
                $this->service->removerUsuario((int) $params['id'], (int) $params['usuario_id'], $userId);
                return ['success' => true];
            },
            'configuracion.editar',
            false
        );
    }

    private function respond(callable $callback, string $permission, bool $empresaIdRequired = true): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = $this->requestEmpresaId();

            if ($empresaIdRequired && $empresaId <= 0) {
                throw new HttpException('empresa_id obligatorio', 422);
            }

            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
                (new PermissionMiddleware())->handle($userId, $empresaId, $permission);
            }

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
        if (isset($_GET['empresa_id']) && $_GET['empresa_id'] !== '') {
            return (int) $_GET['empresa_id'];
        }

        $payload = Request::json();

        return (int) ($payload['empresa_id'] ?? 0);
    }
}
