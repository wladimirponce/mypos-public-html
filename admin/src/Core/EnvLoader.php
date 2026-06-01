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
        if (array_key_exists($key, $_ENV)) {
            return (string)$_ENV[$key];
        }

        $value = getenv($key);
        return ($value !== false) ? (string)$value : $default;
    }

    public static function getFirstString(array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = self::getString($key, '');
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
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
