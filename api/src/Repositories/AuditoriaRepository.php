<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class AuditoriaRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function insert(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO auditoria_eventos (
                empresa_id, sucursal_id, usuario_id, modulo, accion, entidad, entidad_id,
                descripcion, datos_anteriores_json, datos_nuevos_json, metadata_json,
                ip, user_agent, dispositivo_id, severidad, resultado
             ) VALUES (
                :empresa_id, :sucursal_id, :usuario_id, :modulo, :accion, :entidad, :entidad_id,
                :descripcion, :datos_anteriores_json, :datos_nuevos_json, :metadata_json,
                :ip, :user_agent, :dispositivo_id, :severidad, :resultado
             )'
        );
        $statement->execute($data);
    }

    public function list(int $empresaId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($empresaId, $filters);
        $sql = "SELECT id, empresa_id, sucursal_id, usuario_id, modulo, accion,
                       entidad, entidad_id, descripcion, severidad, resultado, created_at
                FROM auditoria_eventos
                WHERE {$where}
                ORDER BY id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function find(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, usuario_id, modulo, accion, entidad, entidad_id,
                    descripcion, datos_anteriores_json, datos_nuevos_json, metadata_json,
                    ip, user_agent, dispositivo_id, severidad, resultado, created_at
             FROM auditoria_eventos
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function where(int $empresaId, array $filters): array
    {
        $where = 'empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        foreach (['sucursal_id', 'usuario_id', 'entidad_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $where .= " AND {$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }

        foreach (['modulo', 'accion', 'entidad', 'severidad', 'resultado'] as $field) {
            if (!empty($filters[$field])) {
                $where .= " AND {$field} = :{$field}";
                $params[$field] = strtoupper((string) $filters[$field]);
                if (!in_array($field, ['severidad', 'resultado'], true)) {
                    $params[$field] = strtolower((string) $filters[$field]);
                }
            }
        }

        if (!empty($filters['fecha_desde'])) {
            $where .= ' AND DATE(created_at) >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }

        if (!empty($filters['fecha_hasta'])) {
            $where .= ' AND DATE(created_at) <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }

        return [$where, $params];
    }
}
