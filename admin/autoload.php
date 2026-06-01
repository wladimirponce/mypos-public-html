<?php
declare(strict_types=1);

/**
 * Carga variables del archivo .env en el directorio raíz del proyecto.
 * Solo aplica claves que aún no estén definidas en el entorno del sistema.
 */
(function () {
    $envFile = __DIR__ . '/.env';
    if (!is_file($envFile)) return;
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;           // comentario
        if (!str_contains($line, '=')) continue;                  // sin signo =
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\n\r\0\x0B\"'");                    // quitar comillas
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
})();

/**
 * Autoloader simple para el namespace App.
 */
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
