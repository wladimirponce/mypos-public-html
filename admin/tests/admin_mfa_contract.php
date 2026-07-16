<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use App\Services\AdminMfa;
use App\Services\AdminMfaCrypto;
use App\Services\AdminTotp;

$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$condition) {
        $failures[] = $label;
    }
};

// —— TOTP (RFC 6238 vectores conocidos, SHA1, 6 dígitos) ——
$seedB32 = AdminTotp::base32Encode('12345678901234567890');
$check($seedB32 === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'Base32 del seed RFC coincide');
$check(AdminTotp::codeAt($seedB32, 59) === '287082', 'TOTP(59) = 287082 (RFC 6238)');
$check(AdminTotp::codeAt($seedB32, 1111111109) === '081804', 'TOTP(1111111109) = 081804 (RFC 6238)');
$check(AdminTotp::verify($seedB32, '287082', 59), 'Verify acepta código válido');
$check(AdminTotp::verify($seedB32, '287082', 89), 'Verify tolera desfase de un paso');
$check(!AdminTotp::verify($seedB32, '287082', 200), 'Verify rechaza fuera de ventana');
$check(!AdminTotp::verify($seedB32, '000000', 59), 'Verify rechaza código incorrecto');
$check(!AdminTotp::verify($seedB32, '12345', 59), 'Verify rechaza longitud inválida');

$fresh = AdminTotp::generateSecret();
$check(strlen($fresh) === 32, 'Secreto generado tiene 32 chars base32');
$check(str_contains(AdminTotp::provisioningUri($fresh, 'admin@mypos.cl', 'MyPOS Admin'), 'otpauth://totp/'), 'URI otpauth bien formada');

// —— Cifrado versionado del secreto (clave independiente) ——
putenv('ADMIN_MFA_KEY=clave-mfa-independiente');
putenv('ADMIN_MFA_KEY_ACTIVE_VERSION');
putenv('ADMIN_MFA_KEY_V2');
$_ENV['ADMIN_MFA_KEY'] = 'clave-mfa-independiente';
unset($_ENV['ADMIN_MFA_KEY_ACTIVE_VERSION'], $_ENV['ADMIN_MFA_KEY_V2']);

$blobLegado = AdminMfaCrypto::encrypt('secreto-totp');
$check($blobLegado[0] !== 'v', 'Formato legado sin prefijo de versión');
$check(AdminMfaCrypto::decrypt($blobLegado) === 'secreto-totp', 'Round-trip legado correcto');

putenv('ADMIN_MFA_KEY_V2=clave-mfa-rotada');
putenv('ADMIN_MFA_KEY_ACTIVE_VERSION=2');
$_ENV['ADMIN_MFA_KEY_V2'] = 'clave-mfa-rotada';
$_ENV['ADMIN_MFA_KEY_ACTIVE_VERSION'] = '2';

$check(AdminMfaCrypto::decrypt($blobLegado) === 'secreto-totp', 'Legado sigue legible tras activar v2');
$blobV2 = AdminMfaCrypto::encrypt('secreto-nuevo');
$check(str_starts_with($blobV2, 'v2:'), 'Nuevo cifrado usa prefijo v2');
$check(AdminMfaCrypto::decrypt($blobV2) === 'secreto-nuevo', 'Round-trip versionado correcto');

// —— Reglas de MFA ——
$check(AdminMfa::roleRequiresMfa('superadmin'), 'superadmin requiere MFA');
$check(!AdminMfa::roleRequiresMfa('operador'), 'operador no requiere MFA por defecto');

putenv('ADMIN_MFA_ENABLED');
$check(!AdminMfa::isGloballyEnabled(), 'Feature flag cerrada por defecto');

// —— Códigos de recuperación: normalización estable, distinción real ——
$h1 = AdminMfa::hashRecovery('ab12c-de34f');
$h2 = AdminMfa::hashRecovery('AB12CDE34F');
$check($h1 === $h2, 'Hash de recuperación ignora guiones y mayúsculas');
$check($h1 !== AdminMfa::hashRecovery('ffffffffff'), 'Códigos distintos producen hash distinto');

exit($failures === [] ? 0 : 1);
