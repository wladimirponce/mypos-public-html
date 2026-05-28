<?php

declare(strict_types=1);

namespace Mypos\Services;

final class ImpuestoService
{
    /**
     * @param array<int, array<string, mixed>> $taxes
     * @return array{neto:int, impuestos_total:int, exento:int, detalle:array<int, array<string, mixed>>}
     */
    public function calcular(array $taxes, int $base): array
    {
        if ($taxes === []) {
            return [
                'neto' => $base,
                'impuestos_total' => 0,
                'exento' => $base,
                'detalle' => [],
            ];
        }

        $neto = $base;
        $totalTaxes = 0;
        $details = [];

        foreach ($taxes as $tax) {
            $percent = (int) ($tax['porcentaje'] ?? 0);
            $fixed = (int) ($tax['monto_fijo'] ?? 0);
            $included = (int) ($tax['incluido_en_precio'] ?? 1) === 1;
            $taxAmount = 0;
            $taxBase = $base;

            if ($percent > 0 && $included) {
                $taxBase = (int) round($base * 10000 / (10000 + $percent));
                $taxAmount = $base - $taxBase;
                $neto = min($neto, $taxBase);
            } elseif ($percent > 0) {
                $taxAmount = (int) round($base * $percent / 10000);
            } elseif ($fixed > 0) {
                $taxAmount = $fixed;
            }

            $totalTaxes += $taxAmount;
            $details[] = [
                'impuesto_id' => (int) $tax['impuesto_id'],
                'codigo_impuesto' => (string) $tax['codigo'],
                'nombre_impuesto' => (string) $tax['nombre'],
                'tipo_impuesto' => (string) $tax['tipo'],
                'porcentaje' => $percent,
                'monto_fijo' => $fixed,
                'base_calculo' => $taxBase,
                'monto_impuesto' => $taxAmount,
            ];
        }

        return [
            'neto' => $neto,
            'impuestos_total' => $totalTaxes,
            'exento' => 0,
            'detalle' => $details,
        ];
    }
}
