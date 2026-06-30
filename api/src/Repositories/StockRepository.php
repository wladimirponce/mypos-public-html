<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class StockRepository
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

    public function productoStockData(int $empresaId, int $productoId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, codigo, sku, nombre, controla_stock
             FROM productos
             WHERE id = :producto_id AND empresa_id = :empresa_id AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'producto_id' => $productoId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function ensureStockRow(int $empresaId, int $sucursalId, int $productoId): void
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO stock_sucursal (empresa_id, sucursal_id, producto_id, cantidad, reservado, stock_minimo)
             VALUES (:empresa_id, :sucursal_id, :producto_id, 0.000, 0.000, 0.000)'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'producto_id' => $productoId,
        ]);
    }

    public function lockStockRow(int $empresaId, int $sucursalId, int $productoId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, producto_id, cantidad, reservado, stock_minimo
             FROM stock_sucursal
             WHERE empresa_id = :empresa_id AND sucursal_id = :sucursal_id AND producto_id = :producto_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'producto_id' => $productoId,
        ]);

        return $statement->fetch();
    }

    public function updateQuantity(int $stockId, string $newQuantity): void
    {
        $statement = $this->connection->prepare(
            'UPDATE stock_sucursal SET cantidad = :cantidad, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $stockId, 'cantidad' => $newQuantity]);
    }

    /**
     * Recalcula el saldo derivado en stock_sucursal a partir de la suma de todas
     * las ubicaciones (stock_ubicacion) asociadas a esa sucursal.
     *
     * stock_ubicacion es la unica fuente de verdad; stock_sucursal es una vista
     * materializada para compatibilidad con el POS y los flujos legacy. La lectura
     * agregada usa FOR UPDATE para bloquear las filas de ubicacion que contribuyen
     * al total, de modo que dos movimientos concurrentes sobre la misma
     * sucursal/producto se serialicen y el saldo agregado nunca quede desfasado.
     */
    public function recalcSucursalStock(int $empresaId, int $sucursalId, int $productoId): void
    {
        $sum = $this->connection->prepare(
            'SELECT COALESCE(SUM(su.cantidad), 0.000) AS total
             FROM stock_ubicacion su
             INNER JOIN ubicaciones_stock u
                     ON u.id = su.ubicacion_id AND u.empresa_id = su.empresa_id
             WHERE su.empresa_id = :empresa_id
               AND su.producto_id = :producto_id
               AND u.sucursal_id = :sucursal_id
             FOR UPDATE'
        );
        $sum->execute([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'producto_id' => $productoId,
        ]);
        $total = (string) ($sum->fetchColumn() ?: '0.000');

        $update = $this->connection->prepare(
            'UPDATE stock_sucursal
             SET cantidad = :cantidad, updated_at = CURRENT_TIMESTAMP
             WHERE empresa_id = :empresa_id AND sucursal_id = :sucursal_id AND producto_id = :producto_id'
        );
        $update->execute([
            'cantidad' => $total,
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'producto_id' => $productoId,
        ]);
    }

    public function ensureLocationStockRow(int $empresaId, int $ubicacionId, int $productoId): void
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO stock_ubicacion (empresa_id, ubicacion_id, producto_id, cantidad, reservado, stock_minimo)
             VALUES (:empresa_id, :ubicacion_id, :producto_id, 0.000, 0.000, 0.000)'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'ubicacion_id' => $ubicacionId,
            'producto_id' => $productoId,
        ]);
    }

    public function lockLocationStockRow(int $empresaId, int $ubicacionId, int $productoId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, ubicacion_id, producto_id, cantidad, reservado, stock_minimo, stock_maximo, punto_reorden
             FROM stock_ubicacion
             WHERE empresa_id = :empresa_id AND ubicacion_id = :ubicacion_id AND producto_id = :producto_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'ubicacion_id' => $ubicacionId,
            'producto_id' => $productoId,
        ]);

        return $statement->fetch();
    }

    public function updateLocationQuantity(int $stockId, string $newQuantity): void
    {
        $statement = $this->connection->prepare(
            'UPDATE stock_ubicacion SET cantidad = :cantidad, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $stockId, 'cantidad' => $newQuantity]);
    }

    public function insertMovement(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO stock_movimientos (
                uuid, empresa_id, sucursal_id, ubicacion_id, ubicacion_origen_id, ubicacion_destino_id,
                dispositivo_id, producto_id, lote_id, usuario_id,
                tipo_movimiento, referencia_tipo, referencia_id, cantidad, stock_anterior,
                stock_nuevo, costo_unitario, observacion
             ) VALUES (
                :uuid, :empresa_id, :sucursal_id, :ubicacion_id, :ubicacion_origen_id, :ubicacion_destino_id,
                :dispositivo_id, :producto_id, :lote_id, :usuario_id,
                :tipo_movimiento, :referencia_tipo, :referencia_id, :cantidad, :stock_anterior,
                :stock_nuevo, :costo_unitario, :observacion
             )'
        );
        $statement->execute([
            'uuid' => $data['uuid'] ?? null,
            'empresa_id' => $data['empresa_id'],
            'sucursal_id' => $data['sucursal_id'],
            'ubicacion_id' => $data['ubicacion_id'] ?? null,
            'ubicacion_origen_id' => $data['ubicacion_origen_id'] ?? null,
            'ubicacion_destino_id' => $data['ubicacion_destino_id'] ?? null,
            'dispositivo_id' => $data['dispositivo_id'] ?? null,
            'producto_id' => $data['producto_id'],
            'lote_id' => $data['lote_id'] ?? null,
            'usuario_id' => $data['usuario_id'] ?? null,
            'tipo_movimiento' => $data['tipo_movimiento'],
            'referencia_tipo' => $data['referencia_tipo'] ?? null,
            'referencia_id' => $data['referencia_id'] ?? null,
            'cantidad' => $data['cantidad'],
            'stock_anterior' => $data['stock_anterior'],
            'stock_nuevo' => $data['stock_nuevo'],
            'costo_unitario' => $data['costo_unitario'] ?? 0,
            'observacion' => $data['observacion'] ?? null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Detecta descuadres del invariante de integridad: filas de stock_sucursal
     * cuyo saldo no coincide con la SUMA de stock_ubicacion de esa sucursal.
     * En un sistema sano devuelve []. Es de solo lectura.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findStockDiscrepancies(int $empresaId, float $tolerance = 0.001, int $limit = 500): array
    {
        $statement = $this->connection->prepare(
            'SELECT ss.sucursal_id, s.nombre AS sucursal_nombre, ss.producto_id,
                    p.codigo AS producto_codigo, p.nombre AS producto_nombre,
                    ss.cantidad AS stock_sucursal,
                    COALESCE(agg.total, 0.000) AS suma_ubicaciones,
                    (ss.cantidad - COALESCE(agg.total, 0.000)) AS diferencia
             FROM stock_sucursal ss
             INNER JOIN productos p ON p.id = ss.producto_id
             LEFT JOIN sucursales s ON s.id = ss.sucursal_id AND s.empresa_id = ss.empresa_id
             LEFT JOIN (
                 SELECT su.empresa_id, u.sucursal_id, su.producto_id, SUM(su.cantidad) AS total
                 FROM stock_ubicacion su
                 INNER JOIN ubicaciones_stock u
                         ON u.id = su.ubicacion_id AND u.empresa_id = su.empresa_id
                 WHERE u.sucursal_id IS NOT NULL
                 GROUP BY su.empresa_id, u.sucursal_id, su.producto_id
             ) agg
                 ON agg.empresa_id = ss.empresa_id
                AND agg.sucursal_id = ss.sucursal_id
                AND agg.producto_id = ss.producto_id
             WHERE ss.empresa_id = :empresa_id
               AND ABS(ss.cantidad - COALESCE(agg.total, 0.000)) > :tolerance
             ORDER BY ss.sucursal_id, p.nombre
             LIMIT ' . (int) $limit
        );
        $statement->bindValue('empresa_id', $empresaId, PDO::PARAM_INT);
        $statement->bindValue('tolerance', $tolerance);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function getStockProduct(int $empresaId, int $sucursalId, int $productoId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT ss.producto_id, p.codigo, p.nombre, r.nombre AS rubro,
                    ss.cantidad, ss.reservado, (ss.cantidad - ss.reservado) AS disponible, ss.stock_minimo
             FROM stock_sucursal ss
             INNER JOIN productos p ON p.id = ss.producto_id
             LEFT JOIN rubros r ON r.id = p.rubro_id
             WHERE ss.empresa_id = :empresa_id
               AND ss.sucursal_id = :sucursal_id
               AND ss.producto_id = :producto_id
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'producto_id' => $productoId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function listStock(int $empresaId, int $sucursalId, ?string $q, int $limit = 200, int $offset = 0, bool $soloGestionados = false): array
    {
        $sql = 'SELECT p.id AS producto_id, p.codigo, p.nombre, r.nombre AS rubro,
                       COALESCE(ss.cantidad, 0.000) AS cantidad,
                       COALESCE(ss.reservado, 0.000) AS reservado,
                       (COALESCE(ss.cantidad, 0.000) - COALESCE(ss.reservado, 0.000)) AS disponible,
                       COALESCE(ss.stock_minimo, p.stock_minimo, 0.000) AS stock_minimo
                FROM productos p
                LEFT JOIN rubros r ON r.id = p.rubro_id
                LEFT JOIN stock_sucursal ss ON ss.producto_id = p.id
                    AND ss.empresa_id = p.empresa_id
                    AND ss.sucursal_id = :sucursal_id
                WHERE p.empresa_id = :empresa_id
                  AND p.activo = 1
                  AND p.controla_stock = 1';

        // Solo productos con stock inicializado en la sucursal (los que el cliente
        // realmente administra), no todo el catalogo. La fila en stock_sucursal solo
        // existe cuando hubo carga inicial, ajuste, compra o venta del producto.
        if ($soloGestionados) {
            $sql .= ' AND ss.id IS NOT NULL';
        }

        $params = ['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId];

        if ($q !== null && trim($q) !== '') {
            $sql .= ' AND (p.nombre LIKE :q_nombre OR p.codigo LIKE :q_codigo OR p.sku LIKE :q_sku
                        OR EXISTS (
                            SELECT 1 FROM productos_codigos_barra pcb_q
                            WHERE pcb_q.producto_id = p.id
                              AND pcb_q.empresa_id = p.empresa_id
                              AND pcb_q.activo = 1
                              AND pcb_q.codigo_barra LIKE :q_codigo_barra
                        ))';
            $term = '%' . trim($q) . '%';
            $params['q_nombre'] = $term;
            $params['q_codigo'] = $term;
            $params['q_sku'] = $term;
            $params['q_codigo_barra'] = $term;
        }

        $safeLimit  = max(1, min($limit, 500));
        $safeOffset = max(0, $offset);
        $sql .= ' ORDER BY p.nombre LIMIT ' . $safeLimit . ' OFFSET ' . $safeOffset;
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function countStock(int $empresaId, int $sucursalId, ?string $q, bool $soloGestionados = false): int
    {
        $sql = 'SELECT COUNT(*) FROM productos p
                WHERE p.empresa_id = :empresa_id AND p.activo = 1 AND p.controla_stock = 1';
        $params = ['empresa_id' => $empresaId];

        // Cuenta solo lo gestionado para que la paginacion coincida con listStock().
        if ($soloGestionados) {
            $sql .= ' AND EXISTS (
                        SELECT 1 FROM stock_sucursal ss
                        WHERE ss.producto_id = p.id
                          AND ss.empresa_id = p.empresa_id
                          AND ss.sucursal_id = :sucursal_id
                      )';
            $params['sucursal_id'] = $sucursalId;
        }

        if ($q !== null && trim($q) !== '') {
            $sql .= ' AND (p.nombre LIKE :q_nombre OR p.codigo LIKE :q_codigo OR p.sku LIKE :q_sku
                        OR EXISTS (
                            SELECT 1 FROM productos_codigos_barra pcb_q
                            WHERE pcb_q.producto_id = p.id
                              AND pcb_q.empresa_id = p.empresa_id
                              AND pcb_q.activo = 1
                              AND pcb_q.codigo_barra LIKE :q_codigo_barra
                        ))';
            $term = '%' . trim($q) . '%';
            $params['q_nombre'] = $term;
            $params['q_codigo'] = $term;
            $params['q_sku'] = $term;
            $params['q_codigo_barra'] = $term;
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    public function listStockByLocation(int $empresaId, int $ubicacionId, ?string $q, int $limit = 200, int $offset = 0, bool $soloGestionados = false): array
    {
        $sql = 'SELECT p.id AS producto_id, p.codigo, p.nombre, r.nombre AS rubro,
                       u.id AS ubicacion_id, u.codigo AS ubicacion_codigo, u.nombre AS ubicacion_nombre,
                       COALESCE(su.cantidad, 0.000) AS cantidad,
                       COALESCE(su.reservado, 0.000) AS reservado,
                       (COALESCE(su.cantidad, 0.000) - COALESCE(su.reservado, 0.000)) AS disponible,
                       COALESCE(su.stock_minimo, p.stock_minimo, 0.000) AS stock_minimo,
                       su.stock_maximo,
                       su.punto_reorden
                FROM productos p
                INNER JOIN ubicaciones_stock u ON u.id = :ubicacion_id AND u.empresa_id = p.empresa_id
                LEFT JOIN rubros r ON r.id = p.rubro_id
                LEFT JOIN stock_ubicacion su ON su.producto_id = p.id
                    AND su.empresa_id = p.empresa_id
                    AND su.ubicacion_id = u.id
                WHERE p.empresa_id = :empresa_id
                  AND p.activo = 1
                  AND p.controla_stock = 1';

        // Solo productos con stock inicializado en esta bodega/ubicacion.
        if ($soloGestionados) {
            $sql .= ' AND su.id IS NOT NULL';
        }

        $params = ['empresa_id' => $empresaId, 'ubicacion_id' => $ubicacionId];

        if ($q !== null && trim($q) !== '') {
            $sql .= ' AND (p.nombre LIKE :q_nombre OR p.codigo LIKE :q_codigo OR p.sku LIKE :q_sku
                        OR EXISTS (
                            SELECT 1 FROM productos_codigos_barra pcb_q
                            WHERE pcb_q.producto_id = p.id
                              AND pcb_q.empresa_id = p.empresa_id
                              AND pcb_q.activo = 1
                              AND pcb_q.codigo_barra LIKE :q_codigo_barra
                        ))';
            $term = '%' . trim($q) . '%';
            $params['q_nombre'] = $term;
            $params['q_codigo'] = $term;
            $params['q_sku'] = $term;
            $params['q_codigo_barra'] = $term;
        }

        $safeLimit  = max(1, min($limit, 500));
        $safeOffset = max(0, $offset);
        $sql .= ' ORDER BY p.nombre LIMIT ' . $safeLimit . ' OFFSET ' . $safeOffset;
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function listMovements(int $empresaId, int $sucursalId, ?int $productoId): array
    {
        $sql = 'SELECT sm.id, sm.uuid, sm.empresa_id, sm.sucursal_id, sm.producto_id,
                       p.codigo, p.nombre AS producto_nombre, sm.usuario_id, sm.dispositivo_id,
                       sm.tipo_movimiento, sm.referencia_tipo, sm.referencia_id, sm.cantidad,
                       sm.stock_anterior, sm.stock_nuevo, sm.costo_unitario, sm.observacion,
                       sm.created_at
                FROM stock_movimientos sm
                INNER JOIN productos p ON p.id = sm.producto_id
                WHERE sm.empresa_id = :empresa_id AND sm.sucursal_id = :sucursal_id';
        $params = ['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId];

        if ($productoId !== null && $productoId > 0) {
            $sql .= ' AND sm.producto_id = :producto_id';
            $params['producto_id'] = $productoId;
        }

        $sql .= ' ORDER BY sm.id DESC LIMIT 300 OFFSET 0';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function listLocations(int $empresaId, ?string $tipo = null, ?int $sucursalId = null, bool $includeInactive = false): array
    {
        $sql = 'SELECT u.id, u.empresa_id, u.sucursal_id, s.nombre AS sucursal_nombre,
                       u.codigo, u.nombre, u.tipo, u.descripcion, u.direccion,
                       u.principal, u.permite_venta, u.activo, u.created_at, u.updated_at
                FROM ubicaciones_stock u
                LEFT JOIN sucursales s ON s.id = u.sucursal_id AND s.empresa_id = u.empresa_id
                WHERE u.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        if (!$includeInactive) {
            $sql .= ' AND u.activo = 1';
        }

        if ($tipo !== null && trim($tipo) !== '') {
            $sql .= ' AND u.tipo = :tipo';
            $params['tipo'] = strtoupper(trim($tipo));
        }

        if ($sucursalId !== null && $sucursalId > 0) {
            $sql .= ' AND u.sucursal_id = :sucursal_id';
            $params['sucursal_id'] = $sucursalId;
        }

        $sql .= ' ORDER BY u.activo DESC, u.tipo, u.nombre';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function ensureDefaultLocationForSucursal(int $empresaId, int $sucursalId): void
    {
        $update = $this->connection->prepare(
            'UPDATE ubicaciones_stock
             SET permite_venta = 1,
                 activo = 1,
                 deleted_at = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE empresa_id = :empresa_id
               AND sucursal_id = :sucursal_id
               AND tipo = \'SUCURSAL_VENTA\''
        );
        $update->execute(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId]);

        $statement = $this->connection->prepare(
            'INSERT INTO ubicaciones_stock (
                empresa_id, sucursal_id, codigo, nombre, tipo, descripcion,
                principal, permite_venta, activo
             )
             SELECT s.empresa_id, s.id, CONCAT(\'SUC-\', s.id), s.nombre,
                    \'SUCURSAL_VENTA\', \'Ubicacion de venta creada automaticamente\',
                    1, 1, 1
             FROM sucursales s
             WHERE s.id = :sucursal_id
               AND s.empresa_id = :empresa_id
               AND s.activo = 1
               AND NOT EXISTS (
                   SELECT 1
                   FROM ubicaciones_stock u
                   WHERE u.empresa_id = s.empresa_id
                     AND u.sucursal_id = s.id
                     AND u.tipo = \'SUCURSAL_VENTA\'
                     AND u.activo = 1
               )'
        );
        $statement->execute(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId]);
    }

    public function findLocation(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, codigo, nombre, tipo, descripcion,
                    direccion, principal, permite_venta, activo
             FROM ubicaciones_stock
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function defaultLocationForSucursal(int $empresaId, int $sucursalId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, codigo, nombre, tipo, principal, permite_venta, activo
             FROM ubicaciones_stock
             WHERE empresa_id = :empresa_id
               AND sucursal_id = :sucursal_id
               AND tipo = "SUCURSAL_VENTA"
               AND activo = 1
             ORDER BY principal DESC, id
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createTransfer(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO stock_traslados (
                empresa_id, producto_id, ubicacion_origen_id, ubicacion_destino_id,
                cantidad, estado, usuario_id, observacion
             ) VALUES (
                :empresa_id, :producto_id, :ubicacion_origen_id, :ubicacion_destino_id,
                :cantidad, "APLICADO", :usuario_id, :observacion
             )'
        );
        $statement->execute([
            'empresa_id' => $data['empresa_id'],
            'producto_id' => $data['producto_id'],
            'ubicacion_origen_id' => $data['ubicacion_origen_id'],
            'ubicacion_destino_id' => $data['ubicacion_destino_id'],
            'cantidad' => $data['cantidad'],
            'usuario_id' => $data['usuario_id'] ?? null,
            'observacion' => $data['observacion'] ?? null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function completeTransfer(int $transferId, int $outMovementId, int $inMovementId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE stock_traslados
             SET movimiento_salida_id = :movimiento_salida_id,
                 movimiento_entrada_id = :movimiento_entrada_id
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $transferId,
            'movimiento_salida_id' => $outMovementId,
            'movimiento_entrada_id' => $inMovementId,
        ]);
    }

    public function createLocation(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO ubicaciones_stock (
                empresa_id, sucursal_id, codigo, nombre, tipo, descripcion,
                direccion, principal, permite_venta, activo
             ) VALUES (
                :empresa_id, :sucursal_id, :codigo, :nombre, :tipo, :descripcion,
                :direccion, :principal, :permite_venta, :activo
             )'
        );
        $statement->execute($this->locationParams($data));

        return (int) $this->connection->lastInsertId();
    }

    public function updateLocation(int $id, int $empresaId, array $data): bool
    {
        $params = $this->locationParams($data);
        $params['id'] = $id;
        $params['empresa_id'] = $empresaId;
        $statement = $this->connection->prepare(
            'UPDATE ubicaciones_stock
             SET sucursal_id = :sucursal_id,
                 codigo = :codigo,
                 nombre = :nombre,
                 tipo = :tipo,
                 descripcion = :descripcion,
                 direccion = :direccion,
                 principal = :principal,
                 permite_venta = :permite_venta,
                 activo = :activo,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function deactivateLocation(int $id, int $empresaId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE ubicaciones_stock
             SET activo = 0,
                 deleted_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    public function locationHasStock(int $id, int $empresaId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM stock_ubicacion
             WHERE ubicacion_id = :id AND empresa_id = :empresa_id AND cantidad != 0
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function locationHasMovements(int $id, int $empresaId): bool
    {
        // Placeholders distintos: PDO con EMULATE_PREPARES=false no permite reusar
        // el mismo placeholder con nombre (lanza SQLSTATE[HY093]).
        $statement = $this->connection->prepare(
            'SELECT 1 FROM stock_movimientos
             WHERE (ubicacion_id = :id_ubic OR ubicacion_origen_id = :id_origen OR ubicacion_destino_id = :id_destino)
               AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute([
            'id_ubic'    => $id,
            'id_origen'  => $id,
            'id_destino' => $id,
            'empresa_id' => $empresaId,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function hardDeleteLocation(int $id, int $empresaId): bool
    {
        // Borra también la fila de stock_ubicacion (cantidad debe ser 0 per guarda en servicio)
        $this->connection->prepare(
            'DELETE FROM stock_ubicacion WHERE ubicacion_id = :id AND empresa_id = :empresa_id'
        )->execute(['id' => $id, 'empresa_id' => $empresaId]);

        $statement = $this->connection->prepare(
            'DELETE FROM ubicaciones_stock WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    public function findMovement(int $empresaId, int $movementId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, uuid, empresa_id, sucursal_id, dispositivo_id, producto_id, usuario_id,
                    tipo_movimiento, referencia_tipo, referencia_id, cantidad, stock_anterior,
                    stock_nuevo, costo_unitario, observacion
             FROM stock_movimientos
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $movementId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Productos cuyo stock disponible está en cero o por debajo del mínimo configurado.
     * Filtra por sucursal si se indica; si no, muestra todas las sucursales de la empresa.
     */
    public function listAlertas(int $empresaId, ?int $sucursalId, int $limit = 200): array
    {
        $sql = 'SELECT p.id AS producto_id, p.codigo, p.nombre, r.nombre AS rubro,
                       s.id AS sucursal_id, s.nombre AS sucursal_nombre,
                       COALESCE(ss.cantidad, 0.000) AS cantidad,
                       COALESCE(ss.reservado, 0.000) AS reservado,
                       (COALESCE(ss.cantidad, 0.000) - COALESCE(ss.reservado, 0.000)) AS disponible,
                       COALESCE(ss.stock_minimo, p.stock_minimo, 0.000) AS stock_minimo
                FROM productos p
                INNER JOIN stock_sucursal ss ON ss.producto_id = p.id AND ss.empresa_id = p.empresa_id
                INNER JOIN sucursales s ON s.id = ss.sucursal_id AND s.empresa_id = ss.empresa_id AND s.activo = 1
                LEFT JOIN rubros r ON r.id = p.rubro_id
                WHERE p.empresa_id = :empresa_id
                  AND p.activo = 1
                  AND p.controla_stock = 1
                  AND COALESCE(ss.stock_minimo, p.stock_minimo, 0.000) > 0
                  AND (COALESCE(ss.cantidad, 0.000) - COALESCE(ss.reservado, 0.000))
                      <= COALESCE(ss.stock_minimo, p.stock_minimo, 0.000)';
        $params = ['empresa_id' => $empresaId];

        if ($sucursalId !== null && $sucursalId > 0) {
            $sql .= ' AND ss.sucursal_id = :sucursal_id';
            $params['sucursal_id'] = $sucursalId;
        }

        $sql .= ' ORDER BY disponible ASC, p.nombre LIMIT ' . (int) $limit;
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    private function locationParams(array $data): array
    {
        return [
            'empresa_id' => (int) $data['empresa_id'],
            'sucursal_id' => isset($data['sucursal_id']) && (int) $data['sucursal_id'] > 0 ? (int) $data['sucursal_id'] : null,
            'codigo' => trim((string) $data['codigo']),
            'nombre' => trim((string) $data['nombre']),
            'tipo' => strtoupper((string) ($data['tipo'] ?? 'BODEGA')),
            'descripcion' => $data['descripcion'] ?? null,
            'direccion' => $data['direccion'] ?? null,
            'principal' => (int) ($data['principal'] ?? 0),
            'permite_venta' => (int) ($data['permite_venta'] ?? 0),
            'activo' => (int) ($data['activo'] ?? 1),
        ];
    }
}
