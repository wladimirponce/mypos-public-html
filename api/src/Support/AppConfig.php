<?php

declare(strict_types=1);

namespace Mypos\Support;

final class AppConfig
{
    public static function env(): string
    {
        return strtolower((string) Env::get('APP_ENV', 'local'));
    }

    public static function debug(): bool
    {
        return Env::bool('APP_DEBUG', self::env() !== 'production');
    }

    public static function appUrl(): string
    {
        return (string) Env::get('APP_URL', 'http://localhost:8082');
    }

    /**
     * Correos del/los dueños de plataforma (operadores de MyPOS) autorizados a
     * gestionar links de precio especial. Configurable por env
     * PLATFORM_OWNER_EMAILS (separados por coma). Sin él, nadie puede crearlos.
     *
     * @return list<string> en minúsculas
     */
    public static function platformOwnerEmails(): array
    {
        // Fail closed: omitir la variable no concede privilegios a nadie.
        $emails = Env::array('PLATFORM_OWNER_EMAILS', []);
        return array_values(array_filter(array_map(
            static fn ($e) => strtolower(trim((string) $e)),
            $emails
        )));
    }

    public static function isPlatformOwnerEmail(string $email): bool
    {
        return in_array(strtolower(trim($email)), self::platformOwnerEmails(), true);
    }

    public static function apiBaseUrl(): string
    {
        return (string) Env::get('API_BASE_URL', self::appUrl());
    }

    public static function corsAllowedOrigins(): array
    {
        return Env::array('CORS_ALLOWED_ORIGINS', [
            'http://localhost:5173',
            'http://localhost:3000',
            'http://localhost:8082',
        ]);
    }

    public static function rateLimitEnabled(): bool
    {
        return Env::bool('RATE_LIMIT_ENABLED', false);
    }

    public static function rateLimitMaxRequests(): int
    {
        return max(1, Env::int('RATE_LIMIT_MAX_REQUESTS', 120));
    }

    public static function rateLimitWindowSeconds(): int
    {
        return max(1, Env::int('RATE_LIMIT_WINDOW_SECONDS', 60));
    }

    public static function rateLimitLoginMaxRequests(): int
    {
        return max(1, Env::int('RATE_LIMIT_LOGIN_MAX_REQUESTS', 10));
    }

    public static function rateLimitLoginWindowSeconds(): int
    {
        return max(1, Env::int('RATE_LIMIT_LOGIN_WINDOW_SECONDS', 60));
    }

    public static function storagePath(): string
    {
        return self::absolutePath((string) Env::get('STORAGE_PATH', 'storage'));
    }

    public static function logPath(): string
    {
        $path = (string) Env::get('LOG_PATH', 'storage/logs/app.log');

        return self::absolutePath($path);
    }

    public static function uploadMaxMb(): int
    {
        return max(1, Env::int('UPLOAD_MAX_MB', 20));
    }

    public static function isProduction(): bool
    {
        return self::env() === 'production';
    }

    private static function absolutePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
