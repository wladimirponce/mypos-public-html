<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class ReporteRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function sucursalExists(int $empresaId, int $sucursalId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM sucursales WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1'
        );
        $statement->execute(['id' => $sucursalId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function resumenVentas(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        $where = $this->closureWhere($sucursalId);
        $statement = $this->connection->prepare(
            "SELECT COALESCE(SUM(total_ventas), 0) AS total_ventas,
                    COALESCE(SUM(total_impuestos), 0) AS total_impuestos,
                    COALESCE(SUM(total_descuentos), 0) AS total_descuentos,
                    COALESCE(SUM(total_margen), 0) AS total_margen_estimado,
                    COALESCE(SUM(cantidad_ventas), 0) AS cantidad_ventas,
                    COUNT(DISTINCT fecha_cierre) AS dias_cerrados
             FROM cierres_diarios
             WHERE {$where}"
        );
        $statement->execute($this->closureParams($empresaId, $sucursalId, $from, $to));
        $summary = $statement->fetch() ?: [];
        $summary['cantidad_productos'] = $this->cantidadProductos($empresaId, $sucursalId, $from, $to);

        return $summary;
    }

    public function ventasPorDia(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        $where = $this->closureWhere($sucursalId);
        $statement = $this->connection->prepare(
            "SELECT fecha_cierre AS fecha,
                    COALESCE(SUM(total_ventas), 0) AS total_ventas,
                    COALESCE(SUM(cantidad_ventas), 0) AS cantidad_ventas,
                    'CERRADO' AS estado
             FROM cierres_diarios
             WHERE {$where}
             GROUP BY fecha_cierre
             ORDER BY fecha_cierre"
        );
        $statement->execute($this->closureParams($empresaId, $sucursalId, $from, $to));

        return $statement->fetchAll();
    }

    public function ventasPorMetodoPago(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        $where = $this->closureWhere($sucursalId, 'c');
        $statement = $this->connection->prepare(
            "SELECT crp.metodo_pago_id,
                    crp.metodo_pago_codigo AS codigo,
                    COALESCE(mp.nombre, crp.metodo_pago_codigo) AS nombre,
                    COALESCE(SUM(crp.total), 0) AS total,
                    COALESCE(SUM(crp.cantidad_operaciones), 0) AS cantidad_operaciones
             FROM cierre_resumen_pagos crp
             INNER JOIN cierres_diarios c ON c.id = crp.cierre_diario_id
             LEFT JOIN metodos_pago mp ON mp.id = crp.metodo_pago_id
             WHERE {$where}
             GROUP BY crp.metodo_pago_id, crp.metodo_pago_codigo, mp.nombre
             ORDER BY total DESC"
        );
        $statement->execute($this->closureParams($empresaId, $sucursalId, $from, $to));

        return $statement->fetchAll();
    }

    public function ventasPorProducto(int $empresaId, ?int $sucursalId, string $from, string $to, int $limit, string $order): array
    {
        $where = $this->closureWhere($sucursalId, 'c');
        $orderBy = $order === 'cantidad' ? 'cantidad_vendida' : 'total_vendido';
        $statement = $this->connection->prepare(
            "SELECT crp.producto_id,
                    crp.codigo_producto AS codigo,
                    crp.nombre_producto AS nombre,
                    COALESCE(SUM(crp.cantidad), 0) AS cantidad_vendida,
                    COALESCE(SUM(crp.total), 0) AS total_vendido,
                    COALESCE(SUM(crp.margen_total), 0) AS margen_estimado
             FROM cierre_resumen_productos crp
             INNER JOIN cierres_diarios c ON c.id = crp.cierre_diario_id
             WHERE {$where}
             GROUP BY crp.producto_id, crp.codigo_producto, crp.nombre_producto
             ORDER BY {$orderBy} DESC
             LIMIT {$limit}"
        );
        $statement->execute($this->closureParams($empresaId, $sucursalId, $from, $to));

        return $statement->fetchAll();
    }

    public function ventasPorRubro(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        $where = $this->closureWhere($sucursalId, 'c');
        $statement = $this->connection->prepare(
            "SELECT crr.rubro_id,
                    COALESCE(crr.nombre_rubro, 'Sin rubro') AS rubro,
                    COALESCE(SUM(crr.cantidad), 0) AS cantidad_vendida,
                    COALESCE(SUM(crr.total), 0) AS total_vendido,
                    COALESCE(SUM(crr.margen_total), 0) AS margen_estimado
             FROM cierre_resumen_rubros crr
             INNER JOIN cierres_diarios c ON c.id = crr.cierre_diario_id
             WHERE {$where}
             GROUP BY crr.rubro_id, crr.nombre_rubro
             ORDER BY total_vendido DESC"
        );
        $statement->execute($this->closureParams($empresaId, $sucursalId, $from, $to));

        return $statement->fetchAll();
    }

    public function ventasPorUsuario(int $empresaId, ?int $sucursalId, string $from, string $to, int $limit = 100): array
    {
        $where = $this->closureWhere($sucursalId, 'c');
        $statement = $this->connection->prepare(
            "SELECT cru.usuario_id,
                    cru.nombre_usuario AS usuario,
                    COALESCE(SUM(cru.cantidad_ventas), 0) AS cantidad_ventas,
                    COALESCE(SUM(cru.total), 0) AS total_vendido,
                    COALESCE(SUM(cru.margen_total), 0) AS margen_estimado
             FROM cierre_resumen_usuarios cru
             INNER JOIN cierres_diarios c ON c.id = cru.cierre_diario_id
             WHERE {$where}
             GROUP BY cru.usuario_id, cru.nombre_usuario
             ORDER BY total_vendido DESC
             LIMIT {$limit}"
        );
        $statement->execute($this->closureParams($empresaId, $sucursalId, $from, $to));

        return $statement->fetchAll();
    }

    private function cantidadProductos(int $empresaId, ?int $sucursalId, string $from, string $to): string
    {
        $where = $this->closureWhere($sucursalId, 'c');
        $statement = $this->connection->prepare(
            "SELECT COALESCE(SUM(crp.cantidad), 0) AS cantidad_productos
             FROM cierre_resumen_productos crp
             INNER JOIN cierres_diarios c ON c.id = crp.cierre_diario_id
             WHERE {$where}"
        );
        $statement->execute($this->closureParams($empresaId, $sucursalId, $from, $to));

        return number_format((float) $statement->fetchColumn(), 3, '.', '');
    }

    private function closureWhere(?int $sucursalId, string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $where = "{$prefix}empresa_id = :empresa_id
            AND {$prefix}fecha_cierre >= :fecha_desde
            AND {$prefix}fecha_cierre <= :fecha_hasta
            AND {$prefix}estado = 'CERRADO'";

        if ($sucursalId !== null) {
            $where .= " AND {$prefix}sucursal_id = :sucursal_id";
        }

        return $where;
    }

    private function closureParams(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        $params = [
            'empresa_id' => $empresaId,
            'fecha_desde' => $from,
            'fecha_hasta' => $to,
        ];

        if ($sucursalId !== null) {
            $params['sucursal_id'] = $sucursalId;
        }

        return $params;
    }
}
