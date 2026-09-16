<?php

declare(strict_types=1);

namespace Mypos\Support;

/**
 * Decide si una URL puede ser alcanzada por un tercero desde internet.
 *
 * Existe para una sola pregunta concreta: cuando le entregamos a Flow la URL
 * donde debe confirmar un pago, esa URL tiene que ser publica. Si apunta a
 * localhost, a una IP privada o a un host vacio, el webhook nunca llega y el
 * cliente termina pagando sin que se le acredite nada.
 *
 * No hace ninguna llamada de red: es una verificacion de forma, barata y
 * determinista, pensada para correr antes de mover dinero.
 */
final class PublicUrl
{
    /** Hosts que solo resuelven dentro de la propia maquina. */
    private const HOSTS_LOCALES = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

    public static function isReachableFromInternet(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '' || in_array($host, self::HOSTS_LOCALES, true)) {
            return false;
        }

        // Dominios de red local: no salen a internet.
        if (str_ends_with($host, '.local') || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            return false;
        }

        // Una IP literal solo sirve si es enrutable: se descartan los rangos
        // privados (10.x, 192.168.x, 172.16-31.x, fc00::/7) y los reservados.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        // Un nombre sin punto (p. ej. "backend", el alias de un contenedor) solo
        // resuelve dentro de la red interna.
        return str_contains($host, '.');
    }
}
