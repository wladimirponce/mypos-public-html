<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\CajaService;
use Throwable;

final class CajaController
{
    private CajaService $service;

    public function __construct()
    {
        $this->service = new CajaService();
    }

    public function store(): void
    {
        $this->respond(function (): array {
            return $this->service->crearCaja(Request::json());
        }, 'Caja creada correctamente');
    }

    public function index(): void
    {
        $this->respond(function (): array {
            return $this->service->listarCajas((int) ($_GET['empresa_id'] ?? 0), $_GET);
        });
    }

    public function open(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->abrirCaja($userId, (int) $params['id'], Request::json());
        }, 'Caja abierta correctamente');
    }

    public function status(): void
    {
        $this->respond(function (): array {
            $boxId = isset($_GET['caja_id']) && $_GET['caja_id'] !== '' ? (int) $_GET['caja_id'] : null;

            return $this->service->estadoCaja(
                (int) ($_GET['empresa_id'] ?? 0),
                (int) ($_GET['sucursal_id'] ?? 0),
                $boxId
            );
        });
    }

    public function movement(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->registrarMovimiento($userId, Request::json());
        }, 'Movimiento de caja registrado correctamente');
    }

    public function movements(array $params): void
    {
        $this->respond(function () use ($params): array {
            $openingId = isset($_GET['caja_apertura_id']) && $_GET['caja_apertura_id'] !== ''
                ? (int) $_GET['caja_apertura_id']
                : null;

            return $this->service->listarMovimientos((int) ($_GET['empresa_id'] ?? 0), (int) $params['id'], $openingId);
        });
    }

    public function close(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->cerrarCaja($userId, (int) $params['id'], Request::json());
        }, 'Caja cerrada correctamente');
    }

    public function closures(): void
    {
        $this->respond(function (): array {
            return $this->service->listarCierres((int) ($_GET['empresa_id'] ?? 0), $_GET);
        });
    }

    public function closureDetail(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->detalleCierre((int) ($_GET['empresa_id'] ?? 0), (int) $params['id']);
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

    private function requestEmpresaId(): int
    {
        if (isset($_GET['empresa_id'])) {
            return (int) $_GET['empresa_id'];
        }

        $payload = Request::json();

        return (int) ($payload['empresa_id'] ?? 0);
    }
}
