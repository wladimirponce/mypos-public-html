<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\DocumentoTributarioService;
use Throwable;

final class DocumentoTributarioController
{
    private DocumentoTributarioService $service;

    public function __construct()
    {
        $this->service = new DocumentoTributarioService();
    }

    public function storeFromSale(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->crearDesdeVenta($userId, Request::json());
        }, 'Documento tributario interno creado correctamente');
    }

    public function index(): void
    {
        $this->respond(fn (): array => $this->service->listar($_GET));
    }

    public function show(array $params): void
    {
        $this->respond(fn (): array => $this->service->detalle((int) $params['id'], (int) ($_GET['empresa_id'] ?? 0)));
    }

    public function markInternalIssued(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->marcarEmitidoInterno((int) $params['id'], Request::json());
        }, 'Documento marcado como emitido internamente');
    }

    public function markSentSii(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->marcarEnviadoSii((int) $params['id'], Request::json());
        });
    }

    public function markAcceptedSii(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->marcarAceptadoSii((int) $params['id'], Request::json());
        });
    }

    public function markRejectedSii(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->marcarRechazadoSii((int) $params['id'], Request::json());
        });
    }

    public function cancel(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->anular((int) $params['id'], Request::json());
        }, 'Documento tributario interno anulado correctamente');
    }

    public function assignFolio(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->asignarFolio($userId, (int) $params['id'], Request::json());
        }, 'Folio asignado correctamente al documento tributario');
    }

    public function emitDte(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->emitirDte($userId, (int) $params['id'], Request::json());
        }, 'DTE emitido en modo simulado');
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
