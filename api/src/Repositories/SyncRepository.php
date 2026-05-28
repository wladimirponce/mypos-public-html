<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class SyncRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function connection(): PDO
    {
        return $this->connection;
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

    public function deviceById(int $empresaId, int $deviceId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, uuid_dispositivo, nombre, tipo, estado, ultimo_sync_at
             FROM dispositivos
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $deviceId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function eventByUuid(int $empresaId, string $uuid): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, uuid_evento, tipo_evento, entidad, entidad_uuid, entidad_id,
                    estado, resultado_json, error_mensaje, created_at, procesado_at
             FROM sync_eventos
             WHERE empresa_id = :empresa_id AND uuid_evento = :uuid_evento
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'uuid_evento' => $uuid]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function saleByUuidOffline(int $empresaId, string $uuid): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, total, uuid_offline, origen, sync_estado
             FROM ventas
             WHERE empresa_id = :empresa_id
               AND (uuid_offline = :uuid_offline OR uuid = :uuid)
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'uuid_offline' => $uuid,
            'uuid' => $uuid,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createEvent(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO sync_eventos (
                empresa_id, sucursal_id, dispositivo_id, usuario_id, uuid_evento,
                tipo_evento, entidad, entidad_uuid, entidad_id, operacion, estado,
                payload, payload_json, created_at
             ) VALUES (
                :empresa_id, :sucursal_id, :dispositivo_id, :usuario_id, :uuid_evento,
                :tipo_evento, :entidad, :entidad_uuid, NULL, \'SYNC_IN\', \'RECIBIDO\',
                :payload_json_legacy, :payload_json, CURRENT_TIMESTAMP
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function updateEvent(
        int $eventId,
        string $estado,
        ?int $entidadId,
        ?string $resultadoJson,
        ?string $errorMensaje
    ): void {
        $statement = $this->connection->prepare(
            'UPDATE sync_eventos
             SET estado = :estado,
                 entidad_id = :entidad_id,
                 resultado_json = :resultado_json,
                 error_mensaje = :error_mensaje,
                 procesado_at = CURRENT_TIMESTAMP,
                 processed_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $eventId,
            'estado' => $estado,
            'entidad_id' => $entidadId,
            'resultado_json' => $resultadoJson,
            'error_mensaje' => $errorMensaje,
        ]);
    }

    public function createConflict(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO sync_conflictos (
                empresa_id, sucursal_id, dispositivo_id, sync_evento_id, tipo_conflicto,
                entidad, entidad_uuid, entidad_id, descripcion, payload_json, resolucion, created_at
             ) VALUES (
                :empresa_id, :sucursal_id, :dispositivo_id, :sync_evento_id, :tipo_conflicto,
                :entidad, :entidad_uuid, :entidad_id, :descripcion, :payload_json, \'PENDIENTE\', CURRENT_TIMESTAMP
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function paymentMethodById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, codigo, nombre
             FROM metodos_pago
             WHERE id = :id AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function listEvents(int $empresaId, array $filters, int $limit, int $offset): array
    {
        $sql = 'SELECT id, empresa_id, sucursal_id, dispositivo_id, usuario_id, uuid_evento,
                       tipo_evento, entidad, entidad_uuid, entidad_id, estado,
                       error_mensaje, created_at, procesado_at
                FROM sync_eventos
                WHERE empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        foreach (['dispositivo_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $sql .= " AND {$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }

        foreach (['estado', 'tipo_evento'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND {$field} = :{$field}";
                $params[$field] = strtoupper((string) $filters[$field]);
            }
        }

        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND DATE(created_at) >= :fecha_desde';
            $params['fecha_desde'] = (string) $filters['fecha_desde'];
        }

        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND DATE(created_at) <= :fecha_hasta';
            $params['fecha_hasta'] = (string) $filters['fecha_hasta'];
        }

        $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
        $statement = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function listConflicts(int $empresaId, array $filters): array
    {
        $sql = 'SELECT id, empresa_id, sucursal_id, dispositivo_id, sync_evento_id,
                       tipo_conflicto, entidad, entidad_uuid, entidad_id, descripcion,
                       resolucion, comentario_resolucion, resuelto_por_usuario_id,
                       resuelto_at, created_at
                FROM sync_conflictos
                WHERE empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        if (!empty($filters['dispositivo_id'])) {
            $sql .= ' AND dispositivo_id = :dispositivo_id';
            $params['dispositivo_id'] = (int) $filters['dispositivo_id'];
        }

        if (!empty($filters['resolucion'])) {
            $sql .= ' AND resolucion = :resolucion';
            $params['resolucion'] = strtoupper((string) $filters['resolucion']);
        }

        $sql .= ' ORDER BY id DESC LIMIT 500';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function findConflict(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, dispositivo_id, sync_evento_id,
                    tipo_conflicto, entidad, entidad_uuid, entidad_id, descripcion,
                    resolucion, created_at
             FROM sync_conflictos
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function resolveConflict(int $empresaId, int $id, int $userId, string $resolution, ?string $comment): void
    {
        $statement = $this->connection->prepare(
            'UPDATE sync_conflictos
             SET resolucion = :resolucion,
                 comentario_resolucion = :comentario,
                 resuelto_por_usuario_id = :usuario_id,
                 resuelto_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute([
            'id' => $id,
            'empresa_id' => $empresaId,
            'resolucion' => $resolution,
            'comentario' => $comment,
            'usuario_id' => $userId,
        ]);
    }
}
