<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class StockRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function sucursalExists(int $empresaId, int $sucursalId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM sucursales WHERE id = :sucursal_id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId]);

        return (bool) $statement->fetchColumn();
    }

    public function productoStockData(int $empresaId, int $productoId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, codigo, sku, nombre, controla_stock
             FROM productos
             WHERE id = :producto_id AND empresa_id = :empresa_id AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'producto_id' => $productoId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function ensureStockRow(int $empresaId, int $sucursalId, int $productoId): void
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO stock_sucursal (empresa_id, sucursal_id, producto_id, cantidad, reservado, stock_minimo)
             VALUES (:empresa_id, :sucursal_id, :producto_id, 0.000, 0.000, 0.000)'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'producto_id' => $productoId,
        ]);
    }

    public function lockStockRow(int $empresaId, int $sucursalId, int $productoId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, producto_id, cantidad, reservado, stock_minimo
             FROM stock_sucursal
             WHERE empresa_id = :empresa_id AND sucursal_id = :sucursal_id AND producto_id = :producto_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'producto_id' => $productoId,
        ]);

        return $statement->fetch();
    }

    public function updateQuantity(int $stockId, string $newQuantity): void
    {
        $statement = $this->connection->prepare(
            'UPDATE stock_sucursal SET cantidad = :cantidad, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $stockId, 'cantidad' => $newQuantity]);
    }

    public function insertMovement(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO stock_movimientos (
                uuid, empresa_id, sucursal_id, dispositivo_id, producto_id, usuario_id,
                tipo_movimiento, referencia_tipo, referencia_id, cantidad, stock_anterior,
                stock_nuevo, costo_unitario, observacion
             ) VALUES (
                :uuid, :empresa_id, :sucursal_id, :dispositivo_id, :producto_id, :usuario_id,
                :tipo_movimiento, :referencia_tipo, :referencia_id, :cantidad, :stock_anterior,
                :stock_nuevo, :costo_unitario, :observacion
             )'
        );
        $statement->execute([
            'uuid' => $data['uuid'] ?? null,
            'empresa_id' => $data['empresa_id'],
            'sucursal_id' => $data['sucursal_id'],
            'dispositivo_id' => $data['dispositivo_id'] ?? null,
            'producto_id' => $data['producto_id'],
            'usuario_id' => $data['usuario_id'] ?? null,
            'tipo_movimiento' => $data['tipo_movimiento'],
            'referencia_tipo' => $data['referencia_tipo'] ?? null,
            'referencia_id' => $data['referencia_id'] ?? null,
            'cantidad' => $data['cantidad'],
            'stock_anterior' => $data['stock_anterior'],
            'stock_nuevo' => $data['stock_nuevo'],
            'costo_unitario' => $data['costo_unitario'] ?? 0,
            'observacion' => $data['observacion'] ?? null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function getStockProduct(int $empresaId, int $sucursalId, int $productoId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT ss.producto_id, p.codigo, p.nombre, r.nombre AS rubro,
                    ss.cantidad, ss.reservado, (ss.cantidad - ss.reservado) AS disponible, ss.stock_minimo
             FROM stock_sucursal ss
             INNER JOIN productos p ON p.id = ss.producto_id
             LEFT JOIN rubros r ON r.id = p.rubro_id
             WHERE ss.empresa_id = :empresa_id
               AND ss.sucursal_id = :sucursal_id
               AND ss.producto_id = :producto_id
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'producto_id' => $productoId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function listStock(int $empresaId, int $sucursalId, ?string $q): array
    {
        $sql = 'SELECT p.id AS producto_id, p.codigo, p.nombre, r.nombre AS rubro,
                       COALESCE(ss.cantidad, 0.000) AS cantidad,
                       COALESCE(ss.reservado, 0.000) AS reservado,
                       (COALESCE(ss.cantidad, 0.000) - COALESCE(ss.reservado, 0.000)) AS disponible,
                       COALESCE(ss.stock_minimo, p.stock_minimo, 0.000) AS stock_minimo
                FROM productos p
                LEFT JOIN rubros r ON r.id = p.rubro_id
                LEFT JOIN stock_sucursal ss ON ss.producto_id = p.id
                    AND ss.empresa_id = p.empresa_id
                    AND ss.sucursal_id = :sucursal_id
                WHERE p.empresa_id = :empresa_id
                  AND p.activo = 1
                  AND p.controla_stock = 1';
        $params = ['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId];

        if ($q !== null && trim($q) !== '') {
            $sql .= ' AND (p.nombre LIKE :q OR p.codigo LIKE :q OR p.sku LIKE :q)';
            $params['q'] = '%' . trim($q) . '%';
        }

        $sql .= ' ORDER BY p.nombre LIMIT 300';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function listMovements(int $empresaId, int $sucursalId, ?int $productoId): array
    {
        $sql = 'SELECT sm.id, sm.uuid, sm.empresa_id, sm.sucursal_id, sm.producto_id,
                       p.codigo, p.nombre AS producto_nombre, sm.usuario_id, sm.dispositivo_id,
                       sm.tipo_movimiento, sm.referencia_tipo, sm.referencia_id, sm.cantidad,
                       sm.stock_anterior, sm.stock_nuevo, sm.costo_unitario, sm.observacion,
                       sm.created_at
                FROM stock_movimientos sm
                INNER JOIN productos p ON p.id = sm.producto_id
                WHERE sm.empresa_id = :empresa_id AND sm.sucursal_id = :sucursal_id';
        $params = ['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId];

        if ($productoId !== null && $productoId > 0) {
            $sql .= ' AND sm.producto_id = :producto_id';
            $params['producto_id'] = $productoId;
        }

        $sql .= ' ORDER BY sm.id DESC LIMIT 300';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function findMovement(int $empresaId, int $movementId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, uuid, empresa_id, sucursal_id, dispositivo_id, producto_id, usuario_id,
                    tipo_movimiento, referencia_tipo, referencia_id, cantidad, stock_anterior,
                    stock_nuevo, costo_unitario, observacion
             FROM stock_movimientos
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $movementId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }
}
