<?php

declare(strict_types=1);

namespace Mypos\Config;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;
    private static ?PDO $readOnlyConnection = null;

    public static function reset(): void
    {
        self::$connection = null;
        self::$readOnlyConnection = null;
    }

    /**
     * Conexión de SOLO LECTURA para el agente IA (skills sql_readonly).
     *
     * M5 (auditoría): usa el usuario MySQL con solo SELECT sobre la whitelist
     * (database/scripts/agente_usuario_readonly.sql) cuando está configurado por
     * entorno (AGENTE_DB_USERNAME/AGENTE_DB_PASSWORD). Es el control de fondo:
     * aunque el validador SQL tuviera un bypass, la BD rechaza cualquier
     * escritura. Si no está configurado, cae a la conexión principal para no
     * romper la funcionalidad — el aislamiento se activa solo al aplicar el SQL
     * y definir las variables. Ver docs/AGENTE_SQL_READONLY.md.
     */
    public static function readOnlyConnection(): PDO
    {
        if (self::$readOnlyConnection instanceof PDO) {
            return self::$readOnlyConnection;
        }

        $roUser = $_ENV['AGENTE_DB_USERNAME'] ?? getenv('AGENTE_DB_USERNAME') ?: '';
        $roPass = $_ENV['AGENTE_DB_PASSWORD'] ?? getenv('AGENTE_DB_PASSWORD') ?: '';

        if ($roUser === '') {
            // Sin usuario read-only configurado: fallback seguro a la principal.
            return self::connection();
        }

        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'db';
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
        $database = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'mypos';
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);

        try {
            self::$readOnlyConnection = new PDO($dsn, $roUser, (string) $roPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // Tipos nativos en los fetch (INT/TINYINT llegan como number al
                // JSON, no como "1"); evita el bug recurrente de flags string.
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $exception) {
            throw new PDOException('No se pudo conectar a la base de datos (read-only).', (int) $exception->getCode(), $exception);
        }

        return self::$readOnlyConnection;
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'db';
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
        $database = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'mypos';
        $username = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'mypos_user';
        $password = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: 'mypos_password';

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);

        try {
            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // Tipos nativos en los fetch (ver readOnlyConnection).
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $exception) {
            throw new PDOException('No se pudo conectar a la base de datos.', (int) $exception->getCode(), $exception);
        }

        return self::$connection;
    }
}
