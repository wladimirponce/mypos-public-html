<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\CompraService;
use Throwable;

final class CompraController
{
    private CompraService $service;

    public function __construct()
    {
        $this->service = new CompraService();
    }

    public function store(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->crear($userId, Request::json());
        }, 'Compra registrada correctamente');
    }

    public function index(): void
    {
        $this->respond(function (): array {
            return $this->service->listar((int) ($_GET['empresa_id'] ?? 0), $_GET);
        });
    }

    public function show(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->detalle((int) ($_GET['empresa_id'] ?? 0), (int) $params['id']);
        });
    }

    public function confirm(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            $payload = Request::json();

            return $this->service->confirmar($userId, (int) $params['id'], (int) ($payload['empresa_id'] ?? 0));
        }, 'Compra confirmada correctamente');
    }

    public function cancel(array $params): void
    {
        $this->respond(function () use ($params): array {
            $payload = Request::json();

            return $this->service->anular(
                (int) $params['id'],
                (int) ($payload['empresa_id'] ?? 0),
                $payload['motivo'] ?? null
            );
        }, 'Compra anulada correctamente');
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
