<?php

declare(strict_types=1);

namespace Mypos\Support;

use Mypos\Core\HttpException;

/**
 * Cifrado simetrico reutilizable para secretos en reposo (AES-256-GCM).
 *
 * Mismo esquema que ya usa CorreoService para las passwords de correo: la clave
 * se deriva de APP_KEY (o JWT_SECRET como respaldo) via SHA-256. El formato de
 * salida es base64(iv[12] . tag[16] . ciphertext).
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new HttpException('No se pudo cifrar el secreto', 500);
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encrypted): string
    {
        $raw = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) <= 28) {
            throw new HttpException('Secreto cifrado invalido', 500);
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $data = substr($raw, 28);

        $plain = openssl_decrypt($data, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new HttpException('No se pudo descifrar el secreto', 500);
        }

        return $plain;
    }

    private static function key(): string
    {
        $secret = (string) Env::get('APP_KEY', Env::get('JWT_SECRET', ''));
        if (trim($secret) === '') {
            throw new HttpException('Falta configurar APP_KEY para cifrar secretos', 500);
        }

        return hash('sha256', $secret, true);
    }
}
