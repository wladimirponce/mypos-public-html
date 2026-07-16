<?php
/**
 * Enrolamiento del segundo factor (TOTP) del panel administrativo.
 *
 * Dos modos:
 *   - "pending": sin sesión completa, creado por login.php cuando un rol obligado
 *     a MFA no está enrolado. Al confirmar, se establece la sesión completa.
 *   - "self": operador ya autenticado que gestiona su MFA desde el panel.
 *
 * El enrolamiento solo se activa tras confirmar un primer código (diseño, punto 5).
 * Los códigos de recuperación se muestran una única vez.
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

require_once __DIR__ . '/autoload.php';
use App\Core\Database;
use App\Services\AdminMfa;
use App\Services\AdminSecurity;

try {
    $db = Database::getInstance();
} catch (Exception $e) {
    error_log('mfa_enroll.php: BD no disponible: ' . $e->getMessage());
    http_response_code(503);
    exit('Servicio no disponible temporalmente.');
}

if (!AdminMfa::tableAvailable($db)) {
    http_response_code(503);
    exit('El segundo factor requiere la migración 015_admin_mfa aplicada.');
}

// —— Resolver identidad y modo ——
$mode = null;
$adminId = 0;
$email = '';
$redirect = 'dashboard.php';

if (!empty($_SESSION['admin_id'])) {
    $mode = 'self';
    $adminId = (int) $_SESSION['admin_id'];
} elseif (is_array($_SESSION['admin_mfa_enroll'] ?? null)) {
    $pending = $_SESSION['admin_mfa_enroll'];
    if (time() - (int) ($pending['started'] ?? 0) > 600) {
        unset($_SESSION['admin_mfa_enroll']);
        header('Location: login.php?reason=mfa_expired');
        exit;
    }
    $mode = 'pending';
    $adminId = (int) ($pending['id'] ?? 0);
    $email = (string) ($pending['email'] ?? '');
    $redirect = (string) ($pending['redirect'] ?? 'dashboard.php');
    if (preg_match('#^(?:[a-z][a-z0-9+.\-]*:|//)#i', $redirect) || strpbrk($redirect, "\r\n") !== false) {
        $redirect = 'dashboard.php';
    }
}

if ($mode === null || $adminId <= 0) {
    header('Location: login.php');
    exit;
}

// Email para la etiqueta de la app autenticadora (self mode lo trae de BD).
if ($email === '') {
    $stmt = $db->prepare('SELECT email FROM admin_usuario WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $adminId]);
    $email = (string) ($stmt->fetchColumn() ?: ('admin-' . $adminId));
}

AdminSecurity::validatePostCsrf();

$error = '';
$recovery = null;
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    $code = trim((string) ($_POST['code'] ?? ''));
    $result = AdminMfa::confirmEnrollment($db, $adminId, $code);
    if ($result['ok']) {
        $recovery = $result['recovery'];
        $done = true;
        unset($_SESSION['admin_mfa_candidate']);

        if ($mode === 'pending') {
            // Confirmado: recién ahora se establece la sesión completa.
            $stmt = $db->prepare('SELECT id, nombre, rol, activo FROM admin_usuario WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $adminId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || (int) $user['activo'] !== 1) {
                AdminSecurity::destroySession();
                header('Location: login.php?reason=revoked');
                exit;
            }
            unset($_SESSION['admin_mfa_enroll']);
            AdminSecurity::initializeAuthenticatedSession($user);
            $db->prepare('UPDATE admin_usuario SET ultimo_login = NOW() WHERE id = :id')->execute([':id' => $adminId]);
        }
    } else {
        $error = 'Código incorrecto. Verifique la hora de su dispositivo e intente con el código actual.';
    }
}

// —— Generar candidato si aún no hay enrolamiento en curso ——
$secret = '';
$uri = '';
if (!$done) {
    $cand = $_SESSION['admin_mfa_candidate'] ?? null;
    if (!is_array($cand) || (int) ($cand['admin'] ?? 0) !== $adminId) {
        $enroll = AdminMfa::startEnrollment($db, $adminId, $email);
        $cand = ['admin' => $adminId, 'secret' => $enroll['secret'], 'uri' => $enroll['uri']];
        $_SESSION['admin_mfa_candidate'] = $cand;
    }
    $secret = (string) $cand['secret'];
    $uri = (string) $cand['uri'];
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyPOS — Configurar segundo factor</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f172a; display: flex; align-items: center; justify-content: center; min-height: 100vh; color: #e2e8f0; padding: 1.5rem; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 2.25rem; width: 100%; max-width: 460px; box-shadow: 0 20px 40px rgba(0,0,0,.4); }
        h1 { font-size: 1.4rem; color: #3b82f6; margin-bottom: .25rem; }
        p.sub { color: #94a3b8; font-size: .85rem; margin-bottom: 1.5rem; }
        label { display: block; font-size: .8rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; margin-bottom: .4rem; }
        .secret { font-family: monospace; font-size: 1.1rem; letter-spacing: .15rem; background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: .75rem; word-break: break-all; margin-bottom: 1rem; color: #e2e8f0; }
        .uri { font-family: monospace; font-size: .7rem; color: #64748b; word-break: break-all; margin-bottom: 1.25rem; }
        input[type=text] { width: 100%; padding: .65rem .9rem; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: #e2e8f0; font-size: 1.15rem; letter-spacing: .3rem; text-align: center; outline: none; margin-bottom: 1.25rem; }
        input:focus { border-color: #3b82f6; }
        button { width: 100%; padding: .75rem; background: #3b82f6; color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #2563eb; }
        .error { background: #450a0a; border: 1px solid #b91c1c; color: #fca5a5; border-radius: 8px; padding: .65rem .9rem; font-size: .87rem; margin-bottom: 1.25rem; }
        .ok { background: #052e16; border: 1px solid #16a34a; color: #86efac; border-radius: 8px; padding: .65rem .9rem; font-size: .87rem; margin-bottom: 1.25rem; }
        .codes { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin: 1rem 0 1.25rem; }
        .codes code { font-family: monospace; background: #0f172a; border: 1px solid #334155; border-radius: 6px; padding: .5rem; text-align: center; letter-spacing: .1rem; }
        .warn { color: #fbbf24; font-size: .82rem; margin-bottom: 1.25rem; }
        a.btn { display: block; text-align: center; text-decoration: none; padding: .75rem; background: #3b82f6; color: #fff; border-radius: 8px; font-weight: 600; }
        .steps { font-size: .85rem; color: #cbd5e1; margin-bottom: 1.25rem; line-height: 1.5; }
    </style>
</head>
<body>
<div class="card">
<?php if ($done): ?>
    <h1>Segundo factor activado</h1>
    <div class="ok">Tu autenticación en dos pasos quedó activa.</div>
    <p class="warn">Guarda estos códigos de recuperación en un lugar seguro. Cada uno sirve
        <strong>una sola vez</strong> y no volverán a mostrarse.</p>
    <div class="codes">
        <?php foreach (($recovery ?? []) as $rc): ?>
            <code><?= htmlspecialchars($rc) ?></code>
        <?php endforeach; ?>
    </div>
    <a class="btn" href="<?= htmlspecialchars($redirect) ?>">Continuar al panel</a>
<?php else: ?>
    <h1>Configurar segundo factor</h1>
    <p class="sub">Requerido para tu cuenta. Usa Google Authenticator, Authy, 1Password u otra app TOTP.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="steps">
        1. Abre tu app autenticadora y agrega una cuenta nueva.<br>
        2. Escanea el código o ingresa la clave manual:<br>
    </div>

    <label>Clave manual</label>
    <div class="secret"><?= htmlspecialchars($secret) ?></div>
    <label>Enlace otpauth (para QR)</label>
    <div class="uri"><?= htmlspecialchars($uri) ?></div>

    <form method="POST" action="">
        <input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(AdminSecurity::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="confirm">
        <label for="code">3. Ingresa el código que muestra la app</label>
        <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
               pattern="[0-9]*" maxlength="6" placeholder="123456" required autofocus>
        <button type="submit">Activar segundo factor</button>
    </form>
<?php endif; ?>
</div>
</body>
</html>
