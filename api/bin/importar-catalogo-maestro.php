<?php

declare(strict_types=1);

/**
 * Importa un paquete del catalogo maestro (carpeta o ZIP con productos.csv,
 * productos_codigos_barra.csv y manifest.json). Idempotente: repetir el mismo
 * paquete actualiza sin duplicar.
 *
 * Uso en el hosting:
 *   php bin/importar-catalogo-maestro.php /home/USUARIO/paquete_farma_cl_1.zip FARMA_CL_1
 *
 * Equivalente desplegable de scripts/import_catalogo_maestro.php (que no forma
 * parte del distribuible).
 */

use Mypos\Support\Env;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse por CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);

$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'Mypos\\';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

Env::loadFile(dirname($root) . '/.env');
Env::loadFile($root . '/.env');

$package = $argv[1] ?? '';
$catalog = $argv[2] ?? 'FARMA_CL_1';
if ($package === '') {
    fwrite(STDERR, "Uso: php bin/importar-catalogo-maestro.php <carpeta-o-zip> [codigo_catalogo]\n");
    exit(1);
}

try {
    $result = (new \Mypos\Services\CatalogoMaestroService())->importarPaquete($package, $catalog);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($result['errores'] ?? 0) > 0 ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
