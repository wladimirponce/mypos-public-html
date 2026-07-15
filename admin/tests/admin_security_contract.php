<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use App\Services\AdminSecurity;

$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$condition) {
        $failures[] = $label;
    }
};

$token = str_repeat('a', 64);
$check(AdminSecurity::isValidCsrf($token, $token), 'CSRF valido es aceptado');
$check(!AdminSecurity::isValidCsrf($token, ''), 'CSRF ausente es rechazado');
$check(!AdminSecurity::isValidCsrf($token, str_repeat('b', 64)), 'CSRF incorrecto es rechazado');

$now = 2_000_000_000;
$check(!AdminSecurity::isSessionExpired($now - 100, $now - 100, $now), 'Sesion reciente sigue activa');
$check(AdminSecurity::isSessionExpired($now - 100, $now - 1801, $now), 'Sesion inactiva expira');
$check(AdminSecurity::isSessionExpired($now - 43201, $now - 10, $now), 'Sesion absoluta expira');

$check(AdminSecurity::isModuleAllowed('superadmin', 'config'), 'Superadmin accede a configuracion');
$check(!AdminSecurity::isModuleAllowed('operador', 'config'), 'Operador no accede a configuracion');
$check(AdminSecurity::isModuleAllowed('operador', 'emision'), 'Operador accede a emision');
$check(!AdminSecurity::isModuleAllowed('desconocido', 'emision'), 'Rol desconocido falla cerrado');

try {
    AdminSecurity::validatePassword('frase segura de acceso');
    $check(true, 'Password robusta aceptada');
} catch (Throwable) {
    $check(false, 'Password robusta aceptada');
}

try {
    AdminSecurity::validatePassword('corta');
    $check(false, 'Password corta rechazada');
} catch (InvalidArgumentException) {
    $check(true, 'Password corta rechazada');
}

exit($failures === [] ? 0 : 1);
