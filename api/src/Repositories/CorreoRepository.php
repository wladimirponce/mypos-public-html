<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;
use PDOException;
use RuntimeException;

final class CorreoRepository
{
    private bool $schemaAvailable = true;

    public function __construct(private readonly PDO $connection)
    {
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        try {
            $this->connection->query('SELECT 1 FROM correo_cuentas LIMIT 1');
            return;
        } catch (PDOException) {
            // La tabla aun no existe en instalaciones donde la migracion no se ejecuto.
        }

        try {
            $this->connection->exec(
                "CREATE TABLE IF NOT EXISTS correo_cuentas (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    empresa_id BIGINT UNSIGNED NOT NULL,
                    email VARCHAR(190) NOT NULL,
                    nombre VARCHAR(190) NULL,
                    username VARCHAR(190) NOT NULL,
                    password_encrypted TEXT NULL,
                    imap_host VARCHAR(190) NOT NULL DEFAULT 'mail.mypos.cl',
                    imap_port INT NOT NULL DEFAULT 993,
                    imap_encryption ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
                    imap_validate_cert TINYINT(1) NOT NULL DEFAULT 0,
                    smtp_host VARCHAR(190) NOT NULL DEFAULT 'mail.mypos.cl',
                    smtp_port INT NOT NULL DEFAULT 465,
                    smtp_encryption ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
                    activo TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_correo_cuenta_empresa_email (empresa_id, email),
                    KEY idx_correo_cuentas_empresa (empresa_id, activo),
                    CONSTRAINT fk_correo_cuentas_empresa
                        FOREIGN KEY (empresa_id) REFERENCES empresas(id)
                        ON DELETE RESTRICT ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $this->addColumnIfMissing('correo_cuentas', 'imap_validate_cert', 'ALTER TABLE correo_cuentas ADD COLUMN imap_validate_cert TINYINT(1) NOT NULL DEFAULT 0 AFTER imap_encryption');
        } catch (PDOException) {
            $this->schemaAvailable = false;
        }
    }

    private function addColumnIfMissing(string $table, string $column, string $sql): void
    {
        try {
            $statement = $this->connection->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
            );
            $statement->execute(['table_name' => $table, 'column_name' => $column]);
            if ((int) $statement->fetchColumn() === 0) {
                $this->connection->exec($sql);
            }
        } catch (PDOException) {
            // No bloquear lectura de configuracion si el usuario DB no puede alterar schema.
        }
    }

    public function findActiveAccount(int $empresaId): ?array
    {
        if (!$this->schemaAvailable) {
            return null;
        }

        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, email, nombre, username, password_encrypted,
                    imap_host, imap_port, imap_encryption, imap_validate_cert, smtp_host, smtp_port,
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
        if (!$this->schemaAvailable) {
            throw new RuntimeException('Tabla correo_cuentas no disponible. Ejecuta la migracion 073_correo_empresa.sql.');
        }

        $statement = $this->connection->prepare(
            'INSERT INTO correo_cuentas (
                empresa_id, email, nombre, username, password_encrypted,
                imap_host, imap_port, imap_encryption, imap_validate_cert, smtp_host, smtp_port,
                smtp_encryption, activo
             ) VALUES (
                :empresa_id, :email, :nombre, :username, :password_encrypted,
                :imap_host, :imap_port, :imap_encryption, :imap_validate_cert, :smtp_host, :smtp_port,
                :smtp_encryption, :activo
             )
             ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                username = VALUES(username),
                password_encrypted = COALESCE(VALUES(password_encrypted), password_encrypted),
                imap_host = VALUES(imap_host),
                imap_port = VALUES(imap_port),
                imap_encryption = VALUES(imap_encryption),
                imap_validate_cert = VALUES(imap_validate_cert),
                smtp_host = VALUES(smtp_host),
                smtp_port = VALUES(smtp_port),
                smtp_encryption = VALUES(smtp_encryption),
                activo = VALUES(activo)'
        );
        $statement->execute($data);

        return $this->findActiveAccount((int) $data['empresa_id']) ?? [];
    }
}
