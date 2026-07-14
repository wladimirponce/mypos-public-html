<?php

declare(strict_types=1);

namespace Mypos\Support;

/**
 * Cálculo del precio de venta sugerido a partir del costo y el margen.
 *
 * Dos interpretaciones de `margen_ganancia` según `tipo_margen` del producto:
 *   - 'markup': el % se suma sobre el costo   -> precio = costo * (1 + m/100)
 *   - 'margen': el % es el margen bruto REAL   -> precio = costo / (1 - m/100)
 *
 * El modo 'margen' es el que describe el "Guardián del Margen Bruto":
 * proteger que la ganancia como porcentaje del PRECIO no baje del objetivo.
 */
final class PrecioCalculator
{
    /** Margen bruto real máximo admisible (evita división por cero/negativa). */
    private const MARGEN_MAX = 99.99;

    /**
     * Precio de venta sugerido, redondeado a peso.
     *
     * @param int    $costo  Costo de adquisición (CLP).
     * @param float  $margen Porcentaje configurado (margen_ganancia).
     * @param string $tipo   'markup' | 'margen'.
     * @return int Precio sugerido; 0 si no se puede calcular (costo <= 0).
     */
    public static function sugerido(int $costo, float $margen, string $tipo = 'markup'): int
    {
        if ($costo <= 0) {
            return 0;
        }

        if ($tipo === 'margen') {
            $m = max(0.0, min($margen, self::MARGEN_MAX));
            return (int) round($costo / (1 - $m / 100));
        }

        return (int) round($costo * (1 + max(0.0, $margen) / 100));
    }

    /** Normaliza un valor arbitrario a un tipo de margen válido. */
    public static function normalizarTipo(mixed $tipo): string
    {
        return $tipo === 'margen' ? 'margen' : 'markup';
    }
}
