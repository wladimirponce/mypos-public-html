<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\PermissionMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\DocumentoIaService;
use Throwable;

final class DocumentoIaController
{
    private DocumentoIaService $service;

    public function __construct()
    {
        $this->service = new DocumentoIaService();
    }

    public function store(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->crear($userId, Request::json());
        }, 'Documento registrado correctamente');
    }

    public function process(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            $payload = Request::json();
            if (strtoupper((string) ($payload['modo'] ?? '')) === 'GEMINI') {
                (new PermissionMiddleware())->handle($userId, (int) ($payload['empresa_id'] ?? 0), 'documentos_ia.procesar_real');
                return $this->service->procesarGemini($userId, (int) $params['id'], $payload);
            }

            return $this->service->procesar((int) $params['id'], $payload);
        }, 'Documento procesado correctamente');
    }

    public function processGemini(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->procesarGemini($userId, (int) $params['id'], Request::json());
        }, 'Documento procesado correctamente');
    }

    public function index(): void
    {
        $this->respond(function (): array {
            return $this->service->listar((int) ($_GET['empresa_id'] ?? 0), $_GET);
        });
    }

    public function show(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->detalle((int) ($_GET['empresa_id'] ?? 0), (int) $params['id']);
        });
    }

    public function normalize(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->normalizar($userId, (int) $params['id'], Request::json());
        }, 'Documento normalizado correctamente');
    }

    public function revision(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->revision((int) $params['id'], $_GET);
        });
    }

    public function updateRevisionHeader(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->actualizarRevisionCabecera($userId, (int) $params['id'], Request::json());
        }, 'Cabecera de documento IA actualizada correctamente');
    }

    public function updateRevisionDetail(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->actualizarRevisionDetalle($userId, (int) $params['detalle_id'], Request::json());
        }, 'Detalle de documento IA actualizado correctamente');
    }

    public function alerts(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->alertas((int) $params['id'], $_GET);
        });
    }

    public function resolveAlert(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->resolverAlerta($userId, (int) $params['alerta_id'], Request::json());
        }, 'Alerta resuelta correctamente');
    }

    public function approve(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->aprobar($userId, (int) $params['id'], Request::json());
        }, 'Documento IA aprobado correctamente');
    }

    public function linkProvider(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->vincularProveedor($userId, (int) $params['id'], Request::json());
        }, 'Proveedor vinculado correctamente');
    }

    public function edit(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->editar((int) $params['id'], Request::json());
        }, 'Documento editado correctamente');
    }

    public function generatePurchase(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            $result = $this->service->generarCompra($userId, (int) $params['id'], Request::json());
            $result['message_key'] = $result['estado_compra'] === 'CONFIRMADA' ? 'confirmed' : 'draft';

            return $result;
        }, null, true);
    }

    public function linkProduct(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->vincularProducto((int) $params['id'], Request::json());
        }, 'Producto vinculado correctamente');
    }

    private function respond(callable $callback, ?string $message = null, bool $dynamicPurchaseMessage = false): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = $this->requestEmpresaId();

            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }


            $data = $callback($userId);
            if ($dynamicPurchaseMessage) {
                $key = $data['message_key'] ?? 'draft';
                unset($data['message_key']);
                $message = $key === 'confirmed'
                    ? 'Compra generada y confirmada desde documento IA'
                    : 'Compra generada desde documento IA';
            }

            Response::success($data, $message);
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
