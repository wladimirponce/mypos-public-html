<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class PermissionRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function userContext(int $userId, int $empresaId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT
                u.id AS usuario_id,
                u.activo AS usuario_activo,
                eu.empresa_id,
                eu.sucursal_id,
                eu.activo AS empresa_usuario_activo,
                r.id AS rol_id,
                r.codigo AS rol_codigo,
                r.activo AS rol_activo
             FROM usuarios u
             INNER JOIN empresa_usuarios eu ON eu.usuario_id = u.id
             INNER JOIN roles r ON r.id = eu.rol_id
             WHERE u.id = :usuario_id
               AND eu.empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['usuario_id' => $userId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function userHasPermission(int $userId, int $empresaId, string $permission): bool
    {
        $context = $this->userContext($userId, $empresaId);

        if ($context === null) {
            return false;
        }

        if ((int) $context['usuario_activo'] !== 1 || (int) $context['empresa_usuario_activo'] !== 1 || (int) $context['rol_activo'] !== 1) {
            return false;
        }

        if ((string) $context['rol_codigo'] === 'SUPER_ADMIN') {
            return true;
        }

        $statement = $this->connection->prepare(
            'SELECT 1
             FROM rol_permisos rp
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE rp.rol_id = :rol_id
               AND p.codigo = :permiso
               AND COALESCE(p.activo, 1) = 1
             LIMIT 1'
        );
        $statement->execute([
            'rol_id' => (int) $context['rol_id'],
            'permiso' => $permission,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function userPermissions(int $userId, int $empresaId): array
    {
        $context = $this->userContext($userId, $empresaId);

        if ($context === null || (int) $context['usuario_activo'] !== 1 || (int) $context['empresa_usuario_activo'] !== 1 || (int) $context['rol_activo'] !== 1) {
            return [];
        }

        if ((string) $context['rol_codigo'] === 'SUPER_ADMIN') {
            return array_map(
                static fn (array $row): string => (string) $row['codigo'],
                $this->listPermissions()
            );
        }

        return $this->rolePermissions((int) $context['rol_id']);
    }

    public function rolePermissions(int $roleId): array
    {
        $statement = $this->connection->prepare(
            'SELECT p.codigo
             FROM rol_permisos rp
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE rp.rol_id = :rol_id
               AND COALESCE(p.activo, 1) = 1
             ORDER BY p.codigo'
        );
        $statement->execute(['rol_id' => $roleId]);

        return array_map(static fn (array $row): string => (string) $row['codigo'], $statement->fetchAll());
    }

    public function listPermissions(): array
    {
        $statement = $this->connection->query(
            'SELECT id, codigo, nombre, descripcion
             FROM permisos
             ORDER BY codigo'
        );

        return $statement->fetchAll();
    }

    public function listRoles(): array
    {
        $statement = $this->connection->query(
            'SELECT id, codigo, nombre, descripcion, activo
             FROM roles
             ORDER BY id'
        );

        return $statement->fetchAll();
    }

    public function getRoleById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, codigo, nombre, descripcion, activo, created_at, updated_at
             FROM roles
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findRoleByCodigo(string $codigo): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, codigo, nombre, descripcion, activo
             FROM roles
             WHERE codigo = :codigo
             LIMIT 1'
        );
        $statement->execute(['codigo' => $codigo]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createRole(string $codigo, string $nombre, string $descripcion, int $activo): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO roles (codigo, nombre, descripcion, activo)
             VALUES (:codigo, :nombre, :descripcion, :activo)'
        );
        $statement->execute([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'activo' => $activo,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateRole(int $id, string $nombre, string $descripcion, int $activo): void
    {
        $statement = $this->connection->prepare(
            'UPDATE roles
             SET nombre = :nombre, descripcion = :descripcion, activo = :activo
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'activo' => $activo,
        ]);
    }

    public function deleteRole(int $id): void
    {
        $statement = $this->connection->prepare('DELETE FROM roles WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function isRoleAssigned(int $roleId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM empresa_usuarios WHERE rol_id = :rol_id LIMIT 1'
        );
        $statement->execute(['rol_id' => $roleId]);

        return (bool) $statement->fetchColumn();
    }

    public function getRolePermissionsIds(int $roleId): array
    {
        $statement = $this->connection->prepare(
            'SELECT permiso_id FROM rol_permisos WHERE rol_id = :rol_id'
        );
        $statement->execute(['rol_id' => $roleId]);

        return array_map(static fn (array $row): int => (int) $row['permiso_id'], $statement->fetchAll());
    }

    public function setRolePermissions(int $roleId, array $permissionIds): void
    {
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare('DELETE FROM rol_permisos WHERE rol_id = :rol_id');
            $statement->execute(['rol_id' => $roleId]);

            if ($permissionIds !== []) {
                $insert = $this->connection->prepare(
                    'INSERT INTO rol_permisos (rol_id, permiso_id) VALUES (:rol_id, :permiso_id)'
                );
                foreach ($permissionIds as $permId) {
                    $insert->execute([
                        'rol_id' => $roleId,
                        'permiso_id' => $permId,
                    ]);
                }
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }
}


