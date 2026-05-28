<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\PermissionMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\DteIntegrationService;
use Throwable;

final class DteController
{
    private DteIntegrationService $service;

    public function __construct()
    {
        $this->service = new DteIntegrationService();
    }

    public function config(): void
    {
        $this->respond(fn (int $empresaId): array => $this->service->configuracion($empresaId), 'dte.configuracion.ver');
    }

    public function updateConfig(): void
    {
        $this->respond(fn (int $empresaId, int $userId): array => $this->service->actualizarConfiguracion($userId, Request::json()), 'dte.configuracion.editar');
    }

    public function emissions(): void
    {
        $this->respond(fn (): array => $this->service->listarEmisiones($_GET), 'dte.ver');
    }

    public function emissionDetail(array $params): void
    {
        $this->respond(fn (int $empresaId): array => $this->service->detalleEmision((int) $params['id'], $empresaId), 'dte.ver');
    }

    public function retry(array $params): void
    {
        $this->respond(fn (int $empresaId, int $userId): array => $this->service->reintentar($userId, (int) $params['id'], Request::json()), 'dte.reintentar');
    }

    public function markAccepted(array $params): void
    {
        $this->respond(fn (int $empresaId, int $userId): array => $this->service->marcarAceptado($userId, (int) $params['id'], Request::json()), 'dte.emitir');
    }

    public function markRejected(array $params): void
    {
        $this->respond(fn (int $empresaId, int $userId): array => $this->service->marcarRechazado($userId, (int) $params['id'], Request::json()), 'dte.emitir');
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
