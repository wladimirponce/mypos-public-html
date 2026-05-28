<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class CajaRepository
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
            'SELECT 1 FROM sucursales WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1'
        );
        $statement->execute(['id' => $sucursalId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function createBox(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cajas (empresa_id, sucursal_id, codigo, nombre, activo)
             VALUES (:empresa_id, :sucursal_id, :codigo, :nombre, 1)'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function listBoxes(int $empresaId, array $filters): array
    {
        $sql = 'SELECT id, empresa_id, sucursal_id, codigo, nombre, activo AS activa, created_at, updated_at
                FROM cajas
                WHERE empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        if (!empty($filters['sucursal_id'])) {
            $sql .= ' AND sucursal_id = :sucursal_id';
            $params['sucursal_id'] = (int) $filters['sucursal_id'];
        }

        if (isset($filters['activa']) && $filters['activa'] !== '') {
            $sql .= ' AND activo = :activo';
            $params['activo'] = (int) $filters['activa'];
        }

        $sql .= ' ORDER BY sucursal_id, codigo';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function findBox(int $empresaId, int $boxId, ?int $sucursalId = null): ?array
    {
        $sql = 'SELECT id, empresa_id, sucursal_id, codigo, nombre, activo
                FROM cajas
                WHERE id = :id AND empresa_id = :empresa_id';
        $params = ['id' => $boxId, 'empresa_id' => $empresaId];

        if ($sucursalId !== null) {
            $sql .= ' AND sucursal_id = :sucursal_id';
            $params['sucursal_id'] = $sucursalId;
        }

        $sql .= ' LIMIT 1';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findOpenByBoxForUpdate(int $empresaId, int $boxId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, caja_id, usuario_id, fecha_apertura, monto_inicial, estado
             FROM caja_aperturas
             WHERE empresa_id = :empresa_id AND caja_id = :caja_id AND estado = \'ABIERTA\'
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['empresa_id' => $empresaId, 'caja_id' => $boxId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findOpenByUserSucursalForUpdate(int $empresaId, int $sucursalId, int $userId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, caja_id
             FROM caja_aperturas
             WHERE empresa_id = :empresa_id
               AND sucursal_id = :sucursal_id
               AND usuario_id = :usuario_id
               AND estado = \'ABIERTA\'
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId, 'usuario_id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createOpening(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO caja_aperturas (
                empresa_id, sucursal_id, caja_id, usuario_id, fecha_apertura, monto_inicial, estado, observacion_apertura
             ) VALUES (
                :empresa_id, :sucursal_id, :caja_id, :usuario_id, CURRENT_TIMESTAMP, :monto_inicial, \'ABIERTA\', :observacion_apertura
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function openStatus(int $empresaId, int $sucursalId, ?int $boxId): ?array
    {
        $sql = 'SELECT ca.id AS caja_apertura_id, ca.caja_id, c.codigo, c.nombre,
                       ca.usuario_id, ca.estado, ca.monto_inicial, ca.fecha_apertura
                FROM caja_aperturas ca
                INNER JOIN cajas c ON c.id = ca.caja_id
                WHERE ca.empresa_id = :empresa_id
                  AND ca.sucursal_id = :sucursal_id
                  AND ca.estado = \'ABIERTA\'';
        $params = ['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId];

        if ($boxId !== null) {
            $sql .= ' AND ca.caja_id = :caja_id';
            $params['caja_id'] = $boxId;
        }

        $sql .= ' ORDER BY ca.fecha_apertura DESC LIMIT 1';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findOpeningForUpdate(int $empresaId, int $openingId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT ca.id, ca.empresa_id, ca.sucursal_id, ca.caja_id, ca.usuario_id,
                    ca.fecha_apertura, ca.monto_inicial, ca.estado, c.codigo, c.nombre
             FROM caja_aperturas ca
             INNER JOIN cajas c ON c.id = ca.caja_id
             WHERE ca.id = :id AND ca.empresa_id = :empresa_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $openingId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function insertMovement(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO caja_movimientos (
                empresa_id, sucursal_id, caja_apertura_id, usuario_id, tipo, concepto, monto, observacion
             ) VALUES (
                :empresa_id, :sucursal_id, :caja_apertura_id, :usuario_id, :tipo, :concepto, :monto, :observacion
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function listMovements(int $empresaId, int $boxId, ?int $openingId = null): array
    {
        $sql = 'SELECT cm.id, cm.empresa_id, cm.sucursal_id, cm.caja_apertura_id,
                       ca.caja_id, cm.usuario_id, cm.tipo, cm.concepto, cm.monto,
                       cm.observacion, cm.created_at
                FROM caja_movimientos cm
                INNER JOIN caja_aperturas ca ON ca.id = cm.caja_apertura_id
                WHERE cm.empresa_id = :empresa_id AND ca.caja_id = :caja_id';
        $params = ['empresa_id' => $empresaId, 'caja_id' => $boxId];

        if ($openingId !== null) {
            $sql .= ' AND cm.caja_apertura_id = :caja_apertura_id';
            $params['caja_apertura_id'] = $openingId;
        }

        $sql .= ' ORDER BY cm.id DESC';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function closureExists(int $empresaId, int $openingId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM caja_cierres
             WHERE empresa_id = :empresa_id
               AND (apertura_id = :apertura_id OR caja_apertura_id = :caja_apertura_id)
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'apertura_id' => $openingId,
            'caja_apertura_id' => $openingId,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function paymentTotals(int $empresaId, int $openingId): array
    {
        $statement = $this->connection->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN vp.metodo_pago_codigo = \'EFECTIVO\' THEN vp.monto ELSE 0 END), 0) AS efectivo,
                COALESCE(SUM(CASE WHEN vp.metodo_pago_codigo IN (\'DEBITO\', \'CREDITO\', \'TARJETA\') THEN vp.monto ELSE 0 END), 0) AS tarjeta,
                COALESCE(SUM(CASE WHEN vp.metodo_pago_codigo = \'TRANSFERENCIA\' THEN vp.monto ELSE 0 END), 0) AS transferencia,
                COALESCE(SUM(CASE WHEN vp.metodo_pago_codigo NOT IN (\'EFECTIVO\', \'DEBITO\', \'CREDITO\', \'TARJETA\', \'TRANSFERENCIA\') THEN vp.monto ELSE 0 END), 0) AS otros
             FROM ventas v
             INNER JOIN venta_pagos vp ON vp.venta_id = v.id
             WHERE v.empresa_id = :empresa_id
               AND v.estado = \'EMITIDA\'
               AND (v.caja_apertura_id = :caja_apertura_id OR v.apertura_id = :apertura_id)'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'caja_apertura_id' => $openingId,
            'apertura_id' => $openingId,
        ]);

        return $statement->fetch() ?: ['efectivo' => 0, 'tarjeta' => 0, 'transferencia' => 0, 'otros' => 0];
    }

    public function movementTotals(int $empresaId, int $openingId): array
    {
        $statement = $this->connection->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN tipo = \'INGRESO\' THEN monto ELSE 0 END), 0) AS ingresos,
                COALESCE(SUM(CASE WHEN tipo = \'RETIRO\' THEN monto ELSE 0 END), 0) AS retiros
             FROM caja_movimientos
             WHERE empresa_id = :empresa_id AND caja_apertura_id = :caja_apertura_id'
        );
        $statement->execute(['empresa_id' => $empresaId, 'caja_apertura_id' => $openingId]);

        return $statement->fetch() ?: ['ingresos' => 0, 'retiros' => 0];
    }

    public function insertClosure(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO caja_cierres (
                empresa_id, sucursal_id, caja_id, caja_apertura_id, apertura_id, usuario_id, fecha_cierre,
                monto_inicial, total_ventas_efectivo, total_ventas_tarjeta, total_ventas_transferencia,
                total_ventas_otros, total_ingresos, total_retiros, monto_esperado, monto_contado,
                monto_declarado, monto_sistema, diferencia, observacion, observacion_cierre
             ) VALUES (
                :empresa_id, :sucursal_id, :caja_id, :caja_apertura_id, :apertura_id, :usuario_id, CURRENT_TIMESTAMP,
                :monto_inicial, :total_ventas_efectivo, :total_ventas_tarjeta, :total_ventas_transferencia,
                :total_ventas_otros, :total_ingresos, :total_retiros, :monto_esperado, :monto_contado,
                :monto_declarado, :monto_sistema, :diferencia, :observacion, :observacion_cierre
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function markOpeningClosed(int $empresaId, int $openingId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE caja_aperturas
             SET estado = \'CERRADA\', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $openingId, 'empresa_id' => $empresaId]);
    }

    public function listClosures(int $empresaId, array $filters): array
    {
        $sql = 'SELECT cc.id, cc.empresa_id, cc.sucursal_id, cc.caja_id, c.codigo, c.nombre,
                       cc.caja_apertura_id, cc.apertura_id, cc.usuario_id, u.nombre AS usuario,
                       cc.fecha_cierre, cc.monto_inicial, cc.total_ventas_efectivo,
                       cc.total_ventas_tarjeta, cc.total_ventas_transferencia,
                       cc.total_ventas_otros, cc.total_ingresos, cc.total_retiros,
                       cc.monto_esperado, cc.monto_contado, cc.diferencia, cc.observacion_cierre
                FROM caja_cierres cc
                INNER JOIN cajas c ON c.id = cc.caja_id
                INNER JOIN usuarios u ON u.id = cc.usuario_id
                WHERE cc.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        foreach (['sucursal_id', 'caja_id', 'usuario_id'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND cc.{$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }

        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND DATE(cc.fecha_cierre) >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }

        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND DATE(cc.fecha_cierre) <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }

        $sql .= ' ORDER BY cc.fecha_cierre DESC LIMIT 300';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function closureDetail(int $empresaId, int $closureId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT cc.id, cc.empresa_id, cc.sucursal_id, cc.caja_id, c.codigo, c.nombre,
                    cc.caja_apertura_id, cc.apertura_id, cc.usuario_id, u.nombre AS usuario,
                    cc.fecha_cierre, cc.monto_inicial, cc.total_ventas_efectivo,
                    cc.total_ventas_tarjeta, cc.total_ventas_transferencia,
                    cc.total_ventas_otros, cc.total_ingresos, cc.total_retiros,
                    cc.monto_esperado, cc.monto_contado, cc.diferencia,
                    cc.observacion_cierre, ca.fecha_apertura, ca.observacion_apertura
             FROM caja_cierres cc
             INNER JOIN cajas c ON c.id = cc.caja_id
             INNER JOIN caja_aperturas ca ON ca.id = cc.apertura_id
             INNER JOIN usuarios u ON u.id = cc.usuario_id
             WHERE cc.id = :id AND cc.empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $closureId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function paymentSummaryForOpening(int $empresaId, int $openingId): array
    {
        $statement = $this->connection->prepare(
            'SELECT vp.metodo_pago_id, vp.metodo_pago_codigo AS codigo,
                    COALESCE(mp.nombre, vp.metodo_pago_codigo) AS nombre,
                    COALESCE(SUM(vp.monto), 0) AS total,
                    COUNT(vp.id) AS cantidad_operaciones
             FROM ventas v
             INNER JOIN venta_pagos vp ON vp.venta_id = v.id
             LEFT JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
             WHERE v.empresa_id = :empresa_id
               AND v.estado = \'EMITIDA\'
               AND (v.caja_apertura_id = :caja_apertura_id OR v.apertura_id = :apertura_id)
             GROUP BY vp.metodo_pago_id, vp.metodo_pago_codigo, mp.nombre
             ORDER BY total DESC'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'caja_apertura_id' => $openingId,
            'apertura_id' => $openingId,
        ]);

        return $statement->fetchAll();
    }
}
