<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\AnulacionRepository;
use Mypos\Repositories\StockRepository;
use Throwable;

final class AnulacionService
{
    private AnulacionRepository $repository;

    public function __construct(?AnulacionRepository $repository = null)
    {
        $this->repository = $repository ?? new AnulacionRepository(Database::connection());
    }

    public function anularVenta(int $userId, int $saleId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $reason = $this->reason($payload['motivo'] ?? null);
        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();
            $sale = $this->repository->findSaleForUpdate($empresaId, $saleId);

            if ($sale === null) {
                throw new HttpException('Venta no encontrada', 404);
            }

            if ((string) $sale['estado'] === 'ANULADA' || $sale['anulada_at'] !== null) {
                throw new HttpException('La venta ya se encuentra anulada', 422);
            }

            $saleDate = substr((string) $sale['fecha_venta'], 0, 10);

            if ($this->repository->closedDailyClosureExists($empresaId, (int) $sale['sucursal_id'], $saleDate)) {
                throw new HttpException('No se puede anular una venta incluida en un cierre diario cerrado.', 422);
            }

            $credit = $this->repository->saleCreditForUpdate($empresaId, $saleId);
            if ($credit !== null && (int) $credit['monto_pagado'] > 0) {
                throw new HttpException('No se puede anular venta a credito con pagos registrados.', 422);
            }

            $metadata = $this->saleMetadata($sale);
            $operationId = $this->repository->createOperation([
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) $sale['sucursal_id'],
                'tipo_operacion' => 'VENTA',
                'operacion_id' => $saleId,
                'usuario_id' => $userId,
                'motivo' => $reason,
                'estado' => 'APLICADA',
                'afecta_stock' => 1,
                'afecta_caja' => $metadata['afecta_caja'] ? 1 : 0,
                'referencia_stock_movimiento_id' => null,
                'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]);

            $firstMovementId = null;
            $stockReversed = false;
            $stockService = new StockService(new StockRepository($connection));

            foreach ($this->repository->saleDetails($empresaId, $saleId) as $detail) {
                if ((int) $detail['controla_stock'] !== 1) {
                    continue;
                }

                $movement = $stockService->registrarMovimiento([
                    'empresa_id' => $empresaId,
                    'sucursal_id' => (int) $sale['sucursal_id'],
                    'producto_id' => (int) $detail['producto_id'],
                    'usuario_id' => $userId,
                    'tipo' => 'ANULACION_VENTA',
                    'referencia_tipo' => 'ANULACION_VENTA',
                    'referencia_id' => $operationId,
                    'cantidad' => (string) $detail['cantidad'],
                    'costo_unitario' => (int) ($detail['costo_unitario'] ?? 0),
                    'observacion' => 'Anulacion venta #' . $saleId,
                ], $connection);

                $movementId = (int) $movement['movimiento_id'];
                $firstMovementId ??= $movementId;
                $stockReversed = true;
                $this->repository->insertDetail(
                    $operationId,
                    (int) $detail['producto_id'],
                    (string) $detail['cantidad'],
                    $movementId
                );
            }

            $this->repository->updateOperationStockReference($operationId, $firstMovementId);
            if ($credit !== null) {
                $this->repository->cancelSaleCredit($empresaId, $saleId);
            }
            $this->repository->markSaleCancelled($empresaId, $saleId, $userId, $reason);
            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) $sale['sucursal_id'],
                'usuario_id' => $userId,
                'modulo' => 'anulaciones',
                'accion' => 'anular_venta',
                'entidad' => 'ventas',
                'entidad_id' => $saleId,
                'descripcion' => 'Venta anulada',
                'datos_anteriores' => [
                    'estado' => (string) $sale['estado'],
                    'credito_cliente_id' => $credit !== null ? (int) $credit['id'] : null,
                ],
                'datos_nuevos' => [
                    'estado' => 'ANULADA',
                    'motivo' => $reason,
                    'stock_reversado' => $stockReversed,
                    'anulacion_operacion_id' => $operationId,
                ],
                'metadata' => $metadata,
            ], $connection);
            $connection->commit();

            return [
                'venta_id' => $saleId,
                'estado' => 'ANULADA',
                'stock_reversado' => $stockReversed,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function reversarCompra(int $userId, int $purchaseId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $reason = $this->reason($payload['motivo'] ?? null);
        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();
            $purchase = $this->repository->findPurchaseForUpdate($empresaId, $purchaseId);

            if ($purchase === null) {
                throw new HttpException('Compra no encontrada', 404);
            }

            if ((string) $purchase['estado'] !== 'CONFIRMADA') {
                throw new HttpException('Solo se pueden reversar compras CONFIRMADAS', 422);
            }

            if ($purchase['reversada_at'] !== null || $purchase['anulada_at'] !== null) {
                throw new HttpException('La compra ya se encuentra reversada o anulada', 422);
            }

            $operationId = $this->repository->createOperation([
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) $purchase['sucursal_id'],
                'tipo_operacion' => 'COMPRA',
                'operacion_id' => $purchaseId,
                'usuario_id' => $userId,
                'motivo' => $reason,
                'estado' => 'APLICADA',
                'afecta_stock' => 1,
                'afecta_caja' => 0,
                'referencia_stock_movimiento_id' => null,
                'metadata_json' => json_encode([
                    'regla' => 'Reversa de compra confirmada mediante movimiento contrario de stock.',
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $firstMovementId = null;
            $stockReversed = false;
            $stockService = new StockService(new StockRepository($connection));

            foreach ($this->repository->purchaseDetails($empresaId, $purchaseId) as $detail) {
                if ((int) $detail['controla_stock'] !== 1) {
                    continue;
                }

                $movement = $stockService->registrarMovimiento([
                    'empresa_id' => $empresaId,
                    'sucursal_id' => (int) $purchase['sucursal_id'],
                    'producto_id' => (int) $detail['producto_id'],
                    'usuario_id' => $userId,
                    'tipo' => 'REVERSA_COMPRA',
                    'referencia_tipo' => 'REVERSA_COMPRA',
                    'referencia_id' => $operationId,
                    'cantidad' => (string) $detail['cantidad'],
                    'costo_unitario' => (int) ($detail['costo_unitario'] ?? 0),
                    'observacion' => 'Reversa compra #' . $purchaseId,
                ], $connection);

                $movementId = (int) $movement['movimiento_id'];
                $firstMovementId ??= $movementId;
                $stockReversed = true;
                $this->repository->insertDetail(
                    $operationId,
                    (int) $detail['producto_id'],
                    (string) $detail['cantidad'],
                    $movementId
                );
            }

            $this->repository->updateOperationStockReference($operationId, $firstMovementId);
            $this->repository->markPurchaseReversed($empresaId, $purchaseId, $userId, $reason);
            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) $purchase['sucursal_id'],
                'usuario_id' => $userId,
                'modulo' => 'anulaciones',
                'accion' => 'reversar_compra',
                'entidad' => 'compras',
                'entidad_id' => $purchaseId,
                'descripcion' => 'Compra reversada',
                'datos_anteriores' => ['estado' => (string) $purchase['estado']],
                'datos_nuevos' => [
                    'estado' => 'REVERSADA',
                    'motivo' => $reason,
                    'stock_reversado' => $stockReversed,
                    'anulacion_operacion_id' => $operationId,
                ],
            ], $connection);
            $connection->commit();

            return [
                'compra_id' => $purchaseId,
                'estado' => 'REVERSADA',
                'stock_reversado' => $stockReversed,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function listar(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateDateRange($filters);

        if (!empty($filters['tipo_operacion']) && !in_array(strtoupper((string) $filters['tipo_operacion']), ['VENTA', 'COMPRA'], true)) {
            throw new HttpException('Tipo de operacion invalido', 422);
        }

        return $this->repository->list($empresaId, $filters);
    }

    public function detalle(int $id, int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $operation = $this->repository->find($empresaId, $id);

        if ($operation === null) {
            throw new HttpException('Anulacion no encontrada', 404);
        }

        $metadata = null;

        if ($operation['metadata_json'] !== null) {
            $decoded = json_decode((string) $operation['metadata_json'], true);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        return [
            'anulacion' => $operation,
            'operacion' => [
                'tipo_operacion' => $operation['tipo_operacion'],
                'operacion_id' => (int) $operation['operacion_id'],
            ],
            'detalles' => $this->repository->details($id),
            'stock_movimientos' => $this->repository->stockMovementsForOperation($empresaId, $id),
            'metadata_json' => $metadata,
        ];
    }

    private function saleMetadata(array $sale): array
    {
        $openingId = (int) ($sale['caja_apertura_id'] ?? $sale['apertura_id'] ?? 0);
        $metadata = [
            'afecta_caja' => $openingId > 0,
            'caja_apertura_id' => $openingId > 0 ? $openingId : null,
            'advertencias' => [],
        ];

        if ($openingId <= 0) {
            return $metadata;
        }

        $opening = $this->repository->cashOpeningState($openingId);

        if ($opening === null) {
            $metadata['advertencias'][] = 'La venta anulada referenciaba una caja_apertura_id que no fue encontrada.';

            return $metadata;
        }

        $metadata['estado_caja_apertura'] = $opening['estado'];
        $metadata['caja_cierre_id'] = $opening['cierre_id'] !== null ? (int) $opening['cierre_id'] : null;

        if ((string) $opening['estado'] === 'CERRADA' || $opening['cierre_id'] !== null) {
            $metadata['advertencias'][] = 'La venta anulada estaba asociada a una caja ya cerrada; revisar ajuste administrativo.';
        } else {
            $metadata['advertencias'][] = 'La venta anulada estaba asociada a una caja abierta; revisar ajuste financiero manual si corresponde.';
        }

        return $metadata;
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);

        if ($value <= 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return $value;
    }

    private function reason(mixed $value): string
    {
        $reason = trim((string) $value);

        if (strlen($reason) < 5) {
            throw new HttpException('El motivo es obligatorio y debe tener al menos 5 caracteres', 422);
        }

        return $reason;
    }

    private function validateDateRange(array $filters): void
    {
        foreach (['fecha_desde', 'fecha_hasta'] as $field) {
            if (empty($filters[$field])) {
                continue;
            }

            $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $filters[$field]);

            if (!$date || $date->format('Y-m-d') !== (string) $filters[$field]) {
                throw new HttpException('Formato de fecha invalido', 422);
            }
        }

        if (!empty($filters['fecha_desde']) && !empty($filters['fecha_hasta']) && $filters['fecha_desde'] > $filters['fecha_hasta']) {
            throw new HttpException('fecha_desde no puede ser mayor que fecha_hasta', 422);
        }
    }
}
