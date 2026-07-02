<?php

declare(strict_types=1);

/**
 * Runner del motor de alertas proactivas del agente IA.
 *
 * Uso:
 *   php bin/agente-alertas.php          # todas las empresas activas
 *   php bin/agente-alertas.php 24       # solo la empresa 24 (pruebas)
 *
 * Pensado para cron cada 15 minutos (la programacion interna por chequeo
 * vive en AlertasRunner::CHEQUEOS — una sola linea en cPanel):
 *   *\/15 * * * * php /home/zylajdcb/public_html/api/bin/agente-alertas.php >> /home/zylajdcb/public_html/api/storage/logs/agente-alertas.log 2>&1
 *
 * Requiere la migracion 068_agente_alertas.sql aplicada.
 */

use Mypos\Config\Database;
use Mypos\Services\Agente\AlertasRunner;
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

// Lock anti-solape: si una pasada anterior sigue corriendo, salir en silencio.
$lockFile = $root . '/storage/agente-alertas.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . "] pasada anterior aun en curso, salto.\n";
    exit(0);
}

$onlyEmpresa = isset($argv[1]) ? (int) $argv[1] : null;

try {
    Database::connection();
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] sin conexion BD: ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    $resumen = (new AlertasRunner())->run($onlyEmpresa);

    $linea = sprintf(
        '[%s] chequeos=[%s] empresas=%d avisos=%d%s',
        date('Y-m-d H:i:s'),
        implode(',', $resumen['chequeos']),
        $resumen['empresas'],
        $resumen['avisos'],
        $resumen['errores'] !== [] ? ' ERRORES: ' . implode(' | ', $resumen['errores']) : ''
    );
    echo $linea . "\n";
    exit($resumen['errores'] !== [] ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] error fatal: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
