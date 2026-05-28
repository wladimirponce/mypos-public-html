<?php

declare(strict_types=1);

namespace Mypos\Middleware;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\AuthRepository;

final class TenantMiddleware
{
    public function handle(int $userId, int $empresaId, ?int $sucursalId = null): void
    {
        $repository = new AuthRepository(Database::connection());

        if (!$repository->userHasEmpresaContext($userId, $empresaId, $sucursalId)) {
            throw new HttpException('No tienes acceso a esta empresa o sucursal', 403);
        }
    }
}
