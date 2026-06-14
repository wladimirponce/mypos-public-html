<?php
/**
 * Instalador Automático de Base de Datos MyPOS
 *
 * SEGURIDAD: deshabilitado por defecto. Solo se ejecuta si INSTALLER_TOKEN está
 * definido en el .env y la petición incluye el mismo token (?token= o campo
 * token). Tras usarlo, vacíe INSTALLER_TOKEN y elimine este archivo.
 * Ya NO reescribe el .env con datos recibidos por web.
 */

/**
 * Lee una clave directamente del archivo .env (sin depender del autoload).
 */
function mypos_env_lookup(string $key): string
{
    foreach ([__DIR__ . '/../.env', __DIR__ . '/../../.env'] as $candidate) {
        $resolved = realpath($candidate);
        if (!$resolved || !is_file($resolved)) {
            continue;
        }
        foreach (file($resolved, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            if (trim(substr($line, 0, $pos)) === $key) {
                return trim(substr($line, $pos + 1), " \t\"'");
            }
        }
    }

    return '';
}

// Puerta de acceso: sin token configurado y coincidente, el instalador no existe.
$installerToken = mypos_env_lookup('INSTALLER_TOKEN');
$providedToken  = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
if ($installerToken === '' || !hash_equals($installerToken, $providedToken)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Not found');
}

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
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
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
        try {
            $stmt = $pdo->query($statement);
            if ($stmt) {
                $stmt->closeCursor();
            }
        } catch (PDOException $e) {
            throw $e;
        }
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
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true, // Necesario para correr archivos con múltiples sentencias
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
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
            $errores = 0;
            $seedsExitosos = 0;
            $seedsErrores = 0;

            // Desactivar temporalmente revisiones de llaves foráneas para evitar conflictos
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;");
            $pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci';");
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
                        $errores++;
                    }
                }
            }

            $soloActualizar = $_POST['solo_actualizar'] ?? '0';

            // Solo el seed maestro global puede ejecutarse desde el instalador.
            // Los seeds demo o de empresas deben ejecutarse manualmente en ambientes controlados.
            if ($soloActualizar !== '1') {
                $seed_dir = realpath($database_dir . '/seeds');
                if ($seed_dir && is_dir($seed_dir)) {
                    $seedBase = mypos_resolve_file([$seed_dir . '/001_seed_base.sql']);
                    $seeds = $seedBase !== null ? [$seedBase] : [];
                    foreach ($seeds as $seed) {
                        $sql = file_get_contents($seed);
                        if (!empty(trim($sql))) {
                            try {
                                mypos_execute_sql_file($pdo, $seed);
                                $log .= "<li class='list-group-item list-group-item-info'>🌱 Seed: " . basename($seed) . " instalado.</li>";
                                $seedsExitosos++;
                            } catch (PDOException $e) {
                                $log .= "<li class='list-group-item list-group-item-danger'>❌ Error en seed " . basename($seed) . ": " . $e->getMessage() . "</li>";
                                $seedsErrores++;
                            }
                        }
                    }
                    $log .= "<li class='list-group-item list-group-item-primary'>ℹ️ Los seeds demo y de empresas fueron omitidos por seguridad.</li>";
                }
            } else {
                $log .= "<li class='list-group-item list-group-item-primary'>ℹ️ Modo de actualización: se omitió la ejecución de seeds.</li>";
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
            $log .= "</ul>";

            $totalErrores = $errores + $seedsErrores;
            if ($totalErrores === 0) {
                $mensaje = "<div class='alert alert-success'><h5>¡Instalación Finalizada!</h5>Se instalaron $exito archivos base y $seedsExitosos seeds sin errores. Detalles: $log</div>";
            } else {
                $mensaje = "<div class='alert alert-danger'><h5>Instalación incompleta</h5>Se instalaron $exito archivos base y $seedsExitosos seeds, con $totalErrores errores. Corrige los errores antes de usar el sistema. Detalles: $log</div>";
            }

            // SEGURIDAD: el instalador ya NO reescribe el .env con datos
            // recibidos por web (era un vector de toma de control). Configure
            // las credenciales de BD directamente en el archivo .env del servidor.
            $mensaje .= "<div class='alert alert-info'>Recuerde configurar las credenciales de BD en el archivo <b>.env</b> del servidor.</div>";

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

                <div class="mb-4 form-check bg-light p-3 border rounded">
                    <input type="checkbox" name="solo_actualizar" class="form-check-input ms-1" id="soloActualizar" value="1">
                    <label class="form-check-label fw-bold text-primary ms-2" for="soloActualizar">
                        Solo actualizar sistema (Ejecuta nuevas tablas y omite el seed maestro)
                    </label>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Ejecutar Instalación / Actualización</button>
            </form>
            
            <div class="mt-4 text-center text-muted small">
                ⚠️ <b>Importante:</b> Una vez que termine la instalación, elimina este archivo (<code>instalar.php</code>) de tu servidor cPanel por seguridad.
            </div>
        </div>
    </div>
</div>

</body>
</html>
