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

    // ─── Barrido con lector ───

    /** POST /api/v1/inventario-fisico/barrido */
    public function abrirBarrido(): void
    {
        $this->respond(fn (int $uid): array => $this->service->abrirSesionBarrido($uid, Request::json()), 'Sesión de barrido lista');
    }

    /**
     * GET /api/v1/inventario-fisico/barrido/{id}?empresa_id=X
     *
     * @param array<string, string> $params
     */
    public function getBarrido(array $params): void
    {
        $this->respond(fn (int $uid): array => $this->service->getBarrido($uid, (int) $params['id'], $_GET));
    }

    /**
     * POST /api/v1/inventario-fisico/barrido/{id}/scan
     *
     * @param array<string, string> $params
     */
    public function scan(array $params): void
    {
        $this->respond(fn (int $uid): array => $this->service->escanear($uid, (int) $params['id'], Request::json()));
    }

    /**
     * POST /api/v1/inventario-fisico/barrido/{id}/consolidar?empresa_id=X
     *
     * @param array<string, string> $params
     */
    public function consolidar(array $params): void
    {
        $p = array_merge($_GET, Request::json());
        $this->respond(fn (int $uid): array => $this->service->consolidarBarrido($uid, (int) $params['id'], $p), 'Consolidación aplicada');
    }

    /**
     * POST /api/v1/inventario-fisico/barrido/{id}/cerrar?empresa_id=X
     *
     * @param array<string, string> $params
     */
    public function cerrarBarrido(array $params): void
    {
        $p = array_merge($_GET, Request::json());
        $this->respond(fn (int $uid): array => $this->service->cerrarBarrido($uid, (int) $params['id'], $p), 'Barrido cerrado');
    }

    /**
     * GET /api/v1/inventario-fisico/barrido/{id}/no-inventariados?empresa_id=X
     *
     * @param array<string, string> $params
     */
    public function noInventariados(array $params): void
    {
        $this->respond(fn (int $uid): array => $this->service->noInventariadosBarrido($uid, (int) $params['id'], $_GET));
    }

    /**
     * POST /api/v1/inventario-fisico/barrido/{id}/llevar-a-cero
     *
     * @param array<string, string> $params
     */
    public function llevarACero(array $params): void
    {
        $body = array_merge($_GET, Request::json());
        $this->respond(fn (int $uid): array => $this->service->llevarACeroBarrido($uid, (int) $params['id'], $body), 'Productos llevados a 0');
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
