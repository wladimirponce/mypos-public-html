<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class OrdenCompraRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function insert(array $data): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO ordenes_compra
             (empresa_id, sucursal_id, proveedor_id, usuario_id, numero_oc,
              estado, fecha_emision, fecha_entrega_esperada, observacion, total_referencia)
             VALUES
             (:empresa_id, :sucursal_id, :proveedor_id, :usuario_id, :numero_oc,
              :estado, :fecha_emision, :fecha_entrega_esperada, :observacion, :total_referencia)'
        );
        $stmt->execute($data);
        $id = (int) $this->connection->lastInsertId();

        // Asignar número OC basado en el id para garantizar unicidad
        $numero = 'OC-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $this->connection->prepare(
            'UPDATE ordenes_compra SET numero_oc = :numero WHERE id = :id'
        )->execute(['numero' => $numero, 'id' => $id]);

        return $id;
    }

    public function insertItem(array $data): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO ordenes_compra_items
             (empresa_id, orden_id, producto_id, linea, codigo_producto,
              nombre_producto, cantidad_solicitada, costo_referencia, unidad, observacion)
             VALUES
             (:empresa_id, :orden_id, :producto_id, :linea, :codigo_producto,
              :nombre_producto, :cantidad_solicitada, :costo_referencia, :unidad, :observacion)'
        );
        $stmt->execute($data);
        return (int) $this->connection->lastInsertId();
    }

    public function find(int $empresaId, int $id): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT oc.*,
                    p.razon_social AS proveedor_nombre,
                    p.rut          AS proveedor_rut,
                    p.email        AS proveedor_email,
                    p.telefono     AS proveedor_telefono,
                    s.nombre       AS sucursal_nombre,
                    u.nombre       AS usuario_nombre
             FROM ordenes_compra oc
             JOIN proveedores p  ON p.id  = oc.proveedor_id
             JOIN sucursales  s  ON s.id  = oc.sucursal_id
             JOIN usuarios    u  ON u.id  = oc.usuario_id
             WHERE oc.id = :id AND oc.empresa_id = :empresa_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function findForUpdate(int $empresaId, int $id): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, estado, sucursal_id, proveedor_id, numero_oc
             FROM ordenes_compra
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function items(int $ordenId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT oci.*, p.nombre AS nombre_actual
             FROM ordenes_compra_items oci
             LEFT JOIN productos p ON p.id = oci.producto_id
             WHERE oci.orden_id = :orden_id
             ORDER BY oci.linea'
        );
        $stmt->execute(['orden_id' => $ordenId]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function list(int $empresaId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($empresaId, $filters);
        $stmt = $this->connection->prepare(
            "SELECT oc.id, oc.numero_oc, oc.estado, oc.fecha_emision, oc.fecha_entrega_esperada,
                    oc.total_referencia, oc.total_recibido, oc.created_at,
                    p.razon_social AS proveedor_nombre,
                    s.nombre       AS sucursal_nombre,
                    (SELECT COUNT(*) FROM ordenes_compra_items WHERE orden_id = oc.id) AS total_items
             FROM ordenes_compra oc
             JOIN proveedores p ON p.id = oc.proveedor_id
             JOIN sucursales  s ON s.id = oc.sucursal_id
             WHERE {$where}
             ORDER BY oc.created_at DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countList(int $empresaId, array $filters): int
    {
        [$where, $params] = $this->buildWhere($empresaId, $filters);
        $stmt = $this->connection->prepare(
            "SELECT COUNT(*) FROM ordenes_compra oc WHERE {$where}"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function updateEstado(int $id, string $estado, array $extra = []): void
    {
        $set = 'estado = :estado, updated_at = CURRENT_TIMESTAMP';
        $params = array_merge(['id' => $id, 'estado' => $estado], $extra);
        if (isset($extra['cancelada_at'])) {
            $set .= ', cancelada_at = :cancelada_at, motivo_cancelacion = :motivo_cancelacion';
        }
        $this->connection->prepare(
            "UPDATE ordenes_compra SET {$set} WHERE id = :id"
        )->execute($params);
    }

    public function refreshTotales(int $ordenId): void
    {
        $this->connection->prepare(
            'UPDATE ordenes_compra oc
             SET total_referencia = (
                 SELECT COALESCE(SUM(cantidad_solicitada * costo_referencia), 0)
                 FROM ordenes_compra_items WHERE orden_id = oc.id
             ),
             total_recibido = (
                 SELECT COALESCE(SUM(ocri.cantidad_recibida * ocri.costo_unitario), 0)
                 FROM ordenes_compra_recepcion_items ocri
                 JOIN ordenes_compra_recepciones ocr ON ocr.id = ocri.recepcion_id
                 WHERE ocr.orden_id = oc.id
             ),
             updated_at = CURRENT_TIMESTAMP
             WHERE oc.id = :id'
        )->execute(['id' => $ordenId]);
    }

    public function acumularRecibido(int $ordenItemId, string $cantidad): void
    {
        $this->connection->prepare(
            'UPDATE ordenes_compra_items
             SET cantidad_recibida = cantidad_recibida + :cantidad,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute(['id' => $ordenItemId, 'cantidad' => $cantidad]);
    }

    /** Devuelve true si todos los ítems están 100% recibidos */
    public function allItemsReceived(int $ordenId): bool
    {
        $stmt = $this->connection->prepare(
            'SELECT COUNT(*) FROM ordenes_compra_items
             WHERE orden_id = :id AND cantidad_recibida < cantidad_solicitada'
        );
        $stmt->execute(['id' => $ordenId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    public function insertRecepcion(array $data): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO ordenes_compra_recepciones
             (empresa_id, orden_id, compra_id, usuario_id, fecha, notas)
             VALUES (:empresa_id, :orden_id, :compra_id, :usuario_id, :fecha, :notas)'
        );
        $stmt->execute($data);
        return (int) $this->connection->lastInsertId();
    }

    public function linkCompra(int $recepcionId, int $compraId): void
    {
        $this->connection->prepare(
            'UPDATE ordenes_compra_recepciones SET compra_id = :compra_id WHERE id = :id'
        )->execute(['compra_id' => $compraId, 'id' => $recepcionId]);
    }

    public function insertRecepcionItem(array $data): void
    {
        $this->connection->prepare(
            'INSERT INTO ordenes_compra_recepcion_items
             (empresa_id, recepcion_id, orden_item_id, producto_id, cantidad_recibida, costo_unitario)
             VALUES (:empresa_id, :recepcion_id, :orden_item_id, :producto_id, :cantidad_recibida, :costo_unitario)'
        )->execute($data);
    }

    /** @return array<int, array<string, mixed>> */
    public function recepciones(int $ordenId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT ocr.*, u.nombre AS usuario_nombre,
                    c.id AS compra_id, c.estado AS compra_estado, c.total AS compra_total,
                    c.tipo_documento AS compra_tipo_documento, c.folio AS compra_folio
             FROM ordenes_compra_recepciones ocr
             JOIN usuarios u ON u.id = ocr.usuario_id
             LEFT JOIN compras c ON c.id = ocr.compra_id
             WHERE ocr.orden_id = :orden_id
             ORDER BY ocr.created_at DESC'
        );
        $stmt->execute(['orden_id' => $ordenId]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function recepcionItems(int $recepcionId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT ocri.*, p.nombre AS nombre_producto
             FROM ordenes_compra_recepcion_items ocri
             JOIN productos p ON p.id = ocri.producto_id
             WHERE ocri.recepcion_id = :id'
        );
        $stmt->execute(['id' => $recepcionId]);
        return $stmt->fetchAll();
    }

    /**
     * Productos que el proveedor ha vendido a la empresa con contexto de stock actual.
     * Incluye: último precio, stock actual vs mínimo, cantidad sugerida para reponer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sugerirProductos(int $empresaId, int $proveedorId, int $sucursalId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT
                pp.id              AS proveedor_producto_id,
                pp.producto_id,
                pp.codigo_proveedor,
                pp.nombre_proveedor,
                pp.costo_ultimo    AS costo_referencia,
                pp.unidad_compra   AS unidad,
                pp.proveedor_preferido,
                pp.compra_minima,
                p.nombre           AS nombre_producto,
                p.codigo           AS codigo_producto,
                p.stock_minimo,
                COALESCE(ss.disponible, 0)                                              AS stock_actual,
                GREATEST(0, COALESCE(p.stock_minimo, 0) - COALESCE(ss.disponible, 0))  AS cantidad_sugerida,
                (SELECT precio_compra
                 FROM proveedor_precios
                 WHERE empresa_id   = pp.empresa_id
                   AND proveedor_id = pp.proveedor_id
                   AND producto_id  = pp.producto_id
                 ORDER BY fecha_precio DESC, id DESC
                 LIMIT 1)                                                                AS ultimo_precio_historico
             FROM proveedor_productos pp
             JOIN productos p ON p.id = pp.producto_id AND p.empresa_id = pp.empresa_id AND p.activo = 1
             LEFT JOIN stock_sucursal ss
                    ON ss.producto_id = pp.producto_id AND ss.sucursal_id = :sucursal_id
             WHERE pp.empresa_id   = :empresa_id
               AND pp.proveedor_id = :proveedor_id
               AND pp.activo = 1
               AND (pp.deleted_at IS NULL OR pp.deleted_at > NOW())
             ORDER BY pp.proveedor_preferido DESC, cantidad_sugerida DESC, p.nombre ASC'
        );
        $stmt->execute([
            'empresa_id'   => $empresaId,
            'proveedor_id' => $proveedorId,
            'sucursal_id'  => $sucursalId,
        ]);
        return $stmt->fetchAll();
    }

    /** @return array{string, array<string, mixed>} */
    private function buildWhere(int $empresaId, array $filters): array
    {
        $where  = 'oc.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        if (!empty($filters['estado'])) {
            $where .= ' AND oc.estado = :estado';
            $params['estado'] = $filters['estado'];
        }
        if (!empty($filters['proveedor_id'])) {
            $where .= ' AND oc.proveedor_id = :proveedor_id';
            $params['proveedor_id'] = (int) $filters['proveedor_id'];
        }
        if (!empty($filters['sucursal_id'])) {
            $where .= ' AND oc.sucursal_id = :sucursal_id';
            $params['sucursal_id'] = (int) $filters['sucursal_id'];
        }
        if (!empty($filters['fecha_desde'])) {
            $where .= ' AND oc.fecha_emision >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }
        if (!empty($filters['fecha_hasta'])) {
            $where .= ' AND oc.fecha_emision <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }

        return [$where, $params];
    }
}
