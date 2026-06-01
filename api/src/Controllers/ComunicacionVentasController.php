<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Services\ComunicacionVentasService;
use Throwable;

class ComunicacionVentasController
{
    private ComunicacionVentasService $service;

    public function __construct()
    {
        $this->service = new ComunicacionVentasService();
    }

    public function store(): void
    {
        $this->respond(function (): array {
            return $this->service->create(Request::json());
        }, 201);
    }

    public function godIndex(): void
    {
        $this->respond(function (): array {
            if (!isset($_GET['pwd']) || $_GET['pwd'] !== 'tronador') {
                throw new HttpException('No autorizado', 403);
            }

            return $this->service->latest((int) ($_GET['limit'] ?? 100));
        });
    }

    private function respond(callable $callback, int $statusCode = 200): void
    {
        try {
            Response::success($callback(), null, $statusCode);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
