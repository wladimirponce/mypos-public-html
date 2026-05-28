<?php

declare(strict_types=1);

namespace Mypos\Support;

final class Env
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    public static function required(string $key): string
    {
        $value = self::get($key);
        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException('Variable de entorno requerida no configurada: ' . $key);
        }

        return trim($value);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function array(string $key, array $default = []): array
    {
        $value = self::get($key);
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
    }
}
