<?php
declare(strict_types=1);

namespace App\Repositories;

use RuntimeException;

final class MyposSubscriptionRepository extends BaseRepository
{
    public function updateRecurringPrice(int $empresaId, int $montoClp): void
    {
        $statement = $this->db->prepare(
            'UPDATE empresas_suscripcion
             SET precio_especial_clp = :monto
             WHERE empresa_id = :empresa_id'
        );
        $statement->execute(['monto' => $montoClp, 'empresa_id' => $empresaId]);

        if ($statement->rowCount() === 0 && !$this->subscriptionExists($empresaId)) {
            throw new RuntimeException('La empresa no tiene una suscripcion asociada.');
        }
    }

    private function subscriptionExists(int $empresaId): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM empresas_suscripcion WHERE empresa_id = :empresa_id LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        return (bool)$statement->fetchColumn();
    }
}
