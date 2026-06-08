<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class AuthRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function createUser(string $nombre, string $email, string $passwordHash, ?string $emailVerificationToken = null): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO usuarios (nombre, email, password_hash, activo, email_verificado, email_verification_token)
             VALUES (:nombre, :email, :password_hash, 1, 0, :email_verification_token)'
        );

        $statement->execute([
            'nombre' => $nombre,
            'email' => $email,
            'password_hash' => $passwordHash,
            'email_verification_token' => $emailVerificationToken,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, nombre, email, password_hash, activo, email_verificado, email_verification_token
             FROM usuarios
             WHERE email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);

        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserById(int $userId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, nombre, email, activo, email_verificado, email_verification_token
             FROM usuarios
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);

        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function updateLastLogin(int $userId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE usuarios SET ultimo_login_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function empresasByUserId(int $userId): array
    {
        $statement = $this->connection->prepare(
            'SELECT
                eu.empresa_id,
                e.razon_social,
                e.nombre_fantasia,
                e.onboarding_completado,
                r.codigo AS rol,
                eu.sucursal_id,
                s.nombre AS sucursal_nombre,
                (ec.empresa_id IS NULL OR ec.giro IS NULL OR ec.giro = \'\') AS sii_pendiente
             FROM empresa_usuarios eu
             INNER JOIN empresas e ON e.id = eu.empresa_id
             INNER JOIN roles r ON r.id = eu.rol_id
             LEFT JOIN sucursales s ON s.id = eu.sucursal_id
             LEFT JOIN empresa_configuracion ec ON ec.empresa_id = e.id
             WHERE eu.usuario_id = :user_id
               AND eu.activo = 1
               AND e.activo = 1
             ORDER BY e.nombre_fantasia, s.nombre'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, string>
     */
    public function permisosByUserId(int $userId): array
    {
        $statement = $this->connection->prepare(
            'SELECT DISTINCT p.codigo
             FROM empresa_usuarios eu
             INNER JOIN rol_permisos rp ON rp.rol_id = eu.rol_id
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE eu.usuario_id = :user_id
               AND eu.activo = 1
             ORDER BY p.codigo'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(static fn (array $row): string => (string) $row['codigo'], $statement->fetchAll());
    }

    public function userHasEmpresaContext(int $userId, int $empresaId, ?int $sucursalId = null): bool
    {
        $sql = 'SELECT 1
                FROM empresa_usuarios eu
                WHERE eu.usuario_id = :user_id
                  AND eu.empresa_id = :empresa_id
                  AND eu.activo = 1';
        $params = [
            'user_id' => $userId,
            'empresa_id' => $empresaId,
        ];

        if ($sucursalId !== null) {
            $sql .= ' AND (eu.sucursal_id = :sucursal_id OR eu.sucursal_id IS NULL)';
            $params['sucursal_id'] = $sucursalId;
        }

        $sql .= ' LIMIT 1';

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return (bool) $statement->fetchColumn();
    }

    public function setUserVerificationToken(int $userId, ?string $token): void
    {
        $statement = $this->connection->prepare(
            'UPDATE usuarios SET email_verification_token = :token WHERE id = :id'
        );
        $statement->execute([
            'token' => $token,
            'id' => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByVerificationToken(string $token): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, nombre, email, activo, email_verificado
             FROM usuarios
             WHERE email_verification_token = :token
             LIMIT 1'
        );
        $statement->execute(['token' => $token]);

        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function verifyUserEmail(int $userId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE usuarios 
             SET email_verificado = 1, email_verification_token = NULL 
             WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
    }
}
