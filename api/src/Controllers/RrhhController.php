<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\RrhhService;
use Throwable;

final class RrhhController
{
    private RrhhService $service;

    public function __construct()
    {
        $this->service = new RrhhService();
    }

    public function descuentosCredito(): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);
            
            $mes = (int) ($_GET['mes'] ?? (int) date('m'));
            $anio = (int) ($_GET['anio'] ?? (int) date('Y'));

            if ($empresaId <= 0) {
                throw new HttpException('Empresa ID requerido', 422);
            }

            (new TenantMiddleware())->handle($userId, $empresaId);

            Response::success($this->service->getDescuentosMensuales($empresaId, $mes, $anio));
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
