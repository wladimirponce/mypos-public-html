<?php

declare(strict_types=1);

namespace Mypos\Support;

/**
 * Ciclo de vida de la suscripcion mensual.
 *
 * MyPOS no guarda medios de pago ni cobra por su cuenta: el cliente paga cada
 * mes con Flow. Para que ese modelo no lo deje fuera de un dia para otro, la
 * vigencia se evalua en cada entrada al sistema y recorre cuatro fases:
 *
 *   activa      quedan mas de 2 dias           -> entra sin ruido
 *   por_vencer  quedan 2 dias o menos          -> entra, con aviso amable
 *   gracia      vencio hace 5 dias o menos     -> entra, con aviso urgente
 *   bloqueada   vencio hace mas de 5 dias      -> 402, muro de pago
 *
 * La fase se calcula SIEMPRE desde `fecha_fin`, nunca desde la columna `estado`:
 * el middleware marca 'vencida' apenas pasa la fecha, y si la decision dependiera
 * de esa columna los 5 dias de gracia no existirian. `estado` solo manda cuando
 * vale 'cancelada', que es una baja explicita y no tiene gracia.
 */
final class SubscriptionLifecycle
{
    /** Dias antes del vencimiento en que se empieza a avisar. */
    public const DIAS_AVISO_PREVIO = 2;

    /** Dias de cortesia despues del vencimiento antes de cortar el acceso. */
    public const DIAS_GRACIA = 5;

    public const FASE_ACTIVA = 'activa';
    public const FASE_POR_VENCER = 'por_vencer';
    public const FASE_GRACIA = 'gracia';
    public const FASE_BLOQUEADA = 'bloqueada';
    public const FASE_CANCELADA = 'cancelada';
    public const FASE_SIN_SUSCRIPCION = 'sin_suscripcion';

    private const SEGUNDOS_POR_DIA = 86400;

    /**
     * Evalua la vigencia de una suscripcion.
     *
     * @param array<string,mixed>|null $suscripcion Fila de `empresas_suscripcion`, o null si no existe.
     * @param int|null                 $now         Timestamp de referencia; solo se inyecta en tests.
     *
     * @return array{
     *     fase: string,
     *     permite_acceso: bool,
     *     en_periodo_de_aviso: bool,
     *     dias_para_vencer: int|null,
     *     dias_de_gracia_restantes: int|null,
     *     fecha_fin: string|null,
     *     fecha_limite_gracia: string|null,
     *     mensaje: string
     * }
     */
    public static function evaluate(?array $suscripcion, ?int $now = null): array
    {
        $now ??= time();

        if ($suscripcion === null) {
            return self::resultado(
                self::FASE_SIN_SUSCRIPCION,
                false,
                'Tu suscripcion no se encuentra activa o no existe. Por favor regulariza tu pago.'
            );
        }

        // La baja explicita no pasa por la gracia: el cliente pidio terminar.
        if ((string) ($suscripcion['estado'] ?? '') === 'cancelada') {
            return self::resultado(
                self::FASE_CANCELADA,
                false,
                'Tu suscripcion esta cancelada. Contrata un plan para volver a operar.'
            );
        }

        $fechaFinRaw = $suscripcion['fecha_fin'] ?? null;
        $fechaFin = is_string($fechaFinRaw) && $fechaFinRaw !== '' ? strtotime($fechaFinRaw) : false;

        // Sin fecha de termino legible no hay periodo que defender: se trata como
        // vencida en vez de conceder acceso indefinido por un dato corrupto.
        if ($fechaFin === false) {
            return self::resultado(
                self::FASE_BLOQUEADA,
                false,
                'No pudimos determinar la vigencia de tu plan. Realiza el pago para reactivar MyPOS.'
            );
        }

        $limiteGracia = $fechaFin + (self::DIAS_GRACIA * self::SEGUNDOS_POR_DIA);
        $fechaFinTexto = self::fecha($fechaFin);

        if ($now <= $fechaFin) {
            $diasParaVencer = (int) ceil(($fechaFin - $now) / self::SEGUNDOS_POR_DIA);

            if ($diasParaVencer > self::DIAS_AVISO_PREVIO) {
                return self::resultado(self::FASE_ACTIVA, true, '', $diasParaVencer, null, $fechaFin, $limiteGracia);
            }

            $mensaje = $diasParaVencer <= 0
                ? sprintf('Tu plan MyPOS vence hoy (%s). Paga con Flow para seguir operando sin interrupciones.', $fechaFinTexto)
                : sprintf(
                    'Tu plan MyPOS vence el %s: %s. Puedes pagar con Flow cuando quieras, en un minuto.',
                    $fechaFinTexto,
                    $diasParaVencer === 1 ? 'queda 1 dia' : sprintf('quedan %d dias', $diasParaVencer)
                );

            return self::resultado(self::FASE_POR_VENCER, true, $mensaje, $diasParaVencer, null, $fechaFin, $limiteGracia);
        }

        if ($now <= $limiteGracia) {
            $diasDeGracia = (int) ceil(($limiteGracia - $now) / self::SEGUNDOS_POR_DIA);
            $mensaje = sprintf(
                'Tu plan vencio el %s. Te %s de cortesia para regularizar el pago antes de que se suspenda el acceso.',
                $fechaFinTexto,
                $diasDeGracia === 1 ? 'queda 1 dia' : sprintf('quedan %d dias', $diasDeGracia)
            );

            return self::resultado(self::FASE_GRACIA, true, $mensaje, 0, $diasDeGracia, $fechaFin, $limiteGracia);
        }

        return self::resultado(
            self::FASE_BLOQUEADA,
            false,
            sprintf(
                'Tu plan vencio el %s y se agotaron los %d dias de cortesia. Realiza el pago para reactivar MyPOS.',
                $fechaFinTexto,
                self::DIAS_GRACIA
            ),
            0,
            0,
            $fechaFin,
            $limiteGracia
        );
    }

