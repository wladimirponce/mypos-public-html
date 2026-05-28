<?php

declare(strict_types=1);

namespace Mypos\Core;

final class Response
{
    /**
     * @param array<string, mixed>|null $data
     */
    public static function success(?array $data = null, ?string $message = null, int $statusCode = 200): void
    {
        self::json([
            'success' => true,
            'data' => $data ?? new \stdClass(),
            'message' => $message,
            'errors' => null,
        ], $statusCode);
    }

    public static function successNull(?string $message = null, int $statusCode = 200): void
    {
        self::json([
            'success' => true,
            'data' => null,
            'message' => $message,
            'errors' => null,
        ], $statusCode);
    }

    /**
     * @param array<string, array<int, string>>|null $errors
     */
    public static function error(string $message, ?array $errors = null, int $statusCode = 400): void
    {
        self::json([
            'success' => false,
            'data' => null,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload, int $statusCode): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
