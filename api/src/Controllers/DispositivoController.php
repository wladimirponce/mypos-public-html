<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\DispositivoService;
use Throwable;

final class DispositivoController
{
    private DispositivoService $service;

    public function __construct()
    {
        $this->service = new DispositivoService();
    }

    public function register(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->registrar($userId, Request::json());
        }, 'Dispositivo registrado correctamente');
    }

    public function index(): void
    {
        $this->respond(fn (): array => $this->service->listar($_GET));
    }

    public function show(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->detalle((int) $params['id'], (int) ($_GET['empresa_id'] ?? 0));
        });
    }

    public function update(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->actualizar($userId, (int) $params['id'], Request::json());
        });
    }

    public function block(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->cambiarEstado($userId, (int) $params['id'], Request::json(), 'BLOQUEADO', 'dispositivo.bloquear');
        });
    }

    public function revoke(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->cambiarEstado($userId, (int) $params['id'], Request::json(), 'REVOCADO', 'dispositivo.revocar');
        });
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = $this->requestEmpresaId();

            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }

            Response::success($callback($userId), $message);
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
