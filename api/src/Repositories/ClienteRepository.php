<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class ClienteRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function rutExists(int $empresaId, string $rut, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM clientes WHERE empresa_id = :empresa_id AND rut = :rut AND deleted_at IS NULL';
        $params = ['empresa_id' => $empresaId, 'rut' => $rut];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return (bool) $statement->fetchColumn();
    }

    public function create(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO clientes (
                empresa_id, tipo_cliente, rut, nombre, razon_social, nombre_fantasia, giro,
                email, telefono, direccion, comuna, ciudad, activo, credito_habilitado,
                permite_credito, limite_credito, observacion
             ) VALUES (
                :empresa_id, :tipo_cliente, :rut, :nombre, :razon_social, :nombre_fantasia, :giro,
                :email, :telefono, :direccion, :comuna, :ciudad, :activo, :credito_habilitado,
                :permite_credito, :limite_credito, :observacion
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function list(int $empresaId, array $filters, int $limit, int $offset): array
    {
        $sql = 'SELECT id, empresa_id, tipo_cliente, rut, nombre, razon_social, giro, email,
                       telefono, direccion, comuna, ciudad, activo, permite_credito,
                       limite_credito, observacion, created_at, updated_at
                FROM clientes
                WHERE empresa_id = :empresa_id AND deleted_at IS NULL';
        $params = ['empresa_id' => $empresaId];

        if (!empty($filters['q'])) {
            $sql .= ' AND (nombre LIKE :q_nombre OR razon_social LIKE :q_razon OR rut LIKE :q_rut OR telefono LIKE :q_telefono OR email LIKE :q_email)';
            $query = '%' . (string) $filters['q'] . '%';
            $params['q_nombre'] = $query;
            $params['q_razon'] = $query;
            $params['q_rut'] = $query;
            $params['q_telefono'] = $query;
            $params['q_email'] = $query;
        }
        foreach (['activo', 'permite_credito'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $sql .= " AND {$field} = :{$field}";
                $params[$field] = filter_var($filters[$field], FILTER_VALIDATE_BOOL) ? 1 : 0;
            }
        }

        $sql .= " ORDER BY nombre LIMIT {$limit} OFFSET {$offset}";
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function find(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, tipo_cliente, rut, nombre, razon_social, giro, email,
                    telefono, direccion, comuna, ciudad, activo, permite_credito,
                    limite_credito, observacion, created_at, updated_at, deleted_at
             FROM clientes
             WHERE id = :id AND empresa_id = :empresa_id AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function update(int $empresaId, int $id, array $data): void
    {
        $statement = $this->connection->prepare(
            'UPDATE clientes
             SET tipo_cliente = :tipo_cliente,
                 rut = :rut,
                 nombre = :nombre,
                 razon_social = :razon_social,
                 nombre_fantasia = :nombre_fantasia,
                 giro = :giro,
                 email = :email,
                 telefono = :telefono,
                 direccion = :direccion,
                 comuna = :comuna,
                 ciudad = :ciudad,
                 activo = :activo,
                 credito_habilitado = :credito_habilitado,
                 permite_credito = :permite_credito,
                 limite_credito = :limite_credito,
                 observacion = :observacion,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id AND deleted_at IS NULL'
        );
        $data['id'] = $id;
        $data['empresa_id'] = $empresaId;
        $statement->execute($data);
    }

    public function softDelete(int $empresaId, int $id): void
    {
        $statement = $this->connection->prepare(
            'UPDATE clientes
             SET activo = 0, deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
    }
}
