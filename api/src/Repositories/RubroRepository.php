<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class RubroRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function list(int $empresaId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, nombre, descripcion, activo, created_at, updated_at
             FROM rubros
             WHERE empresa_id = :empresa_id
             ORDER BY nombre'
        );
        $statement->execute(['empresa_id' => $empresaId]);

        return $statement->fetchAll();
    }

    public function create(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO rubros (empresa_id, nombre, descripcion, activo)
             VALUES (:empresa_id, :nombre, :descripcion, 1)'
        );
        $statement->execute([
            'empresa_id' => $data['empresa_id'],
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $id, int $empresaId, array $data): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE rubros
             SET nombre = :nombre,
                 descripcion = :descripcion,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute([
            'id' => $id,
            'empresa_id' => $empresaId,
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
        ]);

        return $statement->rowCount() > 0;
    }

    public function deactivate(int $id, int $empresaId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE rubros
             SET activo = 0, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }
}
