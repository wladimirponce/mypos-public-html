<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Services\ProductoService;
use Throwable;

final class CentroCostoController
{
    private ProductoService $service;

    public function __construct()
    {
        $this->service = new ProductoService();
    }

    public function index(): void
    {
        $this->respond(fn (int $userId): array => $this->service->centros($userId, $this->queryEmpresaId()));
    }

    public function store(): void
    {
        $this->respond(fn (int $userId): array => $this->service->createCentro($userId, Request::json()), 'Centro de costo creado');
    }

    public function update(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->updateCentro($userId, (int) $params['id'], Request::json()), 'Centro de costo actualizado');
    }

    public function destroy(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->deleteCentro($userId, (int) $params['id'], $this->queryEmpresaId()), 'Centro de costo desactivado');
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            Response::success($callback((int) $claims['user_id']), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function queryEmpresaId(): int
    {
        return (int) ($_GET['empresa_id'] ?? 0);
    }
}
