<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class CreditoRepository
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
        $statement = $this->connection->prepare('SELECT 1 FROM sucursales WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1');
        $statement->execute(['id' => $sucursalId, 'empresa_id' => $empresaId]);
        return (bool) $statement->fetchColumn();
    }

    public function clienteCredito(int $empresaId, int $clienteId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, rut, nombre, razon_social, permite_credito, limite_credito, activo
             FROM clientes
             WHERE id = :id AND empresa_id = :empresa_id AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $clienteId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function deudaPendienteCliente(int $empresaId, int $clienteId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COALESCE(SUM(saldo_pendiente), 0)
             FROM creditos_clientes
             WHERE empresa_id = :empresa_id
               AND cliente_id = :cliente_id
               AND estado IN (\'PENDIENTE\', \'PARCIAL\')'
        );
        $statement->execute(['empresa_id' => $empresaId, 'cliente_id' => $clienteId]);
        return (int) $statement->fetchColumn();
    }

    public function crearCredito(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO creditos_clientes (
                empresa_id, sucursal_id, cliente_id, venta_id, monto_original,
                monto_pagado, saldo_pendiente, estado, fecha_credito,
                fecha_vencimiento, observacion, created_by_usuario_id
             ) VALUES (
                :empresa_id, :sucursal_id, :cliente_id, :venta_id, :monto_original,
                0, :saldo_pendiente, \'PENDIENTE\', CURRENT_TIMESTAMP,
                :fecha_vencimiento, :observacion, :created_by_usuario_id
             )'
        );
        $statement->execute($data);
        return (int) $this->connection->lastInsertId();
    }

    public function list(int $empresaId, array $filters): array
    {
        $sql = 'SELECT cc.id, cc.empresa_id, cc.sucursal_id, cc.cliente_id, c.nombre AS cliente_nombre,
                       c.rut AS cliente_rut, cc.venta_id, cc.monto_original, cc.monto_pagado,
                       cc.saldo_pendiente, cc.estado, cc.fecha_credito, cc.fecha_vencimiento,
                       cc.observacion, cc.created_at, cc.updated_at
                FROM creditos_clientes cc
                INNER JOIN clientes c ON c.id = cc.cliente_id
                WHERE cc.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        foreach (['sucursal_id', 'cliente_id'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND cc.{$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }
        if (!empty($filters['estado'])) {
            $sql .= ' AND cc.estado = :estado';
            $params['estado'] = strtoupper((string) $filters['estado']);
        }
        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND DATE(cc.fecha_credito) >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }
        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND DATE(cc.fecha_credito) <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }
        $sql .= ' ORDER BY cc.id DESC LIMIT 300';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function findForUpdate(int $empresaId, int $creditId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, cliente_id, venta_id, monto_original,
                    monto_pagado, saldo_pendiente, estado
             FROM creditos_clientes
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $creditId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function find(int $empresaId, int $creditId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT cc.id, cc.empresa_id, cc.sucursal_id, cc.cliente_id, cc.venta_id,
                    cc.monto_original, cc.monto_pagado, cc.saldo_pendiente, cc.estado,
                    cc.fecha_credito, cc.fecha_vencimiento, cc.observacion, cc.created_at, cc.updated_at
             FROM creditos_clientes cc
             WHERE cc.id = :id AND cc.empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $creditId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function pagos(int $empresaId, int $creditId): array
    {
        $statement = $this->connection->prepare(
            'SELECT cp.id, cp.credito_cliente_id, cp.cliente_id, cp.usuario_id,
                    cp.caja_apertura_id, cp.metodo_pago_id, mp.codigo AS metodo_pago_codigo,
                    mp.nombre AS metodo_pago_nombre, cp.monto, cp.fecha_pago, cp.observacion
             FROM creditos_pagos cp
             INNER JOIN metodos_pago mp ON mp.id = cp.metodo_pago_id
             WHERE cp.empresa_id = :empresa_id AND cp.credito_cliente_id = :credito_id
             ORDER BY cp.id'
        );
        $statement->execute(['empresa_id' => $empresaId, 'credito_id' => $creditId]);
        return $statement->fetchAll();
    }

    public function metodoPagoActivo(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT id, codigo, nombre FROM metodos_pago WHERE id = :id AND activo = 1 LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function cajaAbierta(int $empresaId, int $sucursalId, int $openingId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, caja_id, estado
             FROM caja_aperturas
             WHERE id = :id AND empresa_id = :empresa_id AND sucursal_id = :sucursal_id AND estado = \'ABIERTA\'
             LIMIT 1'
        );
        $statement->execute(['id' => $openingId, 'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function insertarPago(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO creditos_pagos (
                empresa_id, sucursal_id, credito_cliente_id, cliente_id, usuario_id,
                caja_apertura_id, metodo_pago_id, monto, fecha_pago, observacion
             ) VALUES (
                :empresa_id, :sucursal_id, :credito_cliente_id, :cliente_id, :usuario_id,
                :caja_apertura_id, :metodo_pago_id, :monto, CURRENT_TIMESTAMP, :observacion
             )'
        );
        $statement->execute($data);
        return (int) $this->connection->lastInsertId();
    }

    public function actualizarSaldo(int $creditId, int $paid, int $balance, string $state): void
    {
        $statement = $this->connection->prepare(
            'UPDATE creditos_clientes
             SET monto_pagado = :monto_pagado,
                 saldo_pendiente = :saldo_pendiente,
                 estado = :estado,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute(['id' => $creditId, 'monto_pagado' => $paid, 'saldo_pendiente' => $balance, 'estado' => $state]);
    }

    public function marcarAnuladoPorVenta(int $empresaId, int $ventaId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE creditos_clientes
             SET estado = \'ANULADO\', saldo_pendiente = 0, updated_at = CURRENT_TIMESTAMP
             WHERE empresa_id = :empresa_id AND venta_id = :venta_id AND estado = \'PENDIENTE\' AND monto_pagado = 0'
        );
        $statement->execute(['empresa_id' => $empresaId, 'venta_id' => $ventaId]);
    }

    public function detalleCliente(int $empresaId, int $clienteId): ?array
    {
        return $this->clienteCredito($empresaId, $clienteId);
    }

    public function resumenCliente(int $empresaId, int $clienteId): array
    {
        $statement = $this->connection->prepare(
            'SELECT COALESCE(SUM(monto_original), 0) AS total_creditos,
                    COALESCE(SUM(monto_pagado), 0) AS total_pagado,
                    COALESCE(SUM(saldo_pendiente), 0) AS saldo_pendiente,
                    SUM(CASE WHEN estado IN (\'PENDIENTE\', \'PARCIAL\') THEN 1 ELSE 0 END) AS creditos_pendientes
             FROM creditos_clientes
             WHERE empresa_id = :empresa_id AND cliente_id = :cliente_id AND estado <> \'ANULADO\''
        );
        $statement->execute(['empresa_id' => $empresaId, 'cliente_id' => $clienteId]);
        return $statement->fetch() ?: [];
    }

    public function pagosCliente(int $empresaId, int $clienteId, array $filters): array
    {
        $sql = 'SELECT id, credito_cliente_id, metodo_pago_id, monto, fecha_pago, observacion
                FROM creditos_pagos
                WHERE empresa_id = :empresa_id AND cliente_id = :cliente_id';
        $params = ['empresa_id' => $empresaId, 'cliente_id' => $clienteId];
        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND DATE(fecha_pago) >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }
        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND DATE(fecha_pago) <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }
        $sql .= ' ORDER BY fecha_pago DESC';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function ventasCliente(int $empresaId, int $clienteId, array $filters): array
    {
        $sql = 'SELECT id, tipo_venta, condicion_pago, total, estado, fecha_venta
                FROM ventas
                WHERE empresa_id = :empresa_id AND cliente_id = :cliente_id';
        $params = ['empresa_id' => $empresaId, 'cliente_id' => $clienteId];
        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND DATE(fecha_venta) >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }
        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND DATE(fecha_venta) <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }
        $sql .= ' ORDER BY fecha_venta DESC LIMIT 200';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function venta(int $empresaId, int $ventaId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, cliente_id, tipo_venta, condicion_pago,
                    total, estado, fecha_venta
             FROM ventas
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $ventaId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function documentosCliente(int $empresaId, int $clienteId, array $filters): array
    {
        $sql = 'SELECT id, venta_id, tipo_documento, folio, estado, fecha_emision, total
                FROM documentos_emitidos
                WHERE empresa_id = :empresa_id AND cliente_id = :cliente_id';
        $params = ['empresa_id' => $empresaId, 'cliente_id' => $clienteId];
        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND DATE(fecha_emision) >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }
        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND DATE(fecha_emision) <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }
        $sql .= ' ORDER BY fecha_emision DESC LIMIT 200';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }
}
