<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\OrdenCompraRepository;
use Throwable;

final class OrdenCompraService
{
    private OrdenCompraRepository $repository;

    public function __construct(?OrdenCompraRepository $repository = null)
    {
        $this->repository = $repository ?? new OrdenCompraRepository(Database::connection());
    }

    public function crear(int $userId, array $payload): array
    {
        $empresaId   = $this->positiveInt($payload, 'empresa_id');
        $sucursalId  = $this->positiveInt($payload, 'sucursal_id');
        $proveedorId = $this->positiveInt($payload, 'proveedor_id');
        $items       = $payload['items'] ?? [];

        if (empty($items) || !is_array($items)) {
            throw new HttpException('La orden debe tener al menos un ítem', 422);
        }

        $fechaEmision  = $this->date((string) ($payload['fecha_emision'] ?? $this->today()));
        $fechaEntrega  = !empty($payload['fecha_entrega_esperada'])
            ? $this->date((string) $payload['fecha_entrega_esperada'])
            : null;

        $conn = $this->repository->connection();

        try {
            $conn->beginTransaction();

            $ordenId = $this->repository->insert([
                'empresa_id'             => $empresaId,
                'sucursal_id'            => $sucursalId,
                'proveedor_id'           => $proveedorId,
                'usuario_id'             => $userId,
                'numero_oc'              => 'TEMP',
                'estado'                 => 'BORRADOR',
                'fecha_emision'          => $fechaEmision,
                'fecha_entrega_esperada' => $fechaEntrega,
                'observacion'            => !empty($payload['observacion']) ? trim((string) $payload['observacion']) : null,
                'total_referencia'       => 0,
            ]);

            foreach (array_values($items) as $i => $item) {
                $productoId = $this->positiveInt($item, 'producto_id');
                $cantidad   = (float) ($item['cantidad_solicitada'] ?? 0);
                if ($cantidad <= 0) {
                    throw new HttpException("Cantidad inválida en ítem " . ($i + 1), 422);
                }
                $this->repository->insertItem([
                    'empresa_id'          => $empresaId,
                    'orden_id'            => $ordenId,
                    'producto_id'         => $productoId,
                    'linea'               => $i + 1,
                    'codigo_producto'     => $item['codigo_producto'] ?? null,
                    'nombre_producto'     => (string) ($item['nombre_producto'] ?? ''),
                    'cantidad_solicitada' => number_format($cantidad, 3, '.', ''),
                    'costo_referencia'    => (int) ($item['costo_referencia'] ?? 0),
                    'unidad'              => $item['unidad'] ?? 'UN',
                    'observacion'         => $item['observacion'] ?? null,
                ]);
            }

            $this->repository->refreshTotales($ordenId);
            $conn->commit();

            $orden = $this->repository->find($empresaId, $ordenId);
            return ['orden_id' => $ordenId, 'numero_oc' => $orden['numero_oc'] ?? '', 'estado' => 'BORRADOR'];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public function listar(int $empresaId, array $filters): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $total   = $this->repository->countList($empresaId, $filters);
        $ordenes = $this->repository->list($empresaId, $filters, $perPage, $offset);

        return [
            'ordenes'     => $ordenes,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    public function detalle(int $empresaId, int $id): array
    {
        if ($empresaId <= 0 || $id <= 0) {
            throw new HttpException('Parámetros inválidos', 422);
        }

        $orden = $this->repository->find($empresaId, $id);
        if ($orden === null) {
            throw new HttpException('Orden de compra no encontrada', 404);
        }

        return [
            'orden'       => $orden,
            'items'       => $this->repository->items($id),
            'recepciones' => $this->repository->recepciones($id),
        ];
    }

    public function enviar(int $userId, int $id, int $empresaId): array
    {
        $conn = $this->repository->connection();
        try {
            $conn->beginTransaction();
            $orden = $this->repository->findForUpdate($empresaId, $id);
            if ($orden === null) {
                throw new HttpException('Orden de compra no encontrada', 404);
            }
            if ($orden['estado'] !== 'BORRADOR') {
                throw new HttpException('Solo se puede enviar una OC en estado BORRADOR', 422);
            }
            $this->repository->updateEstado($id, 'ENVIADA');
            $conn->commit();

            AuditoriaService::registrarEvento([
                'usuario_id'  => $userId,
                'modulo'      => 'ordenes_compra',
                'accion'      => 'enviar',
                'entidad'     => 'ordenes_compra',
                'entidad_id'  => $id,
                'descripcion' => 'OC enviada a proveedor: ' . ($orden['numero_oc'] ?? $id),
                'severidad'   => 'INFO',
            ]);

            return ['orden_id' => $id, 'estado' => 'ENVIADA'];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public function recibir(int $userId, int $id, int $empresaId, array $payload): array
    {
        $itemsPayload = $payload['items'] ?? [];
        if (empty($itemsPayload)) {
            throw new HttpException('Debe indicar al menos un ítem a recibir', 422);
        }

        $fecha = $this->date((string) ($payload['fecha'] ?? $this->today()));
        $conn  = $this->repository->connection();

        try {
            $conn->beginTransaction();

            $orden = $this->repository->findForUpdate($empresaId, $id);
            if ($orden === null) {
                throw new HttpException('Orden de compra no encontrada', 404);
            }
            if (in_array($orden['estado'], ['CERRADA', 'CANCELADA'], true)) {
                throw new HttpException('No se puede recibir en una OC ' . $orden['estado'], 422);
            }

            // Cargar ítems actuales para validar cantidades
            $itemsActuales = [];
            foreach ($this->repository->items($id) as $item) {
                $itemsActuales[(int) $item['id']] = $item;
            }

            // Validar y construir mapa de recepciones
            $recepcionItems = [];
            foreach ($itemsPayload as $ri) {
                $ordenItemId   = (int) ($ri['orden_item_id'] ?? 0);
                $cantidadRec   = (float) ($ri['cantidad_recibida'] ?? 0);
                $costoUnitario = (int) ($ri['costo_unitario'] ?? 0);

                if ($ordenItemId <= 0 || $cantidadRec <= 0) {
                    continue;
                }
                if (!isset($itemsActuales[$ordenItemId])) {
                    throw new HttpException("Ítem {$ordenItemId} no pertenece a esta OC", 422);
                }

                $item     = $itemsActuales[$ordenItemId];
                $pendiente = (float) $item['cantidad_solicitada'] - (float) $item['cantidad_recibida'];
                if ($cantidadRec > $pendiente + 0.001) {
                    throw new HttpException(
                        "Cantidad recibida ({$cantidadRec}) supera la pendiente ({$pendiente}) en " . $item['nombre_producto'],
                        422
                    );
                }

                $recepcionItems[] = [
                    'orden_item_id' => $ordenItemId,
                    'producto_id'   => (int) $item['producto_id'],
                    'nombre'        => $item['nombre_producto'],
                    'cantidad'      => number_format($cantidadRec, 3, '.', ''),
                    'costo'         => $costoUnitario,
                ];
            }

            if (empty($recepcionItems)) {
                throw new HttpException('No hay ítems válidos para recibir', 422);
            }

            // Crear registro de recepción (sin compra aún)
            $recepcionId = $this->repository->insertRecepcion([
                'empresa_id' => $empresaId,
                'orden_id'   => $id,
                'compra_id'  => null,
                'usuario_id' => $userId,
                'fecha'      => $fecha,
                'notas'      => !empty($payload['notas']) ? trim((string) $payload['notas']) : null,
            ]);

            // Insertar ítems de recepción y actualizar acumulados
            foreach ($recepcionItems as $ri) {
                $this->repository->insertRecepcionItem([
                    'empresa_id'       => $empresaId,
                    'recepcion_id'     => $recepcionId,
                    'orden_item_id'    => $ri['orden_item_id'],
                    'producto_id'      => $ri['producto_id'],
                    'cantidad_recibida' => $ri['cantidad'],
                    'costo_unitario'   => $ri['costo'],
                ]);
                $this->repository->acumularRecibido($ri['orden_item_id'], $ri['cantidad']);
            }

            // Actualizar estado de la OC
            $todoRecibido = $this->repository->allItemsReceived($id);
            $nuevoEstado  = $todoRecibido ? 'CERRADA' : 'RECIBIDA_PARCIAL';
            $this->repository->updateEstado($id, $nuevoEstado);
            $this->repository->refreshTotales($id);

            // Crear compra BORRADOR desde la recepción
            $compraService = new CompraService();
            $compraItems   = array_map(fn($ri) => [
                'producto_id'    => $ri['producto_id'],
                'cantidad'       => $ri['cantidad'],
                'costo_unitario' => $ri['costo'],
            ], $recepcionItems);

            $compraPayload = [
                'empresa_id'      => $empresaId,
                'sucursal_id'     => (int) $orden['sucursal_id'],
                'proveedor_id'    => (int) $orden['proveedor_id'],
                'tipo_documento'  => $payload['tipo_documento'] ?? 'GUIA_DESPACHO_COMPRA',
                'folio'           => !empty($payload['folio']) ? trim((string) $payload['folio']) : null,
                'fecha_documento' => $fecha,
                'estado'          => 'BORRADOR',
                'observacion'     => 'Recepción OC ' . ($orden['numero_oc'] ?? $id),
                'items'           => $compraItems,
            ];
            $compraResult = $compraService->crear($userId, $compraPayload);
            $compraId     = (int) ($compraResult['compra_id'] ?? 0);

            // Vincular compra a recepción
            if ($compraId > 0) {
                $this->repository->linkCompra($recepcionId, $compraId);
            }

            $conn->commit();

            return [
                'recepcion_id' => $recepcionId,
                'compra_id'    => $compraId,
                'orden_estado' => $nuevoEstado,
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public function cerrar(int $userId, int $id, int $empresaId): array
    {
        $conn = $this->repository->connection();
        try {
            $conn->beginTransaction();
            $orden = $this->repository->findForUpdate($empresaId, $id);
            if ($orden === null) {
                throw new HttpException('Orden de compra no encontrada', 404);
            }
            if (in_array($orden['estado'], ['CERRADA', 'CANCELADA'], true)) {
                throw new HttpException('La OC ya está ' . $orden['estado'], 422);
            }
            $this->repository->updateEstado($id, 'CERRADA');
            $conn->commit();
            return ['orden_id' => $id, 'estado' => 'CERRADA'];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public function cancelar(int $userId, int $id, int $empresaId, ?string $motivo): array
    {
        $conn = $this->repository->connection();
        try {
            $conn->beginTransaction();
            $orden = $this->repository->findForUpdate($empresaId, $id);
            if ($orden === null) {
                throw new HttpException('Orden de compra no encontrada', 404);
            }
            if ($orden['estado'] === 'CANCELADA') {
                throw new HttpException('La OC ya está cancelada', 422);
            }
            if ($orden['estado'] === 'CERRADA') {
                throw new HttpException('No se puede cancelar una OC cerrada', 422);
            }
            $this->repository->updateEstado($id, 'CANCELADA', [
                'cancelada_at'        => 'CURRENT_TIMESTAMP',
                'motivo_cancelacion'  => $motivo,
            ]);
            $conn->commit();
            return ['orden_id' => $id, 'estado' => 'CANCELADA'];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public function sugerirProductos(int $empresaId, int $proveedorId, int $sucursalId): array
    {
        if ($empresaId <= 0 || $proveedorId <= 0 || $sucursalId <= 0) {
            throw new HttpException('empresa_id, proveedor_id y sucursal_id son obligatorios', 422);
        }
        return [
            'sugerencias' => $this->repository->sugerirProductos($empresaId, $proveedorId, $sucursalId),
        ];
    }

    private function today(): string
    {
        return (new DateTimeImmutable('now', new \DateTimeZone('America/Santiago')))->format('Y-m-d');
    }

    private function date(string $value): string
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            throw new HttpException('Fecha inválida: ' . $value, 422);
        }
        return $value;
    }

    private function positiveInt(array $data, string $field): int
    {
        $v = (int) ($data[$field] ?? 0);
        if ($v <= 0) {
            throw new HttpException('Error de validación', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }
        return $v;
    }
}
