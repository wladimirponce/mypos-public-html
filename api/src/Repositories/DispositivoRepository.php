<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class DispositivoRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function empresaExists(int $empresaId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM empresas WHERE id = :id AND activo = 1 LIMIT 1'
        );
        $statement->execute(['id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function sucursalExists(int $empresaId, int $sucursalId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1
             FROM sucursales
             WHERE id = :id AND empresa_id = :empresa_id AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $sucursalId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function findByUuid(int $empresaId, string $uuid): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, usuario_id, uuid_dispositivo, device_uuid,
                    nombre, tipo, estado, activo, ultimo_sync_at, metadata_json, created_at, updated_at
             FROM dispositivos
             WHERE empresa_id = :empresa_id
               AND (uuid_dispositivo = :uuid_dispositivo OR device_uuid = :device_uuid)
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'uuid_dispositivo' => $uuid,
            'device_uuid' => $uuid,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findById(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, usuario_id, uuid_dispositivo, device_uuid,
                    nombre, tipo, estado, activo, ultimo_sync_at, metadata_json, created_at, updated_at
             FROM dispositivos
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function create(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO dispositivos (
                empresa_id, sucursal_id, usuario_id, uuid_dispositivo, device_uuid,
                nombre, tipo, estado, activo, metadata_json, created_at, updated_at
             ) VALUES (
                :empresa_id, :sucursal_id, :usuario_id, :uuid_dispositivo, :device_uuid,
                :nombre, :tipo, :estado, 1, :metadata_json, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $id, int $empresaId, array $data): void
    {
        $statement = $this->connection->prepare(
            'UPDATE dispositivos
             SET sucursal_id = :sucursal_id,
                 usuario_id = :usuario_id,
                 nombre = :nombre,
                 tipo = :tipo,
                 estado = :estado,
                 activo = CASE WHEN :estado_activo = \'ACTIVO\' THEN 1 ELSE 0 END,
                 metadata_json = :metadata_json,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute([
            'id' => $id,
            'empresa_id' => $empresaId,
            'sucursal_id' => $data['sucursal_id'],
            'usuario_id' => $data['usuario_id'],
            'nombre' => $data['nombre'],
            'tipo' => $data['tipo'],
            'estado' => $data['estado'],
            'estado_activo' => $data['estado'],
            'metadata_json' => $data['metadata_json'],
        ]);
    }

    public function updateState(int $id, int $empresaId, string $estado, ?string $metadataJson): void
    {
        $statement = $this->connection->prepare(
            'UPDATE dispositivos
             SET estado = :estado,
                 activo = CASE WHEN :estado_activo = \'ACTIVO\' THEN 1 ELSE 0 END,
                 metadata_json = COALESCE(:metadata_json, metadata_json),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute([
            'id' => $id,
            'empresa_id' => $empresaId,
            'estado' => $estado,
            'estado_activo' => $estado,
            'metadata_json' => $metadataJson,
        ]);
    }

    public function touchSync(int $id, int $empresaId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE dispositivos
             SET ultimo_sync_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
    }

    public function list(int $empresaId, array $filters): array
    {
        $sql = 'SELECT id, empresa_id, sucursal_id, usuario_id, uuid_dispositivo,
                       nombre, tipo, estado, ultimo_sync_at, metadata_json, created_at, updated_at
                FROM dispositivos
                WHERE empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        if (isset($filters['sucursal_id']) && $filters['sucursal_id'] !== '') {
            $sql .= ' AND sucursal_id = :sucursal_id';
            $params['sucursal_id'] = (int) $filters['sucursal_id'];
        }

        if (!empty($filters['estado'])) {
            $sql .= ' AND estado = :estado';
            $params['estado'] = strtoupper((string) $filters['estado']);
        }

        $sql .= ' ORDER BY id DESC';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }
}
