<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use DateTimeZone;
use Mypos\Core\HttpException;

/**
 * Reglas de venta restringida (alcohol y tabaco).
 *
 * - Edad (Ley 19.925 alcohol / Ley 19.419 tabaco): si la venta incluye items
 *   restringidos y la empresa tiene control_edad_activo, el POS debe enviar
 *   edad_verificada = true (el cajero confirmó cédula). Sin eso, se bloquea.
 * - Horario (solo ALCOHOL, Ley 19.925): si control_horario_alcohol_activo,
 *   fuera de la ventana configurada la venta de alcohol se rechaza.
 *
 * La ventana horaria puede cruzar medianoche (inicio 09:00, fin 01:00 = hasta
 * la 01:00 del día siguiente). Para decidir qué "día" rige en la madrugada,
 * las horas antes de las 06:00 se atribuyen al día anterior: a las 00:30 del
 * sábado rige la regla del viernes (fin de semana empieza con la noche del
 * sábado, no con su madrugada).
 */
final class VentaRestringidaService
{
    private const TIMEZONE = 'America/Santiago';

    /**
     * Valida una venta contra la configuración efectiva de la empresa.
     *
     * @param array $config Configuración efectiva (ConfiguracionService::efectiva)
     * @param array $preparedItems Items preparados; cada uno con 'venta_restringida'
     * @param array $payload Payload original de la venta (para edad_verificada)
     * @return bool true si la venta requirió verificación de edad
     */
    public function validar(array $config, array $preparedItems, array $payload): bool
    {
        $restricciones = array_unique(array_filter(array_map(
            static fn (array $item): string => strtoupper((string) ($item['venta_restringida'] ?? 'NINGUNA')),
            $preparedItems
        ), static fn (string $r): bool => $r !== 'NINGUNA' && $r !== ''));

        if ($restricciones === []) {
            return false;
        }

        if (in_array('ALCOHOL', $restricciones, true)) {
            $this->validarHorarioAlcohol($config);
        }

        $controlEdad = !isset($config['control_edad_activo']) || (bool) (int) $config['control_edad_activo'];
        if (!$controlEdad) {
            return false;
        }

        $edadVerificada = filter_var($payload['edad_verificada'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$edadVerificada) {
            $tipos = implode(' y ', array_map('ucfirst', array_map('strtolower', $restricciones)));
            throw new HttpException(
                "La venta incluye productos restringidos ({$tipos}): el cajero debe verificar la cedula de identidad (mayor de 18) y confirmarlo.",
                422,
                ['edad_verificada' => ['Confirmacion de cedula requerida para productos restringidos']]
            );
        }

        return true;
    }

    public function validarHorarioAlcohol(array $config, ?DateTimeImmutable $ahora = null): void
    {
        $activo = (bool) (int) ($config['control_horario_alcohol_activo'] ?? 0);
        if (!$activo) {
            return;
        }

        $ahora ??= new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));

        if ($this->dentroDeHorario($config, $ahora)) {
            return;
        }

        $inicio = $this->hora($config['alcohol_hora_inicio'] ?? '09:00:00');
        $finSemana = $this->hora($config['alcohol_hora_fin'] ?? '01:00:00');
        $finFinde = $this->hora($config['alcohol_hora_fin_finde'] ?? '03:00:00');

        throw new HttpException(
            'Venta de alcohol fuera del horario permitido '
            . "(lun-vie {$inicio} a {$finSemana}, sab-dom {$inicio} a {$finFinde}). "
            . 'Retire los productos con alcohol para continuar.',
            422,
            ['horario_alcohol' => ['Fuera del horario legal de venta de alcohol']]
        );
    }

    public function dentroDeHorario(array $config, DateTimeImmutable $ahora): bool
    {
        $inicio = $this->minutos($config['alcohol_hora_inicio'] ?? '09:00:00');
        $finSemana = $this->minutos($config['alcohol_hora_fin'] ?? '01:00:00');
        $finFinde = $this->minutos($config['alcohol_hora_fin_finde'] ?? '03:00:00');

        $minutosAhora = ((int) $ahora->format('H')) * 60 + (int) $ahora->format('i');

        // Madrugada (< 06:00): rige la ventana que abrió el día anterior.
        $esMadrugada = $minutosAhora < 360;
        $diaEfectivo = $esMadrugada ? $ahora->modify('-1 day') : $ahora;
        $diaSemana = (int) $diaEfectivo->format('N'); // 1=lunes .. 7=domingo
        $fin = $diaSemana >= 6 ? $finFinde : $finSemana;

        if ($fin > $inicio) {
            // Ventana dentro del mismo día (ej: 09:00–21:00).
            return !$esMadrugada && $minutosAhora >= $inicio && $minutosAhora < $fin;
        }

        // Ventana que cruza medianoche (ej: 09:00–01:00).
        if ($esMadrugada) {
            return $minutosAhora < $fin;
        }

        return $minutosAhora >= $inicio;
    }

    private function minutos(mixed $hora): int
    {
        $parts = explode(':', (string) $hora);

        return ((int) ($parts[0] ?? 0)) * 60 + (int) ($parts[1] ?? 0);
    }

    private function hora(mixed $hora): string
    {
        return substr((string) $hora, 0, 5);
    }
}
