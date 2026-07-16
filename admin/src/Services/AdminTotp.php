<?php
declare(strict_types=1);

namespace App\Services;

/**
 * TOTP (RFC 6238 sobre HOTP RFC 4226) en PHP puro, sin dependencias externas.
 *
 * Se usa como segundo factor del panel administrativo. El diseño prefiere
 * WebAuthn/FIDO2; TOTP queda como alternativa compatible con apps estandar
 * (Google Authenticator, Authy, 1Password, etc.).
 */
final class AdminTotp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALGO = 'sha1'; // requerido por los autenticadores estandar
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Genera un secreto nuevo en base32 (20 bytes => 32 chars). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Codigo TOTP para un instante dado. */
    public static function codeAt(string $base32Secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, self::PERIOD);

        return self::hotp(self::base32Decode($base32Secret), $counter);
    }

    /**
     * Verifica un codigo permitiendo una ventana de +/- $window pasos para
     * tolerar desfase de reloj. Comparacion en tiempo constante.
     */
    public static function verify(string $base32Secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $timestamp ??= time();
        $secret = self::base32Decode($base32Secret);
        if ($secret === '') {
            return false;
        }

        $counter = intdiv($timestamp, self::PERIOD);
        $match = false;
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = self::hotp($secret, $counter + $i);
            // Recorremos toda la ventana igual para no filtrar tiempo.
            if (hash_equals($candidate, $code)) {
                $match = true;
            }
        }

        return $match;
    }

    /** URI otpauth:// para el QR o alta manual en la app autenticadora. */
    public static function provisioningUri(string $base32Secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        $params = http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGO),
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);

        return 'otpauth://totp/' . $label . '?' . $params;
    }

    private static function hotp(string $secret, int $counter): string
    {
        $binCounter = pack('N*', 0) . pack('N*', $counter); // 8 bytes big-endian
        $hash = hash_hmac(self::ALGO, $binCounter, $secret, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        $otp = $binary % (10 ** self::DIGITS);

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(trim(str_replace([' ', '='], '', $b32)));
        if ($b32 === '') {
            return '';
        }
        $bits = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $pos = strpos(self::B32, $b32[$i]);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
