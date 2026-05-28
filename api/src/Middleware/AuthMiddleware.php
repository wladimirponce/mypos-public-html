<?php

declare(strict_types=1);

namespace Mypos\Middleware;

use Mypos\Core\Auth;
use Mypos\Core\HttpException;

final class AuthMiddleware
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $token = Auth::bearerToken();

        if ($token === null || $token === '') {
            throw new HttpException('Token requerido', 401);
        }

        return Auth::decodeToken($token);
    }
}
