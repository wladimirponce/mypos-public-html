<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\FarmaciaService;
use Throwable;

/**
 * Controlador de la vertical farmacia.
 *
 * Endpoints:
 *   GET /api/v1/farmacia/atributos?empresa_id=
 *   GET /api/v1/farmacia/buscar?empresa_id=&q=&limite=
 *   GET /api/v1/farmacia/productos-con-receta?empresa_id=&limite=
 *   GET /api/v1/farmacia/productos/{id}/ficha?empresa_id=
 */
final class FarmaciaController
{
    private FarmaciaService $service;

    public function __construct()
    {
        $this->service = new FarmaciaService();
    }

    /**
     * GET /api/v1/farmacia/atributos?empresa_id=
     *
     * Retorna los atributos farmacéuticos disponibles para la empresa.
     * Útil para construir formularios y filtros en el frontend.
     */
    public function atributos(): void
    {
        $this->respond(function (): array {
            return $this->service->atributosFarmacia((int) ($_GET['empresa_id'] ?? 0));
        });
    }

    /**
     * GET /api/v1/farmacia/buscar?empresa_id=&q=PARACETAMOL[&limite=50]
     *
     * Búsqueda de productos por principio activo.
     */
    public function buscar(): void
    {
        $this->respond(function (): array {
            return $this->service->buscarPorPrincipioActivo(
                (int) ($_GET['empresa_id'] ?? 0),
                (string) ($_GET['q'] ?? ''),
                (int) ($_GET['limite'] ?? 50)
            );
        });
    }

    /**
     * GET /api/v1/farmacia/productos-con-receta?empresa_id=[&limite=200]
     *
     * Listado de productos con requiere_receta = true.
     */
    public function productosConReceta(): void
    {
        $this->respond(function (): array {
            return $this->service->productosConReceta(
                (int) ($_GET['empresa_id'] ?? 0),
                (int) ($_GET['limite'] ?? 200)
            );
        });
    }

    /**
     * GET /api/v1/farmacia/productos/{id}/ficha?empresa_id=
     *
     * Ficha farmacéutica completa de un producto.
     */
    public function ficha(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->fichaFarmaceutica(
                (int) ($_GET['empresa_id'] ?? 0),
                (int) ($params['id'] ?? 0)
            );
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
            error_log('[Farmacia] ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
