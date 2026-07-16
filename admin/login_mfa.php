<?php
/**
 * Desafío de segundo factor (TOTP) del panel administrativo.
 * Solo accesible con una sesión de desafío creada por login.php tras verificar
 * la contraseña. Nunca establece sesión completa sin un código válido.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
    ]);
    session_start();
}

if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/autoload.php';
use App\Core\Database;
use App\Services\AdminMfa;
use App\Services\AdminSecurity;

$challenge = $_SESSION['admin_mfa_challenge'] ?? null;
if (!is_array($challenge) || (int) ($challenge['id'] ?? 0) <= 0) {
    header('Location: login.php');
    exit;
}

// El segundo factor debe completarse en 5 minutos.
if (time() - (int) ($challenge['started'] ?? 0) > 300) {
    unset($_SESSION['admin_mfa_challenge']);
    header('Location: login.php?reason=mfa_expired');
    exit;
}

$error = '';
AdminSecurity::validatePostCsrf();

// Anti-fuerza-bruta del desafío (lockout por IP, basado en archivo).
$throttleMax    = 5;
$throttleWindow = 900;
$throttleDir    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mypos_admin_mfa';
$throttleFile   = $throttleDir . DIRECTORY_SEPARATOR . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . '.json';
$blocked = static function () use ($throttleFile, $throttleMax, $throttleWindow): bool {
    if (!is_file($throttleFile)) return false;
    $d = json_decode((string) file_get_contents($throttleFile), true);
    if (!is_array($d) || (int) ($d['first'] ?? 0) + $throttleWindow < time()) return false;
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
    $code = trim((string) ($_POST['code'] ?? ''));
    if ($blocked()) {
        $error = 'Demasiados intentos fallidos. Espere unos minutos e intente nuevamente.';
    } elseif ($code === '') {
        $error = 'Ingrese el código de su aplicación autenticadora.';
    } else {
        try {
            $db = Database::getInstance();
        } catch (Exception $e) {
            error_log('login_mfa.php: BD no disponible: ' . $e->getMessage());
            $error = 'No fue posible conectar con el servicio. Intente más tarde.';
            $db = null;
        }

        if ($db instanceof PDO && $error === '') {
            $adminId = (int) $challenge['id'];
            if (AdminMfa::verifyChallenge($db, $adminId, $code)) {
                // Revalidar que la cuenta siga activa antes de dar sesión completa.
                $stmt = $db->prepare('SELECT id, nombre, rol, activo FROM admin_usuario WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $adminId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user || (int) $user['activo'] !== 1) {
                    AdminSecurity::destroySession();
                    header('Location: login.php?reason=revoked');
                    exit;
                }
                $clearThrottle();
                $redirect = (string) ($challenge['redirect'] ?? 'dashboard.php');
                if (preg_match('#^(?:[a-z][a-z0-9+.\-]*:|//)#i', $redirect) || strpbrk($redirect, "\r\n") !== false) {
                    $redirect = 'dashboard.php';
                }
                unset($_SESSION['admin_mfa_challenge']);
                AdminSecurity::initializeAuthenticatedSession($user);
                $db->prepare('UPDATE admin_usuario SET ultimo_login = NOW() WHERE id = :id')->execute([':id' => $adminId]);
                header('Location: ' . $redirect);
                exit;
            }
            $registerFail();
            $error = 'Código incorrecto. Use el código actual o uno de recuperación.';
        }
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyPOS — Verificación en dos pasos</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f172a; display: flex; align-items: center; justify-content: center; min-height: 100vh; color: #e2e8f0; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 2.5rem; width: 100%; max-width: 380px; box-shadow: 0 20px 40px rgba(0,0,0,.4); }
        .logo { text-align: center; margin-bottom: 1.5rem; }
        .logo h1 { font-size: 1.75rem; font-weight: 700; color: #3b82f6; letter-spacing: -0.5px; }
        .logo p { color: #94a3b8; font-size: .85rem; margin-top: .25rem; }
        label { display: block; font-size: .8rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; margin-bottom: .4rem; }
        input[type=text] { width: 100%; padding: .65rem .9rem; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: #e2e8f0; font-size: 1.2rem; letter-spacing: .3rem; text-align: center; outline: none; margin-bottom: 1.25rem; }
        input:focus { border-color: #3b82f6; }
        button { width: 100%; padding: .75rem; background: #3b82f6; color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #2563eb; }
        .hint { color: #94a3b8; font-size: .8rem; margin-top: 1rem; text-align: center; }
        .hint a { color: #3b82f6; }
        .error { background: #450a0a; border: 1px solid #b91c1c; color: #fca5a5; border-radius: 8px; padding: .65rem .9rem; font-size: .87rem; margin-bottom: 1.25rem; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">
        <h1>MyPOS</h1>
        <p>Verificación en dos pasos</p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(AdminSecurity::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <label for="code">Código de verificación</label>
        <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
               pattern="[0-9A-Za-z\-]*" placeholder="123456" required autofocus>
        <button type="submit">Verificar</button>
    </form>
    <p class="hint">Ingrese el código de su app autenticadora, o un código de recuperación.<br>
        <a href="logout.php">Cancelar</a></p>
</div>
</body>
</html>
