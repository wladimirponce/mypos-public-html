<?php

declare(strict_types=1);

namespace Mypos\Support;

final class SubscriptionChargePolicy
{
    private const DEFAULT_RULES = '10:500';

    /**
     * Retorna la regla mensual de una empresa o null cuando no requiere cobro.
     *
     * El formato configurable es: empresa_id:monto_clp,empresa_id:monto_clp.
     * Ejemplo: 10:500,12:1500
     *
     * @return array{empresa_id:int,amount_clp:int,gateway:string}|null
     */
    public static function forEmpresa(int $empresaId): ?array
    {
        if ($empresaId <= 0) {
            return null;
        }

        $configured = $_ENV['FLOW_MONTHLY_CHARGE_RULES'] ?? getenv('FLOW_MONTHLY_CHARGE_RULES');
        $rawRules = $configured === false ? self::DEFAULT_RULES : trim((string) $configured);

        foreach (explode(',', $rawRules) as $rawRule) {
            $parts = array_map('trim', explode(':', $rawRule, 2));
            if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                continue;
            }

            $ruleEmpresaId = (int) $parts[0];
            $amountClp = (int) $parts[1];
            if ($ruleEmpresaId === $empresaId && $amountClp > 0) {
                return [
                    'empresa_id' => $ruleEmpresaId,
                    'amount_clp' => $amountClp,
                    'gateway' => 'flow',
                ];
            }
        }

        return null;
    }
}
