<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\FolioService;
use Throwable;

final class FolioController
{
    private FolioService $service;

    public function __construct()
    {
        $this->service = new FolioService();
    }

    public function storeCaf(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->registrarCaf($userId, Request::json());
        }, 'CAF registrado correctamente');
    }

    public function listCafs(): void
    {
        $this->respond(fn (): array => $this->service->listarCafs($_GET));
    }

    public function storeAssignment(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->crearAsignacion($userId, Request::json());
        }, 'Rango de folios asignado correctamente');
    }

    public function listAssignments(): void
    {
        $this->respond(fn (): array => $this->service->listarAsignaciones($_GET));
    }

    public function availability(): void
    {
        $this->respond(fn (): array => $this->service->disponibilidad($_GET));
    }

    public function consume(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->consumir($userId, Request::json());
        }, 'Folio consumido correctamente');
    }

    public function consumed(): void
    {
        $this->respond(fn (): array => $this->service->listarConsumidos($_GET));
    }

    public function alerts(): void
    {
        $this->respond(fn (): array => $this->service->alertas($_GET));
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
