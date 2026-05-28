<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\StockService;
use Throwable;

final class StockController
{
    private StockService $service;

    public function __construct()
    {
        $this->service = new StockService();
    }

    public function index(): void
    {
        $this->respond(function (): array {
            $empresaId = $this->queryInt('empresa_id');
            $sucursalId = $this->queryInt('sucursal_id');

            return $this->service->listarStock($empresaId, $sucursalId, $_GET['q'] ?? null);
        });
    }

    public function showProduct(array $params): void
    {
        $this->respond(function () use ($params): array {
            $empresaId = $this->queryInt('empresa_id');
            $sucursalId = $this->queryInt('sucursal_id');
            $productoId = (int) $params['producto_id'];

            return ['stock' => $this->service->obtenerStockProducto($empresaId, $sucursalId, $productoId)];
        });
    }

    public function ajuste(): void
    {
        $this->respond(function (int $userId): array {
            $payload = Request::json();
            $payload['usuario_id'] = $userId;

            return $this->service->ajustarStock($payload);
        }, 'Ajuste de stock registrado');
    }

    public function movimientos(): void
    {
        $this->respond(function (): array {
            $empresaId = $this->queryInt('empresa_id');
            $sucursalId = $this->queryInt('sucursal_id');
            $productoId = isset($_GET['producto_id']) ? (int) $_GET['producto_id'] : null;

            return $this->service->listarMovimientos($empresaId, $sucursalId, $productoId);
        });
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = $this->requestEmpresaId();

            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }



            Response::success($callback($userId), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function queryInt(string $key): int
    {
        return (int) ($_GET[$key] ?? 0);
    }

    private function requestEmpresaId(): int
    {
        if (isset($_GET['empresa_id'])) {
            return (int) $_GET['empresa_id'];
        }

        $payload = Request::json();

        return (int) ($payload['empresa_id'] ?? 0);
    }
}
