<?php
/**
 * Instalador Automático de Base de Datos MyPOS
 * ¡ADVERTENCIA! Elimina este archivo después de usarlo en producción.
 */

$mensaje = '';

function mypos_resolve_dir(array $candidates, string $label): string
{
    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved && is_dir($resolved)) {
            return $resolved;
        }
    }

    throw new Exception("No se pudo encontrar la carpeta requerida: {$label}.");
}

function mypos_resolve_file(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved && is_file($resolved)) {
            return $resolved;
        }
    }

    return null;
}

function mypos_sql_statements(string $sql): array
{
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $lines = explode("\n", $sql);
    $delimiter = ';';
    $buffer = '';
    $statements = [];

    foreach ($lines as $line) {
        $trimmedLine = trim($line);

        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmedLine, $matches) === 1) {
            $delimiter = $matches[1];
            continue;
        }

        $buffer .= $line . "\n";
        $trimmedBuffer = rtrim($buffer);

        if ($trimmedBuffer === '' || !str_ends_with($trimmedBuffer, $delimiter)) {
            continue;
        }

        $statement = substr($trimmedBuffer, 0, -strlen($delimiter));
        $statement = trim($statement);

        if ($statement !== '') {
            $statements[] = $statement;
        }

        $buffer = '';
    }

    $remaining = trim($buffer);
    if ($remaining !== '') {
        $statements[] = $remaining;
    }

    return $statements;
}

function mypos_execute_sql_file(PDO $pdo, string $archivo): void
{
    $sql = file_get_contents($archivo);
    if ($sql === false || trim($sql) === '') {
        return;
    }

    foreach (mypos_sql_statements($sql) as $statement) {
        $pdo->exec($statement);
    }
}

function mypos_env_value(string $value): string
{
    if (preg_match('/^[A-Za-z0-9_.:@\/-]*$/', $value) === 1) {
        return $value;
    }

    return '"' . addcslashes($value, "\\\"") . '"';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = $_POST['host'] ?? 'localhost';
    $db   = $_POST['db'] ?? '';
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';

    if (empty($db) || empty($user)) {
        $mensaje = "<div class='alert alert-danger'>La Base de Datos y el Usuario son obligatorios.</div>";
    } else {
        try {
            // Conexión a la BD
            $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true // Necesario para correr archivos con múltiples sentencias
            ]);

            // Ruta a los archivos SQL (asumiendo que este script está en backend/public/)
            $database_dir = mypos_resolve_dir([
                __DIR__ . '/../../database',
                __DIR__ . '/../database',
            ], 'database');
            $sql_dir = mypos_resolve_dir([$database_dir . '/init'], 'database/init');

            // Buscar todos los archivos .sql y ordenarlos alfabéticamente para respetar llaves foráneas
            $archivos = glob($sql_dir . '/*.sql');
            sort($archivos);

            $log = "<ul class='list-group mt-3'>";
            $exito = 0;

            // Desactivar temporalmente revisiones de llaves foráneas para evitar conflictos
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");

            foreach ($archivos as $archivo) {
                $sql = file_get_contents($archivo);
                if (!empty(trim($sql))) {
                    try {
                        mypos_execute_sql_file($pdo, $archivo);
                        $log .= "<li class='list-group-item list-group-item-success'>✔️ " . basename($archivo) . " instalado.</li>";
                        $exito++;
                    } catch (PDOException $e) {
                        $log .= "<li class='list-group-item list-group-item-danger'>❌ Error en " . basename($archivo) . ": " . $e->getMessage() . "</li>";
                    }
                }
            }

            // Ejecutar datos semilla (seeds) si existen
            $seed_dir = realpath($database_dir . '/seeds');
            if ($seed_dir && is_dir($seed_dir)) {
                $seeds = glob($seed_dir . '/*.sql');
                sort($seeds);
                foreach ($seeds as $seed) {
                    $sql = file_get_contents($seed);
                    if (!empty(trim($sql))) {
                        try {
                            mypos_execute_sql_file($pdo, $seed);
                            $log .= "<li class='list-group-item list-group-item-info'>🌱 Seed: " . basename($seed) . " instalado.</li>";
                        } catch (PDOException $e) {
                            $log .= "<li class='list-group-item list-group-item-warning'>⚠️ Seed " . basename($seed) . " (quizás ya existe): " . $e->getMessage() . "</li>";
                        }
                    }
                }
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
            $log .= "</ul>";

            $mensaje = "<div class='alert alert-success'><h5>¡Instalación Finalizada!</h5>Se procesaron $exito archivos base. Detalles: $log</div>";

            // Guardar también las credenciales en el archivo .env automáticamente
            $envPath = mypos_resolve_file([
                __DIR__ . '/../.env',
                __DIR__ . '/../../.env',
            ]);
            if ($envPath && is_writable($envPath)) {
                $envContent = file_get_contents($envPath);
                $envContent = preg_replace('/DB_DATABASE=.*/', 'DB_DATABASE=' . mypos_env_value($db), $envContent);
                $envContent = preg_replace('/DB_USERNAME=.*/', 'DB_USERNAME=' . mypos_env_value($user), $envContent);
                $envContent = preg_replace('/DB_PASSWORD=.*/', 'DB_PASSWORD=' . mypos_env_value($pass), $envContent);
                $envContent = preg_replace('/DB_HOST=.*/', 'DB_HOST=' . mypos_env_value($host), $envContent);
                file_put_contents($envPath, $envContent);
                $mensaje .= "<div class='alert alert-info'>El archivo <b>.env</b> fue actualizado automáticamente.</div>";
            }

        } catch (PDOException $e) {
            $mensaje = "<div class='alert alert-danger'><b>Error de Conexión:</b> Verifica que el usuario y la clave sean correctos. " . $e->getMessage() . "</div>";
        } catch (Exception $e) {
            $mensaje = "<div class='alert alert-danger'><b>Error:</b> " . $e->getMessage() . "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador de Base de Datos - MyPOS</title>
    <!-- Usamos Bootstrap por CDN para que sea rápido y bonito -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { width: 100%; max-width: 500px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: none; border-radius: 12px; }
        .card-header { background-color: #0d6efd; color: white; border-radius: 12px 12px 0 0 !important; text-align: center; padding: 20px; }
    </style>
</head>
<body>

<div class="container my-5 d-flex justify-content-center">
    <div class="card">
        <div class="card-header">
            <h3 class="mb-0">🚀 Instalador MyPOS</h3>
            <p class="mb-0 text-white-50">Configuración de Base de Datos</p>
        </div>
        <div class="card-body p-4">
            
            <?php if (!empty($mensaje)) echo $mensaje; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label fw-bold">Servidor (Host)</label>
                    <input type="text" name="host" class="form-control" value="localhost" required>
                    <div class="form-text">En cPanel casi siempre es "localhost".</div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombre de la Base de Datos</label>
                    <input type="text" name="db" class="form-control" placeholder="ej: zylajdcb_mypos" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Usuario de BD</label>
                    <input type="text" name="user" class="form-control" placeholder="ej: zylajdcb_user" required>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Contraseña de BD</label>
                    <input type="password" name="pass" class="form-control" placeholder="Tu contraseña">
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Instalar Tablas y Configurar</button>
            </form>
            
            <div class="mt-4 text-center text-muted small">
                ⚠️ <b>Importante:</b> Una vez que termine la instalación, elimina este archivo (<code>instalar.php</code>) de tu servidor cPanel por seguridad.
            </div>
        </div>
    </div>
</div>

</body>
</html>
