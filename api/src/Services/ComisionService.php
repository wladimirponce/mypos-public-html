<?php

declare(strict_types=1);

namespace Mypos\Services;

final class ComisionService
{
    /**
     * @param array<int, array<string, mixed>> $commissions
     */
    public function calcular(array $commissions, int $lineTotal, float $quantity, int $margin): int
    {
        if ($commissions === []) {
            return 0;
        }

        $commission = $commissions[0];
        $type = (string) ($commission['tipo_comision'] ?? $commission['tipo'] ?? '');
        $value = (int) ($commission['valor_comision'] ?? $commission['valor'] ?? 0);

        return match ($type) {
            'PORCENTAJE_VENTA' => (int) round($lineTotal * $value / 10000),
            'MONTO_FIJO' => (int) round($value * $quantity),
            'PORCENTAJE_MARGEN' => (int) round($margin * $value / 10000),
            default => 0,
        };
    }
}
