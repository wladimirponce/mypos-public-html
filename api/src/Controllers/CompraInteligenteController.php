<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\CompraInteligenteService;
use Throwable;

/**
 * Controlador de compras inteligentes v1.
 *
 * Endpoints:
 *   GET  /api/v1/compras-inteligentes/sugerencias?empresa_id=&ubicacion_id=
 *   POST /api/v1/compras-inteligentes/borrador
 */
final class CompraInteligenteController
{
    private CompraInteligenteService $service;

    public function __construct()
    {
        $this->service = new CompraInteligenteService();
    }

    /**
     * GET /api/v1/compras-inteligentes/sugerencias
     *
     * Query params:
     *   - empresa_id     (requerido)
     *   - ubicacion_id   (opcional) filtra a una ubicación específica
     *   - dias_consumo   (opcional, default 60) ventana en días para consumo promedio
     *   - umbral_alza    (opcional, default 0.05) umbral decimal que activa alerta de alza
     */
    public function sugerencias(): void
    {
        $this->respond(function (int $userId): array {
            $empresaId   = (int) ($_GET['empresa_id']   ?? 0);
            $ubicacionId = isset($_GET['ubicacion_id']) && (int) $_GET['ubicacion_id'] > 0
                ? (int) $_GET['ubicacion_id']
                : null;
            $diasConsumo = isset($_GET['dias_consumo']) && is_numeric($_GET['dias_consumo'])
                ? (int) $_GET['dias_consumo']
                : 60;
            $umbralAlza  = isset($_GET['umbral_alza']) && is_numeric($_GET['umbral_alza'])
                ? (float) $_GET['umbral_alza']
                : 0.05;
            $presupuesto = isset($_GET['presupuesto']) && is_numeric($_GET['presupuesto']) && (int) $_GET['presupuesto'] > 0
                ? (int) $_GET['presupuesto']
                : null;

            return $this->service->sugerencias($empresaId, $ubicacionId, $diasConsumo, $umbralAlza, $presupuesto);
        });
    }

    /**
     * POST /api/v1/compras-inteligentes/borrador
     *
     * Body JSON:
     * {
     *   "empresa_id": 1,
     *   "sucursal_id": 1,
     *   "grupos": [
     *     {
     *       "proveedor_id": 5,
     *       "items": [
     *         { "producto_id": 12, "cantidad": 20, "costo_unitario": 1000 }
     *       ]
     *     }
     *   ]
     * }
     */
    public function generarBorrador(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->generarBorradores($userId, Request::json());
        }, 'Borradores de compra generados correctamente');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims    = (new AuthMiddleware())->handle();
            $userId    = (int) $claims['user_id'];
            $empresaId = $this->requestEmpresaId();

            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }

            Response::success($callback($userId), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('[CompraInteligente] ' . $exception->getMessage());
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
