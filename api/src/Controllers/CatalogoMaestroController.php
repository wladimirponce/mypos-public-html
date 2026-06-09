<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Services\CatalogoMaestroService;
use Throwable;

final class CatalogoMaestroController
{
    private CatalogoMaestroService $service;

    public function __construct()
    {
        $this->service = new CatalogoMaestroService();
    }

    public function search(): void
    {
        $this->respond(fn (): array => $this->service->buscar(
            (string) ($_GET['catalogo'] ?? 'FARMA_CL_1'),
            (string) ($_GET['q'] ?? ''),
            (int) ($_GET['limite'] ?? 50)
        ));
    }

    public function barcode(): void
    {
        $this->respond(fn (): array => $this->service->buscarCodigo(
            (string) ($_GET['catalogo'] ?? 'FARMA_CL_1'),
            (string) ($_GET['codigo'] ?? '')
        ));
    }

    public function metrics(): void
    {
        $this->respond(fn (): array => $this->service->metricas(
            (string) ($_GET['catalogo'] ?? 'FARMA_CL_1')
        ));
    }

    public function incorporate(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->incorporar(
            $userId,
            (int) $params['id'],
            Request::json()
        ));
    }

    public function link(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->vinculo(
            $userId,
            (int) $params['id'],
            (int) ($_GET['empresa_id'] ?? 0)
        ));
    }

    private function respond(callable $callback): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            Response::success($callback((int) $claims['user_id']));
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('[CatalogoMaestro] ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
