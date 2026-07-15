<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\MyposSubscriptionRepository;
use InvalidArgumentException;

final class MyposSubscriptionService
{
    public function __construct(
        private readonly MyposSubscriptionRepository $repository = new MyposSubscriptionRepository()
    ) {
    }

    public function actualizarMontoRecurrente(int $empresaId, int $montoClp): void
    {
        if ($empresaId <= 0) {
            throw new InvalidArgumentException('Empresa invalida.');
        }
        if ($montoClp < 1 || $montoClp > 100000000) {
            throw new InvalidArgumentException('El monto mensual debe estar entre $1 y $100.000.000.');
        }
        $this->repository->updateRecurringPrice($empresaId, $montoClp);
    }
}
