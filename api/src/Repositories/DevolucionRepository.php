<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class DevolucionRepository
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
            'SELECT id, empresa_id, sucursal_id, cliente_id, estado, anulada_at, fecha_venta, total
             FROM ventas
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $saleId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function saleDetails(int $empresaId, int $saleId): array
    {
        $statement = $this->connection->prepare(
            'SELECT vd.id, vd.producto_id, vd.nombre_producto, vd.cantidad, vd.precio_unitario,
                    vd.total, vd.costo_unitario, p.controla_stock,
                    COALESCE((
                        SELECT SUM(dd.cantidad)
                        FROM devolucion_detalles dd
                        WHERE dd.venta_detalle_id = vd.id AND dd.empresa_id = vd.empresa_id
                    ), 0) AS cantidad_devuelta
             FROM venta_detalles vd
             INNER JOIN productos p ON p.id = vd.producto_id
             WHERE vd.venta_id = :venta_id AND vd.empresa_id = :empresa_id
             ORDER BY vd.linea'
        );
        $statement->execute(['venta_id' => $saleId, 'empresa_id' => $empresaId]);

        return $statement->fetchAll();
    }

    public function saleHasEmittedDocument(int $empresaId, int $saleId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1 FROM documentos
             WHERE empresa_id = :empresa_id AND venta_id = :venta_id
               AND estado NOT IN ('ANULADO', 'BORRADOR')
             LIMIT 1"
        );
        $statement->execute(['empresa_id' => $empresaId, 'venta_id' => $saleId]);

        return (bool) $statement->fetchColumn();
    }

    public function insertDevolucion(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO devoluciones (
                empresa_id, sucursal_id, venta_id, usuario_id, caja_apertura_id,
                tipo_reembolso, vale_id, egreso_id, monto_total, motivo, destino,
                nc_estado, metadata_json
             ) VALUES (
                :empresa_id, :sucursal_id, :venta_id, :usuario_id, :caja_apertura_id,
                :tipo_reembolso, :vale_id, :egreso_id, :monto_total, :motivo, :destino,
                :nc_estado, :metadata_json
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function updateReembolso(int $id, ?int $valeId, ?int $egresoId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE devoluciones SET vale_id = :vale_id, egreso_id = :egreso_id WHERE id = :id'
        );
        $statement->execute(['vale_id' => $valeId, 'egreso_id' => $egresoId, 'id' => $id]);
    }

    public function insertDetalle(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO devolucion_detalles (
                empresa_id, devolucion_id, venta_detalle_id, producto_id,
                cantidad, precio_unitario, total_linea, stock_movimiento_id
             ) VALUES (
                :empresa_id, :devolucion_id, :venta_detalle_id, :producto_id,
                :cantidad, :precio_unitario, :total_linea, :stock_movimiento_id
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function listar(int $empresaId, array $filters): array
    {
        $sql = 'SELECT d.*, u.nombre AS usuario_nombre, v.total AS venta_total,
                       vc.codigo AS vale_codigo
                FROM devoluciones d
                LEFT JOIN usuarios u ON u.id = d.usuario_id
                LEFT JOIN ventas v ON v.id = d.venta_id
                LEFT JOIN vales_credito vc ON vc.id = d.vale_id
                WHERE d.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        if (!empty($filters['venta_id'])) {
            $sql .= ' AND d.venta_id = :venta_id';
            $params['venta_id'] = (int) $filters['venta_id'];
        }
        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND d.created_at >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'] . ' 00:00:00';
        }
        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND d.created_at <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'] . ' 23:59:59';
        }

        $sql .= ' ORDER BY d.id DESC LIMIT 200';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function find(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT d.*, u.nombre AS usuario_nombre, vc.codigo AS vale_codigo, vc.saldo AS vale_saldo
             FROM devoluciones d
             LEFT JOIN usuarios u ON u.id = d.usuario_id
             LEFT JOIN vales_credito vc ON vc.id = d.vale_id
             WHERE d.empresa_id = :empresa_id AND d.id = :id
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function detalles(int $empresaId, int $devolucionId): array
    {
        $statement = $this->connection->prepare(
            'SELECT dd.*, p.nombre AS producto_nombre
             FROM devolucion_detalles dd
             LEFT JOIN productos p ON p.id = dd.producto_id
             WHERE dd.empresa_id = :empresa_id AND dd.devolucion_id = :devolucion_id
             ORDER BY dd.id'
        );
        $statement->execute(['empresa_id' => $empresaId, 'devolucion_id' => $devolucionId]);

        return $statement->fetchAll();
    }
}
