<?php
/**
 * fb/Database.php
 *
 * Conexión PDO a la BD central MyPOS (mypos).
 * Delega a App\Core\Database que ya tiene las credenciales de producción.
 */

namespace Fb;

use PDO;

final class Database
{
    private function __construct() {}

    /**
     * Retorna la conexión PDO a mypos.
     * Usa App\Core\Database (mismo singleton que api.php) para no duplicar credenciales.
     */
    public static function get(): PDO
    {
        return \App\Core\Database::getInstance();
    }

    public static function reset(): void
    {
        // no-op: el ciclo de vida lo maneja App\Core\Database
    }
}
