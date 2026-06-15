<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\CierreDiarioService;
use Throwable;

final class CierreDiarioController
{
    private CierreDiarioService $service;

    public function __construct()
    {
        $this->service = new CierreDiarioService();
    }

    public function store(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->generar($userId, Request::json());
        }, 'Cierre diario generado correctamente');
    }

    public function index(): void
    {
        $this->respond(function (): array {
            return $this->service->listar(
                (int) ($_GET['empresa_id'] ?? 0),
                (int) ($_GET['sucursal_id'] ?? 0),
                $_GET['fecha_desde'] ?? null,
                $_GET['fecha_hasta'] ?? null
            );
        });
    }

    public function show(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->detalle((int) $params['id'], (int) ($_GET['empresa_id'] ?? 0));
        });
    }

    public function pending(): void
    {
        $this->respond(function (): array {
            $sucursalId = isset($_GET['sucursal_id']) && $_GET['sucursal_id'] !== '' ? (int) $_GET['sucursal_id'] : null;
            return $this->service->pendientes((int) ($_GET['empresa_id'] ?? 0), $sucursalId);
        });
    }

    public function reopen(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            $payload = Request::json();
            $motivo = isset($payload['motivo']) ? trim((string) $payload['motivo']) : null;

            return $this->service->reabrir(
                $userId,
                (int) $params['id'],
                $this->requestEmpresaId(),
                $motivo !== null && $motivo !== '' ? $motivo : null
            );
        }, 'Cierre reabierto correctamente');
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
