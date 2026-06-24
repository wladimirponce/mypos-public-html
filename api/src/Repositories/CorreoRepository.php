<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class CorreoRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function findActiveAccount(int $empresaId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, email, nombre, username, password_encrypted,
                    imap_host, imap_port, imap_encryption, smtp_host, smtp_port,
                    smtp_encryption, activo, created_at, updated_at
             FROM correo_cuentas
             WHERE empresa_id = :empresa_id AND activo = 1
             ORDER BY id ASC
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function upsertAccount(array $data): array
    {
        $statement = $this->connection->prepare(
            'INSERT INTO correo_cuentas (
                empresa_id, email, nombre, username, password_encrypted,
                imap_host, imap_port, imap_encryption, smtp_host, smtp_port,
                smtp_encryption, activo
             ) VALUES (
                :empresa_id, :email, :nombre, :username, :password_encrypted,
                :imap_host, :imap_port, :imap_encryption, :smtp_host, :smtp_port,
                :smtp_encryption, :activo
             )
             ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                username = VALUES(username),
                password_encrypted = COALESCE(VALUES(password_encrypted), password_encrypted),
                imap_host = VALUES(imap_host),
                imap_port = VALUES(imap_port),
                imap_encryption = VALUES(imap_encryption),
                smtp_host = VALUES(smtp_host),
                smtp_port = VALUES(smtp_port),
                smtp_encryption = VALUES(smtp_encryption),
                activo = VALUES(activo)'
        );
        $statement->execute($data);

        return $this->findActiveAccount((int) $data['empresa_id']) ?? [];
    }
}
