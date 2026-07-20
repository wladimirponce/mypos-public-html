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

    /** @return array<string, int> */
    public function calidadDatos(int $empresaId): array
    {
        $queries = [
            'productos_activos' => 'SELECT COUNT(*) FROM productos p WHERE p.empresa_id = :empresa_id AND p.activo = 1',
            'productos_sin_costo' => 'SELECT COUNT(*) FROM productos p WHERE p.empresa_id = :empresa_id AND p.activo = 1 AND p.costo_actual <= 0',
            'productos_sin_rubro' => 'SELECT COUNT(*) FROM productos p WHERE p.empresa_id = :empresa_id AND p.activo = 1 AND p.rubro_id IS NULL',
            'productos_sin_proveedor' => 'SELECT COUNT(*) FROM productos p WHERE p.empresa_id = :empresa_id AND p.activo = 1 AND NOT EXISTS (SELECT 1 FROM proveedor_productos pp WHERE pp.empresa_id = p.empresa_id AND pp.producto_id = p.id AND pp.activo = 1 AND pp.deleted_at IS NULL)',
            'productos_sin_parametros_stock' => 'SELECT COUNT(*) FROM productos p WHERE p.empresa_id = :empresa_id AND p.activo = 1 AND p.controla_stock = 1 AND NOT EXISTS (SELECT 1 FROM stock_ubicacion su WHERE su.empresa_id = p.empresa_id AND su.producto_id = p.id AND su.punto_reorden > 0 AND su.stock_maximo > 0)',
            'relaciones_sin_plazo_entrega' => 'SELECT COUNT(*) FROM proveedor_productos pp WHERE pp.empresa_id = :empresa_id AND pp.activo = 1 AND pp.deleted_at IS NULL AND (pp.plazo_entrega_dias IS NULL OR pp.plazo_entrega_dias = 0)',
            'ventas_sin_costo_historico' => 'SELECT COUNT(DISTINCT vd.producto_id) FROM venta_detalles vd INNER JOIN ventas v ON v.id = vd.venta_id AND v.empresa_id = vd.empresa_id WHERE vd.empresa_id = :empresa_id AND v.estado = \'REGISTRADA\' AND v.fecha_venta >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND vd.costo_unitario <= 0',
            'productos_lote_sin_vencimiento' => 'SELECT COUNT(DISTINCT p.id) FROM productos p INNER JOIN stock_ubicacion su ON su.empresa_id = p.empresa_id AND su.producto_id = p.id AND su.cantidad > 0 WHERE p.empresa_id = :empresa_id AND p.activo = 1 AND p.requiere_lote = 1 AND NOT EXISTS (SELECT 1 FROM stock_lotes sl WHERE sl.empresa_id = p.empresa_id AND sl.producto_id = p.id AND sl.activo = 1 AND sl.fecha_vencimiento IS NOT NULL)',
        ];

        $resultado = [];
        foreach ($queries as $key => $sql) {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute(['empresa_id' => $empresaId]);
            $resultado[$key] = (int) $stmt->fetchColumn();
        }
        return $resultado;
    }

    /** @return array<string, mixed> */
    public function analiticaAvanzada(int $empresaId, string $desde, string $hasta): array
    {
        $stmt = $this->connection->prepare(
            'SELECT COALESCE(SUM(ventas),0) ventas, COALESCE(SUM(unidades),0) unidades,
                    COALESCE(SUM(total),0) total, COALESCE(SUM(descuentos),0) descuentos,
                    COALESCE(SUM(margen),0) margen, COALESCE(SUM(anulaciones),0) anulaciones
             FROM analytics_ventas_diarias
             WHERE empresa_id=:empresa_id AND fecha BETWEEN :desde AND :hasta'
        );
        $stmt->execute(['empresa_id' => $empresaId, 'desde' => $desde, 'hasta' => $hasta]);
        $resumen = $stmt->fetch() ?: [];

        $stmt = $this->connection->prepare(
            'SELECT hora, SUM(ventas) ventas, SUM(total) total
             FROM analytics_ventas_diarias
             WHERE empresa_id=:empresa_id AND fecha BETWEEN :desde AND :hasta
             GROUP BY hora ORDER BY hora'
        );
        $stmt->execute(['empresa_id' => $empresaId, 'desde' => $desde, 'hasta' => $hasta]);
        $horarios = $stmt->fetchAll();

        $stmt = $this->connection->prepare(
            'SELECT a.producto_id,p.nombre,p.sku,
                    SUM(a.unidades_vendidas) unidades_vendidas,SUM(a.venta_neta) venta_neta,SUM(a.margen) margen,
                    MAX(a.stock_cierre) stock_actual,MAX(a.valor_stock) capital_inmovilizado,
                    CASE WHEN SUM(a.unidades_vendidas)>0
                         THEN ROUND(MAX(a.stock_cierre)/(SUM(a.unidades_vendidas)/GREATEST(DATEDIFF(:hasta_dias,:desde_dias)+1,1)),1)
                         ELSE NULL END dias_inventario
             FROM analytics_producto_sucursal_diario a
             INNER JOIN productos p ON p.id=a.producto_id AND p.empresa_id=a.empresa_id
             WHERE a.empresa_id=:empresa_id AND a.fecha BETWEEN :desde AND :hasta
             GROUP BY a.producto_id,p.nombre,p.sku
             ORDER BY unidades_vendidas DESC, p.nombre ASC LIMIT 100'
        );
        $stmt->execute([
            'empresa_id' => $empresaId, 'desde' => $desde, 'hasta' => $hasta,
            'desde_dias' => $desde, 'hasta_dias' => $hasta,
        ]);
        $productos = $stmt->fetchAll();

        $stmt = $this->connection->prepare(
            'SELECT a.sucursal_id,s.nombre,SUM(a.ventas) ventas,SUM(a.total) total,SUM(a.margen) margen,SUM(a.anulaciones) anulaciones
             FROM analytics_ventas_diarias a INNER JOIN sucursales s ON s.id=a.sucursal_id AND s.empresa_id=a.empresa_id
             WHERE a.empresa_id=:empresa_id AND a.fecha BETWEEN :desde AND :hasta
             GROUP BY a.sucursal_id,s.nombre ORDER BY total DESC'
        );
        $stmt->execute(['empresa_id' => $empresaId, 'desde' => $desde, 'hasta' => $hasta]);
        $sucursales = $stmt->fetchAll();

        $stmt = $this->connection->prepare(
            'SELECT a.proveedor_id,p.razon_social proveedor,a.ordenes_recibidas,a.entregas_atrasadas,a.plazo_real_promedio,a.cumplimiento_pct
             FROM analytics_proveedor_desempeno a INNER JOIN proveedores p ON p.id=a.proveedor_id AND p.empresa_id=a.empresa_id
             WHERE a.empresa_id=:empresa_id AND a.fecha_calculo=(SELECT MAX(x.fecha_calculo) FROM analytics_proveedor_desempeno x WHERE x.empresa_id=a.empresa_id)
             ORDER BY a.cumplimiento_pct DESC, p.razon_social'
        );
        $stmt->execute(['empresa_id' => $empresaId]);

        return [
            'resumen' => $resumen,
            'ventas_por_hora' => $horarios,
            'productos' => $productos,
            'sucursales' => $sucursales,
            'proveedores' => $stmt->fetchAll(),
        ];
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

    /**
     * Resumen de capital inmovilizado y stock muerto (P8). "Muerto" = producto
     * con stock > 0 y SIN ventas en los últimos $dias días. Valor = stock ×
     * costo actual (o precio_costo si no hay costo).
     *
     * @return array{items_total:int, valor_inventario_total:int, items_muertos:int, valor_stock_muerto:int}
     */
    public function capitalInmovilizadoResumen(int $empresaId, int $dias): array
    {
        $stmt = $this->connection->prepare(
            'SELECT COUNT(*) AS items_total,
                    COALESCE(SUM(valor), 0)                              AS valor_inventario_total,
                    COALESCE(SUM(muerto), 0)                             AS items_muertos,
                    COALESCE(SUM(CASE WHEN muerto = 1 THEN valor ELSE 0 END), 0) AS valor_stock_muerto
             FROM (
                SELECT SUM(su.cantidad) * COALESCE(NULLIF(p.costo_actual, 0), p.precio_costo) AS valor,
                       CASE WHEN NOT EXISTS (
                            SELECT 1 FROM stock_movimientos sm
                             WHERE sm.empresa_id = p.empresa_id AND sm.producto_id = p.id
                               AND sm.tipo_movimiento = \'VENTA\' AND sm.cantidad < 0
                               AND sm.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL :dias DAY)
                       ) THEN 1 ELSE 0 END AS muerto
                FROM productos p
                INNER JOIN stock_ubicacion su
                        ON su.producto_id = p.id AND su.empresa_id = p.empresa_id
                WHERE p.empresa_id = :empresa_id AND p.activo = 1
                GROUP BY p.id
                HAVING SUM(su.cantidad) > 0
             ) t'
        );
        $stmt->execute(['dias' => max(1, $dias), 'empresa_id' => $empresaId]);
        $row = $stmt->fetch();

        return [
            'items_total'            => (int) ($row['items_total'] ?? 0),
            'valor_inventario_total' => (int) round((float) ($row['valor_inventario_total'] ?? 0)),
            'items_muertos'          => (int) ($row['items_muertos'] ?? 0),
            'valor_stock_muerto'     => (int) round((float) ($row['valor_stock_muerto'] ?? 0)),
        ];
    }

    /**
     * Top productos de stock muerto (sin ventas en $dias) por valor inmovilizado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stockMuertoTop(int $empresaId, int $dias, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->connection->prepare(
            'SELECT p.id, p.codigo, p.nombre,
                    SUM(su.cantidad) AS stock,
                    COALESCE(NULLIF(p.costo_actual, 0), p.precio_costo) AS costo,
                    SUM(su.cantidad) * COALESCE(NULLIF(p.costo_actual, 0), p.precio_costo) AS valor,
                    (SELECT MAX(sm.created_at) FROM stock_movimientos sm
                      WHERE sm.empresa_id = p.empresa_id AND sm.producto_id = p.id
                        AND sm.tipo_movimiento = \'VENTA\' AND sm.cantidad < 0) AS ultima_venta
             FROM productos p
             INNER JOIN stock_ubicacion su
                     ON su.producto_id = p.id AND su.empresa_id = p.empresa_id
             WHERE p.empresa_id = :empresa_id AND p.activo = 1
               AND NOT EXISTS (
                    SELECT 1 FROM stock_movimientos sm
                     WHERE sm.empresa_id = p.empresa_id AND sm.producto_id = p.id
                       AND sm.tipo_movimiento = \'VENTA\' AND sm.cantidad < 0
                       AND sm.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL :dias DAY)
               )
             GROUP BY p.id
             HAVING stock > 0
             ORDER BY valor DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['empresa_id' => $empresaId, 'dias' => max(1, $dias)]);

        return $stmt->fetchAll();
    }
}
