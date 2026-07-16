<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class InventarioFisicoRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function find(int $empresaId, int $id): ?array
    {
        $st = $this->connection->prepare(
            'SELECT * FROM inventarios_fisicos WHERE id = :id AND empresa_id = :empresa_id LIMIT 1'
        );
        $st->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function list(int $empresaId, int $limit = 30): array
    {
        $st = $this->connection->prepare(
            'SELECT i.*, s.nombre AS sucursal_nombre
             FROM inventarios_fisicos i
             LEFT JOIN sucursales s ON s.id = i.sucursal_id AND s.empresa_id = i.empresa_id
             WHERE i.empresa_id = :empresa_id
             ORDER BY i.created_at DESC
             LIMIT ' . (int) $limit
        );
        $st->execute(['empresa_id' => $empresaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $this->connection->prepare(
            'INSERT INTO inventarios_fisicos
                (empresa_id, sucursal_id, nombre, usuario_id, total_items)
             VALUES
                (:empresa_id, :sucursal_id, :nombre, :usuario_id, 0)'
        )->execute([
            'empresa_id'  => $data['empresa_id'],
            'sucursal_id' => $data['sucursal_id'],
            'nombre'      => $data['nombre'],
            'usuario_id'  => $data['usuario_id'] ?? null,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    /**
     * Poblar items con todos los productos con controla_stock=1 de la sucursal.
     * cantidad_sistema = stock actual en el momento de crear el inventario.
     * Devuelve el número de items insertados.
     */
    public function populateItems(int $inventarioId, int $empresaId, int $sucursalId): int
    {
        $st = $this->connection->prepare(
            'INSERT IGNORE INTO inventarios_fisicos_items
                (inventario_id, empresa_id, producto_id, nombre_producto, codigo_producto, cantidad_sistema)
             SELECT :inv_id, p.empresa_id, p.id,
                    p.nombre,
                    COALESCE(p.codigo, p.sku, \'\'),
                    COALESCE(ss.cantidad, 0.000)
             FROM productos p
             LEFT JOIN stock_sucursal ss
                    ON ss.producto_id = p.id
                   AND ss.empresa_id  = p.empresa_id
                   AND ss.sucursal_id = :sucursal_id
             WHERE p.empresa_id    = :empresa_id
               AND p.activo        = 1
               AND p.controla_stock = 1
             ORDER BY p.nombre'
        );
        $st->execute([
            'inv_id'      => $inventarioId,
            'empresa_id'  => $empresaId,
            'sucursal_id' => $sucursalId,
        ]);
        $count = $st->rowCount();

        $this->connection->prepare(
            'UPDATE inventarios_fisicos SET total_items = :n WHERE id = :id'
        )->execute(['n' => $count, 'id' => $inventarioId]);

        return $count;
    }

    public function getItems(int $inventarioId, int $empresaId): array
    {
        $st = $this->connection->prepare(
            'SELECT id, producto_id, nombre_producto, codigo_producto,
                    cantidad_sistema, cantidad_contada, ajuste_movimiento_id
             FROM inventarios_fisicos_items
             WHERE inventario_id = :inv_id AND empresa_id = :empresa_id
             ORDER BY nombre_producto'
        );
        $st->execute(['inv_id' => $inventarioId, 'empresa_id' => $empresaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Guarda una tanda de conteos: [{producto_id, cantidad_contada}] */
    public function saveConteoLote(int $inventarioId, int $empresaId, array $items): int
    {
        $st = $this->connection->prepare(
            'UPDATE inventarios_fisicos_items
             SET cantidad_contada = :cantidad
             WHERE inventario_id = :inv_id AND empresa_id = :empresa_id AND producto_id = :producto_id'
        );

        $updated = 0;
        foreach ($items as $item) {
            $cantidad = isset($item['cantidad_contada']) ? (float) $item['cantidad_contada'] : null;
            $st->execute([
                'cantidad'    => $cantidad === null ? null : number_format($cantidad, 3, '.', ''),
                'inv_id'      => $inventarioId,
                'empresa_id'  => $empresaId,
                'producto_id' => (int) $item['producto_id'],
            ]);
            $updated += $st->rowCount();
        }

        // Recalcular contadores del header
        $this->refreshHeader($inventarioId, $empresaId);

        return $updated;
    }

    public function getItemsConDiferencia(int $inventarioId, int $empresaId): array
    {
        $st = $this->connection->prepare(
            'SELECT * FROM inventarios_fisicos_items
             WHERE inventario_id = :inv_id AND empresa_id = :empresa_id
               AND cantidad_contada IS NOT NULL
               AND ABS(cantidad_contada - cantidad_sistema) >= 0.001
             ORDER BY nombre_producto'
        );
        $st->execute(['inv_id' => $inventarioId, 'empresa_id' => $empresaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setAjusteMovimiento(int $itemId, int $movimientoId): void
    {
        $this->connection->prepare(
            'UPDATE inventarios_fisicos_items SET ajuste_movimiento_id = :mov WHERE id = :id'
        )->execute(['mov' => $movimientoId, 'id' => $itemId]);
    }

    public function marcarAplicado(int $empresaId, int $inventarioId): void
    {
        $this->connection->prepare(
            "UPDATE inventarios_fisicos
             SET estado = 'APLICADO', applied_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id"
        )->execute(['id' => $inventarioId, 'empresa_id' => $empresaId]);
    }

    // ─── Barrido con lector ───

    /**
     * Sesión de barrido ABIERTA de la sucursal, o null.
     *
     * @return array<string, string|null>|null Fila cruda (columnas PDO como string|null)
     */
    public function findSesionAbierta(int $empresaId, int $sucursalId): ?array
    {
        $st = $this->connection->prepare(
            "SELECT * FROM inventarios_fisicos
             WHERE empresa_id = :empresa_id AND sucursal_id = :sucursal_id
               AND tipo = 'BARRIDO' AND estado = 'ABIERTA'
             ORDER BY created_at DESC LIMIT 1"
        );
        $st->execute(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Crea una cabecera de barrido ABIERTA (no puebla items; el log es la fuente).
     *
     * @param array<string, mixed> $data
     */
    public function createBarrido(array $data): int
    {
        $this->connection->prepare(
            "INSERT INTO inventarios_fisicos
                (empresa_id, sucursal_id, nombre, tipo, estado, usuario_id, total_items)
             VALUES
                (:empresa_id, :sucursal_id, :nombre, 'BARRIDO', 'ABIERTA', :usuario_id, 0)"
        )->execute([
            'empresa_id'  => $data['empresa_id'],
            'sucursal_id' => $data['sucursal_id'],
            'nombre'      => $data['nombre'],
            'usuario_id'  => $data['usuario_id'] ?? null,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    /**
     * Inserta una lectura. $snapshot = stock de sistema al iniciar el conteo del
     * producto (solo la primera lectura del lote lo lleva; el resto NULL).
     */
    public function insertScan(int $inventarioId, int $empresaId, int $productoId, float $cantidad, ?float $snapshot, ?int $usuarioId): void
    {
        $this->connection->prepare(
            'INSERT INTO inventario_barrido_scans
                (inventario_id, empresa_id, producto_id, cantidad, stock_snapshot, usuario_id)
             VALUES (:inv_id, :empresa_id, :producto_id, :cantidad, :snapshot, :usuario_id)'
        )->execute([
            'inv_id'      => $inventarioId,
            'empresa_id'  => $empresaId,
            'producto_id' => $productoId,
            'cantidad'    => number_format($cantidad, 3, '.', ''),
            'snapshot'    => $snapshot === null ? null : number_format($snapshot, 3, '.', ''),
            'usuario_id'  => $usuarioId,
        ]);
    }

    /** Acumulado NO consolidado de un producto en la sesión. */
    public function acumuladoNoConsolidado(int $inventarioId, int $productoId): float
    {
        $st = $this->connection->prepare(
            'SELECT COALESCE(SUM(cantidad), 0) FROM inventario_barrido_scans
             WHERE inventario_id = :inv_id AND producto_id = :producto_id
               AND consolidado_at IS NULL'
        );
        $st->execute(['inv_id' => $inventarioId, 'producto_id' => $productoId]);
        return (float) $st->fetchColumn();
    }

    /** True si el producto ya tiene al menos una lectura consolidada en la sesión. */
    public function estaBloqueado(int $inventarioId, int $productoId): bool
    {
        $st = $this->connection->prepare(
            'SELECT 1 FROM inventario_barrido_scans
             WHERE inventario_id = :inv_id AND producto_id = :producto_id
               AND consolidado_at IS NOT NULL LIMIT 1'
        );
        $st->execute(['inv_id' => $inventarioId, 'producto_id' => $productoId]);
        return (bool) $st->fetchColumn();
    }

    /**
     * Resumen por producto de la sesión: pendiente, consolidado y bloqueo.
     * Une el log con productos para nombre/código actualizados.
     *
     * @return list<array<string, string|null>> Filas crudas (columnas PDO como string|null)
     */
    public function resumenBarrido(int $inventarioId, int $empresaId): array
    {
        $st = $this->connection->prepare(
            "SELECT s.producto_id,
                    p.nombre AS nombre_producto,
                    COALESCE(p.codigo, p.sku, '') AS codigo_producto,
                    SUM(CASE WHEN s.consolidado_at IS NULL THEN s.cantidad ELSE 0 END) AS pendiente,
                    SUM(CASE WHEN s.consolidado_at IS NOT NULL THEN s.cantidad ELSE 0 END) AS consolidado,
                    MAX(CASE WHEN s.consolidado_at IS NOT NULL THEN 1 ELSE 0 END) AS bloqueado,
                    MAX(s.created_at) AS ultima_lectura
             FROM inventario_barrido_scans s
             JOIN productos p ON p.id = s.producto_id AND p.empresa_id = s.empresa_id
             WHERE s.inventario_id = :inv_id AND s.empresa_id = :empresa_id
             GROUP BY s.producto_id, p.nombre, codigo_producto
             ORDER BY ultima_lectura DESC"
        );
        $st->execute(['inv_id' => $inventarioId, 'empresa_id' => $empresaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Productos con lecturas pendientes agrupadas: [{producto_id, contado, snapshot}].
     * snapshot = stock de sistema al iniciar el conteo (única lectura no-NULL del lote).
     *
     * @return list<array<string, string|null>> Filas crudas (columnas PDO como string|null)
     */
    public function scansPendientesAgrupados(int $inventarioId, int $empresaId): array
    {
        $st = $this->connection->prepare(
            'SELECT producto_id, SUM(cantidad) AS contado, MAX(stock_snapshot) AS snapshot
             FROM inventario_barrido_scans
             WHERE inventario_id = :inv_id AND empresa_id = :empresa_id
               AND consolidado_at IS NULL
             GROUP BY producto_id'
        );
        $st->execute(['inv_id' => $inventarioId, 'empresa_id' => $empresaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Marca como consolidadas las lecturas pendientes de un producto. */
    public function marcarScansConsolidados(int $inventarioId, int $productoId, ?int $movimientoId): void
    {
        $this->connection->prepare(
            'UPDATE inventario_barrido_scans
             SET consolidado_at = CURRENT_TIMESTAMP, consolidado_movimiento_id = :mov
             WHERE inventario_id = :inv_id AND producto_id = :producto_id
               AND consolidado_at IS NULL'
        )->execute([
            'mov'         => $movimientoId,
            'inv_id'      => $inventarioId,
            'producto_id' => $productoId,
        ]);
    }

    /** Stock actual del producto en la sucursal (vista derivada stock_sucursal). */
    public function stockActualSucursal(int $empresaId, int $sucursalId, int $productoId): float
    {
        $st = $this->connection->prepare(
            'SELECT COALESCE(cantidad, 0) FROM stock_sucursal
             WHERE empresa_id = :empresa_id AND sucursal_id = :sucursal_id AND producto_id = :producto_id
             LIMIT 1'
        );
        $st->execute([
            'empresa_id'  => $empresaId,
            'sucursal_id' => $sucursalId,
            'producto_id' => $productoId,
        ]);
        $val = $st->fetchColumn();
        return $val === false ? 0.0 : (float) $val;
    }

    /** True si el producto tiene al menos una lectura (pendiente o consolidada) en la sesión. */
    public function tieneScans(int $inventarioId, int $productoId): bool
    {
        $st = $this->connection->prepare(
            'SELECT 1 FROM inventario_barrido_scans
             WHERE inventario_id = :inv_id AND producto_id = :producto_id LIMIT 1'
        );
        $st->execute(['inv_id' => $inventarioId, 'producto_id' => $productoId]);
        return (bool) $st->fetchColumn();
    }

    /**
     * Productos del catálogo que controlan stock y NO fueron inventariados en la
     * sesión (sin ninguna lectura). Incluye el stock actual de la sucursal, que es
     * lo que se descontaría al llevarlos a 0. Ordena primero los que tienen stock.
     *
     * @return list<array<string, string|null>> Filas crudas (columnas PDO como string|null)
     */
    public function noInventariados(int $inventarioId, int $empresaId, int $sucursalId): array
    {
        $st = $this->connection->prepare(
            "SELECT p.id AS producto_id,
                    p.nombre AS nombre_producto,
                    COALESCE(p.codigo, p.sku, '') AS codigo_producto,
                    COALESCE(ss.cantidad, 0.000) AS stock_sistema
             FROM productos p
             LEFT JOIN stock_sucursal ss
                    ON ss.producto_id = p.id
                   AND ss.empresa_id  = p.empresa_id
                   AND ss.sucursal_id = :sucursal_id
             WHERE p.empresa_id = :empresa_id
               AND p.activo = 1
               AND p.controla_stock = 1
               AND NOT EXISTS (
                    SELECT 1 FROM inventario_barrido_scans s
                    WHERE s.inventario_id = :inv_id AND s.producto_id = p.id
               )
             ORDER BY (COALESCE(ss.cantidad, 0) > 0) DESC, p.nombre"
        );
        $st->execute([
            'sucursal_id' => $sucursalId,
            'empresa_id'  => $empresaId,
            'inv_id'      => $inventarioId,
        ]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Registra en la sesión que un producto se inventarió en 0 (no encontrado en el
     * barrido). Deja una lectura consolidada de cantidad 0 enlazada al movimiento de
     * ajuste, para que quede en el acta como contado=0 y bloqueado.
     */
    public function registrarCeroInventario(int $inventarioId, int $empresaId, int $productoId, ?int $movimientoId, ?int $usuarioId): void
    {
        $this->connection->prepare(
            'INSERT INTO inventario_barrido_scans
                (inventario_id, empresa_id, producto_id, cantidad, consolidado_movimiento_id, consolidado_at, usuario_id)
             VALUES (:inv_id, :empresa_id, :producto_id, 0.000, :mov, CURRENT_TIMESTAMP, :usuario_id)'
        )->execute([
            'inv_id'      => $inventarioId,
            'empresa_id'  => $empresaId,
            'producto_id' => $productoId,
            'mov'         => $movimientoId,
            'usuario_id'  => $usuarioId,
        ]);
    }

    public function cerrarBarrido(int $empresaId, int $inventarioId): void
    {
        $this->connection->prepare(
            "UPDATE inventarios_fisicos SET estado = 'CERRADA'
             WHERE id = :id AND empresa_id = :empresa_id AND tipo = 'BARRIDO'"
        )->execute(['id' => $inventarioId, 'empresa_id' => $empresaId]);
    }

    /** Recalcula total_items/contados del header de una sesión de barrido. */
    public function refreshBarridoHeader(int $inventarioId, int $empresaId): void
    {
        $this->connection->prepare(
            'UPDATE inventarios_fisicos i
             SET i.total_items = (
                     SELECT COUNT(DISTINCT producto_id) FROM inventario_barrido_scans
                     WHERE inventario_id = :inv_id AND empresa_id = :empresa_id
                 ),
                 i.items_contados = (
                     SELECT COUNT(DISTINCT producto_id) FROM inventario_barrido_scans
                     WHERE inventario_id = :inv_id2 AND empresa_id = :empresa_id2
                       AND consolidado_at IS NOT NULL
                 )
             WHERE i.id = :id AND i.empresa_id = :empresa_id3'
        )->execute([
            'inv_id'      => $inventarioId,
            'empresa_id'  => $empresaId,
            'inv_id2'     => $inventarioId,
            'empresa_id2' => $empresaId,
            'id'          => $inventarioId,
            'empresa_id3' => $empresaId,
        ]);
    }

    private function refreshHeader(int $inventarioId, int $empresaId): void
    {
        $this->connection->prepare(
            'UPDATE inventarios_fisicos i
             SET i.items_contados = (
                     SELECT COUNT(*) FROM inventarios_fisicos_items
                     WHERE inventario_id = :inv_id AND empresa_id = :empresa_id
                       AND cantidad_contada IS NOT NULL
                 ),
                 i.items_con_dif = (
                     SELECT COUNT(*) FROM inventarios_fisicos_items
                     WHERE inventario_id = :inv_id2 AND empresa_id = :empresa_id2
                       AND cantidad_contada IS NOT NULL
                       AND ABS(cantidad_contada - cantidad_sistema) >= 0.001
                 )
             WHERE i.id = :id AND i.empresa_id = :empresa_id3'
        )->execute([
            'inv_id'      => $inventarioId,
            'empresa_id'  => $empresaId,
            'inv_id2'     => $inventarioId,
            'empresa_id2' => $empresaId,
            'id'          => $inventarioId,
            'empresa_id3' => $empresaId,
        ]);
    }
}
