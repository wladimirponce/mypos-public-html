<?php
/**
 * Login del panel de administración MyPOS
 * Autentica contra la tabla admin_usuario de la BD mypos.
 */
if (session_status() === PHP_SESSION_NONE) {
    // Cookie de sesión endurecida: HttpOnly + SameSite=Strict (defensa CSRF)
    // + Secure cuando hay HTTPS.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
    ]);
    session_start();
}

// Si ya está autenticado, ir al dashboard directamente
if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/autoload.php';
use App\Core\Database;
use App\Services\AdminBootstrap;
use App\Services\AdminMfa;
use App\Services\AdminSecurity;

$error = '';
AdminSecurity::validatePostCsrf();

// --- Anti-fuerza-bruta del login (lockout por IP, basado en archivo) ---
$throttleMax    = 5;     // intentos fallidos permitidos por ventana
$throttleWindow = 900;   // ventana y duración del bloqueo, en segundos (15 min)
$throttleDir    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mypos_admin_login';
$throttleFile   = $throttleDir . DIRECTORY_SEPARATOR . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.json';

$loginBlocked = static function () use ($throttleFile, $throttleMax, $throttleWindow): bool {
    if (!is_file($throttleFile)) return false;
    $d = json_decode((string) file_get_contents($throttleFile), true);
    if (!is_array($d)) return false;
    if ((int) ($d['first'] ?? 0) + $throttleWindow < time()) return false; // ventana expirada
    return (int) ($d['count'] ?? 0) >= $throttleMax;
};
$registerFail = static function () use ($throttleDir, $throttleFile, $throttleWindow): void {
    if (!is_dir($throttleDir)) { @mkdir($throttleDir, 0700, true); }
    $d = is_file($throttleFile) ? json_decode((string) file_get_contents($throttleFile), true) : null;
    if (!is_array($d) || (int) ($d['first'] ?? 0) + $throttleWindow < time()) {
        $d = ['count' => 0, 'first' => time()];
    }
    $d['count'] = (int) ($d['count'] ?? 0) + 1;
    @file_put_contents($throttleFile, json_encode($d), LOCK_EX);
};
$clearThrottle = static function () use ($throttleFile): void {
    if (is_file($throttleFile)) { @unlink($throttleFile); }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim((string)($_POST['email']    ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($loginBlocked()) {
        $error = 'Demasiados intentos fallidos. Espere unos minutos e intente nuevamente.';
    } elseif ($email === '' || $password === '') {
        $error = 'Ingrese email y contraseña.';
    } else {
        try {
            $db  = Database::getInstance();
        } catch (Exception $e) {
            error_log('login.php: error de conexión a la BD: ' . $e->getMessage());
            $error = 'No fue posible conectar con el servicio. Intente más tarde.';
            $db = null;
        }

        if ($db instanceof PDO && $error === '') {
            try {
                AdminBootstrap::ensureSuperAdmin($db);
            } catch (Exception $e) {
                error_log('login.php: error preparando superadmin: ' . $e->getMessage());
                $error = 'No fue posible conectar con el servicio. Intente más tarde.';
            }
        }

        if ($db instanceof PDO && $error === '') {
            try {
            $stmt = $db->prepare(
                "SELECT id, nombre, password_hash, rol
                   FROM admin_usuario
                  WHERE email = :email AND activo = 1
                  LIMIT 1"
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                // Primer factor OK.
                $clearThrottle();

                // Prevención de open redirect: solo se permiten rutas internas
                // relativas. Se descartan URLs absolutas, protocol-relative (//)
                // y cualquier intento de inyección de cabeceras (CRLF).
                $redirect = (string) ($_GET['redirect'] ?? 'dashboard.php');
                if (preg_match('#^(?:[a-z][a-z0-9+.\-]*:|//)#i', $redirect)
                    || strpbrk($redirect, "\r\n") !== false) {
                    $redirect = 'dashboard.php';
                }

                $adminId = (int) $user['id'];
                $rol     = (string) $user['rol'];
                $mfaOn   = AdminMfa::isGloballyEnabled();

                if ($mfaOn && AdminMfa::isEnrolled($db, $adminId)) {
                    // Segundo factor obligatorio: sesión de DESAFÍO, no completa.
                    session_regenerate_id(true);
                    $_SESSION['admin_mfa_challenge'] = [
                        'id' => $adminId, 'nombre' => (string) $user['nombre'],
                        'rol' => $rol, 'started' => time(), 'redirect' => $redirect,
                    ];
                    header('Location: login_mfa.php');
                    exit;
                }

                if ($mfaOn && AdminMfa::roleRequiresMfa($rol) && AdminMfa::tableAvailable($db)) {
                    // Rol obligado a MFA sin enrolar: forzar enrolamiento primero.
                    session_regenerate_id(true);
                    $_SESSION['admin_mfa_enroll'] = [
                        'id' => $adminId, 'nombre' => (string) $user['nombre'],
                        'rol' => $rol, 'email' => $email, 'started' => time(),
                        'redirect' => $redirect,
                    ];
                    header('Location: mfa_enroll.php');
                    exit;
                }

                // Flag apagada, u operador no obligado: sesión completa directa.
                AdminSecurity::initializeAuthenticatedSession($user);
                $db->prepare("UPDATE admin_usuario SET ultimo_login = NOW() WHERE id = :id")
                   ->execute([':id' => $adminId]);
                header('Location: ' . $redirect);
                exit;
            } else {
                $registerFail();
                $error = 'Credenciales incorrectas.';
            }
        } catch (Exception $e) {
            error_log('login.php: error consultando admin_usuario: ' . $e->getMessage());
            $error = 'No fue posible procesar la solicitud. Intente más tarde.';
        }
    }
}
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyPOS — Iniciar sesión</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: #e2e8f0;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 2.5rem;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 20px 40px rgba(0,0,0,.4);
        }
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .logo h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #3b82f6;
            letter-spacing: -0.5px;
        }
        .logo p {
            color: #94a3b8;
            font-size: .85rem;
            margin-top: .25rem;
        }
        label {
            display: block;
            font-size: .8rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: .4rem;
        }
        input[type=email], input[type=password] {
            width: 100%;
            padding: .65rem .9rem;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            color: #e2e8f0;
            font-size: .95rem;
            outline: none;
            transition: border-color .2s;
            margin-bottom: 1.25rem;
        }
        input:focus { border-color: #3b82f6; }
        button {
            width: 100%;
            padding: .75rem;
            background: #3b82f6;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }
        button:hover { background: #2563eb; }
        .error {
            background: #450a0a;
            border: 1px solid #b91c1c;
            color: #fca5a5;
            border-radius: 8px;
            padding: .65rem .9rem;
            font-size: .87rem;
            margin-bottom: 1.25rem;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">
        <h1>MyPOS</h1>
        <p>Panel de administración</p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(AdminSecurity::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <label for="email">Correo electrónico</label>
        <input type="email" id="email" name="email"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               placeholder="admin@mypos.cl" required autofocus>

        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password"
               placeholder="••••••••" required>

        <button type="submit">Iniciar sesión</button>
    </form>
</div>
</body>
</html>
