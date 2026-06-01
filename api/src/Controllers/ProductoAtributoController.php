<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Services\ProductoAtributoService;
use Throwable;

final class ProductoAtributoController
{
    private ProductoAtributoService $service;

    public function __construct()
    {
        $this->service = new ProductoAtributoService();
    }

    public function index(): void
    {
        $this->respond(fn (int $userId): array => $this->service->listDefinitions(
            $userId,
            $this->queryEmpresaId(),
            (int) ($_GET['include_inactive'] ?? 0) === 1
        ));
    }

    public function store(): void
    {
        $this->respond(fn (int $userId): array => $this->service->createDefinition($userId, Request::json()), 'Atributo creado');
    }

    public function update(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->updateDefinition($userId, (int) $params['id'], Request::json()), 'Atributo actualizado');
    }

    public function destroy(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->deleteDefinition($userId, (int) $params['id'], $this->queryEmpresaId()), 'Atributo desactivado');
    }

    public function productValues(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->valuesForProduct($userId, (int) $params['id'], $this->queryEmpresaId()));
    }

    public function updateProductValues(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->updateValuesForProduct($userId, (int) $params['id'], Request::json()), 'Atributos del producto actualizados');
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
