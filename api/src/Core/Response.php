<?php

declare(strict_types=1);

namespace Mypos\Core;

final class Response
{
    /**
     * Claves booleanas (tinyint 0/1) que el frontend compara con === 1.
     * En produccion PDO devuelve los enteros como string ("1"), por lo que esas
     * comparaciones estrictas fallaban y ocultaban filas activas (p.ej. el selector
     * de sucursal). Aqui se normalizan a int en la salida JSON. Es deliberadamente
     * acotado a banderas 0/1: no toca montos ("0.000"), RUT, codigos ni folios.
     */
    private const INT_FLAG_KEYS = [
        'activo',
        'principal',
        'permite_venta',
        'proveedor_preferido',
        'leido',
        'controla_stock',
        'permite_stock_negativo',
    ];

    /**
     * @param array<string, mixed>|null $data
     */
    public static function success(?array $data = null, ?string $message = null, int $statusCode = 200): void
    {
        self::json([
            'success' => true,
            'data' => $data === null ? new \stdClass() : self::normalizeFlags($data),
            'message' => $message,
            'errors' => null,
        ], $statusCode);
    }

    /**
     * Recorre recursivamente la estructura y castea a int las claves de
     * INT_FLAG_KEYS cuyo valor sea el string "0" o "1". Cualquier otro valor
     * (incluido un int ya nativo, null, o strings no 0/1) se deja intacto.
     */
    private static function normalizeFlags(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (
                is_string($key)
                && in_array($key, self::INT_FLAG_KEYS, true)
                && ($item === '0' || $item === '1')
            ) {
                $result[$key] = (int) $item;
            } else {
                $result[$key] = self::normalizeFlags($item);
            }
        }

        return $result;
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
