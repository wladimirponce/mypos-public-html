<?php
/**
 * Login del panel de administración MyPOS
 * Autentica contra la tabla admin_usuario de la BD mypos.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

// Si ya está autenticado, ir al dashboard directamente
if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/autoload.php';
use App\Core\Database;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim((string)($_POST['email']    ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Ingrese email y contraseña.';
    } else {
        try {
            $db  = Database::getInstance();
            $stmt = $db->prepare(
                "SELECT id, nombre, password_hash, rol
                   FROM admin_usuario
                  WHERE email = :email AND activo = 1
                  LIMIT 1"
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                // Login exitoso
                session_regenerate_id(true);
                $_SESSION['admin_id']     = (int)$user['id'];
                $_SESSION['admin_nombre'] = $user['nombre'];
                $_SESSION['admin_rol']    = $user['rol'];

                // Actualizar último login
                $db->prepare("UPDATE admin_usuario SET ultimo_login = NOW() WHERE id = :id")
                   ->execute([':id' => $user['id']]);

                $redirect = $_GET['redirect'] ?? 'dashboard.php';
                header('Location: ' . $redirect);
                exit;
            } else {
                $error = 'Credenciales incorrectas.';
            }
        } catch (Exception $e) {
            $error = 'Error de conexión a la base de datos: ' . $e->getMessage();
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
