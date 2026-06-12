<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\EgresoService;
use Throwable;

final class EgresoController
{
    private EgresoService $service;
    public function __construct() { $this->service = new EgresoService(); }
    public function index(): void { $this->respond(fn() => $this->service->list((int) ($_GET['empresa_id'] ?? 0), $_GET)); }
    public function store(): void { $this->respond(fn(int $userId) => $this->service->create($userId, Request::json()), 'Egreso registrado correctamente'); }
    public function cancel(array $params): void { $this->respond(fn(int $userId) => $this->service->cancel($userId, (int) $params['id'], Request::json()), 'Egreso anulado correctamente'); }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : (int) (Request::json()['empresa_id'] ?? 0);
            if ($empresaId > 0) (new TenantMiddleware())->handle($userId, $empresaId);
            Response::success($callback($userId), $message);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
