<?php

declare(strict_types=1);

namespace Mypos\Support;

final class RequestContext
{
    private static ?string $correlationId = null;

    public static function initialize(?string $incoming = null): string
    {
        $incoming = trim((string) $incoming);
        self::$correlationId = self::isValid($incoming)
            ? $incoming
            : bin2hex(random_bytes(16));

        if (!headers_sent()) {
            header('X-Correlation-ID: ' . self::$correlationId);
        }

        return self::$correlationId;
    }

    public static function correlationId(): string
    {
        return self::$correlationId ?? self::initialize();
    }

    public static function reset(): void
    {
        self::$correlationId = null;
    }

    private static function isValid(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$/', $value) === 1;
    }
}
