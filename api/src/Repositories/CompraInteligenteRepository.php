<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

/**
 * Repositorio de compras inteligentes.
 *
 * Lee stock_ubicacion para detectar productos que están en o por debajo del
 * punto de reorden, y resuelve el proveedor sugerido usando proveedor_productos
 * y proveedor_precios.
 */
final class CompraInteligenteRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * Retorna sugerencias de compra para una empresa.
     *
     * Criterio: productos con controla_stock = 1, activo = 1 y cuya cantidad
     * (en la ubicación indicada, o en cualquier ubicación activa de la empresa)
     * es <= punto_reorden (cuando punto_reorden no es NULL).
     *
     * Por cada producto se incluye:
     * - datos del producto
     * - datos de stock/ubicacion
     * - proveedor sugerido (preferido > menor precio vigente)
     * - último precio vigente del proveedor sugerido
     * - cantidad sugerida = GREATEST(stock_maximo - cantidad, compra_minima, 1)
     *
     * @param int      $empresaId   Empresa a evaluar
     * @param int|null $ubicacionId Filtrar por ubicación específica (opcional)
     * @return array<int, array<string, mixed>>
     */
    public function obtenerSugerencias(int $empresaId, ?int $ubicacionId): array
    {
        // Subconsulta: para cada (empresa, producto), toma la relación preferida
        // o la de menor precio vigente hoy.
        $sql = <<<'SQL'
            SELECT
                su.id                                         AS stock_ubicacion_id,
                su.empresa_id,
                su.ubicacion_id,
                u.codigo                                      AS ubicacion_codigo,
                u.nombre                                      AS ubicacion_nombre,
                u.tipo                                        AS ubicacion_tipo,
                p.id                                          AS producto_id,
                p.sku,
                p.nombre                                      AS producto_nombre,
                p.unidad_medida,
                p.costo_actual,
                CAST(su.cantidad    AS DECIMAL(14,3))         AS cantidad_actual,
                CAST(su.reservado   AS DECIMAL(14,3))         AS reservado,
                CAST(su.stock_minimo AS DECIMAL(14,3))        AS stock_minimo,
                CAST(COALESCE(su.stock_maximo, 0) AS DECIMAL(14,3))  AS stock_maximo,
                CAST(COALESCE(su.punto_reorden, 0) AS DECIMAL(14,3)) AS punto_reorden,

                /* cantidad sugerida: llegar al máximo; si no hay máximo, pedir el doble del reorden */
                CAST(
                    GREATEST(
                        COALESCE(su.stock_maximo, su.punto_reorden * 2) - su.cantidad,
                        COALESCE(pp_rel.compra_minima, 1),
                        1
                    ) AS DECIMAL(14,3)
                )                                             AS cantidad_sugerida,

                /* proveedor sugerido */
                prov.id                                       AS proveedor_id,
                prov.nombre                                   AS proveedor_nombre,
                prov.rut                                      AS proveedor_rut,

                /* relación proveedor-producto */
                pp_rel.id                                     AS proveedor_producto_id,
                pp_rel.codigo_proveedor,
                pp_rel.nombre_proveedor                       AS nombre_en_proveedor,
                pp_rel.unidad_compra,
                pp_rel.factor_conversion,
                pp_rel.proveedor_preferido,
                pp_rel.plazo_entrega_dias,
                pp_rel.compra_minima,

                /* precio vigente del proveedor sugerido */
                precio_vig.precio_compra                      AS precio_vigente,
                precio_vig.moneda                             AS precio_moneda,
                precio_vig.fecha_precio                       AS precio_fecha,
                precio_vig.origen                             AS precio_origen

            FROM stock_ubicacion su
            INNER JOIN ubicaciones_stock u
                    ON u.id = su.ubicacion_id
                   AND u.empresa_id = su.empresa_id
                   AND u.activo = 1
            INNER JOIN productos p
                    ON p.id = su.producto_id
                   AND p.empresa_id = su.empresa_id
                   AND p.activo = 1
                   AND p.controla_stock = 1

            /* proveedor sugerido: preferido primero; si hay empate, menor precio vigente */
            LEFT JOIN proveedor_productos pp_rel
                   ON pp_rel.empresa_id = su.empresa_id
                  AND pp_rel.producto_id = su.producto_id
                  AND pp_rel.activo = 1
                  AND pp_rel.deleted_at IS NULL
                  AND pp_rel.id = (
                      SELECT pp2.id
                      FROM   proveedor_productos pp2
                      LEFT JOIN (
                          SELECT   proveedor_id, producto_id, MIN(precio_compra) AS min_precio
                          FROM     proveedor_precios
                          WHERE    empresa_id = :empresa_id_sub
                            AND    activo = 1
                            AND    (vigente_desde IS NULL OR vigente_desde <= CURRENT_DATE)
                            AND    (vigente_hasta IS NULL OR vigente_hasta >= CURRENT_DATE)
                          GROUP BY proveedor_id, producto_id
                      ) precio_hoy
                          ON precio_hoy.proveedor_id = pp2.proveedor_id
                         AND precio_hoy.producto_id  = pp2.producto_id
                      WHERE  pp2.empresa_id  = su.empresa_id
                        AND  pp2.producto_id = su.producto_id
                        AND  pp2.activo      = 1
                        AND  pp2.deleted_at  IS NULL
                      ORDER BY
                          pp2.proveedor_preferido DESC,
                          COALESCE(precio_hoy.min_precio, 2147483647) ASC,
                          pp2.id ASC
                      LIMIT 1
                  )

            LEFT JOIN proveedores prov
                   ON prov.id = pp_rel.proveedor_id
                  AND prov.empresa_id = pp_rel.empresa_id
                  AND prov.activo = 1
                  AND prov.deleted_at IS NULL

            /* precio vigente del proveedor sugerido para este producto */
            LEFT JOIN proveedor_precios precio_vig
                   ON precio_vig.empresa_id  = su.empresa_id
                  AND precio_vig.proveedor_id = pp_rel.proveedor_id
                  AND precio_vig.producto_id  = su.producto_id
                  AND precio_vig.activo       = 1
                  AND (precio_vig.vigente_desde IS NULL OR precio_vig.vigente_desde <= CURRENT_DATE)
                  AND (precio_vig.vigente_hasta IS NULL OR precio_vig.vigente_hasta >= CURRENT_DATE)
                  AND precio_vig.id = (
                      SELECT id FROM proveedor_precios
                      WHERE  empresa_id   = su.empresa_id
                        AND  proveedor_id = pp_rel.proveedor_id
                        AND  producto_id  = su.producto_id
                        AND  activo       = 1
                        AND  (vigente_desde IS NULL OR vigente_desde <= CURRENT_DATE)
                        AND  (vigente_hasta IS NULL OR vigente_hasta >= CURRENT_DATE)
                      ORDER BY precio_compra ASC, fecha_precio DESC, id DESC
                      LIMIT 1
                  )

            WHERE su.empresa_id = :empresa_id
              AND su.punto_reorden IS NOT NULL
              AND su.punto_reorden > 0
              AND su.cantidad <= su.punto_reorden
        SQL;

        $params = [
            'empresa_id'     => $empresaId,
            'empresa_id_sub' => $empresaId,
        ];

        if ($ubicacionId !== null && $ubicacionId > 0) {
            $sql .= ' AND su.ubicacion_id = :ubicacion_id';
            $params['ubicacion_id'] = $ubicacionId;
        }

        $sql .= ' ORDER BY (su.punto_reorden - su.cantidad) DESC, p.nombre ASC';

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Valida que una ubicación pertenezca a la empresa.
     */
    public function ubicacionExists(int $empresaId, int $ubicacionId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM ubicaciones_stock
             WHERE id = :id AND empresa_id = :empresa_id AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $ubicacionId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * Valida que una sucursal pertenezca a la empresa.
     */
    public function sucursalExists(int $empresaId, int $sucursalId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM sucursales
             WHERE id = :id AND empresa_id = :empresa_id AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $sucursalId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * Retorna la ubicación BODEGA o SUCURSAL_VENTA principal de la empresa
     * para usarla como destino por defecto de la compra borrador.
     */
    public function ubicacionPrincipal(int $empresaId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, codigo, nombre, tipo, sucursal_id
             FROM ubicaciones_stock
             WHERE empresa_id = :empresa_id AND activo = 1
             ORDER BY principal DESC, tipo ASC, id ASC
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    // =========================================================================
    // Sprint 12 — métodos de enriquecimiento v2
    // =========================================================================

    /**
     * Calcula el consumo promedio diario de un producto en una ubicación
     * a partir de los movimientos de salida registrados en stock_movimientos
     * durante los últimos $dias días.
     *
     * Tipos de movimiento considerados salida (deben coincidir con
     * StockService::TIPOS_VALIDOS): VENTA (demanda real), TRASPASO_SALIDA
     * (despacho a otra ubicación, relevante para bodegas que surten),
     * MERMA y AJUSTE (correcciones a la baja). El filtro cantidad < 0
     * garantiza que solo se cuenten las salidas, no las entradas.
     *
     * @return float Consumo diario promedio (puede ser 0 si no hubo salidas)
     */
    public function consumoPromedio(int $empresaId, int $productoId, int $ubicacionId, int $dias): float
    {
        $statement = $this->connection->prepare(
            'SELECT COALESCE(
                ABS(SUM(sm.cantidad)) / :dias,
                0
             ) AS consumo_diario
             FROM stock_movimientos sm
             WHERE sm.empresa_id  = :empresa_id
               AND sm.producto_id = :producto_id
               AND sm.ubicacion_id = :ubicacion_id
               AND sm.tipo_movimiento IN (\'VENTA\', \'TRASPASO_SALIDA\', \'MERMA\', \'AJUSTE\')
               AND sm.cantidad < 0
               AND sm.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL :dias_filter DAY)'
        );
        $statement->execute([
            'empresa_id'   => $empresaId,
            'producto_id'  => $productoId,
            'ubicacion_id' => $ubicacionId,
            'dias'         => max(1, $dias),
            'dias_filter'  => max(1, $dias),
        ]);

        return (float) $statement->fetchColumn();
    }

    /**
     * Busca todas las ubicaciones activas de la empresa (distintas a la
     * ubicación con déficit) que tengan disponible >= $necesidad para el
     * producto dado.
     *
     * @return array<int, array{ubicacion_id: int, ubicacion_codigo: string, ubicacion_nombre: string, tipo: string, disponible: float}>
     */
    public function ubicacionesConExcedente(int $empresaId, int $productoId, int $ubicacionOrigenId, float $necesidad): array
    {
        $statement = $this->connection->prepare(
            'SELECT su.ubicacion_id,
                    u.codigo  AS ubicacion_codigo,
                    u.nombre  AS ubicacion_nombre,
                    u.tipo,
                    CAST((su.cantidad - COALESCE(su.reservado, 0)) AS DECIMAL(14,3)) AS disponible
             FROM stock_ubicacion su
             INNER JOIN ubicaciones_stock u
                     ON u.id = su.ubicacion_id
                    AND u.empresa_id = su.empresa_id
                    AND u.activo = 1
             WHERE su.empresa_id  = :empresa_id
               AND su.producto_id = :producto_id
               AND su.ubicacion_id <> :ubicacion_origen_id
               AND (su.cantidad - COALESCE(su.reservado, 0)) >= :necesidad
             ORDER BY (su.cantidad - COALESCE(su.reservado, 0)) DESC'
        );
        $statement->execute([
            'empresa_id'         => $empresaId,
            'producto_id'        => $productoId,
            'ubicacion_origen_id' => $ubicacionOrigenId,
            'necesidad'          => $necesidad,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna el historial de precios del producto para detectar tendencias o
     * alzas recientes. Si se indica $proveedorId, restringe el historial a ese
     * proveedor (necesario para comparar alzas contra el mismo proveedor y no
     * contra precios de otro proveedor más barato/caro).
     *
     * @return array<int, array{proveedor_id: int, proveedor_nombre: string, precio_compra: int, fecha_precio: string, origen: string}>
     */
    public function historialPreciosProveedor(int $empresaId, int $productoId, ?int $proveedorId = null, int $limite = 10): array
    {
        $limiteSafe = max(1, min(100, $limite));
        $sql = "SELECT pp.proveedor_id,
                    prov.nombre AS proveedor_nombre,
                    pp.precio_compra,
                    pp.moneda,
                    pp.fecha_precio,
                    pp.origen,
                    pp.vigente_desde,
                    pp.vigente_hasta
             FROM proveedor_precios pp
             INNER JOIN proveedores prov
                     ON prov.id = pp.proveedor_id
                    AND prov.empresa_id = pp.empresa_id
             WHERE pp.empresa_id  = :empresa_id
               AND pp.producto_id = :producto_id
               AND pp.activo      = 1";

        $params = ['empresa_id' => $empresaId, 'producto_id' => $productoId];

        if ($proveedorId !== null && $proveedorId > 0) {
            $sql .= ' AND pp.proveedor_id = :proveedor_id';
            $params['proveedor_id'] = $proveedorId;
        }

        $sql .= " ORDER BY pp.fecha_precio DESC, pp.id DESC LIMIT {$limiteSafe}";

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna el ranking de proveedores por precio vigente hoy para el producto.
     * Ordena de menor a mayor precio.
     *
     * @return array<int, array{proveedor_id: int, proveedor_nombre: string, precio_compra: int, proveedor_preferido: int, plazo_entrega_dias: int|null}>
     */
    public function rankingProveedores(int $empresaId, int $productoId): array
    {
        $statement = $this->connection->prepare(
            'SELECT pp.proveedor_id,
                    prov.nombre AS proveedor_nombre,
                    prov.rut    AS proveedor_rut,
                    MIN(pp.precio_compra)  AS precio_compra,
                    pp.moneda,
                    rel.proveedor_preferido,
                    rel.plazo_entrega_dias,
                    rel.unidad_compra,
                    rel.factor_conversion
             FROM proveedor_precios pp
             INNER JOIN proveedores prov
                     ON prov.id         = pp.proveedor_id
                    AND prov.empresa_id = pp.empresa_id
                    AND prov.activo     = 1
                    AND prov.deleted_at IS NULL
             LEFT JOIN proveedor_productos rel
                    ON rel.empresa_id  = pp.empresa_id
                   AND rel.proveedor_id = pp.proveedor_id
                   AND rel.producto_id = pp.producto_id
                   AND rel.activo      = 1
                   AND rel.deleted_at  IS NULL
             WHERE pp.empresa_id  = :empresa_id
               AND pp.producto_id = :producto_id
               AND pp.activo      = 1
               AND (pp.vigente_desde IS NULL OR pp.vigente_desde <= CURRENT_DATE)
               AND (pp.vigente_hasta IS NULL OR pp.vigente_hasta >= CURRENT_DATE)
             GROUP BY pp.proveedor_id, prov.nombre, prov.rut, pp.moneda,
                      rel.proveedor_preferido, rel.plazo_entrega_dias,
                      rel.unidad_compra, rel.factor_conversion
             ORDER BY MIN(pp.precio_compra) ASC, rel.proveedor_preferido DESC'
        );
        $statement->execute(['empresa_id' => $empresaId, 'producto_id' => $productoId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
