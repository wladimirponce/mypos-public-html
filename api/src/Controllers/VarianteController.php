<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\VarianteService;
use Throwable;

/**
 * Controlador de variantes de producto.
 *
 * Endpoints:
 *   GET  /api/v1/productos/{id}/variantes?empresa_id=     — lista variantes del padre
 *   GET  /api/v1/productos/{id}/ejes-variante?empresa_id= — ejes con opciones para selector POS
 *   POST /api/v1/productos/{id}/variantes                 — generar variantes
 */
final class VarianteController
{
    private VarianteService $service;

    public function __construct()
    {
        $this->service = new VarianteService();
    }

    /**
     * GET /api/v1/productos/{id}/variantes?empresa_id=
     *
     * Lista todas las variantes del producto padre con atributos y stock agregado.
     */
    public function listar(array $params): void
    {
        $this->respond(function () use ($params): array {
            $claims = (new AuthMiddleware())->handle();
            return $this->service->listarVariantes(
                (int) $claims['user_id'],
                (int) ($_GET['empresa_id'] ?? 0),
                (int) ($params['id'] ?? 0)
            );
        });
    }

    /**
     * GET /api/v1/productos/{id}/ejes-variante?empresa_id=
     *
     * Retorna los ejes de variante con sus opciones para construir el selector en el POS.
     */
    public function ejes(array $params): void
    {
        $this->respond(function () use ($params): array {
            $claims = (new AuthMiddleware())->handle();
            return $this->service->ejesDisponibles(
                (int) $claims['user_id'],
                (int) ($_GET['empresa_id'] ?? 0),
                (int) ($params['id'] ?? 0)
            );
        });
    }

    /**
     * POST /api/v1/productos/{id}/variantes
     *
     * Genera variantes para el producto padre.
     *
     * Body JSON:
     * {
     *   "empresa_id": 5,
     *   "combinaciones": [
     *     {
     *       "codigo_variante": "38-NEGRO",
     *       "precio_venta": 29990,
     *       "atributos": [
     *         { "atributo_id": 10, "opcion_id": 45 },
     *         { "atributo_id": 11, "opcion_id": 52 }
     *       ]
     *     }
     *   ]
     * }
     */
    public function generar(array $params): void
    {
        $this->respond(function () use ($params): array {
            $body      = json_decode(file_get_contents('php://input'), true) ?? [];
            $claims    = (new AuthMiddleware())->handle();
            $empresaId = (int) ($body['empresa_id'] ?? 0);

            return $this->service->generarVariantes(
                (int) $claims['user_id'],
                $empresaId,
                (int) ($params['id'] ?? 0),
                $body['combinaciones'] ?? []
            );
        }, 'Variantes generadas correctamente');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims    = (new AuthMiddleware())->handle();
            $userId    = (int) $claims['user_id'];
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);

            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }

            Response::success($callback(), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('[Variantes] ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
