<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\LibroService;
use Throwable;

final class LibroController
{
    private LibroService $service;

    public function __construct()
    {
        $this->service = new LibroService();
    }

    public function ventas(): void
    {
        $this->respond(fn (): array => $this->service->ventas($_GET));
    }

    public function compras(): void
    {
        $this->respond(fn (): array => $this->service->compras($_GET));
    }

    public function resumenIva(): void
    {
        $this->respond(fn (): array => $this->service->resumenIva($_GET));
    }

    public function ventasResumenTipoDocumento(): void
    {
        $this->respond(fn (): array => $this->service->ventasResumenTipoDocumento($_GET));
    }

    public function comprasResumenProveedor(): void
    {
        $this->respond(fn (): array => $this->service->comprasResumenProveedor($_GET));
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
