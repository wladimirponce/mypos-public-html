<?php

declare(strict_types=1);

namespace Mypos\Support;

final class PlanCatalog
{
    private const PLANS = [
        'mypos-start' => ['nombre' => 'MyPOS Start', 'max_sucursales' => 1, 'max_usuarios' => 9999, 'price_clp' => 23788, 'price_usd' => 25.00],
        'mypos-pro' => ['nombre' => 'MyPOS Pro', 'max_sucursales' => 2, 'max_usuarios' => 9999, 'price_clp' => 30928, 'price_usd' => 33.00],
        'mypos-cadena' => ['nombre' => 'MyPOS Cadena', 'max_sucursales' => 3, 'max_usuarios' => 9999, 'price_clp' => 35688, 'price_usd' => 38.00],
        'mypos-escala' => ['nombre' => 'MyPOS Escala', 'max_sucursales' => 4, 'max_usuarios' => 9999, 'price_clp' => 47588, 'price_usd' => 51.00],
    ];

    private const ALIASES = [
        'pos' => 'mypos-start',
        'multisucursal' => 'mypos-pro',
    ];

    public static function normalize(string $planId): string
    {
        $planId = strtolower(trim($planId));
        return self::ALIASES[$planId] ?? (isset(self::PLANS[$planId]) ? $planId : 'mypos-start');
    }

    public static function get(string $planId): array
    {
        $normalized = self::normalize($planId);
        return ['id' => $normalized, 'dispositivos_ilimitados' => true] + self::PLANS[$normalized];
    }

    public static function isValid(string $planId): bool
    {
        $planId = strtolower(trim($planId));
        return isset(self::PLANS[$planId]) || isset(self::ALIASES[$planId]);
    }
}
