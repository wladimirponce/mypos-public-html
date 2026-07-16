<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\EnvLoader;
use PDO;
use Throwable;

/**
 * Orquestador del segundo factor administrativo (TOTP).
 *
 * Feature flag ADMIN_MFA_ENABLED: cerrada por defecto. Con la flag apagada, el
 * login se comporta exactamente como antes (sin MFA). Con la flag encendida:
 *   - los operadores ya enrolados deben superar el desafío tras la contraseña;
 *   - los roles que requieren MFA (superadmin) y no estén enrolados son
 *     dirigidos a enrolarse antes de obtener sesión completa.
 */
final class AdminMfa
{
    private const ISSUER = 'MyPOS Admin';
    private const RECOVERY_COUNT = 10;

    public static function isGloballyEnabled(): bool
    {
        return EnvLoader::getBool('ADMIN_MFA_ENABLED', false);
    }

    /** Roles obligados a tener MFA cuando la flag está activa (diseño, punto 6). */
    public static function roleRequiresMfa(string $role): bool
    {
        return $role === 'superadmin';
    }

    /** La infraestructura MFA está lista si existe la tabla (migración 015). */
    public static function tableAvailable(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $stmt = $db->query("SHOW TABLES LIKE 'admin_mfa'");
            $cache = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            $cache = false;
        }

        return $cache;
    }

    public static function isEnrolled(PDO $db, int $adminId): bool
    {
        if (!self::tableAvailable($db)) {
            return false;
        }
        $stmt = $db->prepare('SELECT confirmado FROM admin_mfa WHERE admin_id = :id LIMIT 1');
        $stmt->execute([':id' => $adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false && (int) $row['confirmado'] === 1;
    }

    /**
     * Inicia (o reinicia) el enrolamiento: genera un secreto nuevo, lo guarda
     * cifrado y sin confirmar, y devuelve el secreto base32 + la URI otpauth.
     *
     * @return array{secret:string, uri:string}
     */
    public static function startEnrollment(PDO $db, int $adminId, string $account): array
    {
        $secret = AdminTotp::generateSecret();
        $cifrado = AdminMfaCrypto::encrypt($secret);

        $stmt = $db->prepare(
            'INSERT INTO admin_mfa (admin_id, secret_cifrado, confirmado, confirmado_en)
             VALUES (:id, :sec, 0, NULL)
             ON DUPLICATE KEY UPDATE secret_cifrado = VALUES(secret_cifrado),
                                     confirmado = 0, confirmado_en = NULL'
        );
        $stmt->execute([':id' => $adminId, ':sec' => $cifrado]);

        return [
            'secret' => $secret,
            'uri' => AdminTotp::provisioningUri($secret, $account, self::ISSUER),
        ];
    }

    /**
     * Confirma el enrolamiento verificando un primer código. Si es válido,
     * activa el factor y (re)genera los códigos de recuperación de un solo uso.
     *
     * @return array{ok:bool, recovery:list<string>}
     */
    public static function confirmEnrollment(PDO $db, int $adminId, string $code): array
    {
        $secret = self::secretFor($db, $adminId);
        if ($secret === null || !AdminTotp::verify($secret, $code)) {
            return ['ok' => false, 'recovery' => []];
        }

        $db->prepare('UPDATE admin_mfa SET confirmado = 1, confirmado_en = NOW() WHERE admin_id = :id')
            ->execute([':id' => $adminId]);

        $recovery = self::regenerateRecoveryCodes($db, $adminId);

        return ['ok' => true, 'recovery' => $recovery];
    }

    /** Verifica un código de desafío: TOTP (6 dígitos) o código de recuperación. */
    public static function verifyChallenge(PDO $db, int $adminId, string $code): bool
    {
        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code) === 1) {
            $secret = self::secretFor($db, $adminId, true);

            return $secret !== null && AdminTotp::verify($secret, $code);
        }

        return self::consumeRecoveryCode($db, $adminId, $code);
    }

    /** Desactiva MFA para un operador (requiere reautenticación en la UI). */
    public static function disable(PDO $db, int $adminId): void
    {
        $db->prepare('DELETE FROM admin_mfa WHERE admin_id = :id')->execute([':id' => $adminId]);
        $db->prepare('DELETE FROM admin_mfa_recovery WHERE admin_id = :id')->execute([':id' => $adminId]);
    }

    /** Cantidad de códigos de recuperación aún sin usar. */
    public static function unusedRecoveryCount(PDO $db, int $adminId): int
    {
        if (!self::tableAvailable($db)) {
            return 0;
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM admin_mfa_recovery WHERE admin_id = :id AND usado_en IS NULL');
        $stmt->execute([':id' => $adminId]);

        return (int) $stmt->fetchColumn();
    }

    private static function secretFor(PDO $db, int $adminId, bool $confirmedOnly = false): ?string
    {
        if (!self::tableAvailable($db)) {
            return null;
        }
        $sql = 'SELECT secret_cifrado, confirmado FROM admin_mfa WHERE admin_id = :id LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => $adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if ($confirmedOnly && (int) $row['confirmado'] !== 1) {
            return null;
        }
        try {
            return AdminMfaCrypto::decrypt((string) $row['secret_cifrado']);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> códigos en claro (se muestran una sola vez) */
    private static function regenerateRecoveryCodes(PDO $db, int $adminId): array
    {
        $db->prepare('DELETE FROM admin_mfa_recovery WHERE admin_id = :id')->execute([':id' => $adminId]);

        $plain = [];
        $insert = $db->prepare('INSERT INTO admin_mfa_recovery (admin_id, code_hash) VALUES (:id, :h)');
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $code = self::formatRecovery(bin2hex(random_bytes(5))); // 10 hex => xxxxx-xxxxx
            $plain[] = $code;
            $insert->execute([':id' => $adminId, ':h' => self::hashRecovery($code)]);
        }

        return $plain;
    }

    private static function consumeRecoveryCode(PDO $db, int $adminId, string $code): bool
    {
        if (!self::tableAvailable($db)) {
            return false;
        }
        $hash = self::hashRecovery($code);
        $stmt = $db->prepare(
            'SELECT id FROM admin_mfa_recovery
             WHERE admin_id = :id AND code_hash = :h AND usado_en IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $adminId, ':h' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        $upd = $db->prepare('UPDATE admin_mfa_recovery SET usado_en = NOW() WHERE id = :rid AND usado_en IS NULL');
        $upd->execute([':rid' => (int) $row['id']]);

        return $upd->rowCount() === 1;
    }

    public static function hashRecovery(string $code): string
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $code) ?? '');

        return hash('sha256', $normalized);
    }

    private static function formatRecovery(string $hex): string
    {
        return substr($hex, 0, 5) . '-' . substr($hex, 5, 5);
    }
}
