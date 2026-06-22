<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\OrdenCompraService;
use Throwable;

final class OrdenCompraController
{
    private OrdenCompraService $service;

    public function __construct()
    {
        $this->service = new OrdenCompraService();
    }

    public function index(): void
    {
        $this->respond(function (): array {
            $empresaId = $this->getEmpresaId();
            $filters   = array_filter([
                'estado'       => $_GET['estado']       ?? null,
                'proveedor_id' => $_GET['proveedor_id'] ?? null,
                'sucursal_id'  => $_GET['sucursal_id']  ?? null,
                'fecha_desde'  => $_GET['fecha_desde']  ?? null,
                'fecha_hasta'  => $_GET['fecha_hasta']  ?? null,
                'page'         => $_GET['page']         ?? null,
                'per_page'     => $_GET['per_page']     ?? null,
            ]);
            return $this->service->listar($empresaId, $filters);
        });
    }

    public function store(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->crear($userId, Request::json());
        }, 'Orden de compra creada');
    }

    public function show(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->detalle(
                $this->getEmpresaId(),
                (int) $params['id']
            );
        });
    }

    public function sugerencias(): void
    {
        $this->respond(function (): array {
            return $this->service->sugerirProductos(
                $this->getEmpresaId(),
                (int) ($_GET['proveedor_id'] ?? 0),
                (int) ($_GET['sucursal_id']  ?? 0)
            );
        });
    }

    public function enviar(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->enviar($userId, (int) $params['id'], $this->getEmpresaId());
        }, 'Orden enviada al proveedor');
    }

    public function recibir(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            $payload = Request::json();
            $payload['empresa_id'] = $this->getEmpresaId();
            return $this->service->recibir($userId, (int) $params['id'], $this->getEmpresaId(), $payload);
        }, 'Recepción registrada');
    }

    public function cerrar(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->cerrar($userId, (int) $params['id'], $this->getEmpresaId());
        }, 'Orden cerrada');
    }

    public function cancelar(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            $payload = Request::json();
            $motivo  = !empty($payload['motivo']) ? trim((string) $payload['motivo']) : null;
            return $this->service->cancelar($userId, (int) $params['id'], $this->getEmpresaId(), $motivo);
        }, 'Orden cancelada');
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims    = (new AuthMiddleware())->handle();
            $userId    = (int) $claims['user_id'];
            $empresaId = $this->getEmpresaId();

            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }

            Response::success($callback($userId), $message);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function getEmpresaId(): int
    {
        if (isset($_GET['empresa_id'])) {
            return (int) $_GET['empresa_id'];
        }
        $payload = Request::json();
        return (int) ($payload['empresa_id'] ?? 0);
    }
}
