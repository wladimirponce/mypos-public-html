<?php

declare(strict_types=1);

namespace Mypos\Middleware;

use Mypos\Services\PermissionService;

final class PermissionMiddleware
{
    public function __construct(private ?PermissionService $service = null)
    {
        $this->service ??= new PermissionService();
    }

    public function handle(int $usuarioId, int $empresaId, string $permiso): void
    {
        $this->service->assertPermission($usuarioId, $empresaId, $permiso);
    }
}

