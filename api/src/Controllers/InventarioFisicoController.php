<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Services\InventarioFisicoService;
use Throwable;

final class InventarioFisicoController
{
    private InventarioFisicoService $service;

    public function __construct()
    {
        $this->service = new InventarioFisicoService();
    }

    /** GET /api/v1/inventario-fisico?empresa_id=X */
    public function index(): void
    {
        $this->respond(fn (int $uid): array => $this->service->listar($uid, $_GET));
    }

    /** POST /api/v1/inventario-fisico */
    public function create(): void
    {
        $this->respond(fn (int $uid): array => $this->service->crear($uid, Request::json()), 'Inventario creado');
    }

    /** GET /api/v1/inventario-fisico/{id}?empresa_id=X */
    public function show(array $params): void
    {
        $this->respond(fn (int $uid): array => $this->service->get($uid, (int) $params['id'], $_GET));
    }

    /** PUT /api/v1/inventario-fisico/{id}/conteos */
    public function saveConteos(array $params): void
    {
        $body = array_merge(Request::json(), ['id' => (int) $params['id']]);
        $this->respond(fn (int $uid): array => $this->service->guardarConteos($uid, (int) $params['id'], $body), 'Conteos guardados');
    }

    /** POST /api/v1/inventario-fisico/{id}/aplicar?empresa_id=X */
    public function apply(array $params): void
    {
        $p = array_merge($_GET, ['id' => (int) $params['id']]);
        $this->respond(fn (int $uid): array => $this->service->aplicar($uid, (int) $params['id'], $p), 'Ajustes aplicados');
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            Response::success($callback((int) $claims['user_id']), $message);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
