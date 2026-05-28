<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class CompraRepository
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
        $statement = $this->connection->prepare('SELECT 1 FROM sucursales WHERE id = :sucursal_id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1');
        $statement->execute(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId]);

        return (bool) $statement->fetchColumn();
    }

    public function proveedorExists(int $empresaId, ?int $proveedorId): bool
    {
        if ($proveedorId === null) {
            return true;
        }

        $statement = $this->connection->prepare('SELECT 1 FROM proveedores WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1');
        $statement->execute(['id' => $proveedorId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function product(int $empresaId, int $productId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, codigo, sku, nombre, controla_stock
             FROM productos
             WHERE id = :id AND empresa_id = :empresa_id AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $productId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function insertPurchase(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO compras (
                empresa_id, sucursal_id, proveedor_id, usuario_id, tipo_documento, folio,
                fecha_documento, fecha_ingreso, estado, subtotal, impuesto_total, total, observacion
             ) VALUES (
                :empresa_id, :sucursal_id, :proveedor_id, :usuario_id, :tipo_documento, :folio,
                :fecha_documento, :fecha_ingreso, :estado, :subtotal, :impuesto_total, :total, :observacion
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function insertDetail(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO compra_detalles (
                empresa_id, compra_id, producto_id, linea, codigo_producto, codigo_barra_usado,
                nombre_producto, cantidad, costo_unitario, neto, iva, impuesto_total, total
             ) VALUES (
                :empresa_id, :compra_id, :producto_id, :linea, :codigo_producto, :codigo_barra_usado,
                :nombre_producto, :cantidad, :costo_unitario, :neto, :iva, :impuesto_total, :total
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function findForUpdate(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, proveedor_id, usuario_id, tipo_documento, folio,
                    fecha_documento, fecha_ingreso, estado, subtotal, impuesto_total, total, observacion
             FROM compras
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function find(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT c.id, c.empresa_id, c.sucursal_id, s.nombre AS sucursal_nombre,
                    c.proveedor_id, p.razon_social AS proveedor_razon_social,
                    c.usuario_id, u.nombre AS usuario_nombre, c.tipo_documento, c.folio,
                    c.fecha_documento, c.fecha_ingreso, c.estado, c.subtotal,
                    c.impuesto_total, c.total, c.observacion, c.created_at
             FROM compras c
             INNER JOIN sucursales s ON s.id = c.sucursal_id
             INNER JOIN usuarios u ON u.id = c.usuario_id
             LEFT JOIN proveedores p ON p.id = c.proveedor_id
             WHERE c.id = :id AND c.empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function details(int $empresaId, int $purchaseId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, compra_id, producto_id, linea, codigo_producto,
                    codigo_barra_usado, nombre_producto, cantidad, costo_unitario,
                    neto, iva, impuesto_total, total
             FROM compra_detalles
             WHERE empresa_id = :empresa_id AND compra_id = :compra_id
             ORDER BY linea'
        );
        $statement->execute(['empresa_id' => $empresaId, 'compra_id' => $purchaseId]);

        return $statement->fetchAll();
    }

    public function markConfirmed(int $empresaId, int $id): void
    {
        $statement = $this->connection->prepare(
            'UPDATE compras SET estado = \'CONFIRMADA\', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
    }

    public function markCancelled(int $empresaId, int $id, ?string $reason): void
    {
        $statement = $this->connection->prepare(
            'UPDATE compras
             SET estado = \'ANULADA\', motivo_anulacion = :motivo, anulada_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId, 'motivo' => $reason]);
    }

    public function list(int $empresaId, array $filters): array
    {
        $sql = 'SELECT id, empresa_id, sucursal_id, proveedor_id, usuario_id, tipo_documento, folio,
                       fecha_documento, fecha_ingreso, estado, subtotal, impuesto_total, total, observacion, created_at
                FROM compras
                WHERE empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        foreach (['sucursal_id', 'proveedor_id'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND {$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }

        foreach (['estado', 'tipo_documento'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND {$field} = :{$field}";
                $params[$field] = $filters[$field];
            }
        }

        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND fecha_documento >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }

        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND fecha_documento <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }

        $sql .= ' ORDER BY id DESC LIMIT 300';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }
}
