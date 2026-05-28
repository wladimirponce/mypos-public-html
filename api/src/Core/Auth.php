<?php

declare(strict_types=1);

namespace Mypos\Core;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

final class Auth
{
    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    public static function empresaId(): ?int
    {
        $empresaId = self::positiveInt($_GET['empresa_id'] ?? null);
        if ($empresaId !== null) {
            return $empresaId;
        }

        $empresaId = self::positiveInt($_POST['empresa_id'] ?? null);
        if ($empresaId !== null) {
            return $empresaId;
        }

        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $payload = json_decode($raw, true);
            if (is_array($payload)) {
                return self::positiveInt($payload['empresa_id'] ?? null);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function issueToken(array $claims): string
    {
        return JWT::encode($claims, self::secret(), 'HS256');
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key(self::secret(), 'HS256'));

            return (array) $decoded;
        } catch (ExpiredException) {
            throw new HttpException('Token expirado', 401);
        } catch (Throwable) {
            throw new HttpException('Token inválido', 401);
        }
    }

    private static function secret(): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '';

        if ($secret === '') {
            throw new HttpException('Configuración JWT no disponible', 500);
        }

        return $secret;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($number) && $number > 0 ? $number : null;
    }
}
