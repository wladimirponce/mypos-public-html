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
}
