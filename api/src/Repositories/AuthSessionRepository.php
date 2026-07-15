<?php
declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class AuthSessionRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(array $data): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO auth_sessions
                (usuario_id, family_id, refresh_token_hash, ip_hash, user_agent_hash, expires_at)
             VALUES
                (:usuario_id, :family_id, :refresh_token_hash, :ip_hash, :user_agent_hash, :expires_at)'
        );
        $stmt->execute($data);
        return (int) $this->connection->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findByHashForUpdate(string $hash): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, usuario_id, family_id, refresh_token_hash, replaced_by_hash,
                    ip_hash, user_agent_hash, expires_at, last_used_at, revoked_at,
                    revoke_reason, created_at
             FROM auth_sessions WHERE refresh_token_hash = :hash LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function markRotated(int $id, string $replacementHash): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE auth_sessions
             SET replaced_by_hash = :replacement_hash, revoked_at = NOW(),
                 revoke_reason = :reason, last_used_at = NOW()
             WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'replacement_hash' => $replacementHash,
            'reason' => 'rotated',
            'id' => $id,
        ]);
    }

    public function revokeByHash(string $hash, string $reason): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, NOW()), revoke_reason = :reason
             WHERE refresh_token_hash = :hash'
        );
        $stmt->execute(['reason' => $reason, 'hash' => $hash]);
    }

    public function revokeFamily(string $familyId, string $reason): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, NOW()), revoke_reason = :reason
             WHERE family_id = :family_id AND revoked_at IS NULL'
        );
        $stmt->execute(['reason' => $reason, 'family_id' => $familyId]);
    }

    public function revokeAllForUser(int $userId, string $reason): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, NOW()), revoke_reason = :reason
             WHERE usuario_id = :usuario_id AND revoked_at IS NULL'
        );
        $stmt->execute(['reason' => $reason, 'usuario_id' => $userId]);
    }
}
