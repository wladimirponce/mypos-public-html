<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class ValeCreditoRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function insertar(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO vales_credito (
                empresa_id, sucursal_id, cliente_id, codigo, monto_original, saldo,
                estado, origen, referencia_tipo, referencia_id, fecha_vencimiento,
                observacion, created_by
             ) VALUES (
                :empresa_id, :sucursal_id, :cliente_id, :codigo, :monto_original, :saldo,
                \'ACTIVO\', :origen, :referencia_tipo, :referencia_id, :fecha_vencimiento,
                :observacion, :created_by
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function insertarMovimiento(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO vale_credito_movimientos (
                empresa_id, vale_id, tipo, monto, saldo_resultante, venta_id, usuario_id, observacion
             ) VALUES (
                :empresa_id, :vale_id, :tipo, :monto, :saldo_resultante, :venta_id, :usuario_id, :observacion
             )'
        );
        $statement->execute($data);
    }

    public function findPorCodigo(int $empresaId, string $codigo, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM vales_credito WHERE empresa_id = :empresa_id AND codigo = :codigo LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute(['empresa_id' => $empresaId, 'codigo' => $codigo]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function find(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT v.*, c.nombre AS cliente_nombre, c.rut AS cliente_rut
             FROM vales_credito v
             LEFT JOIN clientes c ON c.id = v.cliente_id AND c.empresa_id = v.empresa_id
             WHERE v.empresa_id = :empresa_id AND v.id = :id
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function actualizarSaldo(int $id, int $saldo, string $estado): void
    {
        $statement = $this->connection->prepare(
            'UPDATE vales_credito SET saldo = :saldo, estado = :estado, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute(['saldo' => $saldo, 'estado' => $estado, 'id' => $id]);
    }

    public function listar(int $empresaId, array $filters): array
    {
        $sql = 'SELECT v.*, c.nombre AS cliente_nombre, c.rut AS cliente_rut
                FROM vales_credito v
                LEFT JOIN clientes c ON c.id = v.cliente_id AND c.empresa_id = v.empresa_id
                WHERE v.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        if (!empty($filters['estado'])) {
            $sql .= ' AND v.estado = :estado';
            $params['estado'] = strtoupper((string) $filters['estado']);
        }
        if (!empty($filters['origen'])) {
            $sql .= ' AND v.origen = :origen';
            $params['origen'] = strtoupper((string) $filters['origen']);
        }
        if (!empty($filters['q'])) {
            $sql .= ' AND (v.codigo LIKE :q_codigo OR c.nombre LIKE :q_nombre OR c.rut LIKE :q_rut)';
            $term = '%' . trim((string) $filters['q']) . '%';
            $params['q_codigo'] = $term;
            $params['q_nombre'] = $term;
            $params['q_rut'] = $term;
        }

        $sql .= ' ORDER BY v.id DESC LIMIT 200';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function movimientos(int $empresaId, int $valeId): array
    {
        $statement = $this->connection->prepare(
            'SELECT m.*, u.nombre AS usuario_nombre
             FROM vale_credito_movimientos m
             LEFT JOIN usuarios u ON u.id = m.usuario_id
             WHERE m.empresa_id = :empresa_id AND m.vale_id = :vale_id
             ORDER BY m.id'
        );
        $statement->execute(['empresa_id' => $empresaId, 'vale_id' => $valeId]);

        return $statement->fetchAll();
    }

    public function codigoExiste(int $empresaId, string $codigo): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM vales_credito WHERE empresa_id = :empresa_id AND codigo = :codigo LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'codigo' => $codigo]);

        return (bool) $statement->fetchColumn();
    }
}
