<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use Exception;

/**
 * Clase Singleton para la gestión de la conexión a la base de datos central.
 */
class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    /**
     * Constructor privado para prevenir instanciación externa.
     */
    private function __construct() {}

    /**
     * Inicializa la configuración y retorna la instancia única de PDO.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::setupConfig();
            self::connect();
        }
        return self::$instance;
    }

    /**
     * Configura los parámetros de conexión basados en el entorno.
     */
    private static function setupConfig(): void
    {
        // Detectar si estamos en un contenedor Docker (puedes ajustar esta lógica)
        $isDocker = file_exists('/.dockerenv') || EnvLoader::getBool('IS_DOCKER', false);
        $host = EnvLoader::getString('DB_HOST', 'localhost');

        self::$config = [
            'host'     => $host,
            'port'     => EnvLoader::getInt('DB_PORT', 3306),
            'dbname'   => EnvLoader::getFirstString(['DB_DATABASE', 'DB_NAME'], 'mypos'),
            'username' => EnvLoader::getFirstString(['DB_USERNAME', 'DB_USER'], $isDocker ? 'mypos_user' : 'mypos_user'),
            'password' => EnvLoader::getFirstString(['DB_PASSWORD', 'DB_PASS'], $isDocker ? 'mypos_password' : ''),
            'charset'  => EnvLoader::getString('DB_CHARSET', 'utf8mb4'),
        ];
    }

    /**
     * Establece la conexión PDO.
     */
    private static function connect(): void
    {
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=%s",
            self::$config['host'],
            self::$config['port'],
            self::$config['dbname'],
            self::$config['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . self::$config['charset']
        ];

        try {
            self::$instance = new PDO(
                $dsn,
                self::$config['username'],
                self::$config['password'],
                $options
            );
        } catch (PDOException $e) {
            // Loguear error (opcional) y lanzar excepción genérica para seguridad
            throw new Exception("Error de conexión a la base de datos central.", 0, $e);
        }
    }

    /**
     * Prevenir la clonación del objeto.
     */
    private function __clone() {}

    /**
     * Prevenir la deserialización.
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize a singleton.");
    }
}
