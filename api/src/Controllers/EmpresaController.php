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
        // Cada usuario solo puede ver las empresas a las que pertenece.
        $this->authenticated(
            fn (int $userId): array => $this->service->listarEmpresasDeUsuario($userId)
        );
    }

    public function show(array $params): void
    {
        $empresaId = (int) $params['id'];
        $this->guard(
            'configuracion.ver',
            static fn (): int => $empresaId,
            fn (): array => $this->service->obtenerEmpresa($empresaId)
        );
    }

    public function store(): void
    {
        // Crear una empresa es una acción de nivel superior (alta de tenant):
        // requiere sesión válida pero no aplica a una empresa existente.
        $this->authenticated(
            fn (): array => $this->service->crearEmpresa(Request::json())
        );
    }

    public function update(array $params): void
    {
        $empresaId = (int) $params['id'];
        $this->guard(
            'configuracion.editar',
            static fn (): int => $empresaId,
            fn (): array => $this->service->actualizarEmpresa($empresaId, Request::json())
        );
    }

    public function destroy(array $params): void
    {
        $empresaId = (int) $params['id'];
        $this->guard(
            'configuracion.editar',
            static fn (): int => $empresaId,
            function () use ($empresaId): array {
                // La empresa "activa" del usuario (contexto) se usa solo para la
                // validación de UX "no elimines la empresa en la que operas".
                $this->service->desactivarEmpresa($empresaId, $this->requestEmpresaId());
                return ['success' => true];
            }
        );
    }

    public function sucursales(array $params): void
    {
        // Cualquier usuario autenticado que pertenezca a la empresa puede
        // consultar sus sucursales — no se requiere configuracion.ver.
        $empresaId = (int) $params['id'];
        $this->tenanted($empresaId, fn (): array => $this->service->listarSucursales($empresaId));
    }

    public function storeSucursal(array $params): void
    {
        $empresaId = (int) $params['id'];
        $this->guard(
            'configuracion.editar',
            static fn (): int => $empresaId,
            fn (): array => $this->service->crearSucursal($empresaId, Request::json())
        );
    }

    public function updateSucursal(array $params): void
    {
        $sucursalId = (int) $params['id'];
        $this->guard(
            'configuracion.editar',
            fn (): int => (int) $this->service->obtenerSucursal($sucursalId)['empresa_id'],
            fn (): array => $this->service->actualizarSucursal($sucursalId, Request::json())
        );
    }

    public function destroySucursal(array $params): void
    {
        $sucursalId = (int) $params['id'];
        $this->guard(
            'configuracion.editar',
            fn (): int => (int) $this->service->obtenerSucursal($sucursalId)['empresa_id'],
            function () use ($sucursalId): array {
                $this->service->desactivarSucursal($sucursalId);
                return ['success' => true];
            }
        );
    }

    public function cajas(array $params): void
    {
        $empresaId = (int) $params['id'];
        $this->guard(
            'cajas.ver',
            static fn (): int => $empresaId,
            function () use ($empresaId): array {
                $sucursalId = isset($_GET['sucursal_id']) && $_GET['sucursal_id'] !== '' ? (int) $_GET['sucursal_id'] : null;
                return $this->service->listarCajas($empresaId, $sucursalId);
            }
        );
    }

    public function storeCaja(array $params): void
    {
        $sucursalId = (int) $params['id'];
        $this->guard(
            'cajas.crear',
            fn (): int => (int) $this->service->obtenerSucursal($sucursalId)['empresa_id'],
            fn (int $empresaId): array => $this->service->crearCaja($empresaId, Request::json() + ['sucursal_id' => $sucursalId])
        );
    }

    public function updateCaja(array $params): void
    {
        $cajaId = (int) $params['id'];
        $this->guard(
            'cajas.crear',
            fn (): int => (int) $this->service->obtenerCaja($cajaId)['empresa_id'],
            fn (): array => $this->service->actualizarCaja($cajaId, Request::json())
        );
    }

    public function destroyCaja(array $params): void
    {
        $cajaId = (int) $params['id'];
        $this->guard(
            'cajas.crear',
            fn (): int => (int) $this->service->obtenerCaja($cajaId)['empresa_id'],
            function () use ($cajaId): array {
                $this->service->desactivarCaja($cajaId);
                return ['success' => true];
            }
        );
    }

    public function usuarios(array $params): void
    {
        $empresaId = (int) $params['id'];
        $this->guard(
            'usuarios.ver',
            static fn (): int => $empresaId,
            fn (): array => $this->service->listarUsuarios($empresaId)
        );
    }

    public function buscarUsuariosGlobales(): void
    {
        // Búsqueda global de usuarios (para asociarlos a una empresa). Exige una
        // empresa de contexto válida y permiso usuarios.ver sobre ella.
        $this->guard(
            'usuarios.ver',
            fn (): int => $this->requestEmpresaId(),
            fn (): array => $this->service->buscarUsuariosGlobales($_GET['q'] ?? '')
        );
    }

    public function asociarUsuario(array $params): void
    {
        $empresaId = (int) $params['id'];
        $this->guard(
            'configuracion.editar',
            static fn (): int => $empresaId,
            fn (): array => $this->service->asociarUsuario($empresaId, Request::json())
        );
    }

    public function actualizarUsuarioEmpresa(array $params): void
    {
        $empresaId = (int) $params['id'];
        $usuarioId = (int) $params['usuario_id'];
        $this->guard(
            'configuracion.editar',
            static fn (): int => $empresaId,
            fn (): array => $this->service->actualizarUsuario($empresaId, $usuarioId, Request::json())
        );
    }

    public function removerUsuarioEmpresa(array $params): void
    {
        $empresaId = (int) $params['id'];
        $usuarioId = (int) $params['usuario_id'];
        $this->guard(
            'configuracion.editar',
            static fn (): int => $empresaId,
            function (int $resolvedEmpresaId, int $userId) use ($empresaId, $usuarioId): array {
                $this->service->removerUsuario($empresaId, $usuarioId, $userId);
                return ['success' => true];
            }
        );
    }

    /**
     * Ejecuta una acción que requiere autenticación, contexto de empresa y permiso.
     *
     * El identificador de la empresa se resuelve SIEMPRE a partir del recurso
     * (después de autenticar) mediante $resolveEmpresaId, y la pertenencia al
     * tenant y el permiso se verifican siempre — sin excepciones ni bypass.
     *
     * @param callable():int                              $resolveEmpresaId
     * @param callable(int $empresaId, int $userId):mixed $callback
     */
    private function guard(string $permission, callable $resolveEmpresaId, callable $callback): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];

            $empresaId = (int) $resolveEmpresaId();
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

    /**
     * Ejecuta una acción que requiere autenticación y pertenencia al tenant,
     * pero sin exigir un permiso específico (adecuado para datos de contexto
     * como la lista de sucursales propias).
     */
    private function tenanted(int $empresaId, callable $callback): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];

            if ($empresaId <= 0) {
                throw new HttpException('empresa_id obligatorio', 422);
            }

            (new TenantMiddleware())->handle($userId, $empresaId);

            Response::success($callback());
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    /**
     * Ejecuta una acción de nivel superior que solo requiere sesión válida
     * (no aplica a una empresa existente: listar las propias, crear una nueva).
     *
     * @param callable(int $userId):mixed $callback
     */
    private function authenticated(callable $callback): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];

            Response::success($callback($userId));
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
