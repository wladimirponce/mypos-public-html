<?php

declare(strict_types=1);

/**
 * Worker de sincronizacion de correo (INBOX IMAP -> BD).
 *
 * Uso:
 *   php bin/correo-sync.php            # sincroniza todas las empresas con cuenta activa
 *   php bin/correo-sync.php 24         # sincroniza solo la empresa con id 24
 *
 * Pensado para cron, p.ej. cada 5 minutos:
 *   *\/5 * * * * php /home/zylajdcb/public_html/api/bin/correo-sync.php >> /home/zylajdcb/public_html/api/storage/logs/correo-sync.log 2>&1
 */

use Mypos\Config\Database;
use Mypos\Services\CorreoService;
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

$onlyEmpresa = isset($argv[1]) ? (int) $argv[1] : 0;

try {
    $pdo = Database::connection();
} catch (Throwable $exception) {
    fwrite(STDERR, '[correo-sync] No se pudo conectar a la BD: ' . $exception->getMessage() . "\n");
    exit(1);
}

$sql = 'SELECT DISTINCT empresa_id FROM correo_cuentas WHERE activo = 1 AND password_encrypted IS NOT NULL';
if ($onlyEmpresa > 0) {
    $sql .= ' AND empresa_id = ' . $onlyEmpresa;
}
$empresas = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];

if ($empresas === []) {
    echo '[correo-sync] Sin cuentas activas para sincronizar.' . PHP_EOL;
    exit(0);
}

$service = new CorreoService();
$totalNuevos = 0;

foreach ($empresas as $empresaId) {
    $empresaId = (int) $empresaId;
    try {
        $resultado = $service->sincronizar($empresaId);
        $nuevos = (int) ($resultado['sincronizados'] ?? 0);
        $totalNuevos += $nuevos;
        printf('[correo-sync] empresa %d: %d mensajes nuevos (ultimo uid %d)%s', $empresaId, $nuevos, (int) ($resultado['ultimo_uid'] ?? 0), PHP_EOL);

        // Backfill de hilos para mensajes previos sin agrupar (idempotente).
        $hilos = $service->reconstruirHilos($empresaId);
        if ((int) ($hilos['procesados'] ?? 0) > 0) {
            printf('[correo-sync] empresa %d: %d mensajes agrupados en %d hilos%s', $empresaId, (int) $hilos['procesados'], (int) ($hilos['hilos'] ?? 0), PHP_EOL);
        }

        // Agenda: clasificar contactos (proveedor/cliente/banco/otro) (idempotente).
        $contactos = $service->reconstruirContactos($empresaId);
        if ((int) ($contactos['procesados'] ?? 0) > 0) {
            printf('[correo-sync] empresa %d: %d contactos en agenda%s', $empresaId, (int) $contactos['procesados'], PHP_EOL);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf('[correo-sync] empresa %d ERROR: %s%s', $empresaId, $exception->getMessage(), PHP_EOL));
    }
}

printf('[correo-sync] Listo. Total mensajes nuevos: %d%s', $totalNuevos, PHP_EOL);
exit(0);
