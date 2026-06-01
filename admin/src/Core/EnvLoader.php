<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Clase para cargar configuraciones de entorno o valores por defecto.
 */
class EnvLoader
{
    /**
     * Obtiene una cadena de texto del entorno o retorna el valor por defecto.
     */
    public static function getString(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return ($value !== false) ? $value : $default;
    }

    /**
     * Obtiene un entero del entorno o retorna el valor por defecto.
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $value = getenv($key);
        return ($value !== false) ? (int)$value : $default;
    }

    /**
     * Obtiene un booleano del entorno.
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = getenv($key);
        if ($value === false) return $default;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
