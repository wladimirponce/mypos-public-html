<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class AdminSecurity
{
    private const IDLE_TIMEOUT = 1800;
    private const ABSOLUTE_TIMEOUT = 43200;
    private const REVALIDATE_SECONDS = 300;

    /** @var list<string> */
    private const SUPERADMIN_ONLY_MODULES = [
        'empresas', 'clientes_mypos', 'promos', 'ventas_reset', 'saas', 'usuarios', 'config',
    ];

    public static function initializeAuthenticatedSession(array $user): void
    {
        session_regenerate_id(true);
        $now = time();
        $_SESSION['admin_id'] = (int) $user['id'];
        $_SESSION['admin_nombre'] = (string) $user['nombre'];
        $_SESSION['admin_rol'] = (string) $user['rol'];
        $_SESSION['admin_created_at'] = $now;
        $_SESSION['admin_last_activity'] = $now;
        $_SESSION['admin_last_validated'] = $now;
        self::csrfToken();
    }

    public static function guard(PDO $db): void
    {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        if ($adminId <= 0) {
            self::redirectToLogin('auth');
        }

        $now = time();
        $createdAt = (int) ($_SESSION['admin_created_at'] ?? $now);
        $lastActivity = (int) ($_SESSION['admin_last_activity'] ?? $now);
        if (self::isSessionExpired($createdAt, $lastActivity, $now)) {
            self::destroySession();
            self::redirectToLogin('expired');
        }

        $lastValidated = (int) ($_SESSION['admin_last_validated'] ?? 0);
        if (($now - $lastValidated) >= self::REVALIDATE_SECONDS) {
            $stmt = $db->prepare('SELECT id, nombre, rol, activo FROM admin_usuario WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $adminId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || (int) $user['activo'] !== 1) {
                self::destroySession();
                self::redirectToLogin('revoked');
            }
            $_SESSION['admin_nombre'] = (string) $user['nombre'];
            $_SESSION['admin_rol'] = (string) $user['rol'];
            $_SESSION['admin_last_validated'] = $now;
        }
        $_SESSION['admin_last_activity'] = $now;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['admin_csrf_token'])) {
            $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['admin_csrf_token'];
    }

    public static function validatePostCsrf(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        $provided = (string) ($_POST['admin_csrf_token'] ?? '');
        if (!self::isValidCsrf(self::csrfToken(), $provided)) {
            http_response_code(403);
            exit('Solicitud rechazada: token de seguridad invalido. Recargue la pagina.');
        }
        self::validateSameOrigin();
    }

    public static function requireModuleAccess(string $module): void
    {
        $role = (string) ($_SESSION['admin_rol'] ?? '');
        if (!self::isModuleAllowed($role, $module)) {
            http_response_code(403);
            exit('No tiene permisos para acceder a este modulo.');
        }
    }

    public static function isSessionExpired(int $createdAt, int $lastActivity, ?int $now = null): bool
    {
        $now ??= time();
        return ($now - $lastActivity) > self::IDLE_TIMEOUT || ($now - $createdAt) > self::ABSOLUTE_TIMEOUT;
    }

    public static function isValidCsrf(string $expected, string $provided): bool
    {
        return $provided !== '' && strlen($expected) === 64 && hash_equals($expected, $provided);
    }

    public static function isModuleAllowed(string $role, string $module): bool
    {
        return $role === 'superadmin'
            || ($role === 'operador' && !in_array($module, self::SUPERADMIN_ONLY_MODULES, true));
    }

    public static function validatePassword(string $password): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
        if ($length < 12 || $length > 128) {
            throw new \InvalidArgumentException('La contrasena debe tener entre 12 y 128 caracteres.');
        }
    }

    public static function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private static function validateSameOrigin(): void
    {
        $source = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
        if ($source === '') {
            return;
        }
        $sourceHost = strtolower((string) parse_url($source, PHP_URL_HOST));
        $requestHost = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
        if ($sourceHost === '' || $requestHost === '' || !hash_equals($requestHost, $sourceHost)) {
            http_response_code(403);
            exit('Solicitud rechazada: origen no permitido.');
        }
    }

    private static function redirectToLogin(string $reason): never
    {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? 'dashboard.php');
        header('Location: login.php?reason=' . rawurlencode($reason) . '&redirect=' . $redirect);
        exit;
    }
}
