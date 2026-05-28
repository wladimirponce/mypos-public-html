<?php

declare(strict_types=1);

namespace Mypos\Support;

final class Env
{
    public static function loadFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '' || preg_match('/^[A-Z0-9_]+$/i', $key) !== 1) {
                continue;
            }

            $value = trim($value);
            $quote = $value[0] ?? '';
            if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

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
