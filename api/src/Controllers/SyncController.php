<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\SyncService;
use Throwable;

final class SyncController
{
    private SyncService $service;

    public function __construct()
    {
        $this->service = new SyncService();
    }

    public function status(): void
    {
        $this->respond(fn (): array => $this->service->estado($_GET));
    }

    public function events(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->procesarEventos($userId, Request::json());
        }, 'Eventos sincronizados');
    }

    public function listEvents(): void
    {
        $this->respond(fn (): array => $this->service->listarEventos($_GET));
    }

    public function conflicts(): void
    {
        $this->respond(fn (): array => $this->service->listarConflictos($_GET));
    }

    public function resolveConflict(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->resolverConflicto($userId, (int) $params['id'], Request::json());
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
