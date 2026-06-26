<?php
/**
 * Instalador / Actualizador de Base de Datos MyPOS
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

/**
 * Registra una migración en schema_migrations (idempotente).
 */
function mypos_register_migration(PDO $pdo, string $migrationName): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO schema_migrations (migration) VALUES (:migration)'
    );
    $stmt->execute(['migration' => $migrationName]);
}

/**
 * Devuelve el conjunto de migraciones ya aplicadas.
 * Si schema_migrations no existe aún, devuelve array vacío.
 *
 * @return array<string, true>
 */
function mypos_applied_migrations(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT migration FROM schema_migrations');
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys($rows, true);
    } catch (PDOException) {
        return [];
    }
}

function mypos_safe_pdo_exec(?PDO $pdo, string $sql): void
{
    if ($pdo === null) {
        return;
    }

    try {
        $pdo->exec($sql);
    } catch (PDOException) {
        // No romper la respuesta del instalador por una limpieza defensiva fallida.
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = $_POST['host'] ?? 'localhost';
    $db   = $_POST['db'] ?? '';
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';
    $soloActualizar = ($_POST['solo_actualizar'] ?? '0') === '1';
    $pdo = null;

    if (empty($db) || empty($user)) {
        $mensaje = "<div class='alert alert-danger'>La Base de Datos y el Usuario son obligatorios.</div>";
    } else {
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);

            $database_dir = mypos_resolve_dir([
                __DIR__ . '/../../database',
                __DIR__ . '/../database',
            ], 'database');
            $sql_dir = mypos_resolve_dir([$database_dir . '/init'], 'database/init');

            $archivos = glob($sql_dir . '/*.sql') ?: [];
            sort($archivos);
            if ($archivos === []) {
                throw new Exception("No se encontraron migraciones SQL en {$sql_dir}.");
            }

            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;");
            $pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci';");
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");

            // Garantizar que schema_migrations exista antes de consultarla.
            // (001_schema.sql la crea, pero puede que aún no se haya corrido en una BD vacía.)
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS schema_migrations (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    migration VARCHAR(190) NOT NULL,
                    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_schema_migrations_migration (migration)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            );

            // En modo actualización cargamos las ya aplicadas para saltarlas.
            $applied = $soloActualizar ? mypos_applied_migrations($pdo) : [];

            $log            = "<ul class='list-group mt-3'>";
            $log           .= "<li class='list-group-item list-group-item-primary'>"
                            . "Carpeta SQL: <code>" . htmlspecialchars($sql_dir) . "</code> - "
                            . "<b>" . count($archivos) . "</b> migraciones detectadas.</li>";
            $aplicadas      = 0;
            $saltadas       = 0;
            $errores        = 0;
            $seedsExitosos  = 0;
            $seedsErrores   = 0;

            foreach ($archivos as $archivo) {
                $migrationName = pathinfo($archivo, PATHINFO_FILENAME);

                // Modo actualización: saltar migraciones ya registradas.
                if ($soloActualizar && isset($applied[$migrationName])) {
                    $log .= "<li class='list-group-item list-group-item-secondary text-muted'>"
                          . "⏭ <b>{$migrationName}</b> — ya aplicada, saltada.</li>";
                    $saltadas++;
                    continue;
                }

                $sql = file_get_contents($archivo);
                if (empty(trim((string) $sql))) {
                    continue;
                }

                try {
                    mypos_execute_sql_file($pdo, $archivo);
                    // Registrar en schema_migrations si el archivo no lo hace él mismo.
                    mypos_register_migration($pdo, $migrationName);
                    $log .= "<li class='list-group-item list-group-item-success'>"
                          . "✔ <b>{$migrationName}</b> — aplicada.</li>";
                    $aplicadas++;
                } catch (PDOException $e) {
                    $log .= "<li class='list-group-item list-group-item-danger'>"
                          . "✖ <b>{$migrationName}</b>: " . htmlspecialchars($e->getMessage()) . "</li>";
                    $errores++;
                }
            }

            // Seeds: solo en instalación fresca.
            if (!$soloActualizar) {
                $seed_dir = realpath($database_dir . '/seeds');
                if ($seed_dir && is_dir($seed_dir)) {
                    $seedBase = mypos_resolve_file([$seed_dir . '/001_seed_base.sql']);
                    foreach ($seedBase !== null ? [$seedBase] : [] as $seed) {
                        $sql = file_get_contents($seed);
                        if (!empty(trim((string) $sql))) {
                            try {
                                mypos_execute_sql_file($pdo, $seed);
                                $log .= "<li class='list-group-item list-group-item-info'>"
                                      . "🌱 Seed: " . basename($seed) . " instalado.</li>";
                                $seedsExitosos++;
                            } catch (PDOException $e) {
                                $log .= "<li class='list-group-item list-group-item-danger'>"
                                      . "✖ Seed " . basename($seed) . ": " . htmlspecialchars($e->getMessage()) . "</li>";
                                $seedsErrores++;
                            }
                        }
                    }
                    $log .= "<li class='list-group-item list-group-item-primary'>"
                          . "ℹ️ Seeds demo y de empresas omitidos por seguridad.</li>";
                }
            } else {
                $log .= "<li class='list-group-item list-group-item-primary'>"
                      . "ℹ️ Modo actualización: seeds omitidos · "
                      . "<b>{$aplicadas}</b> nuevas · <b>{$saltadas}</b> ya aplicadas · <b>{$errores}</b> errores.</li>";
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
            $log .= "</ul>";

            $totalErrores = $errores + $seedsErrores;
            if ($totalErrores === 0) {
                $resumen = $soloActualizar
                    ? "Actualización finalizada: <b>{$aplicadas}</b> migraciones nuevas aplicadas, "
                      . "<b>{$saltadas}</b> ya estaban al día."
                    : "Instalación finalizada: <b>{$aplicadas}</b> archivos base y <b>{$seedsExitosos}</b> seeds.";
                $mensaje = "<div class='alert alert-success'><h5>✅ {$resumen}</h5>{$log}</div>";
            } else {
                $resumen = $soloActualizar
                    ? "Actualización incompleta: <b>{$aplicadas}</b> aplicadas, <b>{$saltadas}</b> saltadas, "
                      . "<b>{$errores}</b> errores."
                    : "Instalación incompleta: <b>{$aplicadas}</b> archivos base, <b>{$seedsExitosos}</b> seeds, "
                      . "<b>{$totalErrores}</b> errores. Corrige los errores antes de usar el sistema.";
                $mensaje = "<div class='alert alert-danger'><h5>⚠️ {$resumen}</h5>{$log}</div>";
            }

            $mensaje .= "<div class='alert alert-info mt-2'>"
                      . "Configure las credenciales de BD en el archivo <b>.env</b> del servidor.</div>";

        } catch (PDOException $e) {
            mypos_safe_pdo_exec($pdo, "SET FOREIGN_KEY_CHECKS=1;");
            $mensaje = "<div class='alert alert-danger'><b>Error de Conexión:</b> "
                     . htmlspecialchars($e->getMessage()) . "</div>";
        } catch (Exception $e) {
            mypos_safe_pdo_exec($pdo, "SET FOREIGN_KEY_CHECKS=1;");
            $mensaje = "<div class='alert alert-danger'><b>Error:</b> "
                     . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador de Base de Datos — MyPOS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { width: 100%; max-width: 540px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: none; border-radius: 12px; }
        .card-header { background-color: #0d6efd; color: white; border-radius: 12px 12px 0 0 !important; text-align: center; padding: 20px; }
        .list-group-item-secondary { font-size: .85rem; }
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
                <input type="hidden" name="token" value="<?= htmlspecialchars($providedToken, ENT_QUOTES, 'UTF-8') ?>">
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

                <div class="mb-4 p-3 border rounded">
                    <div class="form-check">
                        <input type="checkbox" name="solo_actualizar" class="form-check-input"
                               id="soloActualizar" value="1">
                        <label class="form-check-label fw-bold text-primary" for="soloActualizar">
                            Modo actualización (recomendado para sistemas en uso)
                        </label>
                    </div>
                    <div class="form-text ms-4 mt-1">
                        <b>Marcado:</b> consulta <code>schema_migrations</code> y aplica solo las migraciones
                        nuevas que no han sido ejecutadas aún. Los seeds se omiten.<br>
                        <b>Desmarcado:</b> instalación fresca — ejecuta todos los archivos SQL y el seed
                        base maestro. Úsalo solo en una base de datos vacía.
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
                    Ejecutar Instalación / Actualización
                </button>
            </form>

            <div class="mt-4 text-center text-muted small">
                ⚠️ <b>Importante:</b> Una vez finalizada la instalación, elimina
                <code>instalar.php</code> del servidor y vacía <code>INSTALLER_TOKEN</code> en el
                <code>.env</code>.
            </div>
        </div>
    </div>
</div>
</body>
</html>
