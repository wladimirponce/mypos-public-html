<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Services\ReporteCapacidadService;

/**
 * Endpoints de reportes habilitados por capacidades multi-rubro.
 *
 * Todos los endpoints requieren empresa_id (via query string).
 * El servicio verifica que la empresa tenga la capacidad correspondiente
 * antes de ejecutar la consulta; si no la tiene devuelve 403.
 *
 * Rutas:
 *   GET /api/v1/reportes/merma
 *   GET /api/v1/reportes/lotes/vencimientos
 *   GET /api/v1/reportes/lotes/vencidos
 *   GET /api/v1/reportes/variantes/stock
 *   GET /api/v1/reportes/variantes/ventas
 */
final class ReporteCapacidadController
{
    private ReporteCapacidadService $service;

    public function __construct()
    {
        $this->service = new ReporteCapacidadService();
    }

    // =========================================================================
    // MERMA_OPERATIVA
    // =========================================================================

    /**
     * GET /api/v1/reportes/merma
     *   ?empresa_id=&sucursal_id=&fecha_desde=&fecha_hasta=
     *
     * Requiere capacidad: MERMA_OPERATIVA
     */
    public function merma(array $params): void
    {
        AuthMiddleware::handle();

        $filters = [
            'empresa_id'   => (int) ($_GET['empresa_id'] ?? 0),
            'sucursal_id'  => isset($_GET['sucursal_id']) && (int) $_GET['sucursal_id'] > 0
                ? (int) $_GET['sucursal_id'] : null,
            'fecha_desde'  => $_GET['fecha_desde'] ?? null,
            'fecha_hasta'  => $_GET['fecha_hasta'] ?? null,
        ];

        $result = $this->service->reporteMerma($filters);
        Response::json(['success' => true, 'data' => $result]);
    }

    // =========================================================================
    // LOTES_VENCIMIENTO
    // =========================================================================

    /**
     * GET /api/v1/reportes/lotes/vencimientos
     *   ?empresa_id=&dias=30
     *
     * Lotes proximos a vencer con stock disponible.
     * Requiere capacidad: LOTES_VENCIMIENTO
     */
    public function lotesPorVencer(array $params): void
    {
        AuthMiddleware::handle();

        $filters = [
            'empresa_id' => (int) ($_GET['empresa_id'] ?? 0),
            'dias'       => isset($_GET['dias']) && (int) $_GET['dias'] > 0 ? (int) $_GET['dias'] : 30,
        ];

        $result = $this->service->reporteLotesPorVencer($filters);
        Response::json(['success' => true, 'data' => $result]);
    }

    /**
     * GET /api/v1/reportes/lotes/vencidos
     *   ?empresa_id=
     *
     * Lotes ya vencidos con stock restante (requieren accion inmediata).
     * Requiere capacidad: LOTES_VENCIMIENTO
     */
    public function lotesVencidos(array $params): void
    {
        AuthMiddleware::handle();

        $filters = ['empresa_id' => (int) ($_GET['empresa_id'] ?? 0)];

        $result = $this->service->reporteLotesVencidos($filters);
        Response::json(['success' => true, 'data' => $result]);
    }

    // =========================================================================
    // VARIANTES
    // =========================================================================

    /**
     * GET /api/v1/reportes/variantes/stock
     *   ?empresa_id=&producto_padre_id=
     *
     * Stock actual por variante, agrupado por producto padre.
     * Requiere capacidad: VARIANTES
     */
    public function stockVariantes(array $params): void
    {
        AuthMiddleware::handle();

        $filters = [
            'empresa_id'       => (int) ($_GET['empresa_id'] ?? 0),
            'producto_padre_id'=> isset($_GET['producto_padre_id']) && (int) $_GET['producto_padre_id'] > 0
                ? (int) $_GET['producto_padre_id'] : null,
        ];

        $result = $this->service->reporteStockVariantes($filters);
        Response::json(['success' => true, 'data' => $result]);
    }

    /**
     * GET /api/v1/reportes/variantes/ventas
     *   ?empresa_id=&sucursal_id=&fecha_desde=&fecha_hasta=
     *
     * Ranking de ventas por variante en el periodo.
     * Requiere capacidad: VARIANTES
     */
    public function ventasVariantes(array $params): void
    {
        AuthMiddleware::handle();

        $filters = [
            'empresa_id'  => (int) ($_GET['empresa_id'] ?? 0),
            'sucursal_id' => isset($_GET['sucursal_id']) && (int) $_GET['sucursal_id'] > 0
                ? (int) $_GET['sucursal_id'] : null,
            'fecha_desde' => $_GET['fecha_desde'] ?? null,
            'fecha_hasta' => $_GET['fecha_hasta'] ?? null,
        ];

        $result = $this->service->reporteVentasVariantes($filters);
        Response::json(['success' => true, 'data' => $result]);
    }
}
