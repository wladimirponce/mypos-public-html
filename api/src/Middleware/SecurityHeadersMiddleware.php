<?php

declare(strict_types=1);

namespace Mypos\Middleware;

final class SecurityHeadersMiddleware
{
    public function handle(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('X-XSS-Protection: 0');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    }
}
