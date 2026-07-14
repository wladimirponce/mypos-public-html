<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class LoteRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function findLote(int $empresaId, int $productoId, string $numeroLote): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, empresa_id, producto_id, numero_lote, fecha_vencimiento, fecha_fabricacion,
                    proveedor_id, compra_id, cantidad_original, activo, observacion, created_at
             FROM stock_lotes
             WHERE empresa_id = :empresa_id AND producto_id = :producto_id AND numero_lote = :numero_lote
             LIMIT 1'
        );
        $stmt->execute(['empresa_id' => $empresaId, 'producto_id' => $productoId, 'numero_lote' => $numeroLote]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findLoteById(int $empresaId, int $loteId): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, empresa_id, producto_id, numero_lote, fecha_vencimiento, fecha_fabricacion,
                    proveedor_id, compra_id, cantidad_original, activo, observacion
             FROM stock_lotes
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $loteId, 'empresa_id' => $empresaId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function insertLote(array $data): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO stock_lotes
             (empresa_id, producto_id, numero_lote, fecha_vencimiento, fecha_fabricacion,
              proveedor_id, compra_id, cantidad_original, observacion)
             VALUES
             (:empresa_id, :producto_id, :numero_lote, :fecha_vencimiento, :fecha_fabricacion,
              :proveedor_id, :compra_id, :cantidad_original, :observacion)'
        );
        $stmt->execute([
            'empresa_id'        => $data['empresa_id'],
            'producto_id'       => $data['producto_id'],
            'numero_lote'       => $data['numero_lote'],
            'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
            'fecha_fabricacion' => $data['fecha_fabricacion'] ?? null,
            'proveedor_id'      => $data['proveedor_id'] ?? null,
            'compra_id'         => $data['compra_id'] ?? null,
            'cantidad_original' => $data['cantidad_original'] ?? '0.000',
            'observacion'       => $data['observacion'] ?? null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function ensureLoteUbicacion(int $empresaId, int $loteId, int $ubicacionId, int $productoId): void
    {
        $stmt = $this->connection->prepare(
            'INSERT IGNORE INTO stock_lotes_ubicacion (empresa_id, lote_id, ubicacion_id, producto_id, cantidad)
             VALUES (:empresa_id, :lote_id, :ubicacion_id, :producto_id, 0.000)'
        );
        $stmt->execute([
            'empresa_id'   => $empresaId,
            'lote_id'      => $loteId,
            'ubicacion_id' => $ubicacionId,
            'producto_id'  => $productoId,
        ]);
    }

    public function lockLoteUbicacion(int $empresaId, int $loteId, int $ubicacionId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, cantidad
             FROM stock_lotes_ubicacion
             WHERE empresa_id = :empresa_id AND lote_id = :lote_id AND ubicacion_id = :ubicacion_id
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['empresa_id' => $empresaId, 'lote_id' => $loteId, 'ubicacion_id' => $ubicacionId]);

        return (array) $stmt->fetch();
    }

    public function updateLoteUbicacionCantidad(int $id, string $cantidad): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE stock_lotes_ubicacion
             SET cantidad = :cantidad, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'cantidad' => $cantidad]);
    }

    /**
     * Retorna lotes con stock > 0 para FEFO: first expired first out.
     * Lotes sin fecha_vencimiento van al final (NULL last).
     */
    public function lotesFefo(int $empresaId, int $productoId, int $ubicacionId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT lu.id AS lote_ubi_id, lu.lote_id, lu.cantidad,
                    sl.numero_lote, sl.fecha_vencimiento, sl.fecha_fabricacion
             FROM stock_lotes_ubicacion lu
             INNER JOIN stock_lotes sl ON sl.id = lu.lote_id
             WHERE lu.empresa_id   = :empresa_id
               AND lu.producto_id  = :producto_id
               AND lu.ubicacion_id = :ubicacion_id
               AND lu.cantidad     > 0
               AND sl.activo       = 1
             ORDER BY ISNULL(sl.fecha_vencimiento) ASC, sl.fecha_vencimiento ASC, sl.id ASC'
        );
        $stmt->execute([
            'empresa_id'   => $empresaId,
            'producto_id'  => $productoId,
            'ubicacion_id' => $ubicacionId,
        ]);

        return $stmt->fetchAll();
    }

    public function stockPorLote(int $empresaId, int $productoId, ?int $ubicacionId): array
    {
        $sql = 'SELECT sl.id AS lote_id, sl.numero_lote, sl.fecha_vencimiento, sl.fecha_fabricacion,
                       sl.cantidad_original, sl.proveedor_id, sl.compra_id, sl.observacion, sl.activo,
                       COALESCE(SUM(lu.cantidad), 0.000) AS stock_disponible
                FROM stock_lotes sl
                LEFT JOIN stock_lotes_ubicacion lu
                       ON lu.lote_id    = sl.id
                      AND lu.empresa_id = sl.empresa_id';

        $params = ['empresa_id' => $empresaId, 'producto_id' => $productoId];

        if ($ubicacionId !== null && $ubicacionId > 0) {
            $sql .= ' AND lu.ubicacion_id = :ubicacion_id';
            $params['ubicacion_id'] = $ubicacionId;
        }

        $sql .= ' WHERE sl.empresa_id = :empresa_id AND sl.producto_id = :producto_id AND sl.activo = 1
                  GROUP BY sl.id
                  ORDER BY sl.fecha_vencimiento ASC, sl.id ASC';

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function alertasVencimiento(int $empresaId, int $diasAlerta): array
    {
        $stmt = $this->connection->prepare(
            'SELECT sl.id AS lote_id, sl.numero_lote, sl.fecha_vencimiento,
                    sl.producto_id, p.codigo AS producto_codigo, p.nombre AS producto_nombre,
                    p.precio_venta,
                    COALESCE(SUM(lu.cantidad), 0.000) AS stock_total,
                    DATEDIFF(sl.fecha_vencimiento, CURDATE()) AS dias_para_vencer
             FROM stock_lotes sl
             INNER JOIN productos p
                     ON p.id         = sl.producto_id
                    AND p.empresa_id = sl.empresa_id
             LEFT JOIN stock_lotes_ubicacion lu
                    ON lu.lote_id    = sl.id
                   AND lu.empresa_id = sl.empresa_id
             WHERE sl.empresa_id            = :empresa_id
               AND sl.activo                = 1
               AND sl.fecha_vencimiento     IS NOT NULL
               AND sl.fecha_vencimiento     <= DATE_ADD(CURDATE(), INTERVAL :dias DAY)
             GROUP BY sl.id, p.id
             HAVING stock_total > 0
             ORDER BY sl.fecha_vencimiento ASC, p.nombre ASC
             LIMIT 200'
        );
        $stmt->execute(['empresa_id' => $empresaId, 'dias' => $diasAlerta]);

        return $stmt->fetchAll();
    }
}
