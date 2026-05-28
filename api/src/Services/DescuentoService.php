<?php

declare(strict_types=1);

namespace Mypos\Services;

final class DescuentoService
{
    /**
     * @param array<int, array<string, mixed>> $discounts
     * @return array{tipo:?string, valor:int, total:int}
     */
    public function calcularMejorDescuento(array $discounts, int $subtotal): array
    {
        $best = ['tipo' => null, 'valor' => 0, 'total' => 0];

        foreach ($discounts as $discount) {
            $type = (string) ($discount['tipo_descuento'] ?? $discount['tipo'] ?? '');
            $value = (int) ($discount['valor_descuento'] ?? $discount['valor'] ?? 0);
            $amount = 0;

            if ($type === 'MONTO') {
                $amount = min($value, $subtotal);
            }

            if ($type === 'PORCENTAJE') {
                $amount = (int) round($subtotal * $value / 10000);
            }

            if ($amount > $best['total']) {
                $best = ['tipo' => $type, 'valor' => $value, 'total' => $amount];
            }

            // TODO: soportar descuentos acumulables cuando se defina prioridad y compatibilidad.
        }

        return $best;
    }
}
