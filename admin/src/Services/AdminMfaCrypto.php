<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\EnvLoader;
use RuntimeException;

/**
 * Cifrado de secretos MFA en reposo (AES-256-GCM), con clave INDEPENDIENTE de
 * las demas (ADMIN_MFA_KEY) y VERSIONADA para rotacion sin interrupcion
 * (diseño de MFA, punto 3).
 *
 *   - Legado (sin prefijo): base64(iv[12] . tag[16] . ct), clave ADMIN_MFA_KEY.
 *   - Versionado: "v{n}:" + base64(...), clave ADMIN_MFA_KEY_V{n}.
 *
 * decrypt() acepta ambos formatos, por lo que la rotacion nunca deja secretos
 * ilegibles: se define ADMIN_MFA_KEY_V2 + ADMIN_MFA_KEY_ACTIVE_VERSION=2 y se
 * re-cifra progresivamente. Ver doc/runbooks/03.
 */
final class AdminMfaCrypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plain): string
    {
        $version = self::activeVersion();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::keyForVersion($version), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('No se pudo cifrar el secreto MFA');
        }
        $blob = base64_encode($iv . $tag . $cipher);

        return $version === 0 ? $blob : 'v' . $version . ':' . $blob;
    }

    public static function decrypt(string $encrypted): string
    {
        [$version, $blob] = self::parse($encrypted);
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) <= 28) {
            throw new RuntimeException('Secreto MFA cifrado invalido');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $data = substr($raw, 28);
        $plain = openssl_decrypt($data, self::CIPHER, self::keyForVersion($version), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('No se pudo descifrar el secreto MFA');
        }

        return $plain;
    }

    /** @return array{0:int,1:string} */
    private static function parse(string $encrypted): array
    {
        if (preg_match('/^v(\d+):(.+)$/s', $encrypted, $m) === 1) {
            return [(int) $m[1], $m[2]];
        }

        return [0, $encrypted];
    }

    private static function activeVersion(): int
    {
        $v = EnvLoader::getInt('ADMIN_MFA_KEY_ACTIVE_VERSION', 0);

        return $v > 0 ? $v : 0;
    }

    private static function keyForVersion(int $version): string
    {
        $secret = $version === 0
            ? EnvLoader::getString('ADMIN_MFA_KEY', '')
            : EnvLoader::getString('ADMIN_MFA_KEY_V' . $version, '');

        if (trim($secret) === '') {
            $name = $version === 0 ? 'ADMIN_MFA_KEY' : 'ADMIN_MFA_KEY_V' . $version;
            throw new RuntimeException('Falta configurar ' . $name . ' para el cifrado MFA');
        }

        return hash('sha256', $secret, true);
    }
}
