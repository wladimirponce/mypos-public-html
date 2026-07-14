<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\CotizacionService;
use Mypos\Services\PdfService;
use Throwable;

final class CotizacionController
{
    private CotizacionService $service;

    public function __construct()
    {
        $this->service = new CotizacionService();
    }

    public function index(): void
    {
        $this->respond(function (): array {
            $filters = array_filter([
                'estado'      => $_GET['estado']      ?? null,
                'cliente_id'  => $_GET['cliente_id']  ?? null,
                'sucursal_id' => $_GET['sucursal_id'] ?? null,
                'fecha_desde' => $_GET['fecha_desde'] ?? null,
                'fecha_hasta' => $_GET['fecha_hasta'] ?? null,
                'q'           => $_GET['q']           ?? null,
                'page'        => $_GET['page']        ?? null,
                'per_page'    => $_GET['per_page']    ?? null,
            ]);
            return $this->service->listar($this->getEmpresaId(), $filters);
        });
    }

    public function store(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->crear($userId, Request::json());
        }, 'Cotización creada');
    }

    public function show(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->detalle($this->getEmpresaId(), (int) $params['id']);
        });
    }

    public function pdf(array $params): void
    {
        try {
            $claims    = (new AuthMiddleware())->handle();
            $userId    = (int) $claims['user_id'];
            $empresaId = $this->getEmpresaId();
            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }

            $pdfBytes = $this->service->generarPdf($empresaId, (int) $params['id']);
            (new PdfService())->streamPdf($pdfBytes, "cotizacion-{$params['id']}.pdf");
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al generar PDF', null, 500);
        }
    }

    public function enviar(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->enviar($userId, (int) $params['id'], $this->getEmpresaId());
        }, 'Cotización enviada al cliente');
    }

    public function aprobar(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->aprobar($userId, (int) $params['id'], $this->getEmpresaId());
        }, 'Cotización aprobada');
    }

    public function rechazar(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            $payload = Request::json();
            $motivo  = !empty($payload['motivo']) ? trim((string) $payload['motivo']) : null;
            return $this->service->rechazar($userId, (int) $params['id'], $this->getEmpresaId(), $motivo);
        }, 'Cotización rechazada');
    }

    public function duplicar(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->duplicar($userId, (int) $params['id'], $this->getEmpresaId());
        }, 'Cotización duplicada como borrador');
    }

    public function convertir(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            $payload = Request::json();
            return $this->service->convertir($userId, (int) $params['id'], $this->getEmpresaId(), $payload);
        }, 'Cotización convertida a venta');
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
