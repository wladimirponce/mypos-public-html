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
            $empresaId  = $this->queryInt('empresa_id');
            $sucursalId = $this->queryInt('sucursal_id');
            $page       = max(1, $this->queryInt('page') ?: 1);
            $perPage    = max(1, min($this->queryInt('per_page') ?: 200, 500));
            $soloGestionados = $this->queryBool('solo_gestionados');

            return $this->service->listarStock($empresaId, $sucursalId, $_GET['q'] ?? null, $page, $perPage, $soloGestionados);
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

    public function alertas(): void
    {
        $this->respond(function (): array {
            $empresaId  = $this->queryInt('empresa_id');
            $sucursalId = isset($_GET['sucursal_id']) && (int) $_GET['sucursal_id'] > 0
                ? (int) $_GET['sucursal_id']
                : null;

            return $this->service->alertas($empresaId, $sucursalId);
        });
    }

    public function integridad(): void
    {
        $this->respond(function (): array {
            return $this->service->verificarIntegridad($this->queryInt('empresa_id'));
        });
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

    public function porUbicacion(array $params): void
    {
        $this->respond(function () use ($params): array {
            $empresaId   = $this->queryInt('empresa_id');
            $ubicacionId = (int) $params['ubicacion_id'];
            $page        = max(1, $this->queryInt('page') ?: 1);
            $perPage     = max(1, min($this->queryInt('per_page') ?: 200, 500));
            $soloGestionados = $this->queryBool('solo_gestionados');

            return $this->service->listarStockUbicacion($empresaId, $ubicacionId, $_GET['q'] ?? null, $page, $perPage, $soloGestionados);
        });
    }

    public function ubicaciones(): void
    {
        $this->respond(function (): array {
            $empresaId = $this->queryInt('empresa_id');
            $sucursalId = isset($_GET['sucursal_id']) ? (int) $_GET['sucursal_id'] : null;
            $includeInactive = (int) ($_GET['incluir_inactivas'] ?? 0) === 1;

            return $this->service->listarUbicaciones($empresaId, $_GET['tipo'] ?? null, $sucursalId, $includeInactive);
        });
    }

    public function crearUbicacion(): void
    {
        $this->respond(fn (): array => $this->service->crearUbicacion(Request::json()), 'Ubicacion de stock creada');
    }

    public function actualizarUbicacion(array $params): void
    {
        $this->respond(fn (): array => $this->service->actualizarUbicacion((int) $params['id'], Request::json()), 'Ubicacion de stock actualizada');
    }

    public function desactivarUbicacion(array $params): void
    {
        $this->respond(fn (): array => $this->service->desactivarUbicacion($this->queryInt('empresa_id'), (int) $params['id']), 'Ubicacion de stock desactivada');
    }

    public function eliminarUbicacion(array $params): void
    {
        $this->respond(fn (): array => $this->service->eliminarUbicacion($this->queryInt('empresa_id'), (int) $params['id']), 'Bodega eliminada permanentemente');
    }

    public function traslado(): void
    {
        $this->respond(function (int $userId): array {
            $payload = Request::json();
            $payload['usuario_id'] = $userId;

            return $this->service->trasladar($payload);
        }, 'Traslado de stock registrado');
    }

    public function merma(): void
    {
        $this->respond(function (int $userId): array {
            $payload = Request::json();
            $payload['usuario_id'] = $userId;

            return $this->service->registrarMerma($payload);
        }, 'Merma de stock registrada');
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

    private function queryBool(string $key): bool
    {
        $value = $_GET[$key] ?? null;

        return $value === '1' || $value === 'true' || $value === 1 || $value === true;
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
