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
