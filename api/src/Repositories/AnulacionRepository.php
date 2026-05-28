<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class AnulacionRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function findSaleForUpdate(int $empresaId, int $saleId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, caja_id, apertura_id, caja_apertura_id,
                    usuario_id, cliente_id, condicion_pago, credito_cliente_id,
                    estado, total, fecha_venta, anulada_at
             FROM ventas
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $saleId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function saleCreditForUpdate(int $empresaId, int $saleId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, venta_id, monto_original, monto_pagado, saldo_pendiente, estado
             FROM creditos_clientes
             WHERE empresa_id = :empresa_id AND venta_id = :venta_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['empresa_id' => $empresaId, 'venta_id' => $saleId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function cancelSaleCredit(int $empresaId, int $saleId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE creditos_clientes
             SET estado = \'ANULADO\',
                 saldo_pendiente = 0,
                 updated_at = CURRENT_TIMESTAMP
             WHERE empresa_id = :empresa_id
               AND venta_id = :venta_id
               AND estado = \'PENDIENTE\'
               AND monto_pagado = 0'
        );
        $statement->execute(['empresa_id' => $empresaId, 'venta_id' => $saleId]);
    }

    public function saleDetails(int $empresaId, int $saleId): array
    {
        $statement = $this->connection->prepare(
            'SELECT vd.id, vd.empresa_id, vd.venta_id, vd.producto_id, vd.cantidad,
                    vd.costo_unitario, p.controla_stock
             FROM venta_detalles vd
             INNER JOIN productos p ON p.id = vd.producto_id
             WHERE vd.empresa_id = :empresa_id AND vd.venta_id = :venta_id
             ORDER BY vd.linea'
        );
        $statement->execute(['empresa_id' => $empresaId, 'venta_id' => $saleId]);

        return $statement->fetchAll();
    }

    public function closedDailyClosureExists(int $empresaId, int $sucursalId, string $date): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM cierres_diarios
             WHERE empresa_id = :empresa_id
               AND sucursal_id = :sucursal_id
               AND fecha_cierre = :fecha_cierre
               AND estado = \'CERRADO\'
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId, 'fecha_cierre' => $date]);

        return (bool) $statement->fetchColumn();
    }

    public function cashOpeningState(?int $openingId): ?array
    {
        if ($openingId === null || $openingId <= 0) {
            return null;
        }

        $statement = $this->connection->prepare(
            'SELECT ca.id, ca.estado, cc.id AS cierre_id
             FROM caja_aperturas ca
             LEFT JOIN caja_cierres cc ON cc.apertura_id = ca.id OR cc.caja_apertura_id = ca.id
             WHERE ca.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $openingId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function markSaleCancelled(int $empresaId, int $saleId, int $userId, string $reason): void
    {
        $statement = $this->connection->prepare(
            'UPDATE ventas
             SET estado = \'ANULADA\',
                 motivo_anulacion = :motivo,
                 anulada_at = CURRENT_TIMESTAMP,
                 anulada_por_usuario_id = :usuario_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute([
            'motivo' => $reason,
            'usuario_id' => $userId,
            'id' => $saleId,
            'empresa_id' => $empresaId,
        ]);
    }

    public function findPurchaseForUpdate(int $empresaId, int $purchaseId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, usuario_id, estado, total,
                    anulada_at, reversada_at
             FROM compras
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $purchaseId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function purchaseDetails(int $empresaId, int $purchaseId): array
    {
        $statement = $this->connection->prepare(
            'SELECT cd.id, cd.empresa_id, cd.compra_id, cd.producto_id, cd.cantidad,
                    cd.costo_unitario, p.controla_stock
             FROM compra_detalles cd
             INNER JOIN productos p ON p.id = cd.producto_id
             WHERE cd.empresa_id = :empresa_id AND cd.compra_id = :compra_id
             ORDER BY cd.linea'
        );
        $statement->execute(['empresa_id' => $empresaId, 'compra_id' => $purchaseId]);

        return $statement->fetchAll();
    }

    public function markPurchaseReversed(int $empresaId, int $purchaseId, int $userId, string $reason): void
    {
        $statement = $this->connection->prepare(
            'UPDATE compras
             SET estado = \'REVERSADA\',
                 motivo_reversa = :motivo,
                 reversada_at = CURRENT_TIMESTAMP,
                 reversada_por_usuario_id = :usuario_id,
                 motivo_anulacion = :motivo_anulacion,
                 anulada_at = CURRENT_TIMESTAMP,
                 anulada_por_usuario_id = :anulada_por_usuario_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute([
            'motivo' => $reason,
            'usuario_id' => $userId,
            'motivo_anulacion' => $reason,
            'anulada_por_usuario_id' => $userId,
            'id' => $purchaseId,
            'empresa_id' => $empresaId,
        ]);
    }

    public function createOperation(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO anulaciones_operaciones (
                empresa_id, sucursal_id, tipo_operacion, operacion_id, usuario_id,
                motivo, estado, afecta_stock, afecta_caja, referencia_stock_movimiento_id, metadata_json
             ) VALUES (
                :empresa_id, :sucursal_id, :tipo_operacion, :operacion_id, :usuario_id,
                :motivo, :estado, :afecta_stock, :afecta_caja, :referencia_stock_movimiento_id, :metadata_json
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function insertDetail(int $operationId, int $productId, string $quantity, ?int $stockMovementId): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO anulacion_detalles (anulacion_operacion_id, producto_id, cantidad, stock_movimiento_id)
             VALUES (:anulacion_operacion_id, :producto_id, :cantidad, :stock_movimiento_id)'
        );
        $statement->execute([
            'anulacion_operacion_id' => $operationId,
            'producto_id' => $productId,
            'cantidad' => $quantity,
            'stock_movimiento_id' => $stockMovementId,
        ]);
    }

    public function updateOperationStockReference(int $operationId, ?int $stockMovementId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE anulaciones_operaciones
             SET referencia_stock_movimiento_id = :stock_movimiento_id
             WHERE id = :id'
        );
        $statement->execute(['stock_movimiento_id' => $stockMovementId, 'id' => $operationId]);
    }

    public function list(int $empresaId, array $filters): array
    {
        $sql = 'SELECT id, empresa_id, sucursal_id, tipo_operacion, operacion_id,
                       usuario_id, motivo, estado, afecta_stock, afecta_caja,
                       referencia_stock_movimiento_id, created_at
                FROM anulaciones_operaciones
                WHERE empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        if (!empty($filters['sucursal_id'])) {
            $sql .= ' AND sucursal_id = :sucursal_id';
            $params['sucursal_id'] = (int) $filters['sucursal_id'];
        }

        if (!empty($filters['tipo_operacion'])) {
            $sql .= ' AND tipo_operacion = :tipo_operacion';
            $params['tipo_operacion'] = strtoupper((string) $filters['tipo_operacion']);
        }

        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND DATE(created_at) >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }

        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND DATE(created_at) <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }

        $sql .= ' ORDER BY id DESC LIMIT 300';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function find(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, tipo_operacion, operacion_id,
                    usuario_id, motivo, estado, afecta_stock, afecta_caja,
                    referencia_stock_movimiento_id, metadata_json, created_at
             FROM anulaciones_operaciones
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function details(int $operationId): array
    {
        $statement = $this->connection->prepare(
            'SELECT ad.id, ad.anulacion_operacion_id, ad.producto_id, p.codigo,
                    p.nombre, ad.cantidad, ad.stock_movimiento_id, ad.created_at
             FROM anulacion_detalles ad
             INNER JOIN productos p ON p.id = ad.producto_id
             WHERE ad.anulacion_operacion_id = :id
             ORDER BY ad.id'
        );
        $statement->execute(['id' => $operationId]);

        return $statement->fetchAll();
    }

    public function stockMovementsForOperation(int $empresaId, int $operationId): array
    {
        $statement = $this->connection->prepare(
            'SELECT sm.id, sm.empresa_id, sm.sucursal_id, sm.producto_id, p.codigo,
                    p.nombre AS producto_nombre, sm.usuario_id, sm.tipo_movimiento,
                    sm.referencia_tipo, sm.referencia_id, sm.cantidad, sm.stock_anterior,
                    sm.stock_nuevo, sm.observacion, sm.created_at
             FROM stock_movimientos sm
             INNER JOIN productos p ON p.id = sm.producto_id
             WHERE sm.empresa_id = :empresa_id
               AND sm.referencia_tipo IN (\'ANULACION_VENTA\', \'REVERSA_COMPRA\')
               AND sm.referencia_id = :referencia_id
             ORDER BY sm.id'
        );
        $statement->execute(['empresa_id' => $empresaId, 'referencia_id' => $operationId]);

        return $statement->fetchAll();
    }
}
