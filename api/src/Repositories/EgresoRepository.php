<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class EgresoRepository
{
    public function __construct(private readonly PDO $connection) {}

    public function connection(): PDO { return $this->connection; }

    public function schemaReady(): bool
    {
        $stmt = $this->connection->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'egresos\'
               AND COLUMN_NAME IN (\'quien_recibe\', \'motivo\')'
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn() === 2;
    }

    public function insert(array $data): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO egresos (empresa_id, sucursal_id, caja_apertura_id, usuario_id,
                fecha_egreso, quien_recibe, motivo, descripcion, monto, metodo_pago_codigo)
             VALUES (:empresa_id, :sucursal_id, :caja_apertura_id, :usuario_id,
                :fecha_egreso, :quien_recibe, :motivo, :descripcion, :monto, \'EFECTIVO\')'
        );
        $stmt->execute($data);
        return (int) $this->connection->lastInsertId();
    }

    public function linkMovement(int $id, int $movementId): void
    {
        $stmt = $this->connection->prepare('UPDATE egresos SET caja_movimiento_id = :movimiento_id WHERE id = :id');
        $stmt->execute(['id' => $id, 'movimiento_id' => $movementId]);
    }

    public function findForUpdate(int $empresaId, int $id): ?array
    {
        $stmt = $this->connection->prepare('SELECT * FROM egresos WHERE id = :id AND empresa_id = :empresa_id LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function cancel(int $id, int $userId, string $reason): void
    {
        $stmt = $this->connection->prepare(
            "UPDATE egresos SET estado = 'ANULADO', anulacion_motivo = :motivo, anulado_por_usuario_id = :usuario_id,
             anulado_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'usuario_id' => $userId, 'motivo' => $reason]);
    }

    public function list(int $empresaId, array $filters): array
    {
        $sql = 'SELECT e.*, COALESCE(e.motivo, e.descripcion) AS motivo, u.nombre AS usuario_nombre
                FROM egresos e INNER JOIN usuarios u ON u.id = e.usuario_id WHERE e.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];
        foreach (['sucursal_id'] as $field) {
            if (!empty($filters[$field])) { $sql .= " AND e.{$field} = :{$field}"; $params[$field] = (int) $filters[$field]; }
        }
        if (!empty($filters['estado'])) { $sql .= ' AND e.estado = :estado'; $params['estado'] = $filters['estado']; }
        if (!empty($filters['fecha_desde'])) { $sql .= ' AND e.fecha_egreso >= :fecha_desde'; $params['fecha_desde'] = $filters['fecha_desde']; }
        if (!empty($filters['fecha_hasta'])) { $sql .= ' AND e.fecha_egreso <= :fecha_hasta'; $params['fecha_hasta'] = $filters['fecha_hasta']; }
        $sql .= ' ORDER BY e.fecha_egreso DESC, e.id DESC LIMIT 300';
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
