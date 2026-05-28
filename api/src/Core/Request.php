<?php

declare(strict_types=1);

namespace Mypos\Core;

final class Request
{
    /**
     * @return array<string, mixed>
     */
    public static function json(): array
    {
        $body = file_get_contents('php://input') ?: '';

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        return self::json()[$key] ?? $default;
    }
}
