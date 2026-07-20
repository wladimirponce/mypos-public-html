<?php

declare(strict_types=1);

namespace Mypos\Support;

final class ReposicionCalculator
{
    /**
     * @return array{lead_time_dias:int,dias_seguridad:int,dias_inventario_optimo:int,punto_reorden_sugerido:?int,quiebre_inminente:bool}
     */
    public static function calcular(float $consumoDiario, float $stockActual, ?int $leadTimeDias): array
    {
        $consumoDiario = max(0.0, $consumoDiario);
        $leadTime = $leadTimeDias !== null && $leadTimeDias > 0 ? $leadTimeDias : 3;
        $diasSeguridad = max(2, (int) ceil($leadTime * 0.5));

        return [
            'lead_time_dias' => $leadTime,
            'dias_seguridad' => $diasSeguridad,
            'dias_inventario_optimo' => $leadTime + $diasSeguridad,
            'punto_reorden_sugerido' => $consumoDiario > 0
                ? (int) ceil($consumoDiario * ($leadTime + $diasSeguridad))
                : null,
            'quiebre_inminente' => $consumoDiario > 0
                && ($stockActual / $consumoDiario) <= $leadTime,
        ];
    }
}
