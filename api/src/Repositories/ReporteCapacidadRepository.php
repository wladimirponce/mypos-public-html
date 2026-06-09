<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

/**
 * Consultas de reportes activadas por capacidades del sistema multi-rubro.
 * Cada metodo corresponde a una capacidad: MERMA_OPERATIVA, LOTES_VENCIMIENTO, VARIANTES.
 */
final class ReporteCapacidadRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    // =========================================================================
    // MERMA_OPERATIVA
    // =========================================================================

    /**
     * Merma total por producto en el periodo. Incluye comparacion con
     * merma_porcentaje esperada definida en el producto.
     */
    public function mermaByPeriod(int $empresaId, ?int $sucursalId, string $desde, string $hasta): array
    {
        $andSucursal = $sucursalId !== null ? 'AND sm.sucursal_id = :sucursal_id' : '';

        $sql = "SELECT p.id AS producto_id, p.codigo AS producto_codigo, p.nombre AS producto_nombre,
                       p.merma_porcentaje AS merma_esperada_pct,
                       SUM(ABS(sm.cantidad))                                      AS unidades_merma,
                       SUM(ABS(sm.cantidad) * COALESCE(sm.costo_unitario, 0))     AS costo_merma,
                       COUNT(sm.id)                                               AS num_movimientos
                FROM stock_movimientos sm
                INNER JOIN productos p ON p.id = sm.producto_id AND p.empresa_id = sm.empresa_id
                WHERE sm.empresa_id     = :empresa_id
                  AND sm.tipo_movimiento = 'MERMA'
                  AND DATE(sm.created_at) BETWEEN :desde AND :hasta
                  {$andSucursal}
                GROUP BY p.id
                ORDER BY SUM(ABS(sm.cantidad)) DESC
                LIMIT 200";

        $params = ['empresa_id' => $empresaId, 'desde' => $desde, 'hasta' => $hasta];
        if ($sucursalId !== null) {
            $params['sucursal_id'] = $sucursalId;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function mermaTotal(int $empresaId, ?int $sucursalId, string $desde, string $hasta): array
    {
        $andSucursal = $sucursalId !== null ? 'AND sm.sucursal_id = :sucursal_id' : '';

        $sql = "SELECT SUM(ABS(sm.cantidad))                                  AS total_unidades,
                       SUM(ABS(sm.cantidad) * COALESCE(sm.costo_unitario, 0)) AS total_costo,
                       COUNT(sm.id)                                           AS total_movimientos
                FROM stock_movimientos sm
                WHERE sm.empresa_id      = :empresa_id
                  AND sm.tipo_movimiento  = 'MERMA'
                  AND DATE(sm.created_at) BETWEEN :desde AND :hasta
                  {$andSucursal}";

        $params = ['empresa_id' => $empresaId, 'desde' => $desde, 'hasta' => $hasta];
        if ($sucursalId !== null) {
            $params['sucursal_id'] = $sucursalId;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() ?: ['total_unidades' => 0, 'total_costo' => 0, 'total_movimientos' => 0];
    }

    // =========================================================================
    // LOTES_VENCIMIENTO
    // =========================================================================

    /**
     * Lotes proximos a vencer con stock disponible, desglosados por ubicacion.
     */
    public function lotesPorVencer(int $empresaId, int $diasAlerta): array
    {
        $stmt = $this->connection->prepare(
            'SELECT sl.id AS lote_id, sl.numero_lote, sl.fecha_vencimiento,
                    DATEDIFF(sl.fecha_vencimiento, CURDATE()) AS dias_para_vencer,
                    p.id AS producto_id, p.codigo AS producto_codigo, p.nombre AS producto_nombre,
                    u.id AS ubicacion_id, u.nombre AS ubicacion_nombre, u.sucursal_id,
                    lu.cantidad AS stock_en_ubicacion
             FROM stock_lotes sl
             INNER JOIN productos p
                     ON p.id = sl.producto_id AND p.empresa_id = sl.empresa_id
             INNER JOIN stock_lotes_ubicacion lu
                     ON lu.lote_id = sl.id AND lu.empresa_id = sl.empresa_id
             INNER JOIN ubicaciones_stock u
                     ON u.id = lu.ubicacion_id
             WHERE sl.empresa_id            = :empresa_id
               AND sl.activo                = 1
               AND sl.fecha_vencimiento      IS NOT NULL
               AND sl.fecha_vencimiento      >= CURDATE()
               AND sl.fecha_vencimiento      <= DATE_ADD(CURDATE(), INTERVAL :dias DAY)
               AND lu.cantidad               > 0
             ORDER BY sl.fecha_vencimiento ASC, p.nombre ASC
             LIMIT 300'
        );
        $stmt->execute(['empresa_id' => $empresaId, 'dias' => $diasAlerta]);

        return $stmt->fetchAll();
    }

    /**
     * Lotes ya vencidos que aun tienen stock (requieren accion inmediata).
     */
    public function lotesVencidosConStock(int $empresaId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT sl.id AS lote_id, sl.numero_lote, sl.fecha_vencimiento,
                    DATEDIFF(CURDATE(), sl.fecha_vencimiento) AS dias_vencido,
                    p.id AS producto_id, p.codigo AS producto_codigo, p.nombre AS producto_nombre,
                    COALESCE(SUM(lu.cantidad), 0.000) AS stock_total
             FROM stock_lotes sl
             INNER JOIN productos p
                     ON p.id = sl.producto_id AND p.empresa_id = sl.empresa_id
             LEFT JOIN stock_lotes_ubicacion lu
                    ON lu.lote_id = sl.id AND lu.empresa_id = sl.empresa_id
             WHERE sl.empresa_id        = :empresa_id
               AND sl.activo            = 1
               AND sl.fecha_vencimiento < CURDATE()
             GROUP BY sl.id, p.id
             HAVING stock_total > 0
             ORDER BY sl.fecha_vencimiento ASC, p.nombre ASC
             LIMIT 200'
        );
        $stmt->execute(['empresa_id' => $empresaId]);

        return $stmt->fetchAll();
    }

    // =========================================================================
    // VARIANTES
    // =========================================================================

    /**
     * Stock actual por variante. Si se pasa producto_padre_id filtra por ese padre.
     */
    public function stockPorVariante(int $empresaId, ?int $productoPadreId): array
    {
        $andPadre = $productoPadreId !== null ? 'AND pv.producto_padre_id = :producto_padre_id' : '';

        $sql = "SELECT pv.id AS variante_id, pv.codigo_variante,
                       pv.producto_padre_id,
                       pp.codigo AS padre_codigo, pp.nombre AS padre_nombre,
                       p.id AS producto_id, p.codigo, p.nombre AS variante_nombre, p.precio_venta,
                       COALESCE(SUM(su.cantidad), 0.000) AS stock_total
                FROM producto_variantes pv
                INNER JOIN productos pp
                        ON pp.id = pv.producto_padre_id AND pp.empresa_id = pv.empresa_id
                INNER JOIN productos p
                        ON p.id = pv.producto_id AND p.empresa_id = pv.empresa_id
                LEFT JOIN stock_ubicacion su
                       ON su.producto_id = pv.producto_id
                      AND su.empresa_id  = pv.empresa_id
                WHERE pv.empresa_id = :empresa_id
                  AND pv.activo     = 1
                  {$andPadre}
                GROUP BY pv.id
                ORDER BY pp.nombre ASC, pv.codigo_variante ASC
                LIMIT 500";

        $params = ['empresa_id' => $empresaId];
        if ($productoPadreId !== null) {
            $params['producto_padre_id'] = $productoPadreId;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Ventas por variante en el periodo. Muestra que combinaciones se venden mas.
     */
    public function ventasPorVariante(int $empresaId, ?int $sucursalId, string $desde, string $hasta): array
    {
        $andSucursal = $sucursalId !== null ? 'AND v.sucursal_id = :sucursal_id' : '';

        $sql = "SELECT pv.producto_padre_id,
                       pp.codigo AS padre_codigo, pp.nombre AS padre_nombre,
                       pv.codigo_variante,
                       p.id AS producto_id, p.codigo AS variante_codigo, p.nombre AS variante_nombre,
                       SUM(vd.cantidad)          AS total_unidades,
                       SUM(vd.total)             AS total_pesos,
                       COUNT(DISTINCT vd.venta_id) AS num_ventas
                FROM venta_detalles vd
                INNER JOIN ventas v
                        ON v.id = vd.venta_id AND v.estado NOT IN ('ANULADA')
                INNER JOIN producto_variantes pv
                        ON pv.producto_id = vd.producto_id AND pv.empresa_id = vd.empresa_id
                INNER JOIN productos pp
                        ON pp.id = pv.producto_padre_id AND pp.empresa_id = pv.empresa_id
                INNER JOIN productos p
                        ON p.id = vd.producto_id
                WHERE vd.empresa_id        = :empresa_id
                  AND DATE(v.created_at)   BETWEEN :desde AND :hasta
                  {$andSucursal}
                GROUP BY pv.id
                ORDER BY SUM(vd.cantidad) DESC
                LIMIT 200";

        $params = ['empresa_id' => $empresaId, 'desde' => $desde, 'hasta' => $hasta];
        if ($sucursalId !== null) {
            $params['sucursal_id'] = $sucursalId;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
