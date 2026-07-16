<?php

declare(strict_types=1);

namespace Mypos\Support;

use Mypos\Core\HttpException;

/**
 * Cifrado simetrico reutilizable para secretos en reposo (AES-256-GCM).
 *
 * La clave se deriva de APP_KEY (o JWT_SECRET como respaldo) via SHA-256.
 *
 * Versionado de clave (S5-06 rotacion sin interrupcion):
 *   - Formato legado (sin prefijo): base64(iv[12] . tag[16] . ciphertext).
 *     Usa la clave derivada de APP_KEY. Es el comportamiento por defecto y no
 *     cambia si no se configura ninguna variable de version.
 *   - Formato versionado: "v{N}:" + base64(iv . tag . ciphertext).
 *     Usa la clave derivada de APP_KEY_V{N}.
 *
 * Rotacion sin downtime:
 *   1. Mantener APP_KEY (clave antigua) para poder descifrar blobs legados.
 *   2. Definir APP_KEY_V2 = clave nueva y APP_KEY_ACTIVE_VERSION=2.
 *   3. Los NUEVOS cifrados salen como "v2:..."; los antiguos siguen legibles.
 *   4. Ejecutar scripts/reencrypt_secrets.php para reescribir todo a v2.
 *   5. Recien entonces retirar/rotar APP_KEY antigua.
 *
 * decrypt() acepta ambos formatos simultaneamente, por lo que la transicion
 * nunca deja secretos ilegibles.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plain): string
    {
        $version = self::activeVersion();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::keyForVersion($version), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new HttpException('No se pudo cifrar el secreto', 500);
        }

        $blob = base64_encode($iv . $tag . $cipher);

        return $version === 0 ? $blob : 'v' . $version . ':' . $blob;
    }

    public static function decrypt(string $encrypted): string
    {
        [$version, $blob] = self::parse($encrypted);

        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) <= 28) {
            throw new HttpException('Secreto cifrado invalido', 500);
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $data = substr($raw, 28);

        $plain = openssl_decrypt($data, self::CIPHER, self::keyForVersion($version), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new HttpException('No se pudo descifrar el secreto', 500);
        }

        return $plain;
    }

    /**
     * Separa el prefijo de version del cuerpo base64.
     *
     * @return array{0:int,1:string} version (0 = legado) y blob base64
     */
    private static function parse(string $encrypted): array
    {
        if (preg_match('/^v(\d+):(.+)$/s', $encrypted, $m) === 1) {
            return [(int) $m[1], $m[2]];
        }

        return [0, $encrypted];
    }

    private static function activeVersion(): int
    {
        $version = Env::int('APP_KEY_ACTIVE_VERSION', 0);

        return $version > 0 ? $version : 0;
    }

    private static function keyForVersion(int $version): string
    {
        if ($version === 0) {
            $secret = self::envString('APP_KEY');
            if ($secret === '') {
                $secret = self::envString('JWT_SECRET');
            }
            if ($secret === '') {
                throw new HttpException('Falta configurar APP_KEY para cifrar secretos', 500);
            }

            return hash('sha256', $secret, true);
        }

        $secret = self::envString('APP_KEY_V' . $version);
        if ($secret === '') {
            throw new HttpException('Falta configurar APP_KEY_V' . $version . ' para cifrar/descifrar secretos', 500);
        }

        return hash('sha256', $secret, true);
    }

    private static function envString(string $key): string
    {
        $value = Env::get($key, '');

        return is_string($value) ? trim($value) : '';
    }
}
