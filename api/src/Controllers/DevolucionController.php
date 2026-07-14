<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\DevolucionService;
use Throwable;

final class DevolucionController
{
    private DevolucionService $service;

    public function __construct()
    {
        $this->service = new DevolucionService();
    }

    public function index(): void
    {
        $this->respond(function (): array {
            $filters = array_filter([
                'venta_id' => $_GET['venta_id'] ?? null,
                'fecha_desde' => $_GET['fecha_desde'] ?? null,
                'fecha_hasta' => $_GET['fecha_hasta'] ?? null,
            ]);

            return $this->service->listar($this->getEmpresaId(), $filters);
        });
    }

    public function store(): void
    {
        $this->respond(fn (int $userId): array => $this->service->registrar($userId, Request::json()), 'Devolucion registrada');
    }

    public function show(array $params): void
    {
        $this->respond(fn (): array => $this->service->detalle($this->getEmpresaId(), (int) $params['id']));
    }

    /** GET /api/v1/devoluciones/venta/{venta_id} — detalle de venta con disponibles para devolver. */
    public function resumenVenta(array $params): void
    {
        $this->respond(fn (): array => $this->service->resumenVenta($this->getEmpresaId(), (int) $params['venta_id']));
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = $this->getEmpresaId();
            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }
            Response::success($callback($userId), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('[Devolucion] ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function getEmpresaId(): int
    {
        if (isset($_GET['empresa_id'])) {
            return (int) $_GET['empresa_id'];
        }
        $payload = Request::json();

        return (int) ($payload['empresa_id'] ?? 0);
    }
}
