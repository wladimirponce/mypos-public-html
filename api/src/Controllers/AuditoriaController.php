<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\PermissionMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\AuditoriaService;
use Throwable;

final class AuditoriaController
{
    private AuditoriaService $service;

    public function __construct()
    {
        $this->service = new AuditoriaService();
    }

    public function index(): void
    {
        $this->respond(fn (): array => $this->service->listar($_GET));
    }

    public function show(array $params): void
    {
        $this->respond(fn (): array => $this->service->detalle((int) $params['id'], (int) ($_GET['empresa_id'] ?? 0)));
    }

    private function respond(callable $callback): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);
            if ($empresaId <= 0) {
                throw new HttpException('empresa_id obligatorio', ['empresa_id' => ['Debe seleccionar una empresa.']], 422);
            }

            (new TenantMiddleware())->handle($userId, $empresaId);
            (new PermissionMiddleware())->handle($userId, $empresaId, 'auditoria.ver');

            Response::success($callback());
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
