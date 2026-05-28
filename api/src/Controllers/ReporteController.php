<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\ReporteService;
use Throwable;

final class ReporteController
{
    private ReporteService $service;

    public function __construct()
    {
        $this->service = new ReporteService();
    }

    public function resumenVentas(): void
    {
        $this->respond(fn (): array => $this->service->resumenVentas($_GET));
    }

    public function ventasPorDia(): void
    {
        $this->respond(fn (): array => $this->service->ventasPorDia($_GET));
    }

    public function ventasPorMetodoPago(): void
    {
        $this->respond(fn (): array => $this->service->ventasPorMetodoPago($_GET));
    }

    public function ventasPorProducto(): void
    {
        $this->respond(fn (): array => $this->service->ventasPorProducto($_GET));
    }

    public function ventasPorRubro(): void
    {
        $this->respond(fn (): array => $this->service->ventasPorRubro($_GET));
    }

    public function ventasPorUsuario(): void
    {
        $this->respond(fn (): array => $this->service->ventasPorUsuario($_GET));
    }

    public function dashboard(): void
    {
        $this->respond(fn (): array => $this->service->dashboard($_GET));
    }

    private function respond(callable $callback): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);

            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }


            Response::success($callback());
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
