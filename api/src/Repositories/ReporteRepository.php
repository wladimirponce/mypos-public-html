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

    /** @return array<string, mixed> */
    public function resumenVentas(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        $statement = $this->connection->prepare(
            'SELECT COALESCE(SUM(v.total), 0) AS total_ventas,
                    COALESCE(SUM(v.impuesto_total), 0) AS total_impuestos,
                    COALESCE(SUM(v.descuento_total), 0) AS total_descuentos,
                    COALESCE(SUM(v.margen_total), 0) AS total_margen_estimado,
                    COUNT(v.id) AS cantidad_ventas
             FROM ventas v
             WHERE ' . $this->salesWhere($sucursalId, 'v')
        );
        $statement->execute($this->salesParams($empresaId, $sucursalId, $from, $to));
        $fetched = $statement->fetch();
        $summary = is_array($fetched) ? $fetched : [];
        $summary['cantidad_productos'] = $this->cantidadProductos($empresaId, $sucursalId, $from, $to);
        $summary['dias_cerrados'] = $this->closedDays($empresaId, $sucursalId, $from, $to);

        return $summary;
    }

    /** @return array<int, array<string, mixed>> */
    public function ventasPorDia(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        $statement = $this->connection->prepare(
            "SELECT DATE(v.fecha_venta) AS fecha,
                    COALESCE(SUM(v.total), 0) AS total_ventas,
                    COUNT(v.id) AS cantidad_ventas,
                    CASE WHEN COUNT(DISTINCT v.sucursal_id) = COUNT(DISTINCT c.sucursal_id)
                         THEN 'CERRADO' ELSE 'PARCIAL' END AS estado
             FROM ventas v
             LEFT JOIN cierres_diarios c
                    ON c.empresa_id = v.empresa_id
                   AND c.sucursal_id = v.sucursal_id
                   AND c.fecha_cierre = DATE(v.fecha_venta)
                   AND c.estado = 'CERRADO'
             WHERE {$this->salesWhere($sucursalId, 'v')}
             GROUP BY DATE(v.fecha_venta)
             ORDER BY fecha"
        );
        $statement->execute($this->salesParams($empresaId, $sucursalId, $from, $to));

        return $statement->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function ventasPorMetodoPago(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        $statement = $this->connection->prepare(
            "SELECT vp.metodo_pago_id,
                    vp.metodo_pago_codigo AS codigo,
                    COALESCE(mp.nombre, vp.metodo_pago_codigo) AS nombre,
                    COALESCE(SUM(vp.monto), 0) AS total,
                    COUNT(vp.id) AS cantidad_operaciones
             FROM venta_pagos vp
             INNER JOIN ventas v ON v.id = vp.venta_id AND v.empresa_id = vp.empresa_id
             LEFT JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
             WHERE {$this->salesWhere($sucursalId, 'v')}
             GROUP BY vp.metodo_pago_id, vp.metodo_pago_codigo, mp.nombre
             ORDER BY total DESC"
        );
        $statement->execute($this->salesParams($empresaId, $sucursalId, $from, $to));

        return $statement->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function productosPorDia(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        $statement = $this->connection->prepare(
            'SELECT DATE(v.fecha_venta) AS fecha,
                    COALESCE(SUM(vd.cantidad), 0) AS cantidad_productos
             FROM venta_detalles vd
             INNER JOIN ventas v ON v.id = vd.venta_id AND v.empresa_id = vd.empresa_id
             WHERE ' . $this->salesWhere($sucursalId, 'v') . '
             GROUP BY DATE(v.fecha_venta)
             ORDER BY fecha'
        );
        $statement->execute($this->salesParams($empresaId, $sucursalId, $from, $to));

        return $statement->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function ventasPorProducto(int $empresaId, ?int $sucursalId, string $from, string $to, int $limit, string $order): array
    {
        $orderBy = $order === 'cantidad' ? 'cantidad_vendida' : 'total_vendido';
        // GROUP BY solo por producto_id: codigo/nombre en venta_detalles son
        // SNAPSHOTS por venta — si el producto cambio de nombre o codigo entre
        // ventas, agrupar por ellos parte el historial en filas duplicadas
        // (bug real: el mismo producto aparecia 2 veces en el top, 2026-07-04).
        $statement = $this->connection->prepare(
            "SELECT vd.producto_id,
                    MAX(vd.codigo_producto) AS codigo,
                    MAX(vd.nombre_producto) AS nombre,
                    COALESCE(SUM(vd.cantidad), 0) AS cantidad_vendida,
                    COALESCE(SUM(vd.total), 0) AS total_vendido,
                    COALESCE(SUM(vd.margen_total), 0) AS margen_estimado
             FROM venta_detalles vd
             INNER JOIN ventas v ON v.id = vd.venta_id AND v.empresa_id = vd.empresa_id
             WHERE {$this->salesWhere($sucursalId, 'v')}
             GROUP BY vd.producto_id
             ORDER BY {$orderBy} DESC
             LIMIT {$limit}"
        );
        $statement->execute($this->salesParams($empresaId, $sucursalId, $from, $to));

        return $statement->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function ventasPorRubro(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        $statement = $this->connection->prepare(
            "SELECT p.rubro_id,
                    COALESCE(r.nombre, 'Sin rubro') AS rubro,
                    COALESCE(SUM(vd.cantidad), 0) AS cantidad_vendida,
                    COALESCE(SUM(vd.total), 0) AS total_vendido,
                    COALESCE(SUM(vd.margen_total), 0) AS margen_estimado
             FROM venta_detalles vd
             INNER JOIN ventas v ON v.id = vd.venta_id AND v.empresa_id = vd.empresa_id
             INNER JOIN productos p ON p.id = vd.producto_id AND p.empresa_id = vd.empresa_id
             LEFT JOIN rubros r ON r.id = p.rubro_id
             WHERE {$this->salesWhere($sucursalId, 'v')}
             GROUP BY p.rubro_id, r.nombre
             ORDER BY total_vendido DESC"
        );
        $statement->execute($this->salesParams($empresaId, $sucursalId, $from, $to));

        return $statement->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function ventasPorUsuario(int $empresaId, ?int $sucursalId, string $from, string $to, int $limit = 100): array
    {
        $statement = $this->connection->prepare(
            "SELECT v.usuario_id,
                    u.nombre AS usuario,
                    COUNT(v.id) AS cantidad_ventas,
                    COALESCE(SUM(v.total), 0) AS total_vendido,
                    COALESCE(SUM(v.margen_total), 0) AS margen_estimado
             FROM ventas v
             INNER JOIN usuarios u ON u.id = v.usuario_id
             WHERE {$this->salesWhere($sucursalId, 'v')}
             GROUP BY v.usuario_id, u.nombre
             ORDER BY total_vendido DESC
             LIMIT {$limit}"
        );
        $statement->execute($this->salesParams($empresaId, $sucursalId, $from, $to));

        return $statement->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function stockBajo(int $empresaId, ?int $sucursalId): array
    {
        $where = 'ss.empresa_id = :empresa_id
            AND p.controla_stock = 1
            AND p.activo = 1
            AND p.stock_minimo IS NOT NULL
            AND CAST(p.stock_minimo AS DECIMAL(20,3)) > 0
            AND ss.cantidad <= CAST(p.stock_minimo AS DECIMAL(20,3))';
        $params = ['empresa_id' => $empresaId];
        if ($sucursalId !== null) {
            $where .= ' AND ss.sucursal_id = :sucursal_id';
            $params['sucursal_id'] = $sucursalId;
        }

        $statement = $this->connection->prepare(
            "SELECT p.id, p.codigo, p.nombre,
                    COALESCE(r.nombre, 'Sin rubro') AS rubro,
                    CAST(ss.cantidad AS DECIMAL(20,3)) AS stock_actual,
                    CAST(p.stock_minimo AS DECIMAL(20,3)) AS stock_minimo
             FROM stock_sucursal ss
             INNER JOIN productos p ON p.id = ss.producto_id AND p.empresa_id = ss.empresa_id
             LEFT JOIN rubros r ON r.id = p.rubro_id
             WHERE {$where}
             ORDER BY (ss.cantidad - CAST(p.stock_minimo AS DECIMAL(20,3))) ASC
             LIMIT 20"
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    private function cantidadProductos(int $empresaId, ?int $sucursalId, string $from, string $to): string
    {
        $statement = $this->connection->prepare(
            'SELECT COALESCE(SUM(vd.cantidad), 0)
             FROM venta_detalles vd
             INNER JOIN ventas v ON v.id = vd.venta_id AND v.empresa_id = vd.empresa_id
             WHERE ' . $this->salesWhere($sucursalId, 'v')
        );
        $statement->execute($this->salesParams($empresaId, $sucursalId, $from, $to));

        return number_format((float) $statement->fetchColumn(), 3, '.', '');
    }

    /**
     * Días "accionables" pendientes de cierre dentro del rango: días ANTERIORES
     * a hoy que tuvieron ventas y cuyo cierre no está completo (alguna sucursal
     * con ventas ese día sigue sin cierre CERRADO). NO cuenta hoy (en curso) ni
     * días sin ventas (no hay nada que cerrar).
     */
    public function diasPendientesDeCierre(int $empresaId, ?int $sucursalId, string $from, string $to, string $today): int
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM (
                SELECT DATE(v.fecha_venta) AS fecha
                FROM ventas v
                LEFT JOIN cierres_diarios c
                       ON c.empresa_id = v.empresa_id
                      AND c.sucursal_id = v.sucursal_id
                      AND c.fecha_cierre = DATE(v.fecha_venta)
                      AND c.estado = 'CERRADO'
                WHERE {$this->salesWhere($sucursalId, 'v')}
                  AND DATE(v.fecha_venta) < :hoy
                GROUP BY DATE(v.fecha_venta)
                HAVING COUNT(DISTINCT v.sucursal_id) <> COUNT(DISTINCT c.sucursal_id)
            ) t"
        );
        $params = $this->salesParams($empresaId, $sucursalId, $from, $to);
        $params['hoy'] = $today;
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    private function closedDays(int $empresaId, ?int $sucursalId, string $from, string $to): int
    {
        $where = 'empresa_id = :empresa_id
            AND fecha_cierre >= :fecha_desde
            AND fecha_cierre <= :fecha_hasta
            AND estado = \'CERRADO\'';
        if ($sucursalId !== null) {
            $where .= ' AND sucursal_id = :sucursal_id';
        }

        $statement = $this->connection->prepare("SELECT COUNT(DISTINCT fecha_cierre) FROM cierres_diarios WHERE {$where}");
        $statement->execute($this->closureParams($empresaId, $sucursalId, $from, $to));

        return (int) $statement->fetchColumn();
    }

    private function salesWhere(?int $sucursalId, string $alias): string
    {
        $where = "{$alias}.empresa_id = :empresa_id
            AND {$alias}.fecha_venta >= :fecha_desde
            AND {$alias}.fecha_venta < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)
            AND {$alias}.estado <> 'ANULADA'";
        if ($sucursalId !== null) {
            $where .= " AND {$alias}.sucursal_id = :sucursal_id";
        }

        return $where;
    }

    /** @return array<string, int|string> */
    private function salesParams(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        return $this->closureParams($empresaId, $sucursalId, $from, $to);
    }

    /** @return array<string, int|string> */
    private function closureParams(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        $params = ['empresa_id' => $empresaId, 'fecha_desde' => $from, 'fecha_hasta' => $to];
        if ($sucursalId !== null) {
            $params['sucursal_id'] = $sucursalId;
        }

        return $params;
    }
}
