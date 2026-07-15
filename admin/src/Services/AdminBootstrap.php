<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\EnvLoader;
use PDO;

class AdminBootstrap
{
    public static function ensureSuperAdmin(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS admin_usuario (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                nombre VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                rol ENUM('superadmin','operador') NOT NULL DEFAULT 'operador',
                activo TINYINT(1) NOT NULL DEFAULT 1,
                ultimo_login DATETIME NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_admin_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Las credenciales del superadmin provienen SIEMPRE del entorno.
        // No hay valores por defecto: sin configuración no se siembra nada
        // (evita una credencial conocida hardcodeada en el código fuente).
        $email = EnvLoader::getString('ADMIN_SUPER_EMAIL', '');
        $password = EnvLoader::getString('ADMIN_SUPER_PASSWORD', '');
        $name = EnvLoader::getString('ADMIN_SUPER_NAME', 'Administrador');

        if ($email === '' || $password === '') {
            return;
        }
        AdminSecurity::validatePassword($password);

        // Crear el superadmin solo si NO existe. Nunca se sobrescribe la
        // contraseña de una cuenta existente: el antiguo ON DUPLICATE KEY
        // UPDATE reseteaba la clave en cada intento de login.
        $check = $db->prepare('SELECT id FROM admin_usuario WHERE email = :email LIMIT 1');
        $check->execute([':email' => $email]);
        if ($check->fetch() !== false) {
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO admin_usuario (nombre, email, password_hash, rol, activo)
             VALUES (:nombre, :email, :password_hash, 'superadmin', 1)"
        );

        $stmt->execute([
            ':nombre' => $name,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }
}
