<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Support\PlanCatalog;
use PDO;

final class PlanLimitService
{
    public function __construct(private readonly ?PDO $providedConnection = null) {}

    public function status(int $empresaId): array
    {
        $connection = $this->providedConnection ?? Database::connection();
        $stmt = $connection->prepare('SELECT plan_id FROM empresas_suscripcion WHERE empresa_id = :empresa_id LIMIT 1');
        $stmt->execute(['empresa_id' => $empresaId]);
        $rawPlanId = (string) ($stmt->fetchColumn() ?: 'mypos-start');
        $plan = PlanCatalog::get($rawPlanId);

        return [
            'plan' => $plan,
            'uso' => [
                'sucursales' => $this->count($connection, 'SELECT COUNT(*) FROM sucursales WHERE empresa_id = :empresa_id AND activo = 1', $empresaId),
                'usuarios' => $this->count($connection, 'SELECT COUNT(*) FROM empresa_usuarios WHERE empresa_id = :empresa_id AND activo = 1', $empresaId),
            ],
        ];
    }

    public function assertCanAddSucursal(int $empresaId): void
    {
        $status = $this->status($empresaId);
        if ($status['uso']['sucursales'] >= $status['plan']['max_sucursales']) {
            throw new HttpException(
                "Tu plan {$status['plan']['nombre']} permite hasta {$status['plan']['max_sucursales']} local(es) activo(s). Mejora el plan para agregar otro local.",
                422,
                ['plan_limit' => ['sucursales']]
            );
        }
    }

    public function assertCanAddUsuario(int $empresaId): void
    {
        $status = $this->status($empresaId);
        if ($status['uso']['usuarios'] >= $status['plan']['max_usuarios']) {
            throw new HttpException(
                "Tu plan {$status['plan']['nombre']} permite hasta {$status['plan']['max_usuarios']} usuario(s) activo(s). Mejora el plan para agregar otro usuario.",
                422,
                ['plan_limit' => ['usuarios']]
            );
        }
    }

    private function count(PDO $connection, string $sql, int $empresaId): int
    {
        $stmt = $connection->prepare($sql);
        $stmt->execute(['empresa_id' => $empresaId]);
        return (int) $stmt->fetchColumn();
    }
}
