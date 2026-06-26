<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use App\Core\Database;

$base = dirname(__DIR__);
$pdo = Database::getInstance();
$empresas = $pdo->query(
    "SELECT rut, UPPER(ambiente_default) AS ambiente
       FROM sii_empresa
      WHERE activo = 1"
)->fetchAll(PDO::FETCH_ASSOC);

$roots = ['cert', 'caf', 'tmp', 'cert_sets', 'archive', 'backups'];
foreach ($empresas as $empresa) {
    $rut = (string)$empresa['rut'];
    $ambiente = (string)$empresa['ambiente'];
    if (!in_array($ambiente, ['CERTIFICACION', 'PRODUCCION'], true)) {
        continue;
    }

    foreach ($roots as $root) {
        $source = $base . DIRECTORY_SEPARATOR . $root . DIRECTORY_SEPARATOR . $rut;
        $target = $source . DIRECTORY_SEPARATOR . $ambiente;
        if (!is_dir($source)) {
            continue;
        }
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        foreach (new DirectoryIterator($source) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $dest = $target . DIRECTORY_SEPARATOR . $file->getFilename();
            if (!is_file($dest)) {
                copy($file->getPathname(), $dest);
            }
        }
    }
}

echo "Migracion de archivos por ambiente completada.\n";
