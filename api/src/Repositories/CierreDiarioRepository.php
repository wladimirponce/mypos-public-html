<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class CierreDiarioRepository
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

    public function findByDateForUpdate(int $empresaId, int $sucursalId, string $date): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, estado
             FROM cierres_diarios
             WHERE empresa_id = :empresa_id AND sucursal_id = :sucursal_id AND fecha_cierre = :fecha_cierre
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId, 'fecha_cierre' => $date]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function saleTotals(int $empresaId, int $sucursalId, string $date): array
    {
        $statement = $this->connection->prepare(
            'SELECT COALESCE(SUM(total), 0) AS total_ventas,
                    COALESCE(SUM(descuento_total), 0) AS total_descuentos,
                    COALESCE(SUM(impuesto_total), 0) AS total_impuestos,
                    COALESCE(SUM(margen_total), 0) AS total_margen,
                    COALESCE(SUM(comision_total), 0) AS total_comisiones,
                    COUNT(id) AS cantidad_ventas
             FROM ventas
             WHERE empresa_id = :empresa_id
               AND sucursal_id = :sucursal_id
               AND DATE(fecha_venta) = :fecha_cierre
               AND estado = \'EMITIDA\''
        );
        $statement->execute(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId, 'fecha_cierre' => $date]);

        return $statement->fetch();
    }

    public function createClosure(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cierres_diarios (
                empresa_id, sucursal_id, usuario_id, fecha_cierre, estado,
                total_ventas, total_descuentos, total_impuestos, total_margen,
                total_comisiones, cantidad_ventas, cerrado_at
             ) VALUES (
                :empresa_id, :sucursal_id, :usuario_id, :fecha_cierre, \'CERRADO\',
                :total_ventas, :total_descuentos, :total_impuestos, :total_margen,
                :total_comisiones, :cantidad_ventas, CURRENT_TIMESTAMP
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function updateClosure(int $id, array $data): void
    {
        $data['id'] = $id;
        $statement = $this->connection->prepare(
            'UPDATE cierres_diarios
             SET usuario_id = :usuario_id,
                 estado = \'CERRADO\',
                 total_ventas = :total_ventas,
                 total_descuentos = :total_descuentos,
                 total_impuestos = :total_impuestos,
                 total_margen = :total_margen,
                 total_comisiones = :total_comisiones,
                 cantidad_ventas = :cantidad_ventas,
                 cerrado_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute($data);
    }

    public function clearSummaries(int $closureId): void
    {
        foreach (['cierre_resumen_pagos', 'cierre_resumen_productos', 'cierre_resumen_rubros', 'cierre_resumen_usuarios'] as $table) {
            $statement = $this->connection->prepare("DELETE FROM {$table} WHERE cierre_diario_id = :cierre_id");
            $statement->execute(['cierre_id' => $closureId]);
        }
    }

    public function insertPaymentSummaries(int $closureId, int $empresaId, int $sucursalId, string $date): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cierre_resumen_pagos (empresa_id, cierre_diario_id, metodo_pago_id, metodo_pago_codigo, cantidad_operaciones, total)
             SELECT :empresa_id, :cierre_id, vp.metodo_pago_id, vp.metodo_pago_codigo, COUNT(vp.id), COALESCE(SUM(vp.monto), 0)
             FROM venta_pagos vp
             INNER JOIN ventas v ON v.id = vp.venta_id
             WHERE v.empresa_id = :empresa_id_filter
               AND v.sucursal_id = :sucursal_id
               AND DATE(v.fecha_venta) = :fecha_cierre
               AND v.estado = \'EMITIDA\'
             GROUP BY vp.metodo_pago_id, vp.metodo_pago_codigo'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'cierre_id' => $closureId,
            'empresa_id_filter' => $empresaId,
            'sucursal_id' => $sucursalId,
            'fecha_cierre' => $date,
        ]);
    }

    public function insertProductSummaries(int $closureId, int $empresaId, int $sucursalId, string $date): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cierre_resumen_productos (empresa_id, cierre_diario_id, producto_id, codigo_producto, nombre_producto, cantidad, total, margen_total, comision_total)
             SELECT :empresa_id, :cierre_id, vd.producto_id, vd.codigo_producto, vd.nombre_producto,
                    COALESCE(SUM(vd.cantidad), 0), COALESCE(SUM(vd.total), 0),
                    COALESCE(SUM(vd.margen_total), 0), COALESCE(SUM(vd.comision_total), 0)
             FROM venta_detalles vd
             INNER JOIN ventas v ON v.id = vd.venta_id
             WHERE v.empresa_id = :empresa_id_filter
               AND v.sucursal_id = :sucursal_id
               AND DATE(v.fecha_venta) = :fecha_cierre
               AND v.estado = \'EMITIDA\'
             GROUP BY vd.producto_id, vd.codigo_producto, vd.nombre_producto'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'cierre_id' => $closureId,
            'empresa_id_filter' => $empresaId,
            'sucursal_id' => $sucursalId,
            'fecha_cierre' => $date,
        ]);
    }

    public function insertRubroSummaries(int $closureId, int $empresaId, int $sucursalId, string $date): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cierre_resumen_rubros (empresa_id, cierre_diario_id, rubro_id, nombre_rubro, cantidad, total, margen_total)
             SELECT :empresa_id, :cierre_id, p.rubro_id, COALESCE(r.nombre, \'Sin rubro\'),
                    COALESCE(SUM(vd.cantidad), 0), COALESCE(SUM(vd.total), 0),
                    COALESCE(SUM(vd.margen_total), 0)
             FROM venta_detalles vd
             INNER JOIN ventas v ON v.id = vd.venta_id
             INNER JOIN productos p ON p.id = vd.producto_id
             LEFT JOIN rubros r ON r.id = p.rubro_id
             WHERE v.empresa_id = :empresa_id_filter
               AND v.sucursal_id = :sucursal_id
               AND DATE(v.fecha_venta) = :fecha_cierre
               AND v.estado = \'EMITIDA\'
             GROUP BY p.rubro_id, r.nombre'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'cierre_id' => $closureId,
            'empresa_id_filter' => $empresaId,
            'sucursal_id' => $sucursalId,
            'fecha_cierre' => $date,
        ]);
    }

    public function insertUserSummaries(int $closureId, int $empresaId, int $sucursalId, string $date): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cierre_resumen_usuarios (empresa_id, cierre_diario_id, usuario_id, nombre_usuario, cantidad_ventas, total, margen_total, comision_total)
             SELECT :empresa_id, :cierre_id, v.usuario_id, u.nombre, COUNT(v.id),
                    COALESCE(SUM(v.total), 0), COALESCE(SUM(v.margen_total), 0),
                    COALESCE(SUM(v.comision_total), 0)
             FROM ventas v
             INNER JOIN usuarios u ON u.id = v.usuario_id
             WHERE v.empresa_id = :empresa_id_filter
               AND v.sucursal_id = :sucursal_id
               AND DATE(v.fecha_venta) = :fecha_cierre
               AND v.estado = \'EMITIDA\'
             GROUP BY v.usuario_id, u.nombre'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'cierre_id' => $closureId,
            'empresa_id_filter' => $empresaId,
            'sucursal_id' => $sucursalId,
            'fecha_cierre' => $date,
        ]);
    }

    public function listClosures(int $empresaId, int $sucursalId, ?string $from, ?string $to): array
    {
        $sql = 'SELECT id, empresa_id, sucursal_id, usuario_id, fecha_cierre, estado,
                       total_ventas, total_descuentos, total_impuestos, total_margen,
                       total_comisiones, cantidad_ventas, cerrado_at
                FROM cierres_diarios
                WHERE empresa_id = :empresa_id AND sucursal_id = :sucursal_id';
        $params = ['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId];

        if ($from !== null) {
            $sql .= ' AND fecha_cierre >= :fecha_desde';
            $params['fecha_desde'] = $from;
        }

        if ($to !== null) {
            $sql .= ' AND fecha_cierre <= :fecha_hasta';
            $params['fecha_hasta'] = $to;
        }

        $sql .= ' ORDER BY fecha_cierre DESC';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function detail(int $id, int $empresaId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, usuario_id, fecha_cierre, estado,
                    total_ventas, total_descuentos, total_impuestos, total_margen,
                    total_comisiones, cantidad_ventas, cerrado_at
             FROM cierres_diarios
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function summaryPayments(int $closureId): array
    {
        return $this->fetchSummary(
            'SELECT id, empresa_id, cierre_diario_id, metodo_pago_id, metodo_pago_codigo, cantidad_operaciones, total
             FROM cierre_resumen_pagos
             WHERE cierre_diario_id = :cierre_id
             ORDER BY id',
            $closureId
        );
    }

    public function summaryProducts(int $closureId): array
    {
        return $this->fetchSummary(
            'SELECT id, empresa_id, cierre_diario_id, producto_id, codigo_producto, nombre_producto,
                    cantidad, total, margen_total, comision_total
             FROM cierre_resumen_productos
             WHERE cierre_diario_id = :cierre_id
             ORDER BY id',
            $closureId
        );
    }

    public function summaryRubros(int $closureId): array
    {
        return $this->fetchSummary(
            'SELECT id, empresa_id, cierre_diario_id, rubro_id, nombre_rubro, cantidad, total, margen_total
             FROM cierre_resumen_rubros
             WHERE cierre_diario_id = :cierre_id
             ORDER BY id',
            $closureId
        );
    }

    public function summaryUsers(int $closureId): array
    {
        return $this->fetchSummary(
            'SELECT id, empresa_id, cierre_diario_id, usuario_id, nombre_usuario, cantidad_ventas,
                    total, margen_total, comision_total
             FROM cierre_resumen_usuarios
             WHERE cierre_diario_id = :cierre_id
             ORDER BY id',
            $closureId
        );
    }

    private function fetchSummary(string $sql, int $closureId): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute(['cierre_id' => $closureId]);

        return $statement->fetchAll();
    }
}
