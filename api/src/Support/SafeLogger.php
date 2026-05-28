<?php

declare(strict_types=1);

namespace Mypos\Support;

final class SafeLogger
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'token',
        'authorization',
        'api_key',
        'secret',
        'gemini',
        'private_key',
        'certificado',
        'caf_xml',
        'base64',
        'file_content',
        'access_token',
        'refresh_token',
    ];

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $path = AppConfig::logPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $payload = [
            'time' => date('c'),
            'level' => $level,
            'message' => self::cleanMessage($message),
            'context' => self::sanitize($context),
        ];

        file_put_contents(
            $path,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private static function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $keyString = strtolower((string) $key);
                if (self::isSensitiveKey($keyString)) {
                    $sanitized[$key] = '[REDACTED]';
                    continue;
                }
                $sanitized[$key] = self::sanitize($item);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            if (strlen($value) > 1000) {
                return substr($value, 0, 1000) . '...[TRUNCATED]';
            }

            return self::cleanMessage($value);
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private static function cleanMessage(string $message): string
    {
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [REDACTED]', $message) ?? $message;
        $message = preg_replace('/AIza[0-9A-Za-z_\-]{20,}/', '[REDACTED_API_KEY]', $message) ?? $message;

        return $message;
    }
}
