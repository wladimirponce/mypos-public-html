<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\VentaService;
use Throwable;

final class VentaController
{
    private VentaService $service;

    public function __construct()
    {
        $this->service = new VentaService();
    }

    public function store(): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $payload = Request::json();
            $empresaId = (int) ($payload['empresa_id'] ?? 0);

            if ($empresaId <= 0) {
                throw new HttpException('Error de validación', 422, ['empresa_id' => ['La empresa_id es obligatoria']]);
            }

            (new TenantMiddleware())->handle($userId, $empresaId);

            Response::success(
                $this->service->registrarVenta($userId, $payload),
                'Venta registrada correctamente'
            );
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
