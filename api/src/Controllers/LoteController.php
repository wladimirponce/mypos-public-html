<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Services\LoteService;

final class LoteController
{
    private LoteService $service;

    public function __construct()
    {
        $this->service = new LoteService();
    }

    /**
     * POST /api/v1/lotes
     * Registra la entrada de un lote con stock al sistema.
     */
    public function registrar(array $params): void
    {
        $user = AuthMiddleware::handle();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $result = $this->service->registrarEntradaLote((int) $user['id'], $data);
        Response::json(['success' => true, 'message' => 'Lote registrado correctamente', 'data' => $result], 201);
    }

    /**
     * GET /api/v1/lotes/stock?empresa_id=&producto_id=&ubicacion_id=
     * Stock disponible desglosado por lote para un producto.
     */
    public function stock(array $params): void
    {
        AuthMiddleware::handle();
        $empresaId   = (int) ($_GET['empresa_id'] ?? 0);
        $productoId  = (int) ($_GET['producto_id'] ?? 0);
        $ubicacionId = isset($_GET['ubicacion_id']) && (int) $_GET['ubicacion_id'] > 0
            ? (int) $_GET['ubicacion_id'] : null;
        $result = $this->service->stockPorLote($empresaId, $productoId, $ubicacionId);
        Response::json(['success' => true, 'data' => $result]);
    }

    /**
     * GET /api/v1/lotes/alertas?empresa_id=&dias=30
     * Lotes que vencen dentro de N dias y aun tienen stock.
     */
    public function alertas(array $params): void
    {
        AuthMiddleware::handle();
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        $dias      = isset($_GET['dias']) && (int) $_GET['dias'] > 0 ? (int) $_GET['dias'] : 30;
        $result    = $this->service->alertasVencimiento($empresaId, $dias);
        Response::json(['success' => true, 'data' => $result]);
    }

    /**
     * GET /api/v1/lotes/fefo?empresa_id=&producto_id=&ubicacion_id=&cantidad=
     * Calcula la asignacion FEFO para la cantidad solicitada.
     * El frontend usa esto para pre-seleccionar el lote antes de enviar la venta.
     */
    public function fefo(array $params): void
    {
        AuthMiddleware::handle();
        $empresaId   = (int) ($_GET['empresa_id'] ?? 0);
        $productoId  = (int) ($_GET['producto_id'] ?? 0);
        $ubicacionId = (int) ($_GET['ubicacion_id'] ?? 0);
        $cantidad    = (float) ($_GET['cantidad'] ?? 0);
        $asignaciones = $this->service->resolverFEFO($empresaId, $ubicacionId, $productoId, $cantidad);
        Response::json(['success' => true, 'data' => ['asignaciones' => $asignaciones]]);
    }
}