    /**
     * Estado que corresponde guardar en `empresas_suscripcion.estado`.
     *
     * Durante la gracia la fila ya dice 'vencida' aunque el acceso siga abierto:
     * la columna describe el calendario, no el permiso.
     */
    public static function estadoPersistible(string $fase): string
    {
        return match ($fase) {
            self::FASE_ACTIVA, self::FASE_POR_VENCER => 'activa',
            self::FASE_CANCELADA => 'cancelada',
            default => 'vencida',
        };
    }

    /**
     * @return array{
     *     fase: string,
     *     permite_acceso: bool,
     *     en_periodo_de_aviso: bool,
     *     dias_para_vencer: int|null,
     *     dias_de_gracia_restantes: int|null,
     *     fecha_fin: string|null,
     *     fecha_limite_gracia: string|null,
     *     mensaje: string
     * }
     */
    private static function resultado(
        string $fase,
        bool $permiteAcceso,
        string $mensaje,
        ?int $diasParaVencer = null,
        ?int $diasDeGracia = null,
        ?int $fechaFin = null,
        ?int $limiteGracia = null
    ): array {
        return [
            'fase' => $fase,
            'permite_acceso' => $permiteAcceso,
            // Señal unica para la SPA: hay algo que decirle al cliente aunque
            // todavia pueda trabajar con normalidad.
            'en_periodo_de_aviso' => $permiteAcceso && $mensaje !== '',
            'dias_para_vencer' => $diasParaVencer,
            'dias_de_gracia_restantes' => $diasDeGracia,
            'fecha_fin' => $fechaFin !== null ? date('Y-m-d H:i:s', $fechaFin) : null,
            'fecha_limite_gracia' => $limiteGracia !== null ? date('Y-m-d H:i:s', $limiteGracia) : null,
            'mensaje' => $mensaje,
        ];
    }

    private static function fecha(int $timestamp): string
    {
        return date('d-m-Y', $timestamp);
    }
}
