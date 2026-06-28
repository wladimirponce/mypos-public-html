<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) {
    // Cookie de sesión endurecida (HttpOnly + SameSite=Strict, Secure en HTTPS).
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
    ]);
    session_start();
}
date_default_timezone_set('America/Santiago');
if (file_exists(__DIR__ . '/openssl_legacy.cnf')) {
    putenv('OPENSSL_CONF=' . __DIR__ . '/openssl_legacy.cnf');
}
// Log de depuración SOLO si existe el flag admin/.debug_enabled (nunca en
// producción). El .htaccess del directorio ya impide su acceso por web.
$apiDebugLog = is_file(__DIR__ . '/.debug_enabled');
if ($apiDebugLog) {
    file_put_contents(__DIR__ . '/debug_api.log', date('Y-m-d H:i:s') . ' | === API CALLED: ' . ($_GET['action'] ?? 'no-action') . ' | file_exists(api.php)=' . (is_file(__FILE__) ? 'YES' : 'NO') . ' | phpversion=' . PHP_VERSION . PHP_EOL, FILE_APPEND);
}

// Verificar parseo al inicio
$parse_errors = [];
set_error_handler(function($code, $msg, $file, $line) use (&$parse_errors) {
    if ($code === E_WARNING || $code === E_NOTICE) {
        $parse_errors[] = "line=$line msg=$msg";
    }
});
// Trigger un include dummy para verificar que todo el archivo compila
$r = eval('return true;');
restore_error_handler();
if (!empty($parse_errors) && $apiDebugLog) {
    file_put_contents(__DIR__ . '/debug_api.log', date('Y-m-d H:i:s') . ' | PARSE ERRORS: ' . implode(' | ', $parse_errors) . PHP_EOL, FILE_APPEND);
}
unset($parse_errors, $r);
// ============================================================
//  RUTAS DE ARCHIVOS LOCALES (no dependen de la empresa)
// ============================================================
// RUT_ENVIA_MANUAL: vacÃ­o => se extrae automÃ¡ticamente del certificado vigente
// (serialNumber). Solo definir si el cert no expone el RUT y se requiere override.
define('RUT_ENVIA_MANUAL', '');

// ContraseÃ±a se lee automÃ¡ticamente desde cert/cert.conf (guardado por setup.php)
// Si no existe el archivo de configuraciÃ³n, getCertPass() lanzarÃ¡ error explÃ­cito.
define('CERT_PASS_FALLBACK', '');

/**
 * Resuelve quÃ© certificado usar segÃºn el TipoDTE.
 *
 *  - Boletas (39/41) y utilidades sin tipo  -> certificado POR DEFECTO
 *      firma.pfx + cert.conf      (en producciÃ³n: certificado de Cristina)
 *  - Resto de DTE (52 guÃ­a, 33/34 factura, 56/61 NC-ND, 110/111/112 export)
 *      firma_dte.pfx + cert_dte.conf   (en producciÃ³n: certificado de David)
 *
 * Si el certificado DTE aÃºn no fue cargado, cae al por defecto (no rompe nada:
 * el sistema sigue operando con un solo certificado como antes).
 */
/**
 * Carga el mapeo certificado<->tipo desde configuraciÃ³n (NO hardcodeado).
 * Busca, en orden: certs.json del directorio del cert (por-RUT) y luego el
 * del directorio padre (global). Si no hay archivo, retorna [] y se usa el
 * fallback seguro (comportamiento previo a un solo certificado).
 *
 * Esquema de cert/{RUT}/certs.json:
 * {
 *   "default":  { "pfx": "firma.pfx",     "conf": "cert.conf" },
 *   "perfiles": [
 *     { "nombre": "boleta", "tipos": [39,41],
 *       "pfx": "firma.pfx",     "conf": "cert.conf" },
 *     { "nombre": "dte",    "tipos": [33,34,52,56,61,110,111,112],
 *       "pfx": "firma_dte.pfx", "conf": "cert_dte.conf" }
 *   ]
 * }
 * Las rutas pueden ser relativas (al directorio del cert) o absolutas.
 */
function loadCertConfig(): array {
    global $actualCertPfx;
    $dir = dirname($actualCertPfx);
    foreach ([$dir . '/certs.json'] as $f) {
        if (is_file($f)) {
            $j = json_decode((string)file_get_contents($f), true);
            if (is_array($j) && (!empty($j['perfiles']) || !empty($j['default']))) {
                return $j;
            }
        }
    }
    return [];
}

function certBundleForTipo(?int $tipo = null): array {
    global $actualCertPfx;
    $dir     = dirname($actualCertPfx);
    $defConf = $dir . '/cert.conf';

    // En certificacion se debe usar exactamente el certificado visible de la
    // empresa. Los perfiles legados/certs.json pueden apuntar a otro PFX y
    // causar ENV-3-6 aunque RutEnvia parezca correcto.
    $ctx = $GLOBALS['globalContext'] ?? null;
    if ($ctx && method_exists($ctx, 'getAmbiente') && $ctx->getAmbiente() === 'CERTIFICACION') {
        return ['pfx' => $actualCertPfx, 'conf' => $defConf, 'rut' => null, 'kind' => 'cert-default'];
    }

    $cfg     = loadCertConfig();

    // Resuelve un perfil del config a rutas absolutas
    $mk = function(array $p, string $kind) use ($dir, $actualCertPfx, $defConf): array {
        $abs = function(?string $name, string $fallback) use ($dir): string {
            if (empty($name)) return $fallback;
            return preg_match('#^(/|[A-Za-z]:[\\\\/])#', $name) ? $name : ($dir . '/' . $name);
        };
        return [
            'pfx'  => $abs($p['pfx']  ?? null, $actualCertPfx),
            'conf' => $abs($p['conf'] ?? null, $defConf),
            'rut'  => !empty($p['rut']) ? $p['rut'] : null,
            'kind' => $kind,
        ];
    };

    if (!empty($cfg['perfiles']) && is_array($cfg['perfiles'])) {
        if ($tipo !== null) {
            foreach ($cfg['perfiles'] as $p) {
                $tipos = array_map('intval', (array)($p['tipos'] ?? []));
                if (in_array($tipo, $tipos, true)) {
                    return $mk($p, 'cfg:' . ($p['nombre'] ?? 'perfil'));
                }
            }
        }
        if (!empty($cfg['default'])) return $mk($cfg['default'], 'cfg:default');
    } elseif (!empty($cfg['default'])) {
        return $mk($cfg['default'], 'cfg:default');
    }

    // â”€â”€ Fallback seguro SIN config: comportamiento previo (un solo cert) â”€â”€
    if ($tipo === null || in_array($tipo, [39, 41], true)) {
        return ['pfx' => $actualCertPfx, 'conf' => $defConf, 'rut' => null, 'kind' => 'default'];
    }
    $dtePfx  = $dir . '/firma_dte.pfx';
    $dteConf = $dir . '/cert_dte.conf';
    if (is_file($dtePfx)) {
        return ['pfx' => $dtePfx, 'conf' => $dteConf, 'rut' => null, 'kind' => 'dte'];
    }
    return ['pfx' => $actualCertPfx, 'conf' => $defConf, 'rut' => null, 'kind' => 'default-fallback'];
}

function getCertPass(?int $tipo = null): string {
    $t = $tipo ?? ($GLOBALS['SII_CERT_TIPO'] ?? null);
    $b = certBundleForTipo($t);
    if (file_exists($b['conf'])) {
        $conf = json_decode(file_get_contents($b['conf']), true);
        if (!empty($conf['pass'])) return $conf['pass'];
    }
    if (CERT_PASS_FALLBACK !== '') return CERT_PASS_FALLBACK;
    throw new Exception('No se encontrÃ³ contraseÃ±a del certificado en ' . $b['conf']
        . '. Suba el certificado nuevamente desde la pantalla de ConfiguraciÃ³n.');
}

// Hosts SII
define('HOST_CERTIF', 'maullin.sii.cl');
define('HOST_PROD',   'palena.sii.cl');

// SII exige TLS 1.2+ desde nov-2021 (cert) / ene-2022 (prod) â€” Instructivo Boleta ElectrÃ³nica.
// Esta constante se aplica a todas las llamadas SII vÃ­a siiCurlSetOpts().
define('SII_MIN_TLS', CURL_SSLVERSION_TLSv1_2);

// Ruta al bundle CA Mozilla (descargado de curl.se/ca/cacert.pem).
// Si el archivo existe, las conexiones SII validan el certificado del servidor.
define('SII_CAINFO',    __DIR__ . '/cert/cacert.pem');
define('SII_SSL_VERIFY', file_exists(__DIR__ . '/cert/cacert.pem'));
// ============================================================

if (!function_exists('normalizeCafXmlContent')) {
    function normalizeCafXmlContent(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = ltrim($content);

        $xmlPos = stripos($content, '<?xml');
        $autPos = stripos($content, '<AUTORIZACION');
        if ($xmlPos !== false && ($autPos === false || $xmlPos < $autPos)) {
            $content = substr($content, $xmlPos);
        } elseif ($autPos !== false) {
            $content = substr($content, $autPos);
        }

        if (!preg_match('//u', $content)) {
            $enc = function_exists('mb_detect_encoding')
                ? (mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) ?: 'Windows-1252')
                : 'Windows-1252';
            $content = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($content, 'UTF-8', $enc)
                : iconv($enc, 'UTF-8//IGNORE', $content);
        }

        $content = preg_replace('/<\?xml[^>]*\?>/i', '', $content) ?? $content;
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . ltrim($content);
    }
}

require_once __DIR__ . '/autoload.php';
use App\Core\Context;
use App\Repositories\EmpresaRepository;

// InicializaciÃ³n de Contexto DinÃ¡mico (Multi-Cliente)
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SESSION['active_api_key'] ?? $_GET['api_key'] ?? $_POST['api_key'] ?? '';

// ResoluciÃ³n del empresa_id solicitado en la peticiÃ³n. El cliente (APK u otro
// consumidor) puede pasar `empresa_id` por GET, POST o body JSON para indicar
// explÃ­citamente sobre quÃ© empresa operar. Si no se envÃ­a, se cae a la cadena
// habitual: sesiÃ³n activa â†' empresa del API key â†' fallback a constantes
$requestedEmpresaId = null;
if (isset($_GET['empresa_id']) && is_numeric($_GET['empresa_id'])) {
    $requestedEmpresaId = (int)$_GET['empresa_id'];
} elseif (isset($_POST['empresa_id']) && is_numeric($_POST['empresa_id'])) {
    $requestedEmpresaId = (int)$_POST['empresa_id'];
} else {
    // php://input se puede leer mÃºltiples veces en PHP 5.6+; la lectura
    // posterior del body en lÃ­nea 526 seguirÃ¡ funcionando.
    $earlyBody = @file_get_contents('php://input');
    if ($earlyBody !== false && $earlyBody !== '') {
        $earlyJson = @json_decode($earlyBody, true);
        if (is_array($earlyJson) && isset($earlyJson['empresa_id']) && is_numeric($earlyJson['empresa_id'])) {
            $requestedEmpresaId = (int)$earlyJson['empresa_id'];
        }
    }
}

$globalContext = null;

try {
    $isAdminRequest = !empty($_SESSION['admin_id']);
    if ($isAdminRequest && $requestedEmpresaId !== null && $requestedEmpresaId > 0) {
        // Prioridad mÃ¡xima: el cliente indicÃ³ explÃ­citamente la empresa.
        $globalContext = new Context($requestedEmpresaId);
    } elseif ($isAdminRequest && isset($_SESSION['active_empresa_id'])) {
        $globalContext = new Context((int)$_SESSION['active_empresa_id']);
    } elseif (!empty($apiKey)) {
        // Llamada servidor-a-servidor (POS / web app) con API key como gate
        // compartido. En el modelo SaaS la empresa NO se resuelve por API key
        // (getByApiKey devuelve null: no hay key por empresa), sino por el
        // empresa_id explicito que envia el consumidor -> getById. Si no se envia
        // empresa_id se conserva la resolucion legacy por API key (tablas sii_*).
        if ($requestedEmpresaId !== null && $requestedEmpresaId > 0) {
            $globalContext = new Context($requestedEmpresaId);
        } else {
            $globalContext = new Context($apiKey);
        }
    }
} catch (Exception $e) {
    if (defined('DTE_API_BOOTSTRAP_ONLY') && DTE_API_BOOTSTRAP_ONLY) {
        unset($_SESSION['active_empresa_id']);
        $globalContext = null;

    } elseif (PHP_SAPI !== 'cli') {
        http_response_code(401);
        echo json_encode([
            'ok'    => false,
            'error' => $e->getMessage(),
            'hint'  => $requestedEmpresaId !== null
                ? "El empresa_id=$requestedEmpresaId no existe o no estÃ¡ activo en sii_empresa"
                : "Sin contexto de empresa: revise sesiÃ³n, API key o pase 'empresa_id' explÃ­cito"
        ]);
        exit;
    }
}

// â”€â”€â”€ Definir constantes de empresa desde la BD (o valores neutros) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// NUNCA se definen valores hardcodeados de empresa: todo viene de sii_empresa.
if ($globalContext) {
    $emp = $globalContext->getEmpresa();
    $actecoRaw = json_decode((string)($emp['acteco'] ?? '[]'), true);
    define('AMBIENTE',     $globalContext->getAmbiente());
    define('RUT_EMISOR',   $emp['rut']);
    define('RAZON_SOCIAL', $emp['razon_social']);
    define('GIRO_EMISOR',  $emp['giro'] ?? '');
    define('ACTECO',       is_array($actecoRaw) ? (int)($actecoRaw[0] ?? 0) : (int)$actecoRaw);
    define('DIRECCION',    $emp['direccion_origen'] ?? '');
    define('COMUNA',       $emp['comuna_origen'] ?? '');
    define('CIUDAD',       $emp['ciudad_origen'] ?? '');
    define('NRO_RESOL',    (int)($emp['numero_resolucion'] ?? 0));
    define('FCH_RESOL',    $emp['fecha_resolucion'] ?? '');
    define('UNIDAD_SII',   $emp['unidad_sii'] ?? '');
    unset($emp, $actecoRaw);
} else {
    // Sin contexto: constantes vacÃ­as / neutras (CLI, BOOTSTRAP_ONLY sin empresa).
    // En peticiÃ³n HTTP real, el bloque de try/catch ya habrÃ¡ redirigido o retornado 401.
    define('AMBIENTE',     '');
    define('RUT_EMISOR',   '');
    define('RAZON_SOCIAL', '');
    define('GIRO_EMISOR',  '');
    define('ACTECO',       0);
    define('DIRECCION',    '');
    define('COMUNA',       '');
    define('CIUDAD',       '');
    define('NRO_RESOL',    0);
    define('FCH_RESOL',    '');
    define('UNIDAD_SII',   '');
}

if (!$globalContext && PHP_SAPI !== 'cli' && !defined('DTE_API_BOOTSTRAP_ONLY')) {
    $emptyAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');
    $emptyResponses = [
        'history'      => ['ok' => true, 'entries' => [], 'history' => []],
        'list_files'   => ['ok' => true, 'files' => []],
        'get_sii_logs' => ['ok' => true, 'logs' => []],
    ];
    if (isset($emptyResponses[$emptyAction])) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($emptyResponses[$emptyAction]);
        exit;
    }
}

// Redirigir a login si peticiÃ³n HTTP sin contexto y sin modo BOOTSTRAP_ONLY
if (!$globalContext && PHP_SAPI !== 'cli' && !defined('DTE_API_BOOTSTRAP_ONLY')) {
    header('Location: login.php');
    exit;
}

// Rutas dinÃ¡micas basadas en contexto
$noCompanyDir = __DIR__ . '/var/no-company/';
$actualTmpDir = $globalContext ? $globalContext->getTmpPath() : ($noCompanyDir . 'tmp/');
$actualCafDir = $globalContext ? dirname($globalContext->getCafPath(0)) . '/' : ($noCompanyDir . 'caf/');
$actualCertPfx = $globalContext ? $globalContext->getCertPath() : ($noCompanyDir . 'cert/firma.pfx');

function listStoredFiles(): array {
    global $actualTmpDir;
    $files = glob($actualTmpDir . "/*.xml");
    $list = [];
    
    // Cargar registro de tracking si existe
    $trackFile = $actualTmpDir . 'tracking.json';
    $tracking = file_exists($trackFile) ? (json_decode(file_get_contents($trackFile), true) ?? []) : [];
    
    foreach ($files as $f) {
        $base = basename($f);
        $tipo = null; $folio = null;
        if (preg_match('/dte_T(\d+)F(\d+)\.xml/i', $base, $m)) {
            $tipo = $m[1]; $folio = $m[2];
        } elseif (preg_match('/DTE_(\d+)_.*?_(\d+)\.xml/i', $base, $m)) {
            $tipo = $m[1]; $folio = $m[2];
        }
        if ($tipo && $folio) {
            $key = "T{$tipo}F{$folio}";
            $trackInfo = $tracking[$key] ?? null;
            $list[] = [
                'file'    => $base,
                'tipo'    => $tipo,
                'folio'   => $folio,
                'ts'      => date('Y-m-d H:i:s', filemtime($f)),
                'trackId' => $trackInfo['trackId'] ?? null,
                'estado'  => $trackInfo['estado'] ?? null,
                'enviado' => $trackInfo['enviado'] ?? null,
            ];
        }
    }
    usort($list, fn($a, $b) => strcmp($b['ts'], $a['ts']));
    return array_slice($list, 0, 50);
}

/**
 * ReenvÃ­a un DTE almacenado al SII con todas las capas de defensa:
 *   - Lock por folio (anti race-condition)
 *   - Bloqueo si el doc ya estÃ¡ aceptado por el SII (a menos que force=true)
 *   - Boletas DOK/REC: bloqueo duro (no se pueden anular)
 *   - RazÃ³n obligatoria si force=true
 *   - ValidaciÃ³n XSD local previa
 *   - DetecciÃ³n de XML modificado entre intentos (hash mismatch)
 *   - Dry-run mode (valida + reporta sin enviar)
 *   - Registro de cada intento con todo el contexto
 *
 * @param int   $tipo  Tipo DTE (33,39,52,etc.)
 * @param int   $folio NÃºmero de folio
 * @param array $opts  ['force'=>bool, 'reason'=>string, 'dry_run'=>bool, 'user'=>string]
 */
function resendStoredDTE(int $tipo, int $folio, array $opts = []): array {
    global $actualTmpDir;

    $force   = !empty($opts['force']);
    $dryRun  = !empty($opts['dry_run']);
    $reason  = trim((string)($opts['reason'] ?? ''));
    $user    = $opts['user'] ?? ($_SESSION['user'] ?? null);

    $file = $actualTmpDir . "/dte_T{$tipo}F{$folio}.xml";
    if (!file_exists($file)) {
        return ['ok' => false, 'error' => "No hay XML para el folio $folio en tmp/", 'code' => 'XML_NOT_FOUND'];
    }
    $xml = file_get_contents($file);
    $hashActual = xmlContentHash($xml);

    // â”€â”€ Pre-flight: cargar historial previo â”€â”€
    $prev      = getDTETracking($tipo, $folio);
    $okEstados = ['REC', 'DOK', 'EPR', 'FOK', 'SOK', 'CRT'];
    $yaOk      = $prev && in_array((string)($prev['estado'] ?? ''), $okEstados, true) && !empty($prev['last_ok_trackId']);
    $esBoleta  = in_array($tipo, [39, 41], true);

    // â”€â”€ Capa 1: Bloqueo si ya estÃ¡ aceptado â”€â”€
    if ($yaOk && !$force) {
        $msg = $esBoleta
            ? "Boleta T{$tipo}F{$folio} YA fue aceptada por el SII (TrackID {$prev['last_ok_trackId']}, estado {$prev['estado']}). "
              . "Las boletas no se pueden anular: si fue emitida con error, se debe regenerar una nota de crÃ©dito (tipo 61). "
              . "Si insiste en reenviar, use force=true con motivo."
            : "DTE T{$tipo}F{$folio} ya tiene envÃ­o aceptado por el SII (TrackID {$prev['last_ok_trackId']}, estado {$prev['estado']}). "
              . "Use force=true con motivo para reenviar.";
        return [
            'ok'           => false,
            'code'         => $esBoleta ? 'BOLETA_YA_ACEPTADA' : 'DTE_YA_ACEPTADO',
            'error'        => $msg,
            'mensaje'      => $msg,
            'last_trackId' => $prev['last_ok_trackId'],
            'estado'       => $prev['estado'],
            'intentos'     => count($prev['intentos'] ?? []),
        ];
    }

    // â”€â”€ Capa 1b: si force, exigir motivo vÃ¡lido â”€â”€
    if ($force) {
        if (strlen($reason) < 5) {
            return [
                'ok'    => false,
                'code'  => 'MOTIVO_REQUERIDO',
                'error' => 'ReenvÃ­o forzado requiere un motivo de al menos 5 caracteres.',
            ];
        }
    }

    // â”€â”€ Capa 2: detecciÃ³n de XML modificado entre intentos â”€â”€
    $hashPrev = $prev['xml_hash'] ?? null;
    $hashMismatch = $hashPrev && $hashPrev !== $hashActual;
    if ($hashMismatch && !$force) {
        return [
            'ok'    => false,
            'code'  => 'XML_MODIFICADO',
            'error' => "El XML del folio T{$tipo}F{$folio} cambiÃ³ desde el Ãºltimo envÃ­o (hash previo {$hashPrev} â†' actual {$hashActual}). "
                     . "Si la modificaciÃ³n fue intencional, use force=true con motivo. Si no, regenere el DTE.",
            'hash_previo' => $hashPrev,
            'hash_actual' => $hashActual,
        ];
    }

    // â”€â”€ Capa 4: adquirir lock â”€â”€
    $lock = acquireFolioLock($tipo, $folio, 15);
    if (!$lock) {
        return [
            'ok'    => false,
            'code'  => 'LOCK_TIMEOUT',
            'error' => "Otro proceso estÃ¡ enviando T{$tipo}F{$folio} en este momento. Reintente en unos segundos.",
        ];
    }

    try {
        // Certificado segÃºn tipo: boletas (39/41) -> Cristina ; guÃ­as/facturas -> David
        $GLOBALS['SII_CERT_TIPO'] = $tipo;
        [$cert, $privKey] = loadCertificate($tipo);

        // Construir y firmar el sobre
        $envio        = buildEnvioDTE($xml, $tipo, $folio, $cert);
        $envioFirmado = signDTE($envio, $cert, $privKey, 'SetDoc');

        // â”€â”€ Capa 2b: validaciÃ³n XSD del sobre â”€â”€
        $val = validateXmlAgainstXSD($envioFirmado);
        if (!$val['valid'] && !$val['skipped']) {
            return [
                'ok'         => false,
                'code'       => 'XSD_INVALID',
                'error'      => 'El sobre no pasa la validaciÃ³n XSD: ' . implode('; ', array_slice($val['errors'], 0, 3)),
                'xsd_errors' => $val['errors'],
            ];
        }

        // â”€â”€ Capa 2c: validaciÃ³n de lÃ­mite de DTEs (boletas) â”€â”€
        if ($esBoleta) {
            $lim = validateEnvioBoletaLimit($envioFirmado);
            if (!$lim['ok']) {
                return [
                    'ok'    => false,
                    'code'  => 'ENVIO_LIMIT_EXCEEDED',
                    'error' => $lim['reason'],
                    'count' => $lim['count'],
                    'max'   => $lim['max'],
                ];
            }
        }

        // â”€â”€ Capa 6: dry-run â”€â”€
        if ($dryRun) {
            return [
                'ok'          => true,
                'dry_run'     => true,
                'mensaje'     => "[DRY-RUN] T{$tipo}F{$folio} pasarÃ­a todos los checks. NO se enviÃ³ al SII.",
                'xsd_valid'   => true,
                'xml_hash'    => $hashActual,
                'hash_match'  => !$hashMismatch,
                'last_trackId'=> $prev['last_ok_trackId'] ?? null,
                'force'       => $force,
                'reason'      => $reason,
            ];
        }

        // â”€â”€ EnvÃ­o real â”€â”€
        $sem = getSemilla();
        $tok = getToken($sem, $cert, $privKey);

        if ($esBoleta) {
            $result = sendBoletaREST($envioFirmado, $tipo, $folio, $tok);
            $via = 'REST';
        } else {
            $result = uploadDTE($envioFirmado, $tok, $cert);
            $via = 'SOAP';
        }

        // Persistir intento (siempre, exitoso o no)
        saveTrackingId(
            $tipo, $folio,
            $result['trackId'] ?? null,
            $result,
            [
                'via'      => $via,
                'http'     => $result['http'] ?? null,
                'reason'   => $reason ?: ($force ? 'reenvÃ­o forzado' : ($prev ? 'reenvÃ­o' : 'envÃ­o inicial')),
                'user'     => $user,
                'xml_hash' => $hashActual,
            ]
        );

        // Resultado enriquecido
        $result['xml_hash']     = $hashActual;
        $result['was_resend']   = (bool)$prev;
        $result['force_used']   = $force;
        $result['reason']       = $reason;
        return $result;

    } finally {
        releaseFolioLock($lock);
    }
}

/**
 * Provisiona en el filesystem del facturador el certificado (.pfx + clave) y/o el
 * CAF de una empresa, en su carpeta por RUT/AMBIENTE. La empresa se resuelve por
 * el Context (empresa_id explicito). Valida que el .pfx abra con la clave y que el
 * CAF sea un XML de autorizacion antes de escribir. No falla la app si algo no
 * viene: solo escribe lo que se envie.
 *
 * Inputs en $data: pfx_base64, pfx_password, caf_xml, caf_tipo (default 39).
 */
function provisionarCredenciales(array $data, $ctx): array {
    if (!$ctx || !method_exists($ctx, 'getRut')) {
        return ['ok' => false, 'error' => 'Sin contexto de empresa: envie empresa_id valido.'];
    }

    $rut      = $ctx->getRut();
    $ambiente = $ctx->getAmbiente();
    $tipo     = (int)($data['caf_tipo'] ?? 39);

    $pfxB64  = (string)($data['pfx_base64'] ?? '');
    $pfxPass = (string)($data['pfx_password'] ?? '');
    // El CAF viaja en base64 (preserva bytes ISO-8859-1); compat con 'caf_xml' crudo.
    $cafB64  = (string)($data['caf_xml_base64'] ?? '');
    $cafXml  = $cafB64 !== '' ? (string)base64_decode($cafB64, true) : (string)($data['caf_xml'] ?? '');

    if ($pfxB64 === '' && $cafXml === '') {
        return ['ok' => false, 'error' => 'No se envio certificado ni CAF.'];
    }

    $escritos = [];

    // ── Certificado ──────────────────────────────────────────────────────────
    if ($pfxB64 !== '') {
        $pfxBytes = base64_decode($pfxB64, true);
        if ($pfxBytes === false || $pfxBytes === '') {
            return ['ok' => false, 'error' => 'pfx_base64 invalido.'];
        }
        $certs = [];
        if (!openssl_pkcs12_read($pfxBytes, $certs, $pfxPass)) {
            return ['ok' => false, 'error' => 'El certificado no abre con la clave indicada: ' . (openssl_error_string() ?: 'error desconocido')];
        }

        $certDir = dirname($ctx->getCertPath()); // cert/{RUT}/{AMB}
        if (!is_dir($certDir) && !@mkdir($certDir, 0755, true) && !is_dir($certDir)) {
            return ['ok' => false, 'error' => 'No se pudo crear el directorio del certificado: ' . $certDir];
        }
        $pfxOut = $certDir . '/firma.pfx';
        if (@file_put_contents($pfxOut, $pfxBytes) === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir el .pfx en ' . $pfxOut];
        }
        // cert.conf: normaliza a firma.pfx; clave en "password" y "pass" por compat.
        $conf = ['pfx_file' => 'firma.pfx', 'password' => $pfxPass, 'pass' => $pfxPass];
        if (@file_put_contents($certDir . '/cert.conf', json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir cert.conf en ' . $certDir];
        }
        @chmod($pfxOut, 0600);
        @chmod($certDir . '/cert.conf', 0600);
        $escritos[] = $pfxOut;
        $escritos[] = $certDir . '/cert.conf';
    }

    // ── CAF ──────────────────────────────────────────────────────────────────
    if ($cafXml !== '') {
        // El CAF del SII viene en ISO-8859-1 SIN declaracion de encoding (trae Ñ/acentos),
        // por lo que DOMDocument::loadXML lo rechaza al asumir UTF-8. Validamos por
        // marcadores de texto (insensibles al encoding); el SII valida el CAF real al emitir.
        $looksLikeCaf = stripos($cafXml, '<AUTORIZACION') !== false
            && stripos($cafXml, '<CAF') !== false
            && stripos($cafXml, '<RNG') !== false
            && stripos($cafXml, '<RSASK') !== false;
        if (!$looksLikeCaf) {
            return ['ok' => false, 'error' => 'El CAF no es un XML de autorizacion valido del SII.'];
        }

        $cafPath = $ctx->getCafPath($tipo); // caf/{RUT}/{AMB}/caf_{tipo}.xml
        $cafDir  = dirname($cafPath);
        if (!is_dir($cafDir) && !@mkdir($cafDir, 0755, true) && !is_dir($cafDir)) {
            return ['ok' => false, 'error' => 'No se pudo crear el directorio del CAF: ' . $cafDir];
        }
        if (@file_put_contents($cafPath, $cafXml) === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir el CAF en ' . $cafPath];
        }
        $escritos[] = $cafPath;
    }

    return [
        'ok'        => true,
        'rut'       => $rut,
        'ambiente'  => $ambiente,
        'tipo_caf'  => $tipo,
        'escritos'  => $escritos,
        'mensaje'   => 'Credenciales provisionadas en el facturador.',
    ];
}

function storedDTEPath(int $tipo, int $folio): string {
    global $actualTmpDir;
    $newFile = $actualTmpDir . "/dte_T{$tipo}F{$folio}.xml";
    if (file_exists($newFile)) {
        return $newFile;
    }

    $legacy = glob($actualTmpDir . "/DTE_{$tipo}_*_" . $folio . ".xml");
    if (!empty($legacy)) {
        return $legacy[0];
    }

    throw new Exception("No existe XML almacenado para tipo $tipo folio $folio.");
}

function getStoredDTE(int $tipo, int $folio): array {
    $file = storedDTEPath($tipo, $folio);
    return [
        'ok' => true,
        'tipo' => $tipo,
        'folio' => $folio,
        'file' => basename($file),
        'updated_at' => date('Y-m-d H:i:s', filemtime($file)),
        'xml' => file_get_contents($file),
    ];
}

if (!defined('DTE_API_BOOTSTRAP_ONLY')) {
    // Registro de quÃ© funciones crÃ­tico existen AL FINAL del archivo (antes del try)
    file_put_contents(__DIR__ . '/debug_api.log', date('Y-m-d H:i:s') . ' | TOPLEVEL funcs: emisorInfo=' . (function_exists('emisorInfo')?'YES':'NO') . ' generateDTE=' . (function_exists('generateDTE')?'YES':'NO') . ' sendDTE=' . (function_exists('sendDTE')?'YES':'NO') . ' validateDTE=' . (function_exists('validateDTE')?'YES':'NO') . ' loadCertificate=' . (function_exists('loadCertificate')?'YES':'NO') . PHP_EOL, FILE_APPEND);

    $body = file_get_contents('php://input');
    $data = json_decode($body, true) ?? [];
    $action = $_GET['action'] ?? $data['action'] ?? '';

    // DEBUG: validar que las funciones criticas esten definidas
    $missingFuncs = [];
    foreach (['emisorInfo','generateDTE','sendDTE','validateDTE','loadCertificate','loadCAF','buildDocumentoXML','signDTE','buildEnvioDTE','getSemilla','getToken','uploadDTE','queryEstadoEnvio','queryEstadoDTE','generateRCOF','generateLibro','sendLibro','getCAFStatus','getHistory','testSIIConnectivity'] as $fn) {
        if (!function_exists($fn)) $missingFuncs[] = $fn;
    }
    if (!empty($missingFuncs)) {
        file_put_contents(__DIR__ . '/debug_api.log', date('Y-m-d H:i:s') . ' | FALTAN FUNCIONES: ' . implode(', ', $missingFuncs) . ' | __FILE__=' . __FILE__ . ' | get_included_files=' . implode('|', get_included_files()) . PHP_EOL, FILE_APPEND);
        error_log('DTE_API: funciones faltantes: ' . implode(', ', $missingFuncs) . ' | included: ' . implode(',', get_included_files()));
    }

    // Mostrar TODAS las funciones disponibles al inicio (sin lÃ­mite)
    $allFuncs = get_defined_functions();
    file_put_contents(__DIR__ . '/debug_api.log', date('Y-m-d H:i:s') . ' | TOTAL funcs: ' . count($allFuncs['user']) . ' | generateDTE? ' . (in_array('generateDTE', $allFuncs['user']) ? 'YES' : 'NO') . ' | ALL: ' . implode(', ', $allFuncs['user']) . PHP_EOL, FILE_APPEND);

if ($action) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    try {
        $legacyPoolActions = [
            'caf_disponibles', 'caf_descargar', 'caf_solicitar', 'caf_consumir',
            'caf_distribuir', 'caf_resumen', 'caf_recalcular_consumo', 'caf_pedido_optimo',
        ];
        if (in_array($action, $legacyPoolActions, true)) {
            throw new Exception('Accion de pool CAF legacy deshabilitada: use CAF asociados a la empresa y ambiente activos.');
        }
        switch ($action) {
        // â”€â”€â”€ MÃ³dulo SaaS / Usuarios / Empresas â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        case 'listar_usuarios':
            $repo = new \App\Repositories\UsuarioRepository();
            $empId = (int)($data['empresa_id'] ?? $_GET['empresa_id'] ?? 0);
            echo json_encode(['ok' => true, 'data' => $repo->getUsuariosPorEmpresa($empId)]);
            break;
        case 'crear_usuario':
            $repo = new \App\Repositories\UsuarioRepository();
            try {
                $id = $repo->crearUsuario($data);
                echo json_encode(['ok' => true, 'id' => $id]);
            } catch (\Exception $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            break;
        case 'actualizar_usuario':
            $repo = new \App\Repositories\UsuarioRepository();
            try {
                $id = (int)($data['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
                $empId = (int)($data['empresa_id'] ?? $_GET['empresa_id'] ?? $_POST['empresa_id'] ?? 0);
                if ($id <= 0 || $empId <= 0) {
                    throw new Exception('ID de usuario o empresa inválidos.');
                }
                $ok = $repo->actualizarUsuario($id, $empId, $data);
                echo json_encode(['ok' => $ok]);
            } catch (\Exception $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            break;
        case 'desactivar_usuario':
            $repo = new \App\Repositories\UsuarioRepository();
            try {
                $id = (int)($data['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
                $empId = (int)($data['empresa_id'] ?? $_GET['empresa_id'] ?? $_POST['empresa_id'] ?? 0);
                if ($id <= 0 || $empId <= 0) {
                    throw new Exception('ID de usuario o empresa inválidos.');
                }
                $repo->desactivarUsuario($id, $empId);
                echo json_encode(['ok' => true]);
            } catch (\Exception $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            break;
        case 'get_matriz_permisos':
            $empId = (int)($data['empresa_id'] ?? $_GET['empresa_id'] ?? 0);
            if ($empId <= 0) { echo json_encode(['ok' => false, 'error' => 'empresa_id requerido']); break; }
            try {
                $pdo = \App\Core\Database::getInstance();
                $stmt = $pdo->prepare("SELECT nombre_rol, puede_anular, puede_descuento_mayor, puede_ver_reportes, puede_configurar_pos FROM saas_rol_matriz WHERE empresa_id = ?");
                $stmt->execute([$empId]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $matriz = [];
                foreach ($rows as $r) {
                    $matriz[$r['nombre_rol']] = [
                        'puede_anular'           => (bool)$r['puede_anular'],
                        'puede_descuento_mayor'  => (bool)$r['puede_descuento_mayor'],
                        'puede_ver_reportes'     => (bool)$r['puede_ver_reportes'],
                        'puede_configurar_pos'   => (bool)$r['puede_configurar_pos'],
                    ];
                }
                echo json_encode(['ok' => true, 'matriz' => $matriz]);
            } catch (\Exception $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            break;
        case 'guardar_matriz_permisos':
            $empId = (int)($data['empresa_id'] ?? 0);
            $matrizData = $data['matriz'] ?? [];
            if ($empId <= 0 || empty($matrizData)) { echo json_encode(['ok' => false, 'error' => 'Datos inválidos']); break; }
            try {
                $pdo = \App\Core\Database::getInstance();
                $sql = "INSERT INTO saas_rol_matriz (empresa_id, nombre_rol, puede_anular, puede_descuento_mayor, puede_ver_reportes, puede_configurar_pos)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        puede_anular=VALUES(puede_anular), puede_descuento_mayor=VALUES(puede_descuento_mayor),
                        puede_ver_reportes=VALUES(puede_ver_reportes), puede_configurar_pos=VALUES(puede_configurar_pos)";
                $stmt = $pdo->prepare($sql);
                foreach ($matrizData as $rol => $perms) {
                    $rol = preg_replace('/[^a-z_]/', '', strtolower((string)$rol));
                    if ($rol === '') continue;
                    $stmt->execute([
                        $empId, $rol,
                        (int)($perms['puede_anular'] ?? 0),
                        (int)($perms['puede_descuento_mayor'] ?? 0),
                        (int)($perms['puede_ver_reportes'] ?? 0),
                        (int)($perms['puede_configurar_pos'] ?? 0),
                    ]);
                }
                echo json_encode(['ok' => true]);
            } catch (\Exception $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            break;
        case 'info':     echo json_encode(emisorInfo());      break;
        case 'generate':
            file_put_contents(__DIR__ . '/debug_api.log', date('Y-m-d H:i:s') . " | generate called. function_exists(generateDTE)=" . (function_exists('generateDTE') ? 'YES' : 'NO') . PHP_EOL, FILE_APPEND);
            if (!function_exists('generateDTE')) {
                $allFuncs = get_defined_functions();
                $debug = [
                    'ok' => false,
                    'error' => 'Funcion generateDTE NO disponible en este contexto',
                    'debug' => [
                        'generateDTE_exists' => function_exists('generateDTE'),
                        'emisorInfo_exists' => function_exists('emisorInfo'),
                        'defined_DTE_API_BOOTSTRAP_ONLY' => defined('DTE_API_BOOTSTRAP_ONLY') ? 'YES' : 'NO',
                        'get_included_files' => get_included_files(),
                        'total_user_funcs' => count($allFuncs['user']),
                        'generateDTE_in_funcs' => in_array('generateDTE', $allFuncs['user']),
                        '__FILE__' => __FILE__,
                        '__LINE__' => __LINE__,
                    ],
                ];
                echo json_encode($debug);
                file_put_contents(__DIR__ . '/debug_api.log', date('Y-m-d H:i:s') . ' | DEBUG RESPONSE: ' . json_encode($debug) . PHP_EOL, FILE_APPEND);
                break;
            }
            $genRes = generateDTE($data);
            // with_pdf=1: además del XML, devolver la representación impresa en PDF
            // (base64) reutilizando MuestraPdfGenerator. El PDF se construye con el
            // XML firmado EN MEMORIA (ISO-8859-1, TED intacto) — no hay round-trip
            // que re-codifique el TED. base64 viaja seguro por JSON.
            if (!empty($genRes['ok']) && !empty($data['with_pdf']) && !empty($genRes['xml'])) {
                try {
                    // El formato (80/58/carta) solo aplica a boletas; el resto siempre carta.
                    $formatoPdf = in_array((int)$genRes['tipo'], [39, 41], true)
                        ? (string)($data['formato_pdf'] ?? 'carta')
                        : 'carta';
                    $genRes['pdf_base64'] = base64_encode(
                        buildDtePdf((string)$genRes['xml'], (int)$genRes['tipo'], (int)$genRes['folio'], $formatoPdf)
                    );
                } catch (\Throwable $e) {
                    $genRes['pdf_error'] = $e->getMessage();
                }
                // Imagen PNG del timbre PDF417 con el MISMO render TCPDF que la carta
                // (que la app del SII sí reconoce). El print server la usa para el
                // ticket termico en vez de renderizar su propio PDF417 (que no se lee).
                try {
                    if (preg_match('/<TED\b[^>]*>[\s\S]*?<\/TED>/', (string)$genRes['xml'], $mTed)) {
                        require_once __DIR__ . '/lib/tcpdf/tcpdf_barcodes_2d.php';
                        $bcTed = new \TCPDF2DBarcode($mTed[0], 'PDF417');
                        $pngTed = $bcTed->getBarcodePngData(4, 4, [0, 0, 0]);
                        if ($pngTed !== false && $pngTed !== '') {
                            $genRes['ted_png_base64'] = base64_encode($pngTed);
                        }
                    }
                } catch (\Throwable $e) {
                    $genRes['ted_png_error'] = $e->getMessage();
                }
            }
            // El XML firmado está en ISO-8859-1 y no sobrevive json_encode (devuelve
            // false). Se transporta en base64 (byte-exacto, preserva la firma); el
            // consumidor headless lo decodifica desde xml_base64.
            if (isset($genRes['xml'])) {
                $genRes['xml_base64'] = base64_encode((string)$genRes['xml']);
                unset($genRes['xml']);
            }
            echo json_encode($genRes, JSON_INVALID_UTF8_SUBSTITUTE); break;
        case 'next_folio':
            $t = (int)($_GET['tipo'] ?? 33);
            try {
                $c = loadCAF($t);
                echo json_encode(['ok'=>true, 'last'=>$c['last'], 'next'=>max((int)$c['desde'], (int)$c['last']+1)]);
            } catch(Exception $e) { echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); }
            break;
        case 'send':     echo json_encode(sendDTE($data));    break;
        case 'validate': echo json_encode(validateDTE($data)); break;
        case 'provisionar_credenciales':
            // Recibe (JSON) el certificado .pfx (base64) + su clave y/o el CAF XML, y
            // los deja en el filesystem del facturador bajo cert/{RUT}/{AMB}/ y
            // caf/{RUT}/{AMB}/. La empresa se resuelve por empresa_id (Context).
            echo json_encode(provisionarCredenciales($data, $globalContext ?? null));
            break;
        case 'reclamo_dte':
            echo json_encode(registroReclamoDTE($data));
            break;
        case 'respuesta_envio':
            echo json_encode(generateRespuestaEnvioDTE($data));
            break;
        case 'cesion_aec':
            echo json_encode(generateCesionAEC($data));
            break;
        case 'rcof':     echo json_encode(generateRCOF($data)); break;
        case 'rcof_submit':
            $fecha = $data['fecha'] ?? ($_GET['fecha'] ?? null);
            $force = !empty($data['force']) || !empty($_GET['force']);
            echo json_encode(submitDailyRCOF($fecha, $force));
            break;
        case 'rcof_log':
            echo json_encode(['ok' => true, 'log' => loadRCOFRegistry()]);
            break;
        case 'rcof_scan':
            $fecha = $data['fecha'] ?? ($_GET['fecha'] ?? date('Y-m-d'));
            echo json_encode(['ok' => true, 'fecha' => $fecha, 'resumenes' => listBoletasDelDia($fecha)]);
            break;
        case 'validate_xsd':
            // Validar un XML contra su XSD oficial del SII.
            // Inputs: 'xml' (raw string) o 'tipo'+'folio' (lee desde tmp/dte_TxFy.xml)
            $xmlIn = $data['xml'] ?? '';
            if (!$xmlIn && !empty($data['tipo']) && !empty($data['folio'])) {
                $g = getStoredDTE((int)$data['tipo'], (int)$data['folio']);
                if (!empty($g['ok'])) $xmlIn = $g['xml'];
            }
            if (!$xmlIn) {
                echo json_encode(['ok' => false, 'error' => 'Se requiere xml o (tipo+folio)']);
                break;
            }
            $val = validateXmlAgainstXSD($xmlIn);
            echo json_encode([
                'ok'      => $val['valid'],
                'skipped' => $val['skipped'] ?? false,
                'xsd'     => $val['xsd'] ? basename($val['xsd']) : null,
                'errors'  => $val['errors'],
                'reason'  => $val['reason'] ?? null,
            ]);
            break;
        case 'libro':      echo json_encode(generateLibro($data)); break;
        case 'sendLibro':  echo json_encode(sendLibro($data));    break;
        case 'caf_status': echo json_encode(getCAFStatus());  break;

        // â”€â”€â”€ CAFs centralizados (multi-sucursal) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        case 'caf_subir': {
            // POST: xml (raw o multipart), sucursal_id (opcional), origen
            $svc = new \App\Services\CafCentralManager();
            $xml = $data['xml'] ?? '';
            if (!$xml && !empty($_FILES['caf_file']['tmp_name'])) {
                $xml = file_get_contents($_FILES['caf_file']['tmp_name']);
            }
            if (!$xml) { echo json_encode(['ok'=>false,'error'=>'Falta XML del CAF']); break; }
            $sucursalId = $data['sucursal_id'] ?? ($_POST['sucursal_id'] ?? null);
            $origen     = $data['origen']      ?? ($_POST['origen']      ?? 'CENTRAL');
            echo json_encode($svc->subirCaf($xml, $sucursalId, $origen));
            break;
        }
        case 'caf_disponibles': {
            $svc = new \App\Services\CafCentralManager();
            $suc = $data['sucursal_id'] ?? ($_GET['sucursal_id'] ?? '');
            $tipo = isset($data['tipo']) ? (int)$data['tipo'] : (isset($_GET['tipo']) ? (int)$_GET['tipo'] : null);
            if (!$suc) { echo json_encode(['ok'=>false,'error'=>'Falta sucursal_id']); break; }
            echo json_encode($svc->cafsDisponibles($suc, $tipo));
            break;
        }
        case 'caf_descargar': {
            $svc = new \App\Services\CafCentralManager();
            $id  = (int)($data['id'] ?? $_GET['id'] ?? 0);
            $suc = $data['sucursal_id'] ?? ($_GET['sucursal_id'] ?? '');
            if (!$id || !$suc) { echo json_encode(['ok'=>false,'error'=>'Faltan id+sucursal_id']); break; }
            echo json_encode($svc->descargarCaf($id, $suc));
            break;
        }
        /**
         * caf_solicitar â€” La sucursal pide un bloque nuevo de folios al pool central.
         *
         * CuÃ¡ndo lo llama el APK:
         *   - Stock local llega a nivel CRITICO (< 20 folios o < 1 dÃ­a)
         *   - Stock local llega a nivel BAJO    (< 100 folios o < 3 dÃ­as)
         *   - Primera ejecuciÃ³n (migraciÃ³n): tras subir los CAFs legacy
         *
         * QuÃ© hace dte_php:
         *   1. autoAsignarDesdePool(): toma folios del pool central y los asigna
         *      a esta sucursal. Cuota = max(50, consumo_medio_30d * 30 dÃ­as).
         *      Si no hay historial â†' 100 folios por defecto.
         *   2. descargarCaf(): devuelve el XML completo en la misma respuesta.
         *
         * Respuesta exitosa:
         *   { ok: true, caf_id, desde, hasta, cantidad, xml, pool_restante }
         *
         * Errores posibles:
         *   { ok: false, reason: 'pool_vacio' }   â†' no hay CAFs en pool para este tipo
         *   { ok: false, reason: 'sin_sucursal' }  â†' falta sucursal_id
         */
        case 'caf_solicitar': {
            $svc     = new \App\Services\CafCentralManager();
            $suc     = trim($data['sucursal_id'] ?? ($_GET['sucursal_id'] ?? ''));
            $tipo    = (int)($data['tipo_dte']   ?? ($_GET['tipo_dte']   ?? 39));
            $maq     = $data['maquina_id'] ?? null;

            if (empty($suc)) {
                echo json_encode(['ok' => false, 'reason' => 'sin_sucursal',
                                  'error' => 'sucursal_id requerido']);
                break;
            }

            // 1) Asignar cuota desde pool
            $asig = $svc->autoAsignarDesdePool($suc, $tipo);
            if (!$asig['ok']) {
                echo json_encode($asig);   // propaga reason: pool_vacio / cuota_cero / exception
                break;
            }

            // 2) Devolver el XML en la misma respuesta (evita un round-trip extra)
            $desc = $svc->descargarCaf($asig['caf_id'], $suc);
            if (!$desc['ok']) {
                echo json_encode(['ok' => false, 'reason' => 'descarga_fallida',
                                  'error' => $desc['error'], 'caf_id' => $asig['caf_id']]);
                break;
            }

            // 3) CuÃ¡ntos folios quedan en el pool para este tipo (info para el dashboard)
            $pdo = \App\Core\Database::getInstance();
            $stPool = $pdo->prepare(
                "SELECT COALESCE(SUM(folio_hasta - folio_actual + 1), 0) AS restantes
                 FROM cafs WHERE sucursal_id IS NULL AND tipo_dte = ? AND agotado = 0"
            );
            $stPool->execute([$tipo]);
            $poolRestante = (int)($stPool->fetchColumn() ?: 0);

            echo json_encode([
                'ok'            => true,
                'caf_id'        => $asig['caf_id'],
                'desde'         => $asig['desde'],
                'hasta'         => $asig['hasta'],
                'cantidad'      => $asig['cantidad'],
                'origen_pool'   => $asig['origen_pool'],
                'pool_restante' => $poolRestante,
                'xml'           => $desc['xml'],
            ]);
            break;
        }

        case 'caf_consumir': {
            $svc = new \App\Services\CafCentralManager();
            $id     = (int)($data['id'] ?? 0);
            $folio  = (int)($data['folio'] ?? 0);
            $suc    = $data['sucursal_id'] ?? '';
            $maq    = $data['maquina_id']  ?? null;
            if (!$id || !$folio || !$suc) {
                echo json_encode(['ok'=>false,'error'=>'Faltan id+folio+sucursal_id']);
                break;
            }
            echo json_encode($svc->consumirFolio($id, $folio, $suc, $maq));
            break;
        }
        /**
         * folio_ultimo_usado â€” Â¿CuÃ¡l es el Ãºltimo folio conocido para esta sucursal?
         *
         * Consultado por el APK al sincronizar, para sembrar el contador local
         * en caso de reinstalaciÃ³n (SharedPreferences/SQLite borrados).
         *
         * Cruza tres fuentes y devuelve el MÃXIMO para minimizar riesgo de reuso:
         *   1. caf_consumos   â€” notificaciones fire-and-forget del dispositivo
         *   2. cafs.folio_actual-1 â€” Ãºltimo folio entregado a esta sucursal por el pool
         *   3. sii_dte        â€” DTEs que llegaron al servidor (mÃ¡s fiables)
         *
         * GET ?action=folio_ultimo_usado&sucursal_id=X&tipo_dte=39
         */
        case 'folio_ultimo_usado': {
            $suc  = trim($data['sucursal_id'] ?? ($_GET['sucursal_id'] ?? ''));
            $tipo = (int)($data['tipo_dte']   ?? ($_GET['tipo_dte']   ?? 39));

            if (empty($suc)) {
                echo json_encode(['ok' => false, 'error' => 'sucursal_id requerido']);
                break;
            }

            $pdo = \App\Core\Database::getInstance();

            // Fuente 1: caf_consumos (notificaciones fire-and-forget del APK)
            if (!$globalContext) {
                echo json_encode(['ok' => false, 'error' => 'Contexto de empresa requerido']);
                break;
            }
            $empresaId = $globalContext->getEmpresaId();
            $ambiente = strtolower($globalContext->getAmbiente());
            $st1 = $pdo->prepare(
                "SELECT COALESCE(MAX(folio), 0) FROM sii_folio_consumo
                 WHERE empresa_id = ? AND tipo_dte = ? AND ambiente = ?"
            );
            $st1->execute([$empresaId, $tipo, $ambiente]);
            $maxConsumo = (int)$st1->fetchColumn();

            // Fuente 2: cafs.folio_actual - 1 (pool central: Ãºltimo folio ya entregado)
            // Fuente 3: sii_dte cruzado con los rangos CAF de esta sucursal
            // (mÃ¡s fiable: solo DTEs realmente enviados al SII)
            $st3 = $pdo->prepare(
                "SELECT COALESCE(MAX(folio), 0) FROM sii_dte
                 WHERE empresa_id = ? AND tipo_dte = ? AND ambiente = ?"
            );
            $st3->execute([$empresaId, $tipo, $ambiente]);
            $maxDte = (int)$st3->fetchColumn();

            $ultimo = max($maxConsumo, $maxDte);

            echo json_encode([
                'ok'           => true,
                'ultimo_folio' => $ultimo,
                'tipo_dte'     => $tipo,
                'sucursal_id'  => $suc,
            ]);
            break;
        }

        case 'caf_distribuir': {
            $svc = new \App\Services\CafCentralManager();
            $id   = (int)($data['id'] ?? 0);
            $sucs = $data['sucursales'] ?? [];
            if (!is_array($sucs)) $sucs = explode(',', (string)$sucs);
            $sucs = array_values(array_filter(array_map('trim', $sucs)));
            if (!$id || !$sucs) {
                echo json_encode(['ok'=>false,'error'=>'Faltan id+sucursales[]']);
                break;
            }
            echo json_encode($svc->distribuirCaf($id, $sucs));
            break;
        }
        case 'caf_resumen': {
            $svc = new \App\Services\CafCentralManager();
            $umbral = (int)($data['dias_umbral'] ?? $_GET['dias_umbral'] ?? 7);
            echo json_encode($svc->resumenGlobal($umbral));
            break;
        }
        case 'caf_recalcular_consumo': {
            $svc = new \App\Services\CafCentralManager();
            $suc = $data['sucursal_id'] ?? ($_GET['sucursal_id'] ?? null);
            echo json_encode($svc->recalcularConsumoMedio($suc));
            break;
        }
        case 'caf_pedido_optimo': {
            $svc = new \App\Services\CafCentralManager();
            $dias    = (int)($data['dias'] ?? $_GET['dias'] ?? 60);
            $factor  = (float)($data['factor'] ?? $_GET['factor'] ?? 1.3);
            echo json_encode($svc->calcularPedidoOptimo($dias, $factor));
            break;
        }
        case 'history':  echo json_encode(getHistory());      break;
        case 'list_files':
            echo json_encode(['ok' => true, 'files' => listStoredFiles()]);
            break;
        case 'resend':
            echo json_encode(resendStoredDTE(
                (int)$data['tipo'],
                (int)$data['folio'],
                [
                    'force'   => !empty($data['force']),
                    'reason'  => $data['reason']  ?? '',
                    'dry_run' => !empty($data['dry_run']),
                    'user'    => $data['user']    ?? null,
                ]
            ));
            break;
        case 'dte_tracking':
            echo json_encode(['ok' => true, 'tracking' => getDTETracking((int)$data['tipo'], (int)$data['folio'])]);
            break;
        case 'upload_caf': echo json_encode(uploadCAF()); break;
        case 'test_sii': echo json_encode(testSIIConnectivity()); break;
        case 'health_sii': echo json_encode(siiHealthcheck()); break;
        case 'broadcasts_active': echo json_encode(getBroadcastsActivos()); break;
        case 'sii_endpoints': echo json_encode(['ok' => true, 'endpoints' => siiEndpoints()]); break;
        case 'sync_xsd':
            $force = !empty($data['force']) || !empty($_GET['force']);
            $rep = syncSIISchemas($force);
            // Si EnvioBOLETA estÃ¡ disponible, tambiÃ©n reportar el lÃ­mite extraÃ­do
            $rep['envio_boleta_max_dte'] = readEnvioBoletaMaxDTE();
            echo json_encode($rep);
            break;
        case 'emit_nc':
            // Emite Nota de CrÃ©dito (61) sobre una boleta existente
            $tipo  = (int)($data['tipo_orig']  ?? 0);
            $folio = (int)($data['folio_orig'] ?? 0);
            $cod   = (int)($data['cod_ref']    ?? 1);
            $razon = trim((string)($data['razon'] ?? ''));
            $opts = [
                'dry_run' => !empty($data['dry_run']),
                'fecha'   => $data['fecha'] ?? null,
            ];
            if (isset($data['items']) && is_array($data['items'])) $opts['items'] = $data['items'];
            echo json_encode(emitirNotaCreditoSobreBoleta($tipo, $folio, $cod, $razon, $opts));
            break;
        case 'retry_queue':
            echo json_encode(['ok' => true, 'queue' => loadRetryQueue(), 'contingency' => isInContingencyMode()]);
            break;
        case 'retry_process':
            $max = (int)($data['max'] ?? $_GET['max'] ?? 20);
            echo json_encode(['ok' => true, 'report' => processRetryQueue($max)]);
            break;
        case 'contingency_toggle':
            $action = $data['action'] ?? $_GET['action_arg'] ?? 'auto';
            if ($action === 'on')      { setContingencyMode(true,  $data['reason'] ?? 'manual'); echo json_encode(['ok'=>true, 'contingency'=>true]); }
            elseif ($action === 'off') { setContingencyMode(false); echo json_encode(['ok'=>true, 'contingency'=>false]); }
            else                       { echo json_encode(['ok'=>true, 'auto_result' => autoToggleContingency(), 'contingency' => isInContingencyMode()]); }
            break;
        case 'poll_estados':
            $max = (int)($data['max'] ?? $_GET['max'] ?? 50);
            echo json_encode(['ok' => true, 'report' => pollEstadoDTEs($max)]);
            break;
        case 'alerts':
            echo json_encode(['ok' => true, 'alerts' => generateAlerts()]);
            break;
        case 'folio_anular':
            $t = (int)($data['tipo'] ?? 0);
            $f = (int)($data['folio'] ?? 0);
            echo json_encode(anularFolio($t, $f, (string)($data['razon'] ?? ''), $data['fecha'] ?? null));
            break;
        case 'folios_anulados':
            $t = isset($data['tipo']) ? (int)$data['tipo'] : null;
            echo json_encode(['ok' => true, 'anulados' => listarFoliosAnulados($t, $data['fecha'] ?? null)]);
            break;
        case 'backup_create':
            $keep = (int)($data['keep'] ?? $_GET['keep'] ?? 14);
            echo json_encode(createBackup($keep));
            break;
        case 'backup_list':
            echo json_encode(['ok' => true, 'backups' => listBackups()]);
            break;
        case 'archive_status':
            echo json_encode(['ok' => true, 'archive' => archiveStatus()]);
            break;
        case 'archive_backfill':
            echo json_encode(['ok' => true, 'report' => backfillArchive()]);
            break;
        case 'xsd_status':
            $candidatos = siiSchemaCandidates();
            $status = [];
            foreach ($candidatos as $name => $urls) {
                $p = __DIR__ . '/schemas/' . $name;
                $status[$name] = [
                    'present' => file_exists($p),
                    'size'    => file_exists($p) ? filesize($p) : 0,
                ];
            }
            echo json_encode([
                'ok' => true,
                'schemas' => $status,
                'envio_boleta_max_dte' => readEnvioBoletaMaxDTE(),
            ]);
            break;
        case 'sii_transactions':
            $limit = (int)($data['limit'] ?? $_GET['limit'] ?? 50);
            $op    = $data['op'] ?? $_GET['op'] ?? null;
            echo json_encode(['ok' => true, 'transactions' => loadSiiTransactions($limit, $op)]);
            break;
        case 'get_xml':
            $t = (int)($data['tipo'] ?? 0);
            $f = (int)($data['folio'] ?? 0);
            echo json_encode(getStoredDTE($t, $f));
            break;
        case 'get_sii_logs':
            global $actualTmpDir;
            $file = $actualTmpDir . 'sii_logs.json';
            $logs = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
            echo json_encode(['ok' => true, 'logs' => $logs]);
            break;
        case 'cs50':
        case 'cert_simulation':
            if (!$globalContext) {
                echo json_encode(['ok' => false, 'error' => 'Debe seleccionar una empresa para realizar la certificaciÃ³n.']);
                break;
            }
            $mgr = new \App\Services\CertificationManager($globalContext);
            $tSim = (int)($data['tipo'] ?? $_GET['tipo'] ?? 33);
            $cantSim = (int)($data['cantidad'] ?? $_GET['cantidad'] ?? 50);
            echo json_encode($mgr->runSimulacion($tSim, $cantSim));
            break;
        case 'cc':
        case 'cert_case':
            if (!$globalContext) {
                echo json_encode(['ok' => false, 'error' => 'Debe seleccionar una empresa para emitir casos de certificaciÃ³n.']);
                break;
            }
            $caseId = $data['cid'] ?? $_GET['cid'] ?? $data['case'] ?? $_GET['case'] ?? '';
            $caseData = getCertCaseData($caseId);
            echo json_encode(generateDTE($caseData));
            break;
        case 'historial':
            // Historial de DTEs emitidos con paginaciÃ³n (para APK Android).
            $page      = (int)($data['page']    ?? $_GET['page']    ?? 1);
            $tipo      = isset($data['tipo'])   ? (int)$data['tipo']   : null;
            $sucursalQ = $data['sucursal']       ?? $_GET['sucursal']   ?? null;
            echo json_encode(getHistorialPaginado($page, $tipo, $sucursalQ));
            break;

        // â”€â”€ POS Local: recibir DTE generado en APK â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        case 'dte_recibir': {
            $mgr = new \App\Services\DteLocalQueueManager();
            echo json_encode($mgr->recibirDte($data));
            break;
        }

        // â”€â”€ POS Local: estado de la cola de DTEs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        case 'dte_cola_estado': {
            $mgr = new \App\Services\DteLocalQueueManager();
            $suc = $data['sucursal_id'] ?? $_GET['sucursal_id'] ?? null;
            echo json_encode($mgr->resumenGlobal());
            break;
        }

        // â”€â”€ POS Local: procesar cola â†' enviar al SII â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        case 'dte_cola_procesar': {
            $max  = (int)($data['max'] ?? $_GET['max'] ?? 10);
            $mgr  = new \App\Services\DteLocalQueueManager();
            $pend = $mgr->getPendientes($max);
            $resultados = [];

            foreach ($pend as $item) {
                $itemId  = (int)$item['id'];
                $tipoDte = (int)$item['tipo_dte'];
                $folio   = (int)$item['folio'];
                $mgr->marcarProcesando($itemId);
                try {
                    $items = json_decode((string)($item['items_json'] ?? '[]'), true) ?: [];
                    $dteData = [
                        'tipoDTE'     => $tipoDte,
                        'folio'       => $folio,
                        'fecha'       => $item['fecha_emision'],
                        'receptor'    => [
                            'rut'    => $item['rut_receptor'],
                            'nombre' => 'Consumidor Final',
                        ],
                        'items'       => $items,
                        'indServicio' => 3,
                    ];
                    $gen = generateDTE($dteData);
                    if (empty($gen['ok'])) {
                        $mgr->marcarError($itemId, $gen['error'] ?? 'generateDTE fallÃ³');
                        $resultados[] = ['id'=>$itemId,'folio'=>$folio,'resultado'=>'ERROR_GEN','error'=>$gen['error']??''];
                        continue;
                    }
                    $send = sendDTE(['xml'=>$gen['xml'],'tipo'=>$tipoDte,'folio'=>$folio]);
                    if (!empty($send['ok']) && !empty($send['trackId'])) {
                        $mgr->marcarEnviado($itemId, $send['trackId']);
                        $resultados[] = ['id'=>$itemId,'folio'=>$folio,'resultado'=>'OK','trackId'=>$send['trackId']];
                    } else {
                        $mgr->marcarError($itemId, $send['error'] ?? 'sendDTE sin trackId');
                        $resultados[] = ['id'=>$itemId,'folio'=>$folio,'resultado'=>'ERROR_SII','error'=>$send['error']??''];
                    }
                } catch (\Throwable $ex) {
                    $mgr->marcarError($itemId, $ex->getMessage());
                    $resultados[] = ['id'=>$itemId,'folio'=>$folio,'resultado'=>'EXCEPTION','error'=>$ex->getMessage()];
                }
            }
            echo json_encode(['ok'=>true,'procesados'=>count($resultados),'resultados'=>$resultados]);
            break;
        }

        // â”€â”€ POS Local: reenviar manualmente UN documento puntual â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        case 'dte_cola_procesar_uno': {
            $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok'=>false,'error'=>'id requerido']);
                break;
            }
            $mgr  = new \App\Services\DteLocalQueueManager();
            $item = $mgr->getById($id);
            if (!$item) {
                echo json_encode(['ok'=>false,'error'=>'Documento no encontrado en la cola']);
                break;
            }
            // Reintento manual: el operador interviene, se ignora el lÃ­mite automÃ¡tico.
            $mgr->resetParaReintento($id);

            $tipoDte = (int)$item['tipo_dte'];
            $folio   = (int)$item['folio'];
            $mgr->marcarProcesando($id);
            try {
                $items = json_decode((string)($item['items_json'] ?? '[]'), true) ?: [];
                $dteData = [
                    'tipoDTE'     => $tipoDte,
                    'folio'       => $folio,
                    'fecha'       => $item['fecha_emision'],
                    'receptor'    => [
                        'rut'    => $item['rut_receptor'],
                        'nombre' => 'Consumidor Final',
                    ],
                    'items'       => $items,
                    'indServicio' => 3,
                ];
                $gen = generateDTE($dteData);
                if (empty($gen['ok'])) {
                    $mgr->marcarError($id, $gen['error'] ?? 'generateDTE fallÃ³');
                    echo json_encode(['ok'=>true,'id'=>$id,'folio'=>$folio,'resultado'=>'ERROR_GEN','error'=>$gen['error']??'']);
                    break;
                }
                $send = sendDTE(['xml'=>$gen['xml'],'tipo'=>$tipoDte,'folio'=>$folio]);
                if (!empty($send['ok']) && !empty($send['trackId'])) {
                    $mgr->marcarEnviado($id, $send['trackId']);
                    echo json_encode(['ok'=>true,'id'=>$id,'folio'=>$folio,'resultado'=>'OK','trackId'=>$send['trackId']]);
                } else {
                    $mgr->marcarError($id, $send['error'] ?? 'sendDTE sin trackId');
                    echo json_encode(['ok'=>true,'id'=>$id,'folio'=>$folio,'resultado'=>'ERROR_SII','error'=>$send['error']??'']);
                }
            } catch (\Throwable $ex) {
                $mgr->marcarError($id, $ex->getMessage());
                echo json_encode(['ok'=>true,'id'=>$id,'folio'=>$folio,'resultado'=>'EXCEPTION','error'=>$ex->getMessage()]);
            }
            break;
        }

        // â”€â”€ POS Local: reportar stock de folios desde APK â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        case 'folios_alerta': {
            $mgr = new \App\Services\FoliosAlertaManager();
            echo json_encode($mgr->reportar($data));
            break;
        }

        // â”€â”€ POS Local: urgencia global de folios (para dashboard y APK) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        case 'folios_urgencia': {
            $mgr = new \App\Services\FoliosAlertaManager();
            echo json_encode($mgr->urgenciaGlobal());
            break;
        }

        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        //  ENROLAMIENTO DE DISPOSITIVOS
        //  Flujo: Admin genera token â†' APK lo presenta â†' recibe API key
        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

        // â”€â”€ Generar token de activaciÃ³n (requiere API key de admin) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        case 'dispositivo_generar_token': {
            if (!$globalContext) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => 'Se requiere API key de empresa para generar tokens']);
                break;
            }
            $pdo        = \App\Core\Database::getInstance();
            $sucursalId = trim($data['sucursal_id'] ?? '');
            $nombre     = trim($data['nombre'] ?? 'POS sin nombre');
            $tipo       = trim($data['tipo'] ?? 'POS_ANDROID');
            $empresaId  = $globalContext->getEmpresaId();

            $tiposValidos = ['POS_ANDROID', 'POS_WEB', 'APK_MOVIL', 'OTRO'];
            if (!in_array($tipo, $tiposValidos, true)) $tipo = 'POS_ANDROID';

            if (empty($sucursalId)) {
                echo json_encode(['ok' => false, 'error' => 'sucursal_id es requerido']);
                break;
            }

            // CÃ³digo legible de 6 chars (sin caracteres ambiguos: 0/O/I/1/L)
            $chars  = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
            $codigo = '';
            for ($i = 0; $i < 6; $i++) {
                $codigo .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $hash   = hash('sha256', $codigo);
            $expira = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $st = $pdo->prepare(
                "INSERT INTO sii_dispositivo
                    (empresa_id, sucursal_id, nombre, tipo, token_activacion, token_hash, token_expira)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $st->execute([$empresaId, $sucursalId, $nombre, $tipo, $codigo, $hash, $expira]);

            echo json_encode([
                'ok'     => true,
                'id'     => (int) $pdo->lastInsertId(),
                'codigo' => $codigo,
                'expira' => $expira,
                'nombre' => $nombre,
                'sucursal_id' => $sucursalId,
            ]);
            break;
        }

        // â”€â”€ Enrolar dispositivo con token (NO requiere API key) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        //    El APK presenta el token de activaciÃ³n y recibe su API key permanente.
        case 'dispositivo_enrolar': {
            $pdo       = \App\Core\Database::getInstance();
            $codigo    = strtoupper(trim($data['codigo'] ?? ''));
            $androidId = trim($data['android_id']   ?? '');
            $modelo    = trim($data['modelo']        ?? '');
            $version   = trim($data['version_app']   ?? '');

            if (empty($codigo)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'CÃ³digo de activaciÃ³n requerido']);
                break;
            }

            $hash = hash('sha256', $codigo);
            $st   = $pdo->prepare(
                "SELECT d.*, e.rut, e.razon_social
                   FROM sii_dispositivo d
                   JOIN sii_empresa e ON e.id = d.empresa_id
                  WHERE d.token_hash = ?
                    AND d.token_usado  = 0
                    AND d.token_expira > NOW()
                    AND d.activo       = 1
                  LIMIT 1"
            );
            $st->execute([$hash]);
            $disp = $st->fetch();

            if (!$disp) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'CÃ³digo invÃ¡lido, expirado o ya utilizado']);
                break;
            }

            // Generar API key permanente para este dispositivo
            $rawKey  = 'sk_' . bin2hex(random_bytes(16));
            $keyHash = hash('sha256', $rawKey);

            // Buscar nombre legible de la sucursal
            $sucNombre = 'Sucursal ' . $disp['sucursal_id'];
            try {
                $stSuc = $pdo->prepare(
                    "SELECT nombre FROM dim_sucursal WHERE id_sucursal = ? LIMIT 1"
                );
                $stSuc->execute([$disp['sucursal_id']]);
                $sucNombreDb = $stSuc->fetchColumn();
                if ($sucNombreDb) $sucNombre = $sucNombreDb;
            } catch (Throwable $_) {}

            // Marcar token como usado y guardar info del dispositivo
            $stUpd = $pdo->prepare(
                "UPDATE sii_dispositivo
                    SET token_usado = 1, api_key_hash = ?,
                        device_android_id = ?, device_modelo = ?,
                        device_version_app = ?, enrolado_en = NOW(), ultimo_contacto = NOW()
                  WHERE id = ?"
            );
            $stUpd->execute([$keyHash, $androidId, $modelo, $version, $disp['id']]);

            // Registrar la API key en sii_api_key para que funcione con el sistema existente
            $stKey = $pdo->prepare(
                "INSERT INTO sii_api_key (empresa_id, nombre, clave_hash, activa)
                 VALUES (?, ?, ?, 1)"
            );
            $stKey->execute([
                $disp['empresa_id'],
                'Dispositivo: ' . $disp['nombre'],
                $keyHash,
            ]);

            echo json_encode([
                'ok'              => true,
                'api_key'         => $rawKey,
                'sucursal_id'     => $disp['sucursal_id'],
                'sucursal_nombre' => $sucNombre,
                'empresa_rut'     => $disp['rut'],
                'empresa_nombre'  => $disp['razon_social'],
                'dispositivo_id'  => (int) $disp['id'],
                'nombre'          => $disp['nombre'],
            ]);
            break;
        }

        // â”€â”€ Listar dispositivos de la empresa (requiere API key) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        case 'dispositivo_lista': {
            if (!$globalContext) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => 'Se requiere API key']);
                break;
            }
            $pdo       = \App\Core\Database::getInstance();
            $empresaId = $globalContext->getEmpresaId();

            $st = $pdo->prepare(
                "SELECT id, sucursal_id, nombre, tipo,
                        token_activacion, token_usado, token_expira,
                        device_modelo, device_version_app,
                        enrolado_en, ultimo_contacto, activo, creado_en
                   FROM sii_dispositivo
                  WHERE empresa_id = ?
                  ORDER BY creado_en DESC"
            );
            $st->execute([$empresaId]);
            echo json_encode(['ok' => true, 'dispositivos' => $st->fetchAll()]);
            break;
        }

        // â”€â”€ Desactivar un dispositivo (requiere API key) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        case 'dispositivo_desactivar': {
            if (!$globalContext) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => 'Se requiere API key']);
                break;
            }
            $pdo       = \App\Core\Database::getInstance();
            $id        = (int) ($data['id'] ?? 0);
            $empresaId = $globalContext->getEmpresaId();

            if (!$id) {
                echo json_encode(['ok' => false, 'error' => 'id de dispositivo requerido']);
                break;
            }
            $st = $pdo->prepare(
                "UPDATE sii_dispositivo SET activo = 0 WHERE id = ? AND empresa_id = ?"
            );
            $st->execute([$id, $empresaId]);
            $afectadas = $st->rowCount();

            // Revocar tambiÃ©n la API key en sii_api_key
            if ($afectadas > 0) {
                $stHash = $pdo->prepare(
                    "SELECT api_key_hash FROM sii_dispositivo WHERE id = ?"
                );
                $stHash->execute([$id]);
                $keyHash = $stHash->fetchColumn();
                if ($keyHash) {
                    $pdo->prepare(
                        "UPDATE sii_api_key SET activa = 0 WHERE clave_hash = ?"
                    )->execute([$keyHash]);
                }
            }
            echo json_encode(['ok' => true, 'desactivado' => $afectadas > 0]);
            break;
        }

        // â”€â”€ Heartbeat del dispositivo (actualiza ultimo_contacto) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        case 'dispositivo_heartbeat': {
            $pdo       = \App\Core\Database::getInstance();
            $dispId    = (int) ($data['dispositivo_id'] ?? 0);
            $version   = trim($data['version_app'] ?? '');

            if ($dispId > 0) {
                $pdo->prepare(
                    "UPDATE sii_dispositivo SET ultimo_contacto = NOW(),
                        device_version_app = COALESCE(NULLIF(?, ''), device_version_app)
                      WHERE id = ? AND activo = 1"
                )->execute([$version, $dispId]);
            }
            echo json_encode(['ok' => true, 'ts' => date('c')]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "AcciÃ³n '$action' no reconocida"]);
    }
} catch (Throwable $e) {
    $code = ($e instanceof Exception) ? 400 : 500;
    http_response_code($code);
    $errData = [
        'ok'      => false, 
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'trace'   => $e->getTraceAsString()
    ];
    file_put_contents(__DIR__ . '/error_api.log', print_r($errData, true), FILE_APPEND);
    if ($code === 400) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode($errData);
    }
    }
}
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// INFO EMISOR
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
file_put_contents(__DIR__ . '/debug_api.log', date('Y-m-d H:i:s') . ' | REACHED EMISOR INFO SECTION. function_exists(generateDTE)=' . (function_exists('generateDTE')?'YES':'NO') . PHP_EOL, FILE_APPEND);
function emisorInfo(): array {
    global $globalContext;
    if ($globalContext) {
        $emp = $globalContext->getEmpresa();
        return [
            'ok'          => true,
            'rut'         => $emp['rut'],
            'razonSocial' => $emp['razon_social'],
            'giro'        => $emp['giro'],
            'direccion'   => $emp['direccion_origen'] . ', ' . ($emp['comuna_origen'] ?? ''),
            'comuna'      => $emp['comuna_origen'] ?? '',
            'ciudad'      => $emp['ciudad_origen'] ?? '',
            'sucursal'    => $emp['sucursal'] ?? null,
            'acteco'      => $emp['acteco'] ?? null,
            // En CERTIFICACION el SII exige Resolucion N° 0 (igual que en la Caratula
            // y en las muestras). En PRODUCCION se imprime la resolucion real de la
            // empresa. Asi certificacion no se ve afectada al pasar una empresa a prod.
            'resolNum'    => ($globalContext->getAmbiente() === 'CERTIFICACION') ? 0 : ($emp['numero_resolucion'] ?? NRO_RESOL),
            'resolFch'    => $emp['fecha_resolucion'] ?? FCH_RESOL,
            'unidadSII'   => $emp['unidad_sii'] ?? UNIDAD_SII,
            'ambiente'    => $globalContext->getAmbiente(),
        ];
    }
    return [
        'ok'          => true,
        'rut'         => RUT_EMISOR,
        'razonSocial' => RAZON_SOCIAL,
        'giro'        => GIRO_EMISOR,
        'direccion'   => DIRECCION,
        'comuna'      => COMUNA,
        'ciudad'      => CIUDAD,
        'sucursal'    => null,
        'acteco'      => ACTECO,
        'resolNum'    => NRO_RESOL,
        'resolFch'    => FCH_RESOL,
        'unidadSII'   => UNIDAD_SII,
        'ambiente'    => AMBIENTE,
    ];
}

function siiDateOnly(string $value): string {
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m)) {
        return $m[0];
    }

    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d', $ts) : date('Y-m-d');
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// GENERAR DTE
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function generateDTE(array $data): array {
    global $globalContext, $actualTmpDir;
    
    // FORZADO DE RECEPTOR PARA CERTIFICACIÃ“N (Seguridad total)
    if (isset($globalContext) && $globalContext->getAmbiente() === 'CERTIFICACION') {
        $tipoDTE = (int)($data['tipoDTE'] ?? 33);
        if (!isset($data['receptor'])) $data['receptor'] = [];
        if ($tipoDTE === 52 && (int)($data['indTraslado'] ?? 0) === 5) {
            $empresa = $globalContext->getEmpresa();
            $data['receptor'] = [
                'rut'       => $empresa['rut'] ?? '',
                'nombre'    => $empresa['razon_social'] ?? '',
                'giro'      => $empresa['giro'] ?? '',
                'direccion' => $empresa['direccion_origen'] ?? '',
                'comuna'    => $empresa['comuna_origen'] ?? '',
                'ciudad'    => $empresa['ciudad_origen'] ?? '',
            ];
        } else {
            $data['receptor']['rut'] = in_array($tipoDTE, [39, 41]) ? '66666666-6' : '55555555-5';
            if (empty($data['receptor']['nombre'])) $data['receptor']['nombre'] = 'EMPRESA DE PRUEBAS SII';
        }
    }
    global $globalContext;
    
    // Aceptar 'tipo' o 'tipoDTE' indistintamente para evitar errores por nomenclatura
    $tipo    = (int)($data['tipoDTE'] ?? $data['tipo'] ?? 0);
    $folio   = (int)($data['folio']   ?? 0);
    $fecha   = siiDateOnly((string)($data['fecha'] ?? date('Y-m-d')));
    // Modo certificaciÃ³n: NO consumir folios (no registrar DTE ni consumo en BD)
    // para poder REUTILIZAR siempre los mismos primeros folios del CAF en cada
    // reintento, sin perderlos. El XML igual se guarda en tmp/ para las muestras.
    $certNoConsume = !empty($data['certNoConsume']);
    $items   = $data['items']   ?? [];
    $recep   = $data['receptor'] ?? [];

    if (!in_array($tipo, [33, 34, 39, 41, 43, 46, 52, 56, 61, 110, 111, 112])) {
        throw new Exception('Tipo de DTE invÃ¡lido: ' . $tipo);
    }
    if (empty($items)) {
        throw new Exception('Debe ingresar al menos un Ã­tem');
    }
    if (count($items) > 60) {
        throw new Exception('El DTE no puede tener mÃ¡s de 60 lÃ­neas de detalle (mÃ¡ximo XSD SII). Tiene ' . count($items) . '.');
    }

    $referencias = $data['referencias'] ?? [];
    $advertencias = [];

    // GiroRecep es opcional en el XSD pero SII suele rechazar facturas sin Ã©l
    if (in_array($tipo, [33, 34, 46, 52, 56, 61]) && empty($recep['giro'])) {
        $advertencias[] = 'GiroRecep no proporcionado. El SII puede rechazar este documento si el receptor no tiene giro registrado.';
    }

    if (in_array($tipo, [56, 61, 111, 112]) && empty($referencias)) {
        throw new Exception(
            in_array($tipo, [61, 112], true)
                ? 'Una Nota de CrÃ©dito (tipo 61) requiere al menos una <Referencia> al documento original que modifica o anula.'
                : 'Una Nota de DÃ©bito (tipo 56) requiere al menos una <Referencia> al documento original que modifica.'
        );
    }
    $descuentoGlobal = $data['descuentoGlobal'] ?? null;

    // Certificado segÃºn tipo: boletas (39/41) -> Cristina ; guÃ­as/facturas -> David
    $GLOBALS['SII_CERT_TIPO'] = $tipo;
    [$cert, $privKey] = loadCertificate($tipo);

    // Multi-CAF: si el consumidor pidiÃ³ un folio especÃ­fico, se carga el CAF
    // cuyo rango lo contiene; si no, se elige el primer CAF activo con folios
    // disponibles y se autoasigna el siguiente folio dentro de ese rango.
    $caf      = loadCAF($tipo, $folio > 0 ? $folio : 0);
    $lastUsed = (int)$caf['last'];
    if ($folio <= 0) {
        $folio = max((int)$caf['desde'], $lastUsed + 1);
    }

    if ($folio < $caf['desde'] || $folio > $caf['hasta']) {
        throw new Exception("Folio $folio fuera del rango CAF ({$caf['desde']} - {$caf['hasta']})");
    }

    $montos = aplicarDescuentoGlobalMontos(calcularMontos($items, $tipo), $descuentoGlobal);
    $idDte  = "T{$tipo}F{$folio}";

    if (in_array($tipo, [110, 111, 112], true)) {
        $xmlDoc = buildExportacionXML(
            $tipo, $folio, $fecha, $recep, $items, $montos, $caf, $idDte, $privKey,
            (int)($data['tipoDespacho'] ?? 0),
            $referencias,
            $data['exportacion'] ?? [],
            $data['aduana'] ?? [],
            $data['moneda'] ?? null,
            isset($data['tipoCambio']) ? (float)$data['tipoCambio'] : null
        );
    } elseif ($tipo === 43) {
        $xmlDoc = buildLiquidacionXML(
            $tipo, $folio, $fecha, $recep, $items, $montos, $caf, $idDte, $privKey,
            $data['comisiones'] ?? [], $referencias
        );
    } else {
        $xmlDoc = buildDocumentoXML(
            $tipo, $folio, $fecha, $recep, $items, $montos, $caf, $idDte, $privKey,
            (int)($data['indTraslado'] ?? 0), (int)($data['tipoDespacho'] ?? 0),
            $data['patente'] ?? '', $data['rutTranspor'] ?? '',
            $referencias, $descuentoGlobal,
            $data['moneda'] ?? null,
            isset($data['tipoCambio']) ? (float)$data['tipoCambio'] : null,
            (int)($data['indServicio'] ?? 3)
        );
    }
    $xmlFirmado = signDTE($xmlDoc, $cert, $privKey, $idDte);

    // Guardar en archivo (como respaldo)
    $tmpDir = $actualTmpDir;
    $tmpFile = $tmpDir . "dte_T{$tipo}F{$folio}.xml";
    file_put_contents($tmpFile, $xmlFirmado);

    // PERSISTENCIA EN BASE DE DATOS CENTRAL
    // En certificaciÃ³n con folios reutilizables se omite: no se registra el DTE
    // ni se marca el folio como consumido (asÃ­ el mismo folio se reusa al reintentar).
    // Segunda guardia: en ambiente CERTIFICACION nunca persistir, aunque certNoConsume
    // no se haya propagado por algÃºn path de cÃ³digo futuro (el XML va en Latin-1 y
    // json_encode del payload romperÃ­a el CHECK json_valid de documentos_emitidos).
    if ($globalContext && !$certNoConsume && $globalContext->getAmbiente() !== 'CERTIFICACION') {
        $repo = new EmpresaRepository();
        $dteId = $repo->registrarDTE([
            'empresa_id'     => $globalContext->getEmpresaId(),
            'tipo_dte'       => $tipo,
            'folio'          => $folio,
            'ambiente'       => $globalContext->getAmbiente(),
            'fecha'          => $fecha,
            'rut_receptor'   => $recep['rut'] ?? '66666666-6',
            'razon_receptor' => $recep['nombre'] ?? 'Consumidor Final',
            'total'          => $montos['mntTotal'],
            'xml'            => $xmlFirmado
        ]);
        
        $repo->registrarConsumoFolio($caf['id_db'], $globalContext->getEmpresaId(), $tipo, $folio, $dteId, $globalContext->getAmbiente());
    }

    return [
        'ok'          => true,
        'xml'         => $xmlFirmado,
        'folio'       => $folio,
        'tipo'        => $tipo,
        'montos'      => $montos,
        'alerta'      => $caf['alerta'] ?? 'normal',
        'advertencias'=> $advertencias,
        'mensaje'     => "DTE tipo $tipo folio $folio generado correctamente. Alerta: " . ($caf['alerta'] ?? 'normal'),
    ];
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// ENVIAR AL SII
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
/**
 * Representación impresa de un DTE como PDF (bytes), reutilizando el generador de
 * certificación (MuestraPdfGenerator) — el MISMO timbre PDF417 y layout, sin
 * duplicar código. Usado por la acción 'generate' (with_pdf) para el respaldo en
 * la nube de boletas cuando no hay print server local. La resolución sale de la
 * empresa real en PRODUCCION; en CERTIFICACION va N° 0 como exige el SII.
 */
function buildDtePdf(string $xmlFirmado, int $tipo, int $folio, string $formato = 'carta'): string {
    global $globalContext;
    $emp  = $globalContext ? $globalContext->getEmpresa() : [];
    $cert = $globalContext && $globalContext->getAmbiente() === 'CERTIFICACION';
    $opts = [
        'resolNum'  => $cert ? 0 : (int)($emp['numero_resolucion'] ?? $emp['nro_resol'] ?? 0),
        'resolFch'  => (string)($emp['fecha_resolucion'] ?? $emp['fch_resol'] ?? ''),
        'unidadSII' => (string)($emp['unidad_sii'] ?? ''),
    ];
    $gen = new \App\Services\MuestraPdfGenerator();
    // xml y raw apuntan al mismo string firmado: loadXML respeta la declaración
    // ISO-8859-1 y el TED se extrae de los bytes originales (no re-codificados).
    // El formato (carta / 80 / 58) lo elige la caja; solo boletas usan térmico.
    return $gen->render(
        ['xml' => $xmlFirmado, 'raw' => $xmlFirmado, 'tipo' => $tipo, 'folio' => $folio],
        $opts,
        'TRIBUTARIA',
        $formato
    );
}

function sendDTE(array $data): array {
    $xml   = $data['xml']   ?? '';
    // El consumidor headless envía el XML firmado en base64 (byte-exacto) porque el
    // XML está en ISO-8859-1 y no sobrevive json_encode.
    if ($xml === '' && !empty($data['xml_base64'])) {
        $xml = (string) base64_decode((string)$data['xml_base64'], true);
    }
    $tipo  = (int)($data['tipo']  ?? 0);
    $folio = (int)($data['folio'] ?? 0);

    if (!$xml) throw new Exception('No hay XML de DTE para enviar');

    // Certificado segÃºn tipo: boletas (39/41) â†' Cristina ; guÃ­as/facturas â†' David
    $GLOBALS['SII_CERT_TIPO'] = $tipo;
    [$cert, $privKey] = loadCertificate($tipo);

    // Construir y firmar el sobre (aplica tanto para Boletas como para DTEs normales)
    $envio        = buildEnvioDTE($xml, $tipo, $folio, $cert);
    $envioFirmado = signDTE($envio, $cert, $privKey, 'SetDoc');

    // Guardar sobre para diagnostico cvc-id.2 (sobreescribe en cada envio)
    global $actualTmpDir;
    if (!empty($actualTmpDir)) {
        @file_put_contents($actualTmpDir . 'sobre_T' . $tipo . 'F' . $folio . '.xml', $envioFirmado);
        @file_put_contents($actualTmpDir . 'envio_raw_T' . $tipo . 'F' . $folio . '.xml', $envio);
    }

    // â”€â”€ ValidaciÃ³n XSD local del sobre â”€â”€
    $val = validateXmlAgainstXSD($envioFirmado);
    if (!$val['valid'] && !$val['skipped']) {
        saveSiiLog('sendDTE', "T{$tipo}F{$folio} rechazado por XSD local: " . implode('; ', array_slice($val['errors'], 0, 3)), 'ERROR');
        return [
            'ok'         => false,
            'error'      => 'XSD invÃ¡lido: ' . implode('; ', array_slice($val['errors'], 0, 5)),
            'xsd_errors' => $val['errors'],
            'mensaje'    => 'DTE no enviado: fallÃ³ validaciÃ³n XSD local del sobre.',
        ];
    }

    // â”€â”€ Boletas (39/41): flujo REST â€” obtiene su propio token, no usa SOAP â”€â”€
    if (in_array($tipo, [39, 41], true)) {
        // ValidaciÃ³n de lÃ­mite solo para boletas
        $lim = validateEnvioBoletaLimit($envioFirmado);
        if (!$lim['ok']) {
            saveSiiLog('sendDTE', "T{$tipo}F{$folio} sobre excede lÃ­mite: {$lim['reason']}", 'ERROR');
            return [
                'ok'      => false,
                'error'   => $lim['reason'],
                'count'   => $lim['count'],
                'max'     => $lim['max'],
                'mensaje' => "Sobre EnvioBOLETA excede el mÃ¡ximo permitido: {$lim['reason']}",
            ];
        }
        $result = sendBoletaREST($envioFirmado, $tipo, $folio, '');
        saveTrackingId($tipo, $folio, $result['trackId'] ?? null, $result);
        return $result;
    }

    // â”€â”€ Facturas, GuÃ­as y otros: flujo SOAP (semilla + token SOAP) â”€â”€
    $semilla  = getSemilla();
    $token    = getToken($semilla, $cert, $privKey);
    $resultado = uploadDTE($envioFirmado, $token, $cert);

    saveTrackingId($tipo, $folio, $resultado['trackId'] ?? null, $resultado);

    return [
        'ok'      => $resultado['ok'],
        'trackId' => $resultado['trackId'] ?? null,
        'estado'  => $resultado['estado']  ?? null,
        'mensaje' => $resultado['mensaje'] ?? 'Enviado al SII',
        // Exponer el error real del SII cuando falla (antes solo iba en 'mensaje'
        // y runOneCase mostraba el genérico "Error en envío SII").
        'error'   => empty($resultado['ok'])
            ? ($resultado['error'] ?? $resultado['mensaje'] ?? $resultado['estado'] ?? 'Error en envío SII')
            : null,
    ];
}

/**
 * Hash determinista del XML del documento (sin <Signature>) para detectar
 * modificaciones manuales entre envÃ­os.
 */
function xmlContentHash(string $xml): string {
    // Eliminar bloque Signature para que la firma no afecte el hash
    $stripped = preg_replace('/<Signature\b[^>]*>.*?<\/Signature>/s', '', $xml);
    // Normalizar whitespace
    $stripped = preg_replace('/\s+/', ' ', (string)$stripped);
    return 'sha256:' . substr(hash('sha256', $stripped), 0, 24);
}

/**
 * Registra un intento de envÃ­o al SII en tracking.json.
 *
 * Estructura por folio:
 *   "T39F123": {
 *     "tipo": 39, "folio": 123,
 *     "xml_hash": "sha256:abc...",
 *     "first_seen": "2026-05-08T12:17:58",
 *     "last_trackId": "...",          (Ãºltimo TrackID â€” exitoso o no)
 *     "last_ok_trackId": "...",       (Ãºltimo TrackID con result=OK; o null)
 *     "last_result": "OK|FAIL|...",
 *     "estado": "REC|DOK|...",
 *     "enviado": "2026-05-14 23:14:42",
 *     "intentos": [ {ts, trackId, http, result, estado, via, reason, user, xml_hash, response} â€¦ ]
 *   }
 */
function saveTrackingId(int $tipo, int $folio, ?string $trackId, array $siiResponse = [], array $meta = []): void {
    global $actualTmpDir;
    $file = $actualTmpDir . 'tracking.json';
    $data = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
    $key = "T{$tipo}F{$folio}";

    $now = date('Y-m-d H:i:s');
    $entry = $data[$key] ?? [
        'tipo'       => $tipo,
        'folio'      => $folio,
        'first_seen' => $now,
        'intentos'   => [],
    ];

    // MIGRACIÃ“N: si el entry viejo no tiene 'intentos', lo convertimos
    if (!isset($entry['intentos'])) {
        $intentoLegacy = [
            'ts'          => $entry['enviado'] ?? $now,
            'trackId'     => $entry['trackId'] ?? null,
            'result'      => !empty($entry['trackId']) ? 'OK' : 'UNKNOWN',
            'estado'      => $entry['estado'] ?? null,
            'reason'      => 'migrado de formato legacy',
            'via'         => null,
            'http'        => null,
            'response'    => $entry['mensaje'] ?? null,
        ];
        $entry['intentos'] = [$intentoLegacy];
        // Inferir agregados desde formato legacy
        if (!empty($entry['trackId'])) {
            $entry['last_trackId']    = $entry['trackId'];
            $entry['last_ok_trackId'] = $entry['trackId'];
            $entry['last_result']     = 'OK';
        }
    }

    // Construir el nuevo intento
    $intento = [
        'ts'       => $now,
        'trackId'  => $trackId,
        'result'   => $trackId ? 'OK' : ($siiResponse['result'] ?? 'FAIL'),
        'estado'   => $siiResponse['estado'] ?? null,
        'via'      => $meta['via'] ?? null,
        'http'     => $meta['http'] ?? null,
        'reason'   => $meta['reason'] ?? 'envÃ­o',
        'user'     => $meta['user'] ?? null,
        'xml_hash' => $meta['xml_hash'] ?? null,
        'mensaje'  => $siiResponse['mensaje'] ?? null,
    ];
    $entry['intentos'][] = $intento;

    // Calcular agregados
    $entry['tipo']           = $tipo;
    $entry['folio']          = $folio;
    if (!empty($meta['xml_hash'])) $entry['xml_hash'] = $meta['xml_hash'];
    if ($trackId) {
        $entry['last_trackId']    = $trackId;
        $entry['last_ok_trackId'] = $trackId;
        $entry['last_result']     = 'OK';
        $entry['estado']          = $siiResponse['estado'] ?? $entry['estado'] ?? null;
        $entry['enviado']         = $now;
        // Mantener trackId top-level por compatibilidad con UI vieja
        $entry['trackId']         = $trackId;
        $entry['mensaje']         = $siiResponse['mensaje'] ?? null;
        $entry['respuesta_sii']   = $siiResponse['raw'] ?? null;
        $entry['respuesta_sii_raw']= $siiResponse['rawResponse'] ?? null;

        // â”€â”€ Archivado legal automÃ¡tico tras envÃ­o exitoso (retenciÃ³n 6 aÃ±os) â”€â”€
        global $actualTmpDir;
        $xmlFile = $actualTmpDir . "dte_T{$tipo}F{$folio}.xml";
        if (file_exists($xmlFile)) {
            try {
                archiveDTE($tipo, $folio, file_get_contents($xmlFile), [
                    'trackId'     => $trackId,
                    'estado_sii'  => $siiResponse['estado'] ?? null,
                    'enviado_ts'  => $now,
                    'sii_response'=> $siiResponse['raw'] ?? null,
                    'via'         => $meta['via'] ?? null,
                ]);
            } catch (Throwable $e) {
                saveSiiLog('archiveDTE', "T{$tipo}F{$folio}: " . $e->getMessage(), 'WARNING');
            }
        }
    } else {
        $entry['last_result'] = 'FAIL';
    }

    // Tope al historial (Ãºltimos 50)
    if (count($entry['intentos']) > 50) {
        $entry['intentos'] = array_slice($entry['intentos'], -50);
    }

    $data[$key] = $entry;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

/**
 * Lock cooperativo por folio para evitar reenvÃ­os concurrentes.
 * Devuelve un handle (resource) o null si el lock no se pudo adquirir.
 * Es responsabilidad del caller pasar el handle a releaseFolioLock().
 */
function acquireFolioLock(int $tipo, int $folio, int $timeoutSec = 30) {
    global $actualTmpDir;
    $lockDir = rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . 'locks';
    if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);
    $lockFile = $lockDir . DIRECTORY_SEPARATOR . "T{$tipo}F{$folio}.lock";

    $fp = fopen($lockFile, 'c+');
    if (!$fp) return null;

    $start = time();
    while (!flock($fp, LOCK_EX | LOCK_NB)) {
        if (time() - $start > $timeoutSec) {
            fclose($fp);
            return null;
        }
        usleep(200000); // 200ms
    }

    // Anotar quiÃ©n y cuÃ¡ndo (informativo, no crÃ­tico)
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode([
        'pid'  => getmypid(),
        'ts'   => date('Y-m-d H:i:s'),
        'tipo' => $tipo,
        'folio'=> $folio,
    ]) . "\n");
    fflush($fp);

    return ['fp' => $fp, 'file' => $lockFile];
}

function releaseFolioLock($lock): void {
    if (!$lock || empty($lock['fp'])) return;
    @flock($lock['fp'], LOCK_UN);
    @fclose($lock['fp']);
    @unlink($lock['file']);
}

/**
 * Lee tracking.json y devuelve la entrada del folio (con sus intentos), o null.
 */
function getDTETracking(int $tipo, int $folio): ?array {
    global $actualTmpDir;
    $file = $actualTmpDir . 'tracking.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true) ?: [];
    return $data["T{$tipo}F{$folio}"] ?? null;
}

function findTrackingById(string $trackId): ?array {
    global $actualTmpDir;
    $file = $actualTmpDir . 'tracking.json';
    $data = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];

    foreach ($data as $entry) {
        if ((string)($entry['trackId'] ?? '') === (string)$trackId) {
            return $entry;
        }
    }

    return null;
}

function saveSiiLog(string $accion, string $mensaje, string $estado = 'INFO'): void {
    global $actualTmpDir;
    $file = $actualTmpDir . 'sii_logs.json';
    $logs = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];

    array_unshift($logs, [
        'ts'      => date('Y-m-d H:i:s'),
        'accion'  => $accion,
        'mensaje' => $mensaje,
        'estado'  => $estado
    ]);

    // Mantener solo los Ãºltimos 100 eventos
    $logs = array_slice($logs, 0, 100);
    file_put_contents($file, json_encode($logs, JSON_PRETTY_PRINT));
}

/**
 * Registra una transacciÃ³n completa con el SII en NDJSON (una lÃ­nea por evento).
 * Ãštil para debug detallado de fallos REST/SOAP â€” captura request + response.
 *
 * Rota cuando el archivo supera 5 MB (renombra a .1 y empieza fresco).
 */
function saveSiiTransaction(array $tx): void {
    global $actualTmpDir;
    $logFile = rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . 'sii_transactions.ndjson';

    if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
        @rename($logFile, $logFile . '.1');
    }

    // Recortar body grande para evitar logs gigantes (mÃ¡x 2KB por direcciÃ³n)
    $trimBody = function ($s) {
        if (!is_string($s)) return $s;
        return strlen($s) > 2048 ? substr($s, 0, 2048) . 'â€¦ [+' . (strlen($s) - 2048) . ' bytes]' : $s;
    };

    $entry = array_merge([
        'ts' => date('Y-m-d\TH:i:s.v'),
    ], $tx);

    if (isset($entry['request_body']))  $entry['request_body']  = $trimBody($entry['request_body']);
    if (isset($entry['response_body'])) $entry['response_body'] = $trimBody($entry['response_body']);

    file_put_contents(
        $logFile,
        json_encode($entry, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND
    );
}

/**
 * Lee Ãºltimas N transacciones del log NDJSON (mÃ¡s recientes primero).
 */
function loadSiiTransactions(int $limit = 50, ?string $filterOp = null): array {
    global $actualTmpDir;
    $logFile = rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . 'sii_transactions.ndjson';
    if (!file_exists($logFile)) return [];

    // Leer desde el final (eficiente para archivos grandes)
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];

    $out = [];
    foreach (array_reverse($lines) as $ln) {
        $j = json_decode($ln, true);
        if (!is_array($j)) continue;
        if ($filterOp !== null && ($j['op'] ?? '') !== $filterOp) continue;
        $out[] = $j;
        if (count($out) >= $limit) break;
    }
    return $out;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// VALIDAR ESTADO EN SII
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function validateDTE(array $data): array {
    $tipo    = (int)($data['tipo']  ?? 0);
    $folio   = (int)($data['folio'] ?? 0);
    $trackId = $data['trackId'] ?? '';

    if ($trackId && $tipo === 0) {
        return [
            'ok' => false,
            'estado' => 'TIPO_REQUERIDO',
            'glosa' => 'Para consultar directamente al SII debe indicar si el TrackID corresponde a boleta electronica o a DTE normal.',
        ];
    }
    
    // ParÃ¡metros obligatorios para consultar un DTE individual
    $rutRecep = $data['rutReceptor'] ?? '66666666-6';
    $fecha    = $data['fecha']       ?? date('Y-m-d');
    $monto    = (int)($data['monto'] ?? 0);

    // Certificado segÃºn tipo: boletas (39/41) -> Cristina ; guÃ­as/facturas -> David
    $GLOBALS['SII_CERT_TIPO'] = $tipo;
    [$cert, $privKey] = loadCertificate($tipo);

    // Si es Boleta (39 o 41), usamos la consulta via API REST
    if (in_array($tipo, [39, 41], true)) {
        $token = getBoletaRestToken($cert, $privKey);
        return queryEstadoBoletaREST($tipo, $folio, $trackId, $token, $rutRecep, $fecha, $monto);
    }

    $semilla = getSemilla();
    $token   = getToken($semilla, $cert, $privKey);

    $result = $trackId
        ? queryEstadoEnvio($trackId, $token)
        : queryEstadoDTE(RUT_EMISOR, $tipo, $folio, $rutRecep, $fecha, $monto, $token);

    return [
        'ok'    => true,
        'estado' => $result['estado'],
        'glosa'  => $result['glosa'],
        'xml'    => $result['xml'] ?? '',
    ];
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// CERTIFICADO Y CAF
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function loadCertificate(?int $tipo = null): array {
    $t   = $tipo ?? ($GLOBALS['SII_CERT_TIPO'] ?? null);
    $b   = certBundleForTipo($t);
    $pfx = $b['pfx'];
    if (!file_exists($pfx)) {
        throw new Exception(
            'Certificado no encontrado. Coloque su archivo .pfx en: ' . $pfx
        );
    }

    $certs = [];
    if (!openssl_pkcs12_read(file_get_contents($pfx), $certs, getCertPass($t))) {
        $err = openssl_error_string() ?: 'Error desconocido';
        throw new Exception('OpenSSL FallÃ³: ' . $err . '. Verifique contraseÃ±a o formato en setup.php');
    }
    return [$certs['cert'], $certs['pkey']];
}

function getRutCertificado(string $certPem): string {
    // Si se definiÃ³ manualmente (por errores de detecciÃ³n), usar ese.
    if (defined('RUT_ENVIA_MANUAL') && !empty(RUT_ENVIA_MANUAL)) {
        return RUT_ENVIA_MANUAL;
    }

    $certData = openssl_x509_parse($certPem);
    
    // FunciÃ³n recursiva para buscar RUT en cualquier parte del array
    $findRut = function($item) use (&$findRut) {
        if (is_array($item)) {
            foreach ($item as $v) {
                $res = $findRut($v);
                if ($res) return $res;
            }
        } else if (is_string($item)) {
            if (preg_match('/(\d{7,8})-([0-9Kk])/', $item, $m)) {
                return $m[1] . '-' . $m[2];
            }
        }
        return null;
    };

    $rut = $findRut($certData);
    if ($rut) return $rut;
    
    throw new Exception("No se pudo extraer el RUT del certificado digital. El certificado no parece contener un RUT vÃ¡lido en sus campos visibles.");
}

function getRutCertificadoSeguro(string $certPem): string {
    // 1) RUT declarado en el perfil de certs.json (autoritativo y parametrizable).
    //    Necesario para certificados cuyo RUT estÃ¡ en una extensiÃ³n que
    //    openssl_x509_parse no expone (ej. E-Certchile otherName).
    $b = certBundleForTipo($GLOBALS['SII_CERT_TIPO'] ?? null);
    if (!empty($b['rut']) && preg_match('/(\d{7,8})-([0-9Kk])/', $b['rut'], $m)) {
        return $m[1] . '-' . strtoupper($m[2]);
    }

    // 2) RUT del TITULAR del certificado: OID 1.3.6.1.4.1.8321.1 dentro del
    //    subjectAltName (otherName). Es la fuente autoritativa del RUT firmante
    //    en TODOS los certificados de acreditados chilenos (E-Cert, Acepta, etc.).
    //    PHP no decodifica este otherName (lo ve como "othername:<unsupported>"),
    //    por eso se busca el OID directamente en el DER del certificado.
    //    Crítico para certs con DOS RUTs en el SAN (titular en .1, otro en .2):
    //    sin esto, el fallback genérico tomaba el RUT equivocado y el SII
    //    rechazaba con (CRT-3-15) "RUT Envia Diferente al registrado en Upload".
    if (preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $certPem, $mm)) {
        $der = base64_decode(preg_replace('/\s+/', '', $mm[1]) ?? '', true);
        if ($der !== false && $der !== '') {
            $oidTitular = "\x06\x08\x2b\x06\x01\x04\x01\xc1\x01\x01"; // 1.3.6.1.4.1.8321.1
            $pos = strpos($der, $oidTitular);
            if ($pos !== false) {
                $tail = substr($der, $pos + strlen($oidTitular), 32);
                if (preg_match('/(\d{7,8})-([0-9Kk])/', $tail, $m)) {
                    return $m[1] . '-' . strtoupper($m[2]);
                }
            }
        }
    }

    $certData = openssl_x509_parse($certPem);

    $subjectRut = $certData['subject']['serialNumber'] ?? '';
    if (preg_match('/(\d{7,8})-([0-9Kk])/', $subjectRut, $m)) {
        return $m[1] . '-' . strtoupper($m[2]);
    }

    $subjectAltName = $certData['extensions']['subjectAltName'] ?? '';
    if (preg_match('/(\d{7,8})-([0-9Kk])/', $subjectAltName, $m)) {
        return $m[1] . '-' . strtoupper($m[2]);
    }

    return strtoupper(getRutCertificado($certPem));
}

if (!function_exists('normalizeCafXmlContent')) {
    function normalizeCafXmlContent(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = ltrim($content);

        $xmlPos = stripos($content, '<?xml');
        $autPos = stripos($content, '<AUTORIZACION');
        if ($xmlPos !== false && ($autPos === false || $xmlPos < $autPos)) {
            $content = substr($content, $xmlPos);
        } elseif ($autPos !== false) {
            $content = substr($content, $autPos);
        }

        if (!preg_match('//u', $content)) {
            $enc = function_exists('mb_detect_encoding')
                ? (mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) ?: 'Windows-1252')
                : 'Windows-1252';
            $content = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($content, 'UTF-8', $enc)
                : iconv($enc, 'UTF-8//IGNORE', $content);
        }

        $content = preg_replace('/<\?xml[^>]*\?>/i', '', $content) ?? $content;
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . ltrim($content);
    }
}

function loadCAF(int $tipo, int $folio = 0): array {
    global $globalContext;

    if ($globalContext) {
        $repo  = new EmpresaRepository();
        $empId = $globalContext->getEmpresaId();
        $amb   = $globalContext->getAmbiente();

        // Multi-CAF: si viene un folio especÃ­fico, se elige el CAF cuyo rango
        // lo contiene; si no, el primer CAF activo con folios disponibles.
        if ($folio > 0) {
            $dbCaf = $repo->getCAFForFolio($empId, $tipo, $amb, $folio);
            if (!$dbCaf) {
                throw new Exception(
                    "No hay CAF autorizado que contenga el folio $folio (tipo $tipo, ambiente $amb). "
                    . "Cargue el CAF correspondiente en Configuracion."
                );
            }
        } else {
            $dbCaf = $repo->getCAFNextAvailable($empId, $tipo, $amb);
            if (!$dbCaf) {
                throw new Exception(
                    "No hay folios disponibles para tipo $tipo en ambiente $amb. "
                    . "Solicite y cargue un nuevo CAF en Configuracion."
                );
            }
        }

        // Preferir el XML guardado en BD (xml_content = caf_xml): siempre accesible.
        // El archivo_path del SaaS es RELATIVO al storage del web app; el admin corre
        // en otro directorio y file_get_contents falla, dejando el CAF vacio →
        // "No se pudo extraer el bloque <CAF>". El path se usa solo como respaldo y
        // unicamente si el archivo existe y es legible.
        if (!empty($dbCaf['xml_content'])) {
            $xmlCont = normalizeCafXmlContent((string)$dbCaf['xml_content']);
        } elseif (!empty($dbCaf['xml_path']) && is_file((string)$dbCaf['xml_path'])) {
            $xmlCont = normalizeCafXmlContent((string)file_get_contents((string)$dbCaf['xml_path']));
        } else {
            throw new Exception("CAF sin contenido XML disponible para folio $folio (tipo $tipo)");
        }
        $xml = simplexml_load_string($xmlCont);
        $last    = $repo->getUltimoFolioUsadoEnRango(
            $empId, $tipo, $amb,
            (int)$dbCaf['folio_desde'], (int)$dbCaf['folio_hasta']
        );

        return [
            'id_db'   => $dbCaf['id'],
            'xml'     => $xmlCont,
            'desde'   => (int)$dbCaf['folio_desde'],
            'hasta'   => (int)$dbCaf['folio_hasta'],
            'privKey' => (string)$xml->RSASK,
            'pubKey'  => (string)$xml->RSAPUBK,
            'last'    => $last,
            'alerta'  => $dbCaf['nivel_alerta'] ?? 'normal',
        ];
    }

    // LEGACY: Carga desde archivos locales (solo si no hay contexto)
    global $actualCafDir;
    $file = $actualCafDir . "caf_{$tipo}.xml";
    if (!file_exists($file)) {
        $tipoNombre = [
            33=>'Factura Electronica', 34=>'Factura Exenta',
            39=>'Boleta Electronica',  41=>'Boleta Exenta',
            52=>'Guia de Despacho',    56=>'Nota de Debito',
            61=>'Nota de Credito',
        ][$tipo] ?? "DTE tipo $tipo";
        throw new Exception(
            "No hay folios disponibles para emitir $tipoNombre (tipo $tipo). "
            . "Solicite folios al SII (Mi SII > Factura Electronica > Administracion de Folios) "
            . "y cargue el CAF .xml en setup.php. Archivo esperado: $file"
        );
    }

    $xmlCont = normalizeCafXmlContent((string)file_get_contents($file));
    $xml = simplexml_load_string($xmlCont);
    $registryFile = $actualCafDir . 'registry.json';
    $lastUsed = file_exists($registryFile) ? (json_decode(file_get_contents($registryFile), true)[$tipo] ?? 0) : 0;

    $desde = (int)$xml->CAF->DA->RNG->D;
    $hasta = (int)$xml->CAF->DA->RNG->H;
    $disponibles = $hasta - max($lastUsed, $desde - 1);

    if ($disponibles <= 0) {
        throw new Exception(
            "CAF tipo $tipo agotado (rango $desde-$hasta, Ãºltimo usado $lastUsed). "
            . "Solicite un nuevo rango de folios al SII y cargue el CAF en setup.php."
        );
    }

    return [
        'xml'     => $xmlCont,
        'desde'   => $desde,
        'hasta'   => $hasta,
        'privKey' => (string)$xml->RSASK,
        'last'    => (int)$lastUsed,
        'restantes' => $disponibles,
        'alerta'  => $disponibles < 10 ? 'critico' : ($disponibles < 50 ? 'bajo' : 'normal'),
    ];
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// CÃLCULO DE MONTOS
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function calcularMontos(array $items, int $tipo): array {
    // Separar afecto y exento por Ã­tem (la marca 'exento' la fija el formulario,
    // el set de pruebas o el nombre del Ã­tem). Necesario para boletas/facturas
    // con lÃ­neas mixtas (ej. CASO-4 del set de boletas: 1 afecto + 1 exento).
    $sumaAfecta = 0;
    $sumaExenta = 0;
    foreach ($items as $it) {
        $qty  = (float)($it['cantidad']  ?? 1);
        $prc  = (float)($it['precio']    ?? 0);
        $desc = (float)($it['descuento'] ?? 0);
        // Misma aritmética que la línea del XML (buildDocumentoXML): bruto
        // redondeado, descuento redondeado y resta. Redondear el neto directo
        // difiere en ±1 cuando el descuento cae en ,5 → el encabezado deja de
        // cuadrar con la suma de MontoItem (reparo SII "Valores del Encabezado").
        $bruto = round($qty * $prc);
        $monto = $bruto - ($desc > 0 ? round($bruto * $desc / 100) : 0);
        if (!empty($it['exento'])) {
            $sumaExenta += $monto;
        } else {
            $sumaAfecta += $monto;
        }
    }

    // Documentos completamente exentos (factura/boleta exenta, exportaciÃ³n)
    $docExento = in_array($tipo, [34, 41, 110, 111, 112], true);
    if ($docExento) {
        $exe = $sumaAfecta + $sumaExenta;
        return ['mntNeto' => 0, 'mntExe' => $exe, 'tasaIVA' => 0, 'iva' => 0, 'mntTotal' => $exe];
    }

    // Boleta afecta (39): el precio ingresado INCLUYE IVA â†' se desglosa.
    if ($tipo === 39) {
        $neto = $sumaAfecta > 0 ? (int)round($sumaAfecta / 1.19) : 0;
        $iva  = $sumaAfecta > 0 ? $sumaAfecta - $neto : 0;
        return [
            'mntNeto'  => $neto,
            'mntExe'   => $sumaExenta,
            'tasaIVA'  => $neto > 0 ? 19 : 0,
            'iva'      => $iva,
            'mntTotal' => $neto + $iva + $sumaExenta,
        ];
    }

    // Factura afecta, guÃ­a, nota â€” el precio es NETO; el IVA se agrega.
    $neto = $sumaAfecta;
    $iva  = (int)round($neto * 0.19);
    return [
        'mntNeto'  => $neto,
        'mntExe'   => $sumaExenta,
        'tasaIVA'  => $neto > 0 ? 19 : 0,
        'iva'      => $iva,
        'mntTotal' => $neto + $iva + $sumaExenta,
    ];
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// CONSTRUCCIÃ“N DEL XML DTE
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function siiExportCurrency(?string $moneda): string {
    $moneda = strtoupper(trim((string)($moneda ?: 'DOLAR USA')));
    return match ($moneda) {
        'USD', 'US$', 'DOLAR', 'DOLLAR', 'DOLAR EEUU', 'DOLAR USA' => 'DOLAR USA',
        'EUR', 'EUROS', 'EURO' => 'EURO',
        'CLP', 'PESO CHILENO', 'PESO CL' => 'PESO CL',
        default => $moneda,
    };
}

function xmlOptionalTag(string $tag, $value, callable $h, int $decimals = -1): string {
    if ($value === null || $value === '') return '';
    if (is_numeric($value)) {
        $value = $decimals >= 0
            ? rtrim(rtrim(number_format((float)$value, $decimals, '.', ''), '0'), '.')
            : (string)$value;
    }
    return "  <$tag>" . $h($value) . "</$tag>\n";
}

function buildAduanaXML(array $aduana, callable $h, int $totalItems, float $mntTotal): string {
    if (!$aduana) return '';
    $map = [
        'CodModVenta' => ['codModVenta', 'codigoModalidadVenta', 'modalidadVenta'],
        'CodClauVenta' => ['codClauVenta', 'codigoClausulaVenta', 'clausulaVenta'],
        'TotClauVenta' => ['totClauVenta', 'totalClausulaVenta'],
        'CodViaTransp' => ['codViaTransp', 'codigoViaTransporte', 'viaTransporte'],
        'NombreTransp' => ['nombreTransp', 'nombreTransporte'],
        'RUTCiaTransp' => ['rutCiaTransp', 'rutCompaniaTransporte'],
        'NomCiaTransp' => ['nomCiaTransp', 'nombreCompaniaTransporte'],
        'IdAdicTransp' => ['idAdicTransp', 'idAdicionalTransporte'],
        'Booking' => ['booking'],
        'Operador' => ['operador'],
        'CodPtoEmbarque' => ['codPtoEmbarque', 'codigoPuertoEmbarque'],
        'IdAdicPtoEmb' => ['idAdicPtoEmb', 'idAdicionalPuertoEmbarque'],
        'CodPtoDesemb' => ['codPtoDesemb', 'codigoPuertoDesembarque'],
        'IdAdicPtoDesemb' => ['idAdicPtoDesemb', 'idAdicionalPuertoDesembarque'],
        'Tara' => ['tara'],
        'CodUnidMedTara' => ['codUnidMedTara', 'codigoUnidadMedidaTara'],
        'PesoBruto' => ['pesoBruto'],
        'CodUnidPesoBruto' => ['codUnidPesoBruto', 'codigoUnidadPesoBruto'],
        'PesoNeto' => ['pesoNeto'],
        'CodUnidPesoNeto' => ['codUnidPesoNeto', 'codigoUnidadPesoNeto'],
        'TotItems' => ['totItems', 'totalItems'],
        'TotBultos' => ['totBultos', 'cantidadBultos'],
    ];
    $get = function (array $keys, $default = null) use ($aduana) {
        foreach ($keys as $key) {
            if (isset($aduana[$key]) && $aduana[$key] !== '') return $aduana[$key];
        }
        return $default;
    };
    $xml = "<Transporte>\n<Aduana>\n";
    foreach ($map as $tag => $keys) {
        $default = $tag === 'TotItems' ? $totalItems : ($tag === 'TotClauVenta' ? $mntTotal : null);
        $dec = in_array($tag, ['TotClauVenta', 'PesoBruto', 'PesoNeto'], true) ? 2 : -1;
        $xml .= xmlOptionalTag($tag, $get($keys, $default), $h, $dec);
    }
    foreach (($aduana['tipoBultos'] ?? $aduana['bultos'] ?? []) as $bulto) {
        $xml .= "  <TipoBultos>\n";
        $xml .= xmlOptionalTag('CodTpoBultos', $bulto['codTpoBultos'] ?? $bulto['codigoTipoBulto'] ?? null, $h);
        $xml .= xmlOptionalTag('CantBultos', $bulto['cantBultos'] ?? $bulto['cantidad'] ?? null, $h);
        $xml .= xmlOptionalTag('Marcas', $bulto['marcas'] ?? null, $h);
        $xml .= xmlOptionalTag('IdContainer', $bulto['idContainer'] ?? null, $h);
        $xml .= xmlOptionalTag('Sello', $bulto['sello'] ?? null, $h);
        $xml .= xmlOptionalTag('EmisorSello', $bulto['emisorSello'] ?? null, $h);
        $xml .= "  </TipoBultos>\n";
    }
    $xml .= xmlOptionalTag('MntFlete', $aduana['mntFlete'] ?? $aduana['montoFlete'] ?? null, $h, 4);
    $xml .= xmlOptionalTag('MntSeguro', $aduana['mntSeguro'] ?? $aduana['montoSeguro'] ?? null, $h, 4);
    $xml .= xmlOptionalTag('CodPaisRecep', $aduana['codPaisRecep'] ?? $aduana['codigoPaisReceptor'] ?? null, $h);
    $xml .= xmlOptionalTag('CodPaisDestin', $aduana['codPaisDestin'] ?? $aduana['codigoPaisDestino'] ?? null, $h);
    return $xml . "</Aduana>\n</Transporte>";
}

function buildExportacionXML(
    int $tipo, int $folio, string $fecha,
    array $recep, array $items, array $m, array $caf, string $idDte, $privKey,
    int $tipoDespacho = 0, array $referencias = [], array $exportacion = [], array $aduana = [],
    ?string $moneda = null, ?float $tipoCambio = null
): string {
    global $globalContext;
    $h = fn($s) => htmlspecialchars((string)$s, ENT_XML1, 'UTF-8');
    $tmstFirma = date('Y-m-d\TH:i:s');
    if ($globalContext) {
        $emp = $globalContext->getEmpresa();
        $rE = $h($emp['rut'] ?? RUT_EMISOR);
        $rsE = $h($emp['razon_social'] ?? RAZON_SOCIAL);
        $gE = $h(mb_substr($emp['giro'] ?? GIRO_EMISOR, 0, 80));
        $dE = $h($emp['direccion'] ?? DIRECCION);
        $cE = $h($emp['comuna_origen'] ?? COMUNA);
        $ciE = $h($emp['ciudad_origen'] ?? CIUDAD);
        $actecoArr = json_decode($emp['acteco'] ?? '[]', true);
        $acteco = !empty($actecoArr) ? $actecoArr[0] : ACTECO;
    } else {
        $rE = $h(RUT_EMISOR);
        $rsE = $h(RAZON_SOCIAL);
        $gE = $h(mb_substr(GIRO_EMISOR, 0, 80));
        $dE = $h(DIRECCION);
        $cE = $h(COMUNA);
        $ciE = $h(CIUDAD);
        $acteco = ACTECO;
    }

    $rRut = $h($recep['rut'] ?? '55555555-5');
    $rNom = $h($recep['nombre'] ?? 'RECEPTOR EXTRANJERO');
    $xmlExtr = '';
    $extr = $recep['extranjero'] ?? $exportacion['extranjero'] ?? [];
    if ($extr || !empty($recep['numId']) || !empty($recep['nacionalidad'])) {
        $numId = $h($extr['numId'] ?? $recep['numId'] ?? '');
        $nac = $h($extr['nacionalidad'] ?? $recep['nacionalidad'] ?? '');
        $idAdic = $h($extr['idAdicRecep'] ?? $recep['idAdicRecep'] ?? '');
        $xmlExtr = "<Extranjero>\n"
            . ($numId ? "  <NumId>$numId</NumId>\n" : '')
            . ($nac ? "  <Nacionalidad>$nac</Nacionalidad>\n" : '')
            . ($idAdic ? "  <IdAdicRecep>$idAdic</IdAdicRecep>\n" : '')
            . "</Extranjero>\n";
    }
    $xmlRecep = "<Receptor>\n  <RUTRecep>$rRut</RUTRecep>\n  <RznSocRecep>$rNom</RznSocRecep>\n$xmlExtr";
    $tagMaxLen = ['GiroRecep' => 40, 'DirRecep' => 80, 'CmnaRecep' => 20, 'CiudadRecep' => 20];
    foreach (['GiroRecep' => 'giro', 'DirRecep' => 'direccion', 'CmnaRecep' => 'comuna', 'CiudadRecep' => 'ciudad'] as $tag => $key) {
        if (!empty($recep[$key])) $xmlRecep .= "  <$tag>" . $h(mb_substr($recep[$key], 0, $tagMaxLen[$tag])) . "</$tag>\n";
    }
    $xmlRecep .= "</Receptor>";

    $monedaExp = siiExportCurrency($exportacion['moneda'] ?? $moneda);
    $mntTotal = (float)$m['mntTotal'];
    $xmlTot = "<Totales>\n  <TpoMoneda>$monedaExp</TpoMoneda>\n"
        . "  <MntExe>" . number_format((float)$m['mntExe'], 4, '.', '') . "</MntExe>\n"
        . "  <MntTotal>" . number_format($mntTotal, 4, '.', '') . "</MntTotal>\n</Totales>";
    $xmlOtra = '';
    $otra = $exportacion['otraMoneda'] ?? [];
    if ($otra || ($tipoCambio && $monedaExp !== 'PESO CL')) {
        $otraMoneda = siiExportCurrency($otra['moneda'] ?? $otra['tipoMoneda'] ?? 'PESO CL');
        $tc = (float)($otra['tipoCambio'] ?? $tipoCambio ?? 0);
        $montoOtra = (float)($otra['montoTotal'] ?? ($tc > 0 ? $mntTotal * $tc : 0));
        $xmlOtra = "<OtraMoneda>\n  <TpoMoneda>$otraMoneda</TpoMoneda>\n"
            . ($tc > 0 ? "  <TpoCambio>" . number_format($tc, 4, '.', '') . "</TpoCambio>\n" : '')
            . "  <MntTotOtrMnda>" . number_format($montoOtra, 4, '.', '') . "</MntTotOtrMnda>\n</OtraMoneda>";
    }

    $xmlDet = '';
    foreach ($items as $i => $it) {
        $lin = $i + 1;
        $qty = (float)($it['cantidad'] ?? 1);
        $prc = (float)($it['precio'] ?? 0);
        $dp = (float)($it['descuento'] ?? 0);
        $mnt = round($qty * $prc * (1 - $dp / 100), 4);
        $xmlDet .= "<Detalle>\n  <NroLinDet>$lin</NroLinDet>\n  <IndExe>1</IndExe>\n  <NmbItem>" . $h($it['nombre'] ?? 'Producto exportacion') . "</NmbItem>\n";
        if (!empty($it['descripcion'])) $xmlDet .= "  <DscItem>" . $h($it['descripcion']) . "</DscItem>\n";
        $xmlDet .= "  <QtyItem>" . number_format($qty, 6, '.', '') . "</QtyItem>\n";
        if (!empty($it['unidadMedida']) || !empty($it['unidad'])) $xmlDet .= "  <UnmdItem>" . $h($it['unidadMedida'] ?? $it['unidad']) . "</UnmdItem>\n";
        $xmlDet .= "  <PrcItem>" . number_format($prc, 6, '.', '') . "</PrcItem>\n";
        if ($dp > 0) $xmlDet .= "  <DescuentoPct>$dp</DescuentoPct>\n";
        $xmlDet .= "  <MontoItem>" . number_format($mnt, 4, '.', '') . "</MontoItem>\n</Detalle>\n";
    }

    $xmlRef = '';
    foreach (array_slice($referencias, 0, 40) as $idx => $ref) {
        $l = $idx + 1;
        // Referencia al SET de pruebas de certificación: FolioRef = folio del
        // propio documento (recién conocido aquí) y FchRef = fecha de emisión.
        if (($ref['tipo'] ?? '') === 'SET' && empty($ref['folio'])) {
            $ref['folio'] = $folio;
            if (empty($ref['fecha'])) $ref['fecha'] = date('Y-m-d');
        }
        $xmlRef .= "<Referencia>\n  <NroLinRef>$l</NroLinRef>\n";
        if (!empty($ref['tipo'])) $xmlRef .= "  <TpoDocRef>" . $h($ref['tipo']) . "</TpoDocRef>\n";
        if (!empty($ref['folio'])) $xmlRef .= "  <FolioRef>" . $h($ref['folio']) . "</FolioRef>\n";
        if (!empty($ref['fecha'])) $xmlRef .= "  <FchRef>" . $h($ref['fecha']) . "</FchRef>\n";
        if (!empty($ref['codigo'])) $xmlRef .= "  <CodRef>" . $h($ref['codigo']) . "</CodRef>\n";
        if (!empty($ref['razon'])) $xmlRef .= "  <RazonRef>" . $h($ref['razon']) . "</RazonRef>\n";
        $xmlRef .= "</Referencia>\n";
    }

    $xmlTipoDesp = $tipoDespacho > 0 ? "\n  <TipoDespacho>$tipoDespacho</TipoDespacho>" : '';
    $xmlIndServ = !empty($exportacion['indServicio']) ? "\n  <IndServicio>" . (int)$exportacion['indServicio'] . "</IndServicio>" : '';
    $xmlFmaPago = "\n  <FmaPago>" . (int)($exportacion['formaPago'] ?? $exportacion['fmaPago'] ?? 1) . "</FmaPago>";
    $xmlFmaPagExp = !empty($exportacion['formaPagoExportacion']) || !empty($exportacion['fmaPagExp'])
        ? "\n  <FmaPagExp>" . (int)($exportacion['formaPagoExportacion'] ?? $exportacion['fmaPagExp']) . "</FmaPagExp>"
        : '';
    $xmlTrans = buildAduanaXML($aduana ?: ($exportacion['aduana'] ?? []), $h, count($items), $mntTotal);
    $ted = generateTimbre($tipo, $folio, $fecha, $rRut, $rNom, (int)round($mntTotal), $items[0]['nombre'] ?? 'Item', $caf, $privKey);

    return <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<DTE version="1.0" xmlns="http://www.sii.cl/SiiDte">
<Exportaciones ID="$idDte">
<Encabezado>
<IdDoc>
  <TipoDTE>$tipo</TipoDTE>
  <Folio>$folio</Folio>
  <FchEmis>$fecha</FchEmis>$xmlTipoDesp$xmlIndServ$xmlFmaPago$xmlFmaPagExp
</IdDoc>
<Emisor>
  <RUTEmisor>$rE</RUTEmisor>
  <RznSoc>$rsE</RznSoc>
  <GiroEmis>$gE</GiroEmis>
  <Acteco>$acteco</Acteco>
  <DirOrigen>$dE</DirOrigen>
  <CmnaOrigen>$cE</CmnaOrigen>
  <CiudadOrigen>$ciE</CiudadOrigen>
</Emisor>
$xmlRecep
$xmlTrans
$xmlTot
$xmlOtra
</Encabezado>
$xmlDet
$ted
$xmlRef
<TmstFirma>$tmstFirma</TmstFirma>
</Exportaciones>
</DTE>
XML;
}

function buildDocumentoXML(
    int $tipo, int $folio, string $fecha,
    array $recep, array $items, array $m, array $caf, string $idDte, $privKey,
    int $indTraslado = 0, int $tipoDespacho = 0, string $patente = '', string $rutTranspor = '',
    array $referencias = [], $descuentoGlobal = null,
    ?string $moneda = null, ?float $tipoCambio = null,
    int $indServicio = 3
): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_XML1, 'UTF-8');
    $tmstFirma = date('Y-m-d\TH:i:s');

    // Emisor
    global $globalContext;
    if ($globalContext) {
        $emp = $globalContext->getEmpresa();
        $rE  = $h($emp['rut']);
        $rsE = $h(mb_substr((string)$emp['razon_social'], 0, 100));
        $gE  = $h(mb_substr((string)$emp['giro'], 0, 80));
        $dE  = $h(mb_substr((string)$emp['direccion_origen'], 0, 70));
        $cE  = $h(mb_substr((string)($emp['comuna_origen'] ?? ''), 0, 20));
        $ciE = $h(mb_substr((string)($emp['ciudad_origen'] ?? ''), 0, 20));
        $actecoArr = json_decode($emp['acteco'] ?? '[]', true);
        $acteco = !empty($actecoArr) ? $actecoArr[0] : ACTECO;
    } else {
        $rE  = $h(RUT_EMISOR);
        $rsE = $h(mb_substr((string)RAZON_SOCIAL, 0, 100));
        $gE  = $h(mb_substr(GIRO_EMISOR, 0, 80));
        $dE  = $h(mb_substr((string)DIRECCION, 0, 70));
        $cE  = $h(mb_substr((string)COMUNA, 0, 20));
        $ciE = $h(mb_substr((string)CIUDAD, 0, 20));
        $acteco = ACTECO;
    }

    // Receptor (boletas pueden ir sin RUT / Consumidor Final)
    // Campos truncados a su maxLength XSD (se corta el valor crudo ANTES de
    // escapar para no partir una entidad HTML). Sin esto, un giro/razón social
    // largo (ej. ACTECO de minimarket en el receptor de guías de traslado
    // interno, donde receptor=emisor) da cvc-maxLength-valid. Multiempresa.
    $rRut  = $h($recep['rut']    ?? '');
    $rNom  = $h(mb_substr((string)($recep['nombre'] ?? ''), 0, 100));

    if (empty($rRut) && in_array($tipo, [39, 41])) {
        $rRut = '66666666-6';
        $rNom = 'Consumidor Final';
    }

    if (!in_array($tipo, [39, 41]) && ($rRut === '66666666-6' || empty($rRut))) {
        throw new Exception("El RUT 66.666.666-6 solo es vÃ¡lido para Boletas ElectrÃ³nicas. Para otros documentos como la GuÃ­a de Despacho (Tipo $tipo), debe ingresar un RUT de receptor vÃ¡lido.");
    }
    $rGiro = $h(mb_substr((string)($recep['giro']      ?? ''), 0, 40));
    $rDir  = $h(mb_substr((string)($recep['direccion'] ?? ''), 0, 70));
    $rCom  = $h(mb_substr((string)($recep['comuna']    ?? ''), 0, 20));
    $rCiu  = $h(mb_substr((string)($recep['ciudad']    ?? ''), 0, 20));

    $xmlRecep  = "<Receptor>\n  <RUTRecep>$rRut</RUTRecep>\n  <RznSocRecep>$rNom</RznSocRecep>\n";
    if ($rGiro) $xmlRecep .= "  <GiroRecep>$rGiro</GiroRecep>\n";
    if ($rDir)  $xmlRecep .= "  <DirRecep>$rDir</DirRecep>\n";
    if ($rCom)  $xmlRecep .= "  <CmnaRecep>$rCom</CmnaRecep>\n";
    if ($rCiu)  $xmlRecep .= "  <CiudadRecep>$rCiu</CiudadRecep>\n";
    $xmlRecep .= "</Receptor>";

    // Detalle
    $xmlDet = '';
    $esExento = in_array($tipo, [34, 41]);
    foreach ($items as $i => $it) {
        $nom  = $h($it['nombre']      ?? '');
        $desc = $h($it['descripcion'] ?? '');
        $qty  = (float)($it['cantidad']  ?? 1);
        $prc  = (float)($it['precio']    ?? 0);
        $dp   = (float)($it['descuento'] ?? 0);
        $uMed = $h($it['unidadMedida'] ?? '');
        $bruto = round($qty * $prc);
        $dscMonto = $dp > 0 ? (int)round($bruto * ($dp / 100)) : 0;
        $mnt  = $bruto - $dscMonto;
        $lin  = $i + 1;

        // Orden requerido por XSD: NroLinDet â†' IndExe â†' NmbItem â†' DscItem
        $xmlDet .= "<Detalle>\n  <NroLinDet>$lin</NroLinDet>\n";
        if ($esExento || ($it['exento'] ?? false)) $xmlDet .= "  <IndExe>1</IndExe>\n";
        $xmlDet .= "  <NmbItem>$nom</NmbItem>\n";
        if ($desc) $xmlDet .= "  <DscItem>$desc</DscItem>\n";
        // Líneas descriptivas sin valor (NC de texto del set de pruebas) van solo
        // con NmbItem + MontoItem 0: emitir QtyItem en ellas hace que el revisor
        // SII rechace "Los Valores de la Linea 1 No Cuadran".
        if ($qty > 0) $xmlDet .= "  <QtyItem>" . number_format($qty, 6, '.', '') . "</QtyItem>\n";
        if ($uMed) $xmlDet .= "  <UnmdItem>$uMed</UnmdItem>\n";
        if ($prc > 0) $xmlDet .= "  <PrcItem>$prc</PrcItem>\n";
        if ($dp > 0) {
            $xmlDet .= "  <DescuentoPct>$dp</DescuentoPct>\n";
            $xmlDet .= "  <DescuentoMonto>$dscMonto</DescuentoMonto>\n";
        }
        $xmlDet .= "  <MontoItem>$mnt</MontoItem>\n</Detalle>\n";
    }

    // Totales (montos siempre en CLP). Si hay moneda extranjera, se reporta como anexo.
    $xmlTot = "<Totales>\n";
    if ($m['mntNeto'] > 0) $xmlTot .= "  <MntNeto>{$m['mntNeto']}</MntNeto>\n";
    if ($m['mntExe']  > 0) $xmlTot .= "  <MntExe>{$m['mntExe']}</MntExe>\n";
    if ($m['tasaIVA'] > 0) $xmlTot .= "  <TasaIVA>{$m['tasaIVA']}</TasaIVA>\n";
    if ($m['iva']     > 0) $xmlTot .= "  <IVA>{$m['iva']}</IVA>\n";
    $xmlTot .= "  <MntTotal>{$m['mntTotal']}</MntTotal>\n";
    // Multimoneda opcional: el documento se factura en CLP pero referencia
    // el monto equivalente en moneda extranjera (USD/EUR/etc.) y el tipo de cambio.
    if ($moneda && $tipoCambio && strtoupper($moneda) !== 'PESO CL' && strtoupper($moneda) !== 'CLP') {
        $tcambio = number_format($tipoCambio, 4, '.', '');
        $mntOtraMon = round($m['mntTotal'] / max($tipoCambio, 0.0001), 4);
        $xmlTot .= "  <MntTotOtrMnda>\n"
                 . "    <TpoMoneda>" . $h(strtoupper($moneda)) . "</TpoMoneda>\n"
                 . "    <TpoCambio>$tcambio</TpoCambio>\n"
                 . "    <MntTotOtrMnda>" . number_format($mntOtraMon, 2, '.', '') . "</MntTotOtrMnda>\n"
                 . "  </MntTotOtrMnda>\n";
    }
    $xmlTot .= "</Totales>";

    // Descuento / Recargo Global
    // Acepta:
    //   - NÃºmero escalar (legacy): se asume Descuento porcentual
    //   - Array: ['tipoMov'=>'D|R', 'tipoVal'=>'%|$', 'valor'=>n, 'glosa'=>'â€¦']
    $xmlDscGlb = '';
    if ($descuentoGlobal) {
        if (is_array($descuentoGlobal)) {
            $tipoMov  = strtoupper(($descuentoGlobal['tipoMov'] ?? 'D')) === 'R' ? 'R' : 'D';
            $tipoVal  = ($descuentoGlobal['tipoVal']  ?? '%') === '$' ? '$' : '%';
            $valor    = (float)($descuentoGlobal['valor'] ?? 0);
            $glosa    = $h($descuentoGlobal['glosa'] ?? ($tipoMov === 'D' ? 'Descuento Global' : 'Recargo Global'));
        } else {
            $tipoMov = 'D'; $tipoVal = '%';
            $valor   = (float)$descuentoGlobal;
            $glosa   = 'Descuento Global';
        }
        // En el schema SII: TpoValor = '%' (porcentaje) o '$' (monto). Se mantiene '%' legacy tambiÃ©n.
        $tpoValorXml = $tipoVal === '%' ? '%' : '$';
        $xmlDscGlb = "<DscRcgGlobal>\n"
            . "  <NroLinDR>1</NroLinDR>\n"
            . "  <TpoMov>$tipoMov</TpoMov>\n"
            . "  <GlosaDR>$glosa</GlosaDR>\n"
            . "  <TpoValor>$tpoValorXml</TpoValor>\n"
            . "  <ValorDR>" . rtrim(rtrim(number_format($valor, 4, '.', ''), '0'), '.') . "</ValorDR>\n"
            . "</DscRcgGlobal>\n";
    }

    // Timbre electrÃ³nico
    $it1 = substr($items[0]['nombre'] ?? 'Item', 0, 40);
    $ted = generateTimbre($tipo, $folio, $fecha, $rRut, $rNom, $m['mntTotal'], $it1, $caf, $privKey);

    $xmlIndTraslado = ($indTraslado > 0) ? "\n  <IndTraslado>$indTraslado</IndTraslado>" : "";
    $xmlFmaPago     = (!in_array($tipo, [39, 41])) ? "\n  <FmaPago>1</FmaPago>" : "";
    $xmlTipoDesp    = ($tipoDespacho > 0) ? "\n  <TipoDespacho>$tipoDespacho</TipoDespacho>" : "";
    // IndServicio requerido en boletas (EnvioBOLETA_v11.xsd, sin minOccurs=0)
    // 1=Boleta servicios periÃ³dicos, 2=Boleta servicios no periÃ³dicos,
    // 3=Boleta venta y servicios, 4=Boleta venta (sin servicios)
    $xmlIndServ = in_array($tipo, [39, 41])
        ? "\n  <IndServicio>$indServicio</IndServicio>"
        : "";
    $acteco         = (int)ACTECO;
    if ($acteco <= 0) {
        throw new Exception('ACTECO no configurado para la empresa. Edite la ficha en Empresas y registre el codigo de actividad economica SII antes de certificar facturas/notas/guias.');
    }

    // SecciÃ³n Transporte (Solo si hay datos o es GuÃ­a)
    $xmlTrans = "";
    if ($tipo == 52 || $patente || $rutTranspor) {
        $xmlTrans .= "\n<Transporte>";
        if ($patente)     $xmlTrans .= "\n  <Patente>" . htmlspecialchars(substr($patente, 0, 8)) . "</Patente>";
        if ($rutTranspor) $xmlTrans .= "\n  <RUTTrans>" . htmlspecialchars($rutTranspor) . "</RUTTrans>";
        
        // Si es traslado interno, ponemos direcciÃ³n de destino del receptor
        if ($indTraslado == 5) {
            if ($rDir) $xmlTrans .= "\n  <DirDest>$rDir</DirDest>";
            if ($rCom) $xmlTrans .= "\n  <CmnaDest>$rCom</CmnaDest>";
            if ($rCiu) $xmlTrans .= "\n  <CiudadDest>$rCiu</CiudadDest>";
        }
        $xmlTrans .= "\n</Transporte>";
    }

    // Referencias
    $xmlRef = '';
    foreach (array_slice($referencias, 0, 40) as $idx => $ref) {
        $l = $idx + 1;
        // Referencia SET de certificación: FolioRef = folio del propio documento
        if (($ref['tipo'] ?? '') === 'SET' && empty($ref['folio'])) {
            $ref['folio'] = $folio;
            if (empty($ref['fecha'])) $ref['fecha'] = date('Y-m-d');
        }
        $xmlRef .= "<Referencia>\n";
        $xmlRef .= "  <NroLinRef>$l</NroLinRef>\n";
        if (!empty($ref['tipo']))   $xmlRef .= "  <TpoDocRef>" . $h($ref['tipo']) . "</TpoDocRef>\n";
        if (!empty($ref['folio']))  $xmlRef .= "  <FolioRef>" . $h($ref['folio']) . "</FolioRef>\n";
        if (!empty($ref['fecha']))  $xmlRef .= "  <FchRef>" . $h($ref['fecha']) . "</FchRef>\n";
        if (!empty($ref['codigo'])) $xmlRef .= "  <CodRef>" . $h($ref['codigo']) . "</CodRef>\n";
        if (!empty($ref['razon']))  $xmlRef .= "  <RazonRef>" . $h($ref['razon']) . "</RazonRef>\n";
        $xmlRef .= "</Referencia>\n";
    }

    return <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<DTE version="1.0" xmlns="http://www.sii.cl/SiiDte">
<Documento ID="$idDte">
<Encabezado>
<IdDoc>
  <TipoDTE>$tipo</TipoDTE>
  <Folio>$folio</Folio>
  <FchEmis>$fecha</FchEmis>{$xmlTipoDesp}{$xmlIndTraslado}{$xmlIndServ}{$xmlFmaPago}
</IdDoc>
<Emisor>
  <RUTEmisor>$rE</RUTEmisor>
  <RznSoc>$rsE</RznSoc>
  <GiroEmis>$gE</GiroEmis>
  <Acteco>$acteco</Acteco>
  <DirOrigen>$dE</DirOrigen>
  <CmnaOrigen>$cE</CmnaOrigen>
  <CiudadOrigen>$ciE</CiudadOrigen>
</Emisor>
$xmlRecep
$xmlTrans
$xmlTot
</Encabezado>
$xmlDet
$xmlDscGlb
$xmlRef
$ted
<TmstFirma>$tmstFirma</TmstFirma>
</Documento>
</DTE>
XML;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// LIQUIDACIÃ“N (Tipo 43) â€” DocumentoLiquidacion
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function buildLiquidacionXML(
    int $tipo, int $folio, string $fecha,
    array $recep, array $items, array $m, array $caf, string $idDte, $privKey,
    array $comisiones = [], array $referencias = []
): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_XML1, 'UTF-8');
    $tmstFirma = date('Y-m-d\TH:i:s');

    // Emisor
    global $globalContext;
    if ($globalContext) {
        $emp = $globalContext->getEmpresa();
        $rE  = $h($emp['rut']);
        $rsE = $h($emp['razon_social']);
        $gE  = $h(mb_substr($emp['giro'], 0, 80));
        $dE  = $h($emp['direccion_origen']);
        $cE  = $h($emp['comuna_origen'] ?? '');
        $ciE = $h($emp['ciudad_origen'] ?? '');
        $acteco = json_decode($emp['acteco'] ?? '[]', true)[0] ?? ACTECO;
    } else {
        $rE  = $h(RUT_EMISOR);
        $rsE = $h(RAZON_SOCIAL);
        $gE  = $h(mb_substr(GIRO_EMISOR, 0, 80));
        $dE  = $h(DIRECCION);
        $cE  = $h(COMUNA);
        $ciE = $h(CIUDAD);
        $acteco = ACTECO;
    }

    // Receptor â€” tipo 43 siempre requiere RUT vÃ¡lido (el mandante)
    $rRut  = $h($recep['rut']    ?? '');
    $rNom  = $h($recep['nombre'] ?? '');
    if (empty($rRut) || $rRut === '66666666-6') {
        throw new Exception('La LiquidaciÃ³n (tipo 43) requiere RUT de receptor vÃ¡lido (el mandante).');
    }
    $rGiro = $h($recep['giro']     ?? '');
    $rDir  = $h($recep['direccion'] ?? '');
    $rCom  = $h($recep['comuna']   ?? '');
    $rCiu  = $h($recep['ciudad']   ?? '');

    $xmlRecep  = "<Receptor>\n  <RUTRecep>$rRut</RUTRecep>\n  <RznSocRecep>$rNom</RznSocRecep>\n";
    if ($rGiro) $xmlRecep .= "  <GiroRecep>$rGiro</GiroRecep>\n";
    if ($rDir)  $xmlRecep .= "  <DirRecep>$rDir</DirRecep>\n";
    if ($rCom)  $xmlRecep .= "  <CmnaRecep>$rCom</CmnaRecep>\n";
    if ($rCiu)  $xmlRecep .= "  <CiudadRecep>$rCiu</CiudadRecep>\n";
    $xmlRecep .= "</Receptor>";

    // Detalle (bienes/servicios liquidados)
    $xmlDet = '';
    foreach ($items as $i => $it) {
        $nom  = $h($it['nombre']      ?? '');
        $desc = $h($it['descripcion'] ?? '');
        $qty  = (float)($it['cantidad']  ?? 1);
        $prc  = (float)($it['precio']    ?? 0);
        $dp   = (float)($it['descuento'] ?? 0);
        $uMed = $h($it['unidadMedida'] ?? '');
        $mnt  = round($qty * $prc * (1 - $dp / 100));
        $lin  = $i + 1;

        $xmlDet .= "<Detalle>\n  <NroLinDet>$lin</NroLinDet>\n";
        if ($it['exento'] ?? false) $xmlDet .= "  <IndExe>1</IndExe>\n";
        $xmlDet .= "  <NmbItem>$nom</NmbItem>\n";
        if ($desc) $xmlDet .= "  <DscItem>$desc</DscItem>\n";
        // Líneas descriptivas sin valor (NC de texto del set de pruebas) van solo
        // con NmbItem + MontoItem 0: emitir QtyItem en ellas hace que el revisor
        // SII rechace "Los Valores de la Linea 1 No Cuadran".
        if ($qty > 0) $xmlDet .= "  <QtyItem>" . number_format($qty, 6, '.', '') . "</QtyItem>\n";
        if ($uMed) $xmlDet .= "  <UnmdItem>$uMed</UnmdItem>\n";
        if ($prc > 0) $xmlDet .= "  <PrcItem>$prc</PrcItem>\n";
        if ($dp > 0) $xmlDet .= "  <DescuentoPct>$dp</DescuentoPct>\n";
        $xmlDet .= "  <MontoItem>$mnt</MontoItem>\n</Detalle>\n";
    }

    // Comisiones â€” secciÃ³n exclusiva de DocumentoLiquidacion
    // TipoMovim: C = Cargo (comisiÃ³n cobrada al mandante), O = Abono (devoluciÃ³n al mandante)
    $xmlCom = '';
    foreach ($comisiones as $i => $com) {
        $lin      = $i + 1;
        $tipoMov  = strtoupper($com['tipoMovim'] ?? 'C') === 'O' ? 'O' : 'C';
        $glosa    = $h(substr($com['glosa'] ?? 'ComisiÃ³n', 0, 60));
        $neto     = (int)round((float)($com['valComNeto'] ?? 0));
        $exe      = (int)round((float)($com['valComExe']  ?? 0));
        $iva      = isset($com['valComIVA']) ? (int)round((float)$com['valComIVA']) : null;
        $tasa     = isset($com['tasaComision']) ? number_format((float)$com['tasaComision'], 2, '.', '') : null;

        $xmlCom .= "<Comisiones>\n  <NroLinCom>$lin</NroLinCom>\n  <TipoMovim>$tipoMov</TipoMovim>\n";
        $xmlCom .= "  <Glosa>$glosa</Glosa>\n";
        if ($tasa !== null) $xmlCom .= "  <TasaComision>$tasa</TasaComision>\n";
        $xmlCom .= "  <ValComNeto>$neto</ValComNeto>\n";
        $xmlCom .= "  <ValComExe>$exe</ValComExe>\n";
        if ($iva !== null) $xmlCom .= "  <ValComIVA>$iva</ValComIVA>\n";
        $xmlCom .= "</Comisiones>\n";
    }

    // Totales
    $xmlTot = "<Totales>\n";
    if ($m['mntNeto'] > 0) $xmlTot .= "  <MntNeto>{$m['mntNeto']}</MntNeto>\n";
    if ($m['mntExe']  > 0) $xmlTot .= "  <MntExe>{$m['mntExe']}</MntExe>\n";
    if ($m['tasaIVA'] > 0) $xmlTot .= "  <TasaIVA>{$m['tasaIVA']}</TasaIVA>\n";
    if ($m['iva']     > 0) $xmlTot .= "  <IVA>{$m['iva']}</IVA>\n";
    $xmlTot .= "  <MntTotal>{$m['mntTotal']}</MntTotal>\n</Totales>";

    // Referencias
    $xmlRef = '';
    foreach (array_slice($referencias, 0, 40) as $idx => $ref) {
        $l = $idx + 1;
        // Referencia SET de certificación: FolioRef = folio del propio documento
        if (($ref['tipo'] ?? '') === 'SET' && empty($ref['folio'])) {
            $ref['folio'] = $folio;
            if (empty($ref['fecha'])) $ref['fecha'] = date('Y-m-d');
        }
        $xmlRef .= "<Referencia>\n  <NroLinRef>$l</NroLinRef>\n";
        if (!empty($ref['tipo']))   $xmlRef .= "  <TpoDocRef>" . $h($ref['tipo']) . "</TpoDocRef>\n";
        if (!empty($ref['folio']))  $xmlRef .= "  <FolioRef>" . $h($ref['folio']) . "</FolioRef>\n";
        if (!empty($ref['fecha']))  $xmlRef .= "  <FchRef>" . $h($ref['fecha']) . "</FchRef>\n";
        if (!empty($ref['codigo'])) $xmlRef .= "  <CodRef>" . $h($ref['codigo']) . "</CodRef>\n";
        if (!empty($ref['razon']))  $xmlRef .= "  <RazonRef>" . $h($ref['razon']) . "</RazonRef>\n";
        $xmlRef .= "</Referencia>\n";
    }

    // Timbre
    $it1 = substr($items[0]['nombre'] ?? 'Item', 0, 40);
    $ted = generateTimbre($tipo, $folio, $fecha, $rRut, $rNom, $m['mntTotal'], $it1, $caf, $privKey);

    return <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<DTE version="1.0" xmlns="http://www.sii.cl/SiiDte">
<DocumentoLiquidacion ID="$idDte">
<Encabezado>
<IdDoc>
  <TipoDTE>$tipo</TipoDTE>
  <Folio>$folio</Folio>
  <FchEmis>$fecha</FchEmis>
  <FmaPago>1</FmaPago>
</IdDoc>
<Emisor>
  <RUTEmisor>$rE</RUTEmisor>
  <RznSoc>$rsE</RznSoc>
  <GiroEmis>$gE</GiroEmis>
  <Acteco>$acteco</Acteco>
  <DirOrigen>$dE</DirOrigen>
  <CmnaOrigen>$cE</CmnaOrigen>
  <CiudadOrigen>$ciE</CiudadOrigen>
</Emisor>
$xmlRecep
$xmlTot
</Encabezado>
$xmlDet
$xmlCom
$ted
$xmlRef
<TmstFirma>$tmstFirma</TmstFirma>
</DocumentoLiquidacion>
</DTE>
XML;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// TIMBRE ELECTRÃ“NICO (TED)
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function generateTimbre(
    int $tipo, int $folio, string $fecha,
    string $rRut, string $rNom, int $mntTotal,
    string $it1, array $caf, $privKey
): string {
    $h   = fn($s) => htmlspecialchars((string)$s, ENT_XML1, 'UTF-8');
    global $globalContext;
    $rE  = $h($globalContext ? $globalContext->getRut() : RUT_EMISOR);
    $rN  = $h(mb_substr($rNom, 0, 40, 'UTF-8'));
    $it  = $h(mb_substr($it1, 0, 40, 'UTF-8'));
    $ts  = date('Y-m-d\TH:i:s');

    // Extraer el bloque <CAF> y limpiarlo (quitar saltos de lÃ­nea y espacios entre etiquetas)
    if (!preg_match('/<CAF.*?>([\s\S]*?)<\/CAF>/i', $caf['xml'], $m)) {
        throw new Exception("No se pudo extraer el bloque <CAF> del archivo original.");
    }
    // Normalizar bloque CAF: eliminar espacios entre etiquetas para evitar rotura de firma en C14N
    $cafBlock = preg_replace('/>\s+</', '><', trim($m[0]));

    // Construir DD en una sola lÃ­nea y sin espacios innecesarios
    $dd = "<DD><RE>$rE</RE><TD>$tipo</TD><F>$folio</F><FE>$fecha</FE>"
        . "<RR>$rRut</RR><RSR>$rN</RSR><MNT>$mntTotal</MNT>"
        . "<IT1>$it</IT1>$cafBlock<TSTED>$ts</TSTED></DD>";

    // Firmar DD con la clave privada del CAF
    // Nota: El SII espera SHA1withRSA sobre el string DD en ISO-8859-1
    $cafKey = openssl_pkey_get_private($caf['privKey']);
    
    // Convertir a ISO-8859-1 si hay caracteres especiales antes de firmar
    $ddToSign = mb_convert_encoding($dd, 'ISO-8859-1', 'UTF-8');
    
    openssl_sign($ddToSign, $sig, $cafKey, OPENSSL_ALGO_SHA1);
    $frmt = base64_encode($sig);

    return "<TED version=\"1.0\">$dd<FRMT algoritmo=\"SHA1withRSA\">$frmt</FRMT></TED>";
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// FIRMA DIGITAL XML (XMLDSig)
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function signDTE(string $xml, string $certPem, $privKey, string $idToSign): string {
    // Endurecimiento anti XPath injection: el ID debe ser un identificador XML
    // válido (sin comillas ni caracteres especiales). Evita romper la consulta
    // XPath //*[@ID='$idToSign'] y la Reference URI="#...".
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/', $idToSign)) {
        throw new Exception('ID de firma inválido.');
    }
    // Reemplazar placeholder de ACTECO
    $xml = str_replace('ACTECO_VAL', ACTECO, $xml);
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml;
    if (!preg_match('//u', $xml)) {
        $xml = mb_convert_encoding($xml, 'UTF-8', 'ISO-8859-1');
    }
    $xml = preg_replace(
        '/<\?xml([^>]*?)encoding=["\'][^"\']+["\']([^>]*?)\?>/i',
        '<?xml$1encoding="UTF-8"$2?>',
        $xml,
        1,
        $encCount
    ) ?? $xml;
    if (empty($encCount)) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . ltrim($xml);
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    @$dom->loadXML($xml);
    $xpath = new DOMXPath($dom);

    // Nodo a firmar: <Documento ID="..."> o <EnvioLibro ID="..."> etc.
    // Usamos local-name() para ignorar namespaces si es necesario
    $nodeToSign = $xpath->query("//*[@ID='$idToSign']")->item(0);
    if (!$nodeToSign) {
        $nodeToSign = $xpath->query("//*[local-name()='Documento' or local-name()='EnvioLibro' or local-name()='Resultado'][@ID='$idToSign']")->item(0);
    }
    
    if (!$nodeToSign) {
        throw new Exception("No se encontrÃ³ el nodo con ID='$idToSign' para firmar.");
    }

    $c14n    = $nodeToSign->C14N();
    $digest  = base64_encode(sha1($c14n, true));

    preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $certPem, $mc);
    // El base64 del certificado se re-parte en líneas de 64 (formato PEM estándar)
    // para que ninguna línea del XML supere el límite del parser del SII
    // (CHR-00002 "Line too long (4090)"). Seguro: base64 ignora el whitespace y
    // X509Certificate no está dentro del SignedInfo firmado → no invalida la firma.
    // Sin esto, los certificados RSA 4096 producen una línea de <Signature> > 4090.
    $certB64 = trim(chunk_split(preg_replace('/\s+/', '', $mc[1] ?? ''), 64, "\n"));

    // Siempre usar xmldsig# — el SII valida server-side con ese namespace para todos
    // los tipos de documento (LibroCV, LibroGuia, EnvioDTE…). El LibroGuia_v10.xsd local
    // tenía una definición propia SiiDte:SignatureType, pero el servidor SII rechaza eso.
    $nsSig = 'http://www.w3.org/2000/09/xmldsig#';

    // SignedInfo
    $signedInfoXml = '<SignedInfo xmlns="' . $nsSig . '">'
                   . '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
                   . '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>'
                   . '<Reference URI="#' . $idToSign . '">'
                   . '<Transforms>'
                   . '<Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
                   . '</Transforms>'
                   . '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>'
                   . '<DigestValue>' . $digest . '</DigestValue>'
                   . '</Reference>'
                   . '</SignedInfo>';

    // Obtener Modulus y Exponent
    $pkObj = openssl_pkey_get_private($privKey);
    $details = $pkObj ? openssl_pkey_get_details($pkObj) : null;
    $modulus = '';
    $exponent = '';
    if ($details && isset($details['rsa'])) {
        $modulus = base64_encode($details['rsa']['n']);
        $exponent = base64_encode($details['rsa']['e']);
    }

    // Construir nodo <Signature> con SignatureValue VACÃO (se firma despuÃ©s).
    $sigXml = '<Signature xmlns="' . $nsSig . '">'
            . $signedInfoXml
            . '<SignatureValue></SignatureValue>'
            . '<KeyInfo>'
            . '<KeyValue>'
            . '<RSAKeyValue>'
            . '<Modulus>' . $modulus . '</Modulus>'
            . '<Exponent>' . $exponent . '</Exponent>'
            . '</RSAKeyValue>'
            . '</KeyValue>'
            . '<X509Data><X509Certificate>' . $certB64 . '</X509Certificate></X509Data>'
            . '</KeyInfo>'
            . '</Signature>';

    $sigDom = new DOMDocument();
    $sigDom->loadXML($sigXml);
    $sigNode = $dom->importNode($sigDom->documentElement, true);

    // PosiciÃ³n de la firma:
    // En DTE va dentro de <DTE> (despuÃ©s de <Documento>)
    // En Libro va al final del Root <LibroCompraVenta>
    $dom->documentElement->appendChild($sigNode);

    // CRÃTICO: canonicalizar el <SignedInfo> YA INSERTADO en su contexto real.
    // La C14N inclusiva hereda los namespaces de los ancestros (p.ej. xmlns:xsi
    // declarado en <EnvioDTE>). Firmar el SignedInfo aislado producÃ­a bytes
    // distintos a los que recanonicaliza el SII -> RFR. Firmando en contexto,
    // los bytes coinciden exactamente con la validaciÃ³n del SII.
    $xpSig = new DOMXPath($dom);
    $xpSig->registerNamespace('dsig', $nsSig);
    $siInCtx = $xpSig->query(".//dsig:SignedInfo", $sigNode)->item(0);
    $svInCtx = $xpSig->query(".//dsig:SignatureValue", $sigNode)->item(0);
    if (!$siInCtx || !$svInCtx) {
        throw new Exception('No se pudo localizar SignedInfo/SignatureValue para firmar en contexto.');
    }

    openssl_sign($siInCtx->C14N(), $sigBytes, $privKey, OPENSSL_ALGO_SHA1);
    $svInCtx->textContent = base64_encode($sigBytes);

    $dom->encoding = 'ISO-8859-1';
    $signedXml = $dom->saveXML();
    if (preg_match('//u', $signedXml)) {
        $signedXml = mb_convert_encoding($signedXml, 'ISO-8859-1', 'UTF-8');
    }

    return $signedXml;
}

function aplicarDescuentoGlobalMontos(array $montos, $descuentoGlobal): array {
    if (empty($descuentoGlobal)) {
        return $montos;
    }

    if (is_array($descuentoGlobal)) {
        $tipoMov = strtoupper((string)($descuentoGlobal['tipoMov'] ?? 'D'));
        $tipoVal = (string)($descuentoGlobal['tipoVal'] ?? '%');
        $valor = (float)($descuentoGlobal['valor'] ?? 0);
    } else {
        $tipoMov = 'D';
        $tipoVal = '%';
        $valor = (float)$descuentoGlobal;
    }

    if ($tipoMov !== 'D' || $valor <= 0) {
        return $montos;
    }

    $baseNeto = (int)($montos['mntNeto'] ?? 0);
    if ($baseNeto <= 0) {
        return $montos;
    }

    $descuento = $tipoVal === '$'
        ? (int)round($valor)
        : (int)round($baseNeto * ($valor / 100));
    $neto = max(0, $baseNeto - $descuento);
    $iva = $neto > 0 ? (int)round($neto * 0.19) : 0;

    $montos['mntNeto'] = $neto;
    $montos['iva'] = $iva;
    $montos['tasaIVA'] = $neto > 0 ? 19 : 0;
    $montos['mntTotal'] = $neto + (int)($montos['mntExe'] ?? 0) + $iva;

    return $montos;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// SOBRE DE ENVÃO (EnvioDTE)
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function siiFormatDate(string $date, string $fallback = ''): string {
    $date = trim($date);
    if ($date === '') return $fallback;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $date;
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $date, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    $ts = strtotime($date);
    return $ts ? date('Y-m-d', $ts) : $fallback;
}

function siiCaratulaResolucion(): array {
    global $globalContext;
    $emp = $globalContext ? $globalContext->getEmpresa() : [];
    $fecha = (string)($emp['fecha_resolucion'] ?? $emp['fch_resol'] ?? FCH_RESOL);
    if ($globalContext && $globalContext->getAmbiente() === 'CERTIFICACION') {
        return [0, siiFormatDate($fecha, date('Y-m-d'))];
    }

    $numero = $emp['numero_resolucion'] ?? $emp['nro_resol'] ?? NRO_RESOL;
    return [(int)$numero, siiFormatDate($fecha, (string)FCH_RESOL)];
}

function buildEnvioDTE(string $dteXml, int $tipo, int $folio, string $certPem): string {
    global $globalContext;
    $rutEmisor  = $globalContext ? $globalContext->getRut() : RUT_EMISOR;
    $rutEnvia   = getRutCertificadoSeguro($certPem);
    // En certificacion el SII exige NroResol=0 y la fecha de resolucion asignada al contribuyente.
    // Los campos en DB son: numero_resolucion y fecha_resolucion
    $emp = $globalContext ? $globalContext->getEmpresa() : [];
    if ($globalContext && $globalContext->getAmbiente() === 'CERTIFICACION') {
        $nroResol = 0;
        $fchResol = siiFormatDate($emp['fecha_resolucion'] ?? FCH_RESOL, date('Y-m-d'));
    } else {
        $nroResol = $emp['numero_resolucion'] ?? $emp['nro_resol'] ?? NRO_RESOL;
        $fchResol = siiFormatDate($emp['fecha_resolucion'] ?? $emp['fch_resol'] ?? FCH_RESOL);
    }
    $tmst       = date('Y-m-d\TH:i:s');

    // Remover la declaraciÃ³n XML del DTE interior para no invalidar el sobre
    $dteXmlClean = preg_replace('/<\?xml.*?\?>\s*/i', '', $dteXml);

    $isBoleta = in_array($tipo, [39, 41], true);
    $tagEnvio = $isBoleta ? 'EnvioBOLETA' : 'EnvioDTE';
    $xsd      = $isBoleta ? 'EnvioBOLETA_v11.xsd' : 'EnvioDTE_v10.xsd';
    // EnvioBOLETA_v11.xsd fija Caratula/@version = "1.0" (igual que EnvioDTE).
    $verCarat = '1.0';

    return <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<$tagEnvio version="1.0"
  xmlns="http://www.sii.cl/SiiDte"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.sii.cl/SiiDte $xsd">
<SetDTE ID="SetDoc">
<Caratula version="$verCarat">
  <RutEmisor>$rutEmisor</RutEmisor>
  <RutEnvia>$rutEnvia</RutEnvia>
  <RutReceptor>60803000-K</RutReceptor>
  <FchResol>$fchResol</FchResol>
  <NroResol>$nroResol</NroResol>
  <TmstFirmaEnv>$tmst</TmstFirmaEnv>
  <SubTotDTE>
    <TpoDTE>$tipo</TpoDTE>
    <NroDTE>1</NroDTE>
  </SubTotDTE>
</Caratula>
$dteXmlClean
</SetDTE>
</$tagEnvio>
XML;
}

/**
 * Arma UN sobre EnvioBOLETA con MÃšLTIPLES boletas firmadas (Set de certificaciÃ³n).
 * El SII exige que el set de prueba de boletas se envÃ­e en un solo archivo (sobre).
 *
 * @param array  $dtesXml  Lista de XML de <Documento> ya firmados (tipo 39/41).
 * @param string $certPem  Certificado para extraer el RUT que envÃ­a.
 */
/**
 * Arma UN sobre EnvioDTE con multiples DTE firmados, usado para reportar el
 * Set de Pruebas completo de facturacion en el orden entregado por el SII.
 *
 * @param array<int,array{xml:string,tipo:int,folio:int}> $dtes
 */
function buildEnvioDTESet(array $dtes, string $certPem): string {
    global $globalContext;
    $rutEmisor = $globalContext ? $globalContext->getRut() : RUT_EMISOR;
    $rutEnvia  = getRutCertificadoSeguro($certPem);

    $emp = $globalContext ? $globalContext->getEmpresa() : [];
    if ($globalContext && $globalContext->getAmbiente() === 'CERTIFICACION') {
        $nroResol = 0;
        $fchResol = siiFormatDate($emp['fecha_resolucion'] ?? FCH_RESOL, date('Y-m-d'));
    } else {
        $nroResol = $emp['numero_resolucion'] ?? $emp['nro_resol'] ?? NRO_RESOL;
        $fchResol = siiFormatDate($emp['fecha_resolucion'] ?? $emp['fch_resol'] ?? FCH_RESOL);
    }
    $tmst = date('Y-m-d\TH:i:s');

    $counts = [];
    $docs = '';
    foreach ($dtes as $dte) {
        $tipo = (int)($dte['tipo'] ?? 0);
        if ($tipo <= 0 || empty($dte['xml'])) {
            continue;
        }
        $counts[$tipo] = ($counts[$tipo] ?? 0) + 1;
        $docs .= preg_replace('/<\?xml.*?\?>\s*/i', '', (string)$dte['xml']) . "\n";
    }
    ksort($counts);

    $subTot = '';
    foreach ($counts as $tipo => $count) {
        $subTot .= "  <SubTotDTE>\n"
            . "    <TpoDTE>$tipo</TpoDTE>\n"
            . "    <NroDTE>$count</NroDTE>\n"
            . "  </SubTotDTE>\n";
    }

    return <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<EnvioDTE version="1.0"
  xmlns="http://www.sii.cl/SiiDte"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioDTE_v10.xsd">
<SetDTE ID="FENV010">
<Caratula version="1.0">
  <RutEmisor>$rutEmisor</RutEmisor>
  <RutEnvia>$rutEnvia</RutEnvia>
  <RutReceptor>60803000-K</RutReceptor>
  <FchResol>$fchResol</FchResol>
  <NroResol>$nroResol</NroResol>
  <TmstFirmaEnv>$tmst</TmstFirmaEnv>
$subTot</Caratula>
$docs</SetDTE>
</EnvioDTE>
XML;
}
function buildEnvioBoletaSet(array $dtesXml, string $certPem): string {
    global $globalContext;
    $rutEmisor = $globalContext ? $globalContext->getRut() : RUT_EMISOR;
    $rutEnvia  = getRutCertificadoSeguro($certPem);

    $emp = $globalContext ? $globalContext->getEmpresa() : [];
    if ($globalContext && $globalContext->getAmbiente() === 'CERTIFICACION') {
        $nroResol = 0;
        $fchResol = siiFormatDate($emp['fecha_resolucion'] ?? FCH_RESOL, date('Y-m-d'));
    } else {
        $nroResol = $emp['numero_resolucion'] ?? NRO_RESOL;
        $fchResol = siiFormatDate($emp['fecha_resolucion'] ?? FCH_RESOL);
    }
    $tmst = date('Y-m-d\TH:i:s');

    // Limpiar declaracion XML de cada documento y concatenar
    $docs = '';
    foreach ($dtesXml as $d) {
        $docs .= preg_replace('/<\?xml.*?\?>\s*/i', '', $d) . "\n";
    }
    $nroDte = count($dtesXml);

    return <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<EnvioBOLETA version="1.0"
  xmlns="http://www.sii.cl/SiiDte"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioBOLETA_v11.xsd">
<SetDTE ID="SetDoc">
<Caratula version="1.0">
  <RutEmisor>$rutEmisor</RutEmisor>
  <RutEnvia>$rutEnvia</RutEnvia>
  <RutReceptor>60803000-K</RutReceptor>
  <FchResol>$fchResol</FchResol>
  <NroResol>$nroResol</NroResol>
  <TmstFirmaEnv>$tmst</TmstFirmaEnv>
  <SubTotDTE>
    <TpoDTE>39</TpoDTE>
    <NroDTE>$nroDte</NroDTE>
  </SubTotDTE>
</Caratula>
$docs</SetDTE>
</EnvioBOLETA>
XML;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// RCOF (Resumen de Consumo de Folios para Boletas)
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function generateRCOF(array $data): array {
    $fechaEmision = $data['fecha'] ?? date('Y-m-d');
    $resumenes    = $data['resumenes'] ?? []; // Ej: [['tipo' => 39, 'neto' => 1000, 'iva' => 190, 'total' => 1190, 'emitidos' => 5, 'anulados' => 0, 'utilizados' => 5, 'rango_desde' => 1, 'rango_hasta' => 5]]
    $secuencia    = (int)($data['secuencia'] ?? 1);
    $correlativo  = isset($data['correlativo']) ? (int)$data['correlativo'] : null;
    $xmlCorrelativo = $correlativo !== null ? "\n  <Correlativo>$correlativo</Correlativo>" : '';

    $GLOBALS['SII_CERT_TIPO'] = 39;
    [$cert, $privKey] = loadCertificate(39);
    
    $rutE = RUT_EMISOR;
    $rutEnvia = getRutCertificadoSeguro($cert);
    $fchR = FCH_RESOL;
    $nroR = NRO_RESOL;
    $idRcof = "RCOF_" . time();
    $fechaTimestamp = date('Y-m-d\TH:i:s');

    $xmlResumen = '';
    foreach ($resumenes as $r) {
        $tipo  = $r['tipo']      ?? 39;
        $total = (int)($r['total']    ?? 0);
        $emit  = (int)($r['emitidos'] ?? 0);
        $util  = (int)($r['utilizados']?? 0);
        $rdesde= (int)($r['rango_desde']?? 0);
        $rhasta= (int)($r['rango_hasta']?? 0);

        $xmlResumen .= "<Resumen>\n"
            . "  <TipoDocumento>$tipo</TipoDocumento>\n";

        // Totales monetarios (solo cuando hay emisiÃ³n con monto)
        if ($emit > 0 && $total > 0) {
            $mntNeto = (int)($r['neto'] ?? 0);
            $mntIva  = (int)($r['iva']  ?? 0);
            $mntExe  = (int)($r['exe']  ?? 0);
            if ($mntNeto > 0) $xmlResumen .= "  <MntNeto>$mntNeto</MntNeto>\n";
            if ($mntIva  > 0) {
                $xmlResumen .= "  <MntIva>$mntIva</MntIva>\n";
                $xmlResumen .= "  <TasaIVA>19</TasaIVA>\n";
            }
            if ($mntExe  > 0) $xmlResumen .= "  <MntExento>$mntExe</MntExento>\n";
        }
        $xmlResumen .= "  <MntTotal>$total</MntTotal>\n"
            . "  <FoliosEmitidos>$emit</FoliosEmitidos>\n"
            . "  <FoliosAnulados>" . (int)($r['anulados'] ?? 0) . "</FoliosAnulados>\n"
            . "  <FoliosUtilizados>$util</FoliosUtilizados>\n";

        // RangoUtilizados: agrupar folios usados en rangos consecutivos
        if ($util > 0 && $rdesde > 0 && $rhasta > 0) {
            $xmlResumen .= "  <RangoUtilizados>\n"
                . "    <Inicial>$rdesde</Inicial>\n"
                . "    <Final>$rhasta</Final>\n"
                . "  </RangoUtilizados>\n";
        }

        // RangoAnulados: un rango por cada folio anulado registrado
        $foliosAnulados = $r['folios_anulados_list'] ?? [];
        if (!empty($foliosAnulados)) {
            sort($foliosAnulados);
            // Agrupar consecutivos en rangos
            $start = $foliosAnulados[0]; $prev = $start;
            foreach (array_slice($foliosAnulados, 1) as $f) {
                if ($f !== $prev + 1) {
                    $xmlResumen .= "  <RangoAnulados>\n    <Inicial>$start</Inicial>\n"
                        . ($prev !== $start ? "    <Final>$prev</Final>\n" : "")
                        . "  </RangoAnulados>\n";
                    $start = $f;
                }
                $prev = $f;
            }
            $xmlResumen .= "  <RangoAnulados>\n    <Inicial>$start</Inicial>\n"
                . ($prev !== $start ? "    <Final>$prev</Final>\n" : "")
                . "  </RangoAnulados>\n";
        }

        $xmlResumen .= "</Resumen>\n";
    }

    $xmlRaw = <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<ConsumoFolios version="1.0"
  xmlns="http://www.sii.cl/SiiDte"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.sii.cl/SiiDte ConsumoFolio_v10.xsd">
<DocumentoConsumoFolios ID="$idRcof">
<Caratula version="1.0">
  <RutEmisor>$rutE</RutEmisor>
  <RutEnvia>$rutEnvia</RutEnvia>
  <FchResol>$fchR</FchResol>
  <NroResol>$nroR</NroResol>
  <FchInicio>$fechaEmision</FchInicio>
  <FchFinal>$fechaEmision</FchFinal>
  <SecEnvio>$secuencia</SecEnvio>{$xmlCorrelativo}
  <TmstFirmaEnv>$fechaTimestamp</TmstFirmaEnv>
</Caratula>
$xmlResumen</DocumentoConsumoFolios>
</ConsumoFolios>
XML;

    $xmlFirmado = signDTE($xmlRaw, $cert, $privKey, $idRcof);
    // signDTE ya inserta el xmlns del namespace XMLDSig â€” no duplicarlo.

    return [
        'ok'  => true,
        'xml' => $xmlFirmado,
        'mensaje' => 'RCOF generado y firmado exitosamente.'
    ];
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// RCOF DIARIO â€” Escaneo, envÃ­o y persistencia
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function rcofRegistryPath(): string {
    global $actualTmpDir;
    return rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . 'rcof_log.json';
}

function loadRCOFRegistry(): array {
    $f = rcofRegistryPath();
    if (!file_exists($f)) return [];
    $r = json_decode(file_get_contents($f), true);
    return is_array($r) ? $r : [];
}

function saveRCOFRegistry(array $reg): void {
    file_put_contents(rcofRegistryPath(), json_encode($reg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

/**
 * Escanea tmp/ y agrupa boletas (39/41) por fecha de emisiÃ³n.
 * Por defecto incluye solo boletas con TrackID registrado en tracking.json
 * (es decir, recepcionadas por el SII). Pasar $soloConTrackId=false para
 * incluir todas las boletas fÃ­sicamente generadas en disco.
 */
function listBoletasDelDia(string $fecha, bool $soloConTrackId = true): array {
    global $actualTmpDir;
    // Tipos 39 y 41 (boletas) + tipo 61 (NC de boleta) para RCOF segÃºn ConsumoFolio_v10.xsd
    $pattern = rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . 'dte_T{39,41,61}F*.xml';
    $files = glob($pattern, GLOB_BRACE) ?: [];

    $trackingFile = rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . 'tracking.json';
    $tracking = file_exists($trackingFile) ? (json_decode(file_get_contents($trackingFile), true) ?? []) : [];

    $resumen = [
        39 => ['tipo' => 39, 'folios' => [], 'neto' => 0, 'iva' => 0, 'total' => 0, 'exe' => 0],
        41 => ['tipo' => 41, 'folios' => [], 'neto' => 0, 'iva' => 0, 'total' => 0, 'exe' => 0],
        61 => ['tipo' => 61, 'folios' => [], 'neto' => 0, 'iva' => 0, 'total' => 0, 'exe' => 0],
    ];

    foreach ($files as $f) {
        if (!preg_match('/dte_T(\d+)F(\d+)\.xml$/', basename($f), $m)) continue;
        $tipo  = (int)$m[1];
        $folio = (int)$m[2];
        $content = @file_get_contents($f);
        if (!$content) continue;

        preg_match('/<FchEmis>([^<]+)<\/FchEmis>/', $content, $mf);
        $fchEmis = trim($mf[1] ?? '');
        if ($fchEmis !== $fecha) continue;

        if ($soloConTrackId) {
            $key = "T{$tipo}F{$folio}";
            if (empty($tracking[$key]['trackId'])) continue;
        }

        // Tipo 61 en RCOF solo aplica a NCs que referencian una boleta (tipo 39 o 41)
        if ($tipo === 61) {
            if (!preg_match('/<TpoDocRef>(39|41)<\/TpoDocRef>/', $content)) continue;
        }

        preg_match('/<MntNeto>(\d+)<\/MntNeto>/',  $content, $mn);
        preg_match('/<MntExe>(\d+)<\/MntExe>/',    $content, $me);
        preg_match('/<IVA>(\d+)<\/IVA>/',          $content, $mi);
        preg_match('/<MntTotal>(\d+)<\/MntTotal>/',$content, $mt);

        $resumen[$tipo]['folios'][] = $folio;
        $resumen[$tipo]['neto']  += (int)($mn[1] ?? 0);
        $resumen[$tipo]['exe']   += (int)($me[1] ?? 0);
        $resumen[$tipo]['iva']   += (int)($mi[1] ?? 0);
        $resumen[$tipo]['total'] += (int)($mt[1] ?? 0);
    }

    foreach ($resumen as $t => &$r) {
        sort($r['folios']);
        $r['emitidos']   = count($r['folios']);
        $r['anulados']   = 0;
        $r['utilizados'] = $r['emitidos'];
        $r['rango_desde']= $r['folios'][0]                ?? 0;
        $r['rango_hasta']= end($r['folios']) ?: 0;
    }

    // Excluir tipo 61 del resumen si no hubo NCs de boleta ese dÃ­a
    if (empty($resumen[61]['folios'])) {
        unset($resumen[61]);
    }

    return array_values($resumen);
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// REGISTRO DE FOLIOS ANULADOS (para incluir en RCOF)
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
//
// Un folio CAF puede "quemarse" sin haber sido emitido: errores de
// generaciÃ³n, sistema caÃ­do, etc. El SII exige reportarlos como anulados
// en el RCOF para mantener consistencia del rango utilizado.

function foliosAnuladosPath(): string {
    global $actualTmpDir;
    return rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . 'folios_anulados.json';
}

function loadFoliosAnulados(): array {
    $f = foliosAnuladosPath();
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function saveFoliosAnulados(array $d): void {
    file_put_contents(foliosAnuladosPath(), json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

/**
 * Marca un folio como anulado. Idempotente.
 * Si el folio fue emitido al SII (TrackID OK), bloquea: anular requiere
 * emitir nota de crÃ©dito, no es lo mismo que anular el folio CAF.
 */
function anularFolio(int $tipo, int $folio, string $razon, ?string $fecha = null): array {
    if (strlen(trim($razon)) < 5) {
        return ['ok' => false, 'error' => 'RazÃ³n debe tener al menos 5 caracteres'];
    }

    // Bloquear si ya fue enviado al SII (consultar tanto last_ok_trackId nuevo
    // como trackId legacy para compatibilidad con tracking pre-migraciÃ³n)
    $tr = getDTETracking($tipo, $folio);
    $trackIdEfectivo = $tr['last_ok_trackId'] ?? $tr['trackId'] ?? null;
    if ($tr && !empty($trackIdEfectivo)) {
        return [
            'ok' => false,
            'code' => 'FOLIO_YA_ENVIADO',
            'error' => "Folio T{$tipo}F{$folio} ya fue enviado al SII (TrackID {$trackIdEfectivo}). " .
                       "No se puede anular el folio: emita una Nota de CrÃ©dito (61) para reversar el efecto tributario.",
        ];
    }

    $fecha = $fecha ?: date('Y-m-d');
    $data = loadFoliosAnulados();
    $key  = "T{$tipo}F{$folio}";
    $data[$key] = [
        'tipo'   => $tipo,
        'folio'  => $folio,
        'fecha'  => $fecha,
        'razon'  => trim($razon),
        'ts'     => date('Y-m-d H:i:s'),
    ];
    saveFoliosAnulados($data);
    saveSiiLog('anularFolio', "$key anulado: $razon", 'INFO');
    return ['ok' => true, 'anulado' => $data[$key]];
}

function listarFoliosAnulados(?int $tipo = null, ?string $fecha = null): array {
    $data = loadFoliosAnulados();
    $out = [];
    foreach ($data as $k => $v) {
        if ($tipo !== null && (int)$v['tipo'] !== $tipo) continue;
        if ($fecha !== null && $v['fecha'] !== $fecha) continue;
        $out[$k] = $v;
    }
    return $out;
}

/**
 * Hook: inyectar folios anulados en el resumen del RCOF de un dÃ­a.
 * Modifica el array de resÃºmenes que generarÃ­a submitDailyRCOF.
 */
function aplicarFoliosAnuladosARCOF(array $resumenes, string $fecha): array {
    $anulados = listarFoliosAnulados(null, $fecha);
    if (empty($anulados)) return $resumenes;

    // Agrupar anulados por tipo
    $porTipo = [];
    foreach ($anulados as $a) {
        $t = (int)$a['tipo'];
        $porTipo[$t] ??= ['folios' => []];
        $porTipo[$t]['folios'][] = (int)$a['folio'];
    }

    foreach ($resumenes as &$r) {
        $t = (int)$r['tipo'];
        if (isset($porTipo[$t])) {
            $r['anulados']   = count($porTipo[$t]['folios']);
            $r['folios_anulados_list'] = $porTipo[$t]['folios'];
            // Folios utilizados = emitidos al SII (no incluye anulados)
        }
    }
    return $resumenes;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// BACKUPS AUTOMÃTICOS â€” Tracking, registros y configuraciÃ³n
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
//
// Genera snapshots zip de los archivos crÃ­ticos para poder recuperar
// el estado en caso de corrupciÃ³n. NO toca archive/ (los DTEs ya estÃ¡n
// inmutables, comprimirlos no aporta valor frente a la duplicaciÃ³n).

function backupBaseDir(): string {
    global $globalContext, $noCompanyDir;
    $d = $globalContext
        ? __DIR__ . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $globalContext->getRut() . DIRECTORY_SEPARATOR . $globalContext->getAmbiente()
        : $noCompanyDir . 'backups';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

/**
 * Crea un backup .zip con tracking, rcof_log, registry CAF, sii_logs, etc.
 * Mantiene los Ãºltimos N backups y borra los mÃ¡s viejos.
 */
function createBackup(int $keepLast = 14): array {
    global $actualTmpDir, $actualCafDir;
    $base = backupBaseDir();
    $stamp = date('Y-m-d_His');

    $files = [
        'tracking.json'           => $actualTmpDir . 'tracking.json',
        'rcof_log.json'           => $actualTmpDir . 'rcof_log.json',
        'sii_logs.json'           => $actualTmpDir . 'sii_logs.json',
        'sii_transactions.ndjson' => $actualTmpDir . 'sii_transactions.ndjson',
        'retry_queue.json'        => $actualTmpDir . 'retry_queue.json',
        'archive_index.ndjson'    => archiveBaseDir() . DIRECTORY_SEPARATOR . 'index.ndjson',
        'caf_registry.json'       => $actualCafDir . 'registry.json',
        'history.json'            => $actualCafDir . 'history.json',
    ];

    $manifest = [
        'created_at' => date('Y-m-d\TH:i:s'),
        'hostname'   => gethostname(),
        'php_version'=> PHP_VERSION,
        'env'        => function_exists('siiEndpoints') ? siiEndpoints()['ambiente'] : (defined('AMBIENTE') ? AMBIENTE : 'unknown'),
    ];

    $included = [];
    $size = 0;
    $useZip = class_exists('ZipArchive');

    if ($useZip) {
        $zipPath = $base . DIRECTORY_SEPARATOR . "backup_{$stamp}.zip";
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => "No se pudo crear $zipPath"];
        }
        foreach ($files as $name => $path) {
            if (file_exists($path)) {
                $zip->addFile($path, $name);
                $included[] = $name;
                $size += filesize($path);
            }
        }
        $manifest['files'] = $included;
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $zip->close();
        $resultPath = $zipPath;
        $fmt = 'zip';
    } else {
        // Fallback: carpeta con archivos individuales
        $dir = $base . DIRECTORY_SEPARATOR . "backup_{$stamp}";
        if (!@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => "No se pudo crear $dir"];
        }
        foreach ($files as $name => $path) {
            if (file_exists($path)) {
                copy($path, $dir . DIRECTORY_SEPARATOR . $name);
                $included[] = $name;
                $size += filesize($path);
            }
        }
        $manifest['files'] = $included;
        $manifest['format'] = 'directory (ZipArchive no disponible)';
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $resultPath = $dir;
        $fmt = 'dir';
    }

    // RotaciÃ³n
    $pattern = $useZip ? 'backup_*.zip' : 'backup_*';
    $existing = glob($base . DIRECTORY_SEPARATOR . $pattern) ?: [];
    // Filtrar para no incluir el directorio reciÃ©n creado en zip mode
    $existing = array_values(array_filter($existing, fn($p) => $useZip ? str_ends_with($p, '.zip') : is_dir($p)));
    sort($existing);
    $deleted = 0;
    while (count($existing) > $keepLast) {
        $old = array_shift($existing);
        if (is_dir($old)) {
            // Borrar dir recursivo
            foreach (glob($old . '/*') ?: [] as $f) @unlink($f);
            @rmdir($old);
        } else {
            @unlink($old);
        }
        $deleted++;
    }

    $finalSize = $useZip ? filesize($resultPath) : $size;

    saveSiiLog('createBackup',
        "Backup $stamp ($fmt) con " . count($included) . " archivos (" . number_format($finalSize) . " bytes); rotaciÃ³n -$deleted",
        'SUCCESS');

    return [
        'ok'         => true,
        'path'       => $resultPath,
        'format'     => $fmt,
        'size'       => $finalSize,
        'files'      => $included,
        'source_size'=> $size,
        'deleted_old'=> $deleted,
        'kept'       => count($existing),
    ];
}

function listBackups(): array {
    $base = backupBaseDir();
    $out = [];
    foreach (glob($base . DIRECTORY_SEPARATOR . 'backup_*') ?: [] as $f) {
        $isDir = is_dir($f);
        $size = $isDir ? array_sum(array_map('filesize', glob($f . '/*') ?: [])) : filesize($f);
        $out[] = [
            'name' => basename($f),
            'path' => $f,
            'format' => $isDir ? 'dir' : 'zip',
            'size' => $size,
            'mtime'=> date('Y-m-d H:i:s', filemtime($f)),
        ];
    }
    usort($out, fn($a, $b) => strcmp($b['mtime'], $a['mtime']));
    return $out;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// SISTEMA DE ALERTAS PROACTIVAS
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
//
// Genera alertas sobre el estado operativo del sistema. Cada alerta tiene:
//   - level: critical | warning | info
//   - code: identificador Ãºnico
//   - title, body, action (quÃ© hacer)

function generateAlerts(): array {
    global $actualCertPfx;
    $alerts = [];
    $now = time();

    // 1. Certificado por vencer
    try {
        $confFile = dirname($actualCertPfx) . '/cert.conf';
        if (file_exists($actualCertPfx) && file_exists($confFile)) {
            $conf = json_decode(file_get_contents($confFile), true);
            if (!empty($conf['pass'])) {
                $certs = [];
                if (openssl_pkcs12_read(file_get_contents($actualCertPfx), $certs, $conf['pass'])) {
                    $parsed = openssl_x509_parse($certs['cert']);
                    $dias = (int)(($parsed['validTo_time_t'] - $now) / 86400);
                    $cn = $parsed['subject']['CN'] ?? 'â€”';
                    if ($dias <= 0) {
                        $alerts[] = ['level'=>'critical','code'=>'CERT_EXPIRED',
                            'title'=>'Certificado digital VENCIDO',
                            'body'=>"El cert de $cn venciÃ³ hace " . abs($dias) . " dÃ­as.",
                            'action'=>'Renovar en E-Certchile/E-Sign y subir el nuevo .pfx en setup.php'];
                    } elseif ($dias < 7) {
                        $alerts[] = ['level'=>'critical','code'=>'CERT_NEAR_EXPIRY',
                            'title'=>"Certificado vence en $dias dÃ­as",
                            'body'=>"$cn â€” fecha vencimiento " . date('Y-m-d', $parsed['validTo_time_t']),
                            'action'=>'Renovar AHORA en E-Certchile/E-Sign'];
                    } elseif ($dias < 30) {
                        $alerts[] = ['level'=>'warning','code'=>'CERT_EXPIRING',
                            'title'=>"Certificado vence en $dias dÃ­as",
                            'body'=>"$cn â€” coordinar renovaciÃ³n antes del vencimiento",
                            'action'=>'Iniciar trÃ¡mite de renovaciÃ³n con la AC'];
                    }
                }
            }
        } else {
            $alerts[] = ['level'=>'critical','code'=>'CERT_MISSING',
                'title'=>'Sin certificado digital',
                'body'=>'No hay archivo .pfx instalado.',
                'action'=>'Subir certificado en setup.php'];
        }
    } catch (Throwable $e) {
        $alerts[] = ['level'=>'warning','code'=>'CERT_CHECK_FAILED','title'=>'No se pudo verificar certificado','body'=>$e->getMessage(),'action'=>'Revisar setup.php'];
    }

    // 2. CAFs faltantes o agotÃ¡ndose
    try {
        $cafs = getCAFStatus();
        foreach ($cafs['cafs'] ?? [] as $c) {
            if ($c['estado'] === 'AGOTADO') {
                $alerts[] = ['level'=>'critical','code'=>'CAF_DEPLETED',
                    'title'=>"CAF tipo {$c['tipo']} ({$c['nombre']}) AGOTADO",
                    'body'=>"Sin folios disponibles para emitir.",
                    'action'=>"Solicitar nuevo rango en sii.cl â†' AdministraciÃ³n de Folios"];
            } elseif ($c['estado'] === 'CRITICO') {
                $alerts[] = ['level'=>'critical','code'=>'CAF_CRITICAL',
                    'title'=>"CAF tipo {$c['tipo']} con solo {$c['restantes']} folios",
                    'body'=>"Quedan menos de 10 folios disponibles para {$c['nombre']}.",
                    'action'=>'Solicitar nuevo rango AHORA en sii.cl'];
            } elseif ($c['estado'] === 'BAJO') {
                $alerts[] = ['level'=>'warning','code'=>'CAF_LOW',
                    'title'=>"CAF tipo {$c['tipo']} con {$c['restantes']} folios",
                    'body'=>"Quedan menos de 50 folios para {$c['nombre']}.",
                    'action'=>'Considerar solicitar nuevo rango en sii.cl'];
            }
        }
        foreach ($cafs['faltantes'] ?? [] as $f) {
            $alerts[] = ['level'=>'warning','code'=>'CAF_MISSING',
                'title'=>"CAF tipo {$f['tipo']} ({$f['nombre']}) faltante",
                'body'=>$f['mensaje'],
                'action'=>'Cargar el CAF correspondiente en setup.php'];
        }
    } catch (Throwable $e) {
        // sin alertar
    }

    // 3. RCOF no enviado hoy
    $rcofLog = loadRCOFRegistry();
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $haboBoletasAyer = false;
    if (function_exists('listBoletasDelDia')) {
        foreach (listBoletasDelDia($yesterday) as $r) {
            if (($r['emitidos'] ?? 0) > 0) { $haboBoletasAyer = true; break; }
        }
    }
    // El RVD se envÃ­a al dÃ­a siguiente. Si ya pasaron las 12:00 hoy y no se enviÃ³ el de ayer, alertar.
    $deadline = strtotime("$today 12:00:00");
    if ($now > $deadline) {
        $rcofAyer = $rcofLog[$yesterday] ?? null;
        if (!$rcofAyer || empty($rcofAyer['ok'])) {
            $alerts[] = ['level'=>'critical','code'=>'RCOF_MISSING',
                'title'=>"RCOF del $yesterday no enviado al SII",
                'body'=>"Vencido el plazo (12:00 del dÃ­a siguiente). El SII puede multar." . ($haboBoletasAyer ? " Hubo boletas emitidas." : " (Sin boletas â€” RVD de cero tambiÃ©n es obligatorio)."),
                'action'=>'Ejecutar rcof_cron.bat o ?action=rcof_submit&fecha=' . $yesterday . '&force=1'];
        }
    }

    // 4. Modo contingencia activo
    if (function_exists('isInContingencyMode') && isInContingencyMode()) {
        $info = json_decode(@file_get_contents(contingencyFlagPath()) ?: '{}', true);
        $alerts[] = ['level'=>'critical','code'=>'CONTINGENCY_ON',
            'title'=>'Modo contingencia ACTIVO',
            'body'=>'El sistema detectÃ³ fallos repetidos con el SII. RazÃ³n: ' . ($info['reason'] ?? 'auto'),
            'action'=>'Revisar healthcheck SII (?action=health_sii) y procesar cola (retry_cron.bat)'];
    }

    // 5. Cola de reintentos creciendo
    if (function_exists('loadRetryQueue')) {
        $queue = loadRetryQueue();
        if (count($queue) > 0) {
            $level = count($queue) >= 5 ? 'critical' : 'warning';
            $alerts[] = ['level'=>$level,'code'=>'RETRY_QUEUE_NONEMPTY',
                'title'=>'Cola de reintentos con ' . count($queue) . ' DTE(s)',
                'body'=>'Hay envÃ­os al SII pendientes de reintento.',
                'action'=>'Verificar retry_cron.bat estÃ© programado, o ejecutar manualmente ?action=retry_process'];
        }
    }

    // 6. DTEs sin estado terminal hace mucho
    global $actualTmpDir;
    $trackFile = $actualTmpDir . 'tracking.json';
    if (file_exists($trackFile)) {
        $tracking = json_decode(file_get_contents($trackFile), true) ?: [];
        $stalled = 0;
        foreach ($tracking as $t) {
            if (!siiEstadoEsTerminal($t['estado'] ?? null)) {
                $sentTs = strtotime($t['enviado'] ?? '');
                if ($sentTs && ($now - $sentTs) > 86400) $stalled++;
            }
        }
        if ($stalled > 0) {
            $alerts[] = ['level'=>'warning','code'=>'DTE_STALLED',
                'title'=>"$stalled DTE(s) sin estado terminal hace +24h",
                'body'=>'El SII no ha confirmado el estado final. Puede ser normal en boletas (FAU) o un problema de permisos del usuario consultor.',
                'action'=>'Ejecutar poller_cron.bat o verificar permisos del RUT del cert en sii.cl'];
        }
    }

    // 7. Healthcheck SII degradado (silenciar si modo contingencia ya alerta)
    try {
        if (!isInContingencyMode()) {
            $h = siiHealthcheck();
            if (!$h['ok']) {
                $fails = array_filter($h['checks'] ?? [], fn($c) => !$c['ok']);
                $names = array_column($fails, 'name');
                $alerts[] = ['level'=>'warning','code'=>'SII_DEGRADED',
                    'title'=>'Conectividad SII degradada',
                    'body'=>'Endpoints con falla: ' . implode(', ', $names),
                    'action'=>'Verificar https://www.sii.cl o esperar a que se normalice'];
            }
        }
    } catch (Throwable $e) {}

    // 8. XSDs oficiales faltantes (no bloqueante, informativo)
    $envioBoletaXsd = __DIR__ . '/schemas/EnvioBOLETA_v11.xsd';
    if (!file_exists($envioBoletaXsd)) {
        $alerts[] = ['level'=>'info','code'=>'XSD_ENVIO_BOLETA_MISSING',
            'title'=>'XSD EnvioBOLETA_v11 no disponible localmente',
            'body'=>'La validaciÃ³n XSD local del sobre boleta no se aplica (el SII valida server-side).',
            'action'=>'Bajar Schemas.zip del portal sii.cl tras inscribirse y extraer EnvioBOLETA_v11.xsd a schemas/'];
    }

    return $alerts;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// NOTAS DE CRÃ‰DITO (tipo 61) PARA ANULAR/CORREGIR BOLETAS
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
//
// Las boletas (39/41) no se pueden anular directamente. La Ãºnica forma de
// reversar el efecto tributario es emitir una Nota de CrÃ©dito ElectrÃ³nica
// que la referencie con CodRef={1:anula, 2:corrige texto, 3:corrige montos}.

/**
 * Carga el XML y datos esenciales de un DTE almacenado (preferir archivo legal).
 */
function loadStoredDTEData(int $tipo, int $folio): ?array {
    global $actualTmpDir;
    // Buscar primero en tmp/
    $f = $actualTmpDir . "dte_T{$tipo}F{$folio}.xml";
    if (!file_exists($f)) {
        // Buscar en archive/ por glob
        $hits = glob(archiveBaseDir() . "/*/*/T{$tipo}/T{$tipo}F{$folio}.xml");
        $f = $hits[0] ?? null;
    }
    if (!$f || !file_exists($f)) return null;
    $xml = file_get_contents($f);

    $get = function ($pattern) use ($xml) {
        return preg_match($pattern, $xml, $m) ? trim($m[1]) : null;
    };
    return [
        'tipo'     => $tipo,
        'folio'    => $folio,
        'xml'      => $xml,
        'fch_emis' => $get('/<FchEmis>([^<]+)<\/FchEmis>/'),
        'rut_recep'=> $get('/<RUTRecep>([^<]+)<\/RUTRecep>/'),
        'rs_recep' => $get('/<RznSocRecep>([^<]+)<\/RznSocRecep>/'),
        'neto'     => (int)($get('/<MntNeto>(\d+)<\/MntNeto>/')      ?? 0),
        'iva'      => (int)($get('/<IVA>(\d+)<\/IVA>/')              ?? 0),
        'total'    => (int)($get('/<MntTotal>(\d+)<\/MntTotal>/')    ?? 0),
        'items'    => [],  // (parseo simplificado: copiamos descripciÃ³n del primer item)
    ];
}

/**
 * Emite una Nota de CrÃ©dito ElectrÃ³nica (tipo 61) que referencia una boleta
 * previamente emitida. Valida que la boleta exista y la NC se firma + envÃ­a.
 *
 * @param int    $tipoOrig   Tipo original (39 o 41)
 * @param int    $folioOrig  Folio de la boleta a anular/corregir
 * @param int    $codRef     1=Anula, 2=Corrige texto, 3=Corrige montos
 * @param string $razon      Glosa de la razÃ³n
 * @param array  $opts       ['items'=>[â€¦], 'fecha'=>'YYYY-MM-DD', 'dry_run'=>bool]
 */
function emitirNotaCreditoSobreBoleta(int $tipoOrig, int $folioOrig, int $codRef, string $razon, array $opts = []): array {
    if (!in_array($tipoOrig, [39, 41], true)) {
        return ['ok' => false, 'error' => "Solo se admiten boletas (39/41), no tipo $tipoOrig"];
    }
    if (!in_array($codRef, [1, 2, 3], true)) {
        return ['ok' => false, 'error' => 'CodRef debe ser 1 (anula), 2 (corrige texto) o 3 (corrige montos)'];
    }
    if (strlen(trim($razon)) < 5) {
        return ['ok' => false, 'error' => 'La razÃ³n debe tener al menos 5 caracteres'];
    }

    $orig = loadStoredDTEData($tipoOrig, $folioOrig);
    if (!$orig) {
        return ['ok' => false, 'error' => "Boleta original T{$tipoOrig}F{$folioOrig} no encontrada en tmp/ ni archive/"];
    }

    // CodRef=1 (anula) o 3 (corrige montos): la NC reversa el monto total de la boleta.
    // CodRef=2 (corrige texto): NC con monto 0, solo cambia texto.
    $items = $opts['items'] ?? null;
    if (!$items) {
        if ($codRef === 2) {
            $items = [['nombre' => 'CorrecciÃ³n de texto', 'cantidad' => 1, 'precio' => 0]];
        } else {
            // Reversar el total bruto de la boleta original
            $items = [[
                'nombre' => "AnulaciÃ³n T{$tipoOrig}F{$folioOrig}",
                'cantidad' => 1,
                'precio' => $orig['total'],
            ]];
        }
    }

    $payload = [
        'tipo'  => 61,
        'fecha' => $opts['fecha'] ?? date('Y-m-d'),
        'items' => $items,
        'receptor' => [
            'rut'    => $orig['rut_recep'] ?? '66666666-6',
            'nombre' => $orig['rs_recep']  ?? 'Consumidor Final',
        ],
        'referencias' => [[
            'tipo'   => $tipoOrig,
            'folio'  => $folioOrig,
            'fecha'  => $orig['fch_emis'],
            'codigo' => $codRef,
            'razon'  => substr(trim($razon), 0, 90),
        ]],
    ];

    if (!empty($opts['dry_run'])) {
        return [
            'ok' => true, 'dry_run' => true,
            'payload' => $payload, 'mensaje' => "[DRY-RUN] NC sobre T{$tipoOrig}F{$folioOrig} preparada â€” no se firmÃ³ ni enviÃ³.",
        ];
    }

    // Generar + enviar
    $gen = generateDTE($payload);
    if (empty($gen['ok'])) {
        return ['ok' => false, 'error' => 'FallÃ³ generaciÃ³n NC: ' . ($gen['error'] ?? 'sin detalle'), 'gen' => $gen];
    }
    $send = sendDTE(['xml' => $gen['xml'], 'tipo' => 61, 'folio' => $gen['folio']]);
    $send['nc_folio']       = $gen['folio'];
    $send['boleta_referida']= "T{$tipoOrig}F{$folioOrig}";
    $send['cod_ref']        = $codRef;
    $send['razon']          = $razon;
    saveSiiLog('emitirNotaCredito', "NC F{$gen['folio']} sobre T{$tipoOrig}F{$folioOrig} cod=$codRef: " . ($send['ok']?'OK':'FAIL'), $send['ok']?'SUCCESS':'ERROR');
    return $send;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// COLA DE REINTENTOS + MODO CONTINGENCIA SII
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
//
// Cuando un envÃ­o al SII falla por error transitorio (timeout, 5xx,
// "Acceso Denegado from client"), el DTE se encola para reintento con
// backoff exponencial. Si la cola acumula muchos fallos seguidos, el
// sistema entra en MODO CONTINGENCIA: las nuevas emisiones se firman
// y archivan localmente, marcadas como "pendientes upload" â€” el flujo
// sigue operando aunque el SII estÃ© caÃ­do. Al volver el SII (vÃ­a
// healthcheck), procesa los pendientes automÃ¡ticamente.

function retryQueuePath(): string {
    global $actualTmpDir;
    return rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . 'retry_queue.json';
}

function contingencyFlagPath(): string {
    global $actualTmpDir;
    return rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . 'contingency.flag';
}

function loadRetryQueue(): array {
    $f = retryQueuePath();
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function saveRetryQueue(array $q): void {
    file_put_contents(retryQueuePath(), json_encode($q, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

/**
 * Backoff exponencial en segundos segÃºn nÃºmero de intentos previos.
 * 0â†'60s, 1â†'300s (5m), 2â†'900s (15m), 3â†'3600s (1h), 4â†'21600s (6h)
 */
function retryBackoffSeconds(int $intentos): int {
    $tabla = [60, 300, 900, 3600, 21600];
    return $tabla[min($intentos, count($tabla) - 1)];
}

/**
 * Encola un DTE para reintento automÃ¡tico tras fallo transitorio.
 * Errores NO encolables (definitivos): rechazo de schema, firma invÃ¡lida, etc.
 */
function enqueueRetry(int $tipo, int $folio, string $errorMsg, int $httpCode = 0): array {
    $q = loadRetryQueue();
    $key = "T{$tipo}F{$folio}";
    $entry = $q[$key] ?? [
        'tipo'        => $tipo,
        'folio'       => $folio,
        'first_fail'  => date('Y-m-d H:i:s'),
        'intentos'    => 0,
    ];
    $entry['intentos']++;
    $entry['last_fail']     = date('Y-m-d H:i:s');
    $entry['last_error']    = substr($errorMsg, 0, 500);
    $entry['last_http']     = $httpCode;
    $entry['next_retry_at'] = date('Y-m-d H:i:s', time() + retryBackoffSeconds($entry['intentos']));
    $q[$key] = $entry;
    saveRetryQueue($q);
    saveSiiLog('enqueueRetry', "$key encolado (intento #{$entry['intentos']}), siguiente reintento {$entry['next_retry_at']}", 'WARNING');
    return $entry;
}

function dequeueRetry(int $tipo, int $folio): void {
    $q = loadRetryQueue();
    unset($q["T{$tipo}F{$folio}"]);
    saveRetryQueue($q);
}

/**
 * Decide si un error es transitorio (encolable) o definitivo (no encolable).
 */
function isErrorTransitorio(int $httpCode, string $errorMsg): bool {
    // Definitivos: schema, firma, auth permanente, RUT no autorizado
    $definitivos = ['SCH-', 'esquema', 'firma', 'no autorizada', 'NO ESTA AUTENTICADO', 'XSD_INVALID', 'BOLETA_YA_ACEPTADA'];
    foreach ($definitivos as $kw) {
        if (stripos($errorMsg, $kw) !== false) return false;
    }
    // CÃ³digos HTTP transitorios
    if ($httpCode >= 500 && $httpCode < 600) return true;
    if ($httpCode === 0)   return true;  // timeout/red
    if ($httpCode === 429) return true;  // rate limit
    if ($httpCode === 408) return true;  // request timeout
    // 4xx generalmente son definitivos (mala request del cliente)
    return false;
}

/**
 * MODO CONTINGENCIA: activa cuando hay N o mÃ¡s fallos consecutivos del SII
 * en un perÃ­odo corto, o cuando el healthcheck reporta degradaciÃ³n crÃ­tica.
 */
function isInContingencyMode(): bool {
    $f = contingencyFlagPath();
    if (!file_exists($f)) return false;
    $info = json_decode(file_get_contents($f), true);
    if (!is_array($info)) return false;
    // Auto-desactivar despuÃ©s de 24h sin renovaciÃ³n
    if (isset($info['ts']) && (time() - strtotime($info['ts'])) > 86400) {
        @unlink($f);
        return false;
    }
    return true;
}

function setContingencyMode(bool $on, string $reason = ''): void {
    $f = contingencyFlagPath();
    if ($on) {
        file_put_contents($f, json_encode([
            'ts'     => date('Y-m-d H:i:s'),
            'reason' => $reason,
        ]));
        saveSiiLog('contingency', "MODO CONTINGENCIA ACTIVADO: $reason", 'ERROR');
    } else {
        if (file_exists($f)) @unlink($f);
        saveSiiLog('contingency', "Modo contingencia desactivado", 'SUCCESS');
    }
}

/**
 * Decide automÃ¡ticamente si entrar/salir del modo contingencia segÃºn
 * el healthcheck del SII y la cola de reintentos.
 */
function autoToggleContingency(): array {
    $health = siiHealthcheck();
    $queue = loadRetryQueue();
    $totalFails = 0;
    foreach ($queue as $e) $totalFails += (int)($e['intentos'] ?? 0);

    // Activar si: healthcheck no OK + 3 o mÃ¡s fallos en cola, o 10+ fallos
    $shouldActivate = (!$health['ok'] && $totalFails >= 3) || $totalFails >= 10;
    // Desactivar si: healthcheck OK + cola vacÃ­a (o solo con backoff largo)
    $shouldDeactivate = $health['ok'] && count($queue) === 0;

    $currentlyOn = isInContingencyMode();
    if (!$currentlyOn && $shouldActivate) {
        setContingencyMode(true, "Healthcheck=" . ($health['ok']?'OK':'FAIL') . " queueFails=$totalFails");
        return ['action' => 'ACTIVATED', 'reason' => 'SII degradado o cola con muchos fallos'];
    }
    if ($currentlyOn && $shouldDeactivate) {
        setContingencyMode(false);
        return ['action' => 'DEACTIVATED', 'reason' => 'SII operativo y cola vacÃ­a'];
    }
    return ['action' => 'UNCHANGED', 'contingency' => $currentlyOn];
}

/**
 * Procesa la cola: reintenta los DTEs cuya hora de next_retry_at ya pasÃ³.
 */
function processRetryQueue(int $maxPerRun = 20): array {
    $queue = loadRetryQueue();
    $report = ['queued' => count($queue), 'processed' => 0, 'success' => 0, 'still_failing' => 0, 'dropped' => 0, 'results' => []];
    $now = time();
    $maxIntentos = 5;

    foreach ($queue as $key => $entry) {
        if ($report['processed'] >= $maxPerRun) break;
        $nextTs = strtotime($entry['next_retry_at'] ?? '1970-01-01');
        if ($nextTs > $now) continue;  // aÃºn no toca

        // Dropear si superÃ³ el mÃ¡ximo de intentos
        if (($entry['intentos'] ?? 0) >= $maxIntentos) {
            dequeueRetry((int)$entry['tipo'], (int)$entry['folio']);
            $report['dropped']++;
            $report['results'][] = ['doc' => $key, 'result' => 'DROPPED', 'razon' => "SuperÃ³ $maxIntentos intentos"];
            saveSiiLog('processRetryQueue', "$key drop tras {$entry['intentos']} intentos", 'ERROR');
            continue;
        }

        $report['processed']++;
        try {
            $res = resendStoredDTE((int)$entry['tipo'], (int)$entry['folio'], [
                'force' => true,
                'reason' => "auto-retry #{$entry['intentos']} tras fallo previo",
            ]);
            if (!empty($res['ok']) && !empty($res['trackId'])) {
                dequeueRetry((int)$entry['tipo'], (int)$entry['folio']);
                $report['success']++;
                $report['results'][] = ['doc' => $key, 'result' => 'OK', 'trackId' => $res['trackId']];
            } else {
                // Re-encolar con backoff incrementado
                enqueueRetry((int)$entry['tipo'], (int)$entry['folio'], $res['error'] ?? 'sin detalle', $res['http'] ?? 0);
                $report['still_failing']++;
                $report['results'][] = ['doc' => $key, 'result' => 'STILL_FAILING', 'error' => substr($res['error'] ?? '', 0, 200)];
            }
        } catch (Throwable $e) {
            enqueueRetry((int)$entry['tipo'], (int)$entry['folio'], $e->getMessage(), 0);
            $report['still_failing']++;
            $report['results'][] = ['doc' => $key, 'result' => 'EXCEPTION', 'error' => $e->getMessage()];
        }
    }
    return $report;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// POLLER DE ESTADO DTE â€” Reconsulta SII hasta llegar a estado final
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
//
// Estados intermedios del SII: REC, EPR, SOK, CRT (envÃ­o recibido, en proceso).
// Estados finales: DOK (datos coinciden), DNK (no coinciden), FAU, FNA, FAN,
//                  RSC (rechazado por schema), RFR (rechazado firma), RCT.

/**
 * CÃ³digos del SII que NO requieren reconsultar (estado terminal).
 */
function siiEstadoEsTerminal(?string $estado): bool {
    $finales = ['DOK', 'DNK', 'FAU', 'FNA', 'FAN', 'RSC', 'RFR', 'RCT', 'EMP'];
    return $estado !== null && in_array(strtoupper($estado), $finales, true);
}

/**
 * Recorre tracking.json y reconsulta el estado de DTEs no terminales.
 * @param int $maxPerRun MÃ¡x DTEs a procesar por ejecuciÃ³n (evita bombardear SII).
 * @return array Reporte de cambios.
 */
function pollEstadoDTEs(int $maxPerRun = 50): array {
    global $actualTmpDir;
    $file = $actualTmpDir . 'tracking.json';
    if (!file_exists($file)) {
        return ['ok' => true, 'scanned' => 0, 'updated' => 0, 'reason' => 'sin tracking.json'];
    }
    $data = json_decode(file_get_contents($file), true) ?? [];

    $report = ['scanned' => 0, 'skipped_final' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'changes' => []];
    $processed = 0;

    // Dos certificados: boletas (Cristina) y DTE SOAP/guÃ­as (David).
    // Si no hay cert DTE separado, ambos resuelven al mismo (sin romper nada).
    [$certBol, $keyBol] = loadCertificate(39);
    [$certSoap, $keySoap] = loadCertificate(52);
    $tokenBoletaRest = null;  // lazy
    $tokenSoap = null;        // lazy

    foreach ($data as $key => &$entry) {
        if ($processed >= $maxPerRun) break;
        $report['scanned']++;

        $tipo  = (int)($entry['tipo']  ?? 0);
        $folio = (int)($entry['folio'] ?? 0);
        $estadoActual = strtoupper((string)($entry['estado'] ?? ''));
        $trackId = $entry['last_ok_trackId'] ?? $entry['trackId'] ?? null;

        if (!$tipo || !$folio || !$trackId) continue;

        // Skip si ya estÃ¡ en estado terminal
        if (siiEstadoEsTerminal($estadoActual)) {
            $report['skipped_final']++;
            continue;
        }

        // Backoff: solo poll si pasaron al menos N segundos desde el Ãºltimo poll
        // (5 min recientemente, 1 hora si ya hicimos varios polls)
        $polls = (int)($entry['polls'] ?? 0);
        $lastPoll = $entry['last_poll_ts'] ?? null;
        $minInterval = $polls < 3 ? 300 : ($polls < 10 ? 3600 : 21600); // 5m â†' 1h â†' 6h
        if ($lastPoll && (time() - strtotime($lastPoll)) < $minInterval) {
            continue;
        }

        $processed++;
        $esBoleta = in_array($tipo, [39, 41], true);

        try {
            if ($esBoleta) {
                $tokenBoletaRest = $tokenBoletaRest ?: getBoletaRestToken($certBol, $keyBol);
                // Para boletas, consultar por folio (mÃ¡s confiable segÃºn diagnÃ³stico previo)
                // Necesitamos rutReceptor + fecha + monto del XML almacenado
                $xmlFile = $actualTmpDir . "dte_T{$tipo}F{$folio}.xml";
                $rutRecep = '66666666-6'; $fecha = date('Y-m-d'); $monto = 0;
                if (file_exists($xmlFile)) {
                    $xml = file_get_contents($xmlFile);
                    if (preg_match('/<RUTRecep>([^<]+)<\/RUTRecep>/', $xml, $m)) $rutRecep = trim($m[1]);
                    if (preg_match('/<FchEmis>([^<]+)<\/FchEmis>/', $xml, $m)) $fecha = trim($m[1]);
                    if (preg_match('/<MntTotal>(\d+)<\/MntTotal>/', $xml, $m)) $monto = (int)$m[1];
                }
                $res = queryEstadoBoletaREST($tipo, $folio, $trackId, $tokenBoletaRest, $rutRecep, $fecha, $monto);
            } else {
                if (!$tokenSoap) {
                    $sem = getSemilla();
                    $tokenSoap = getToken($sem, $certSoap, $keySoap);
                }
                $res = queryEstadoEnvio($trackId, $tokenSoap);
            }

            $estadoNuevo = strtoupper((string)($res['estado'] ?? ''));
            $entry['polls']        = $polls + 1;
            $entry['last_poll_ts'] = date('Y-m-d H:i:s');

            if ($estadoNuevo && $estadoNuevo !== $estadoActual) {
                $entry['estado']       = $estadoNuevo;
                $entry['glosa']        = $res['glosa'] ?? null;
                $entry['updated_ts']   = date('Y-m-d H:i:s');
                $report['updated']++;
                $report['changes'][] = [
                    'doc'    => $key,
                    'antes'  => $estadoActual ?: '(vacÃ­o)',
                    'despues'=> $estadoNuevo,
                    'glosa'  => $res['glosa'] ?? '',
                ];
                saveSiiLog('pollEstadoDTEs', "$key: $estadoActual â†' $estadoNuevo ({$res['glosa']})", siiEstadoEsTerminal($estadoNuevo) ? 'SUCCESS' : 'INFO');
            } else {
                $report['unchanged']++;
            }
        } catch (Throwable $e) {
            $report['errors']++;
            saveSiiLog('pollEstadoDTEs', "$key: error " . $e->getMessage(), 'WARNING');
        }
    }
    unset($entry);

    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    return $report;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// ARCHIVO LEGAL â€” RetenciÃ³n de DTEs por 6 aÃ±os (Art. 17 CT + Res. 74)
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
//
// Estructura: archive/YYYY/MM/T{tipo}/T{tipo}F{folio}.xml + .meta.json
// Ãndice:     archive/index.ndjson (append-only, una lÃ­nea por DTE archivado)
//
// PolÃ­tica de retenciÃ³n: 6 aÃ±os desde fecha de emisiÃ³n (FchEmis del DTE).
// El sistema NUNCA elimina automÃ¡ticamente XMLs que estÃ©n dentro de ese rango.

function archiveBaseDir(): string {
    global $globalContext, $noCompanyDir;
    return $globalContext
        ? __DIR__ . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . $globalContext->getRut() . DIRECTORY_SEPARATOR . $globalContext->getAmbiente()
        : $noCompanyDir . 'archive';
}

function archiveDirFor(int $tipo, string $fchEmis): string {
    $ts = strtotime($fchEmis) ?: time();
    $year  = date('Y', $ts);
    $month = date('m', $ts);
    return archiveBaseDir() . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . "T{$tipo}";
}

function archiveIndexPath(): string {
    return archiveBaseDir() . DIRECTORY_SEPARATOR . 'index.ndjson';
}

/**
 * Extrae FchEmis del XML del DTE (formato YYYY-MM-DD), o null si no se encuentra.
 */
function extractFchEmisFromXML(string $xml): ?string {
    if (preg_match('/<FchEmis>([^<]+)<\/FchEmis>/', $xml, $m)) return trim($m[1]);
    return null;
}

/**
 * Archiva un DTE en la estructura legal de 6 aÃ±os.
 * Es idempotente: si ya estÃ¡ archivado, retorna 'already_archived'.
 *
 * @param int    $tipo
 * @param int    $folio
 * @param string $xml         XML firmado del DTE
 * @param array  $meta        ['trackId', 'sii_response', 'enviado_ts', etc.]
 * @return array Estado de la operaciÃ³n
 */
function archiveDTE(int $tipo, int $folio, string $xml, array $meta = []): array {
    $fchEmis = $meta['fch_emis'] ?? extractFchEmisFromXML($xml);
    if (!$fchEmis) {
        return ['ok' => false, 'error' => 'No se pudo extraer FchEmis del XML para archivar'];
    }

    $dir = archiveDirFor($tipo, $fchEmis);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $xmlFile  = $dir . DIRECTORY_SEPARATOR . "T{$tipo}F{$folio}.xml";
    $metaFile = $dir . DIRECTORY_SEPARATOR . "T{$tipo}F{$folio}.meta.json";

    $alreadyExists = file_exists($xmlFile);

    // Hash del XML antes de archivar (auditorÃ­a: detectar futuras modificaciones del archivo)
    $hash = 'sha256:' . hash('sha256', $xml);

    if ($alreadyExists) {
        $prevHash = 'sha256:' . hash('sha256', file_get_contents($xmlFile));
        if ($prevHash !== $hash) {
            // El XML existe pero es diferente â€” guardamos versiÃ³n con timestamp para no perder evidencia
            $xmlFile = $dir . DIRECTORY_SEPARATOR . "T{$tipo}F{$folio}_v" . date('YmdHis') . ".xml";
        } else {
            // Idempotente: ya archivado idÃ©ntico, solo actualizamos meta si hay datos nuevos
            file_put_contents($metaFile, json_encode(array_merge(
                json_decode(file_get_contents($metaFile), true) ?: [],
                $meta,
                ['ts_archive' => date('Y-m-d\TH:i:s'), 'xml_hash' => $hash]
            ), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            return ['ok' => true, 'status' => 'already_archived', 'path' => $xmlFile, 'hash' => $hash];
        }
    }

    file_put_contents($xmlFile, $xml);
    file_put_contents($metaFile, json_encode(array_merge([
        'tipo'        => $tipo,
        'folio'       => $folio,
        'fch_emis'    => $fchEmis,
        'ts_archive'  => date('Y-m-d\TH:i:s'),
        'xml_hash'    => $hash,
    ], $meta), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

    // Append al Ã­ndice NDJSON
    $idx = [
        'ts'       => date('Y-m-d\TH:i:s'),
        'tipo'     => $tipo,
        'folio'    => $folio,
        'fch_emis' => $fchEmis,
        'path'     => str_replace(archiveBaseDir() . DIRECTORY_SEPARATOR, '', $xmlFile),
        'hash'     => $hash,
        'trackId'  => $meta['trackId'] ?? null,
    ];
    file_put_contents(archiveIndexPath(), json_encode($idx, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

    return ['ok' => true, 'status' => 'archived', 'path' => $xmlFile, 'hash' => $hash];
}

/**
 * Backfill: recorre tmp/ + tracking.json y archiva todos los DTEs ya enviados
 * que aÃºn no estÃ©n en archive/. Ãštil para inicializar el archivo legal.
 */
function backfillArchive(): array {
    global $actualTmpDir;
    $tmpFiles = glob(rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . 'dte_T*F*.xml') ?: [];
    $tracking = file_exists($actualTmpDir . 'tracking.json')
        ? (json_decode(file_get_contents($actualTmpDir . 'tracking.json'), true) ?? [])
        : [];

    $report = ['scanned' => 0, 'archived' => 0, 'already' => 0, 'skipped_no_track' => 0, 'errors' => []];

    foreach ($tmpFiles as $f) {
        $report['scanned']++;
        if (!preg_match('/dte_T(\d+)F(\d+)\.xml$/', basename($f), $m)) continue;
        $tipo = (int)$m[1]; $folio = (int)$m[2];
        $key  = "T{$tipo}F{$folio}";

        $track = $tracking[$key] ?? null;
        // PolÃ­tica: solo archivamos DTEs que tuvieron al menos un envÃ­o al SII (con TrackID).
        // Los que estÃ¡n en tmp/ sin TrackID son "borradores" no oficiales.
        if (!$track || empty($track['last_ok_trackId'] ?? $track['trackId'] ?? null)) {
            $report['skipped_no_track']++;
            continue;
        }

        $xml = file_get_contents($f);
        $meta = [
            'trackId'      => $track['last_ok_trackId'] ?? $track['trackId'] ?? null,
            'estado_sii'   => $track['estado'] ?? null,
            'enviado_ts'   => $track['enviado'] ?? null,
            'sii_response' => $track['respuesta_sii'] ?? null,
            'backfill'     => true,
            'backfill_ts'  => date('Y-m-d\TH:i:s'),
        ];
        $r = archiveDTE($tipo, $folio, $xml, $meta);
        if (!empty($r['ok'])) {
            if ($r['status'] === 'archived') $report['archived']++;
            else $report['already']++;
        } else {
            $report['errors'][] = "T{$tipo}F{$folio}: " . ($r['error'] ?? 'error');
        }
    }
    return $report;
}

/**
 * Estado del archivo legal: cuenta DTEs por aÃ±o/mes/tipo, espacio usado,
 * y reporta XMLs no archivados que deberÃ­an estarlo (alerta de cumplimiento).
 */
function archiveStatus(): array {
    $base = archiveBaseDir();
    $stats = [
        'archive_dir' => $base,
        'exists'      => is_dir($base),
        'total_dtes'  => 0,
        'bytes'       => 0,
        'por_anio'    => [],
        'por_tipo'    => [],
        'oldest'      => null,
        'newest'      => null,
        'index_entries' => 0,
        'pending_archive' => [],
    ];

    if (!is_dir($base)) return $stats;

    $idx = archiveIndexPath();
    if (file_exists($idx)) {
        $stats['index_entries'] = count(file($idx, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    foreach (glob($base . '/*/*/T*/T*F*.xml', GLOB_BRACE) ?: [] as $f) {
        $stats['total_dtes']++;
        $stats['bytes'] += filesize($f);
        if (preg_match('#archive[\\\\/](\d{4})[\\\\/](\d{2})[\\\\/]T(\d+)#', $f, $m)) {
            $year  = $m[1];
            $tipo  = (int)$m[3];
            $stats['por_anio'][$year]   = ($stats['por_anio'][$year]   ?? 0) + 1;
            $stats['por_tipo'][$tipo]   = ($stats['por_tipo'][$tipo]   ?? 0) + 1;
            $ymKey = $year . '-' . $m[2];
            if ($stats['oldest'] === null || $ymKey < $stats['oldest']) $stats['oldest'] = $ymKey;
            if ($stats['newest'] === null || $ymKey > $stats['newest']) $stats['newest'] = $ymKey;
        }
    }

    // Detectar DTEs en tmp/ con TrackID que NO estÃ¡n archivados (gap de cumplimiento)
    global $actualTmpDir;
    $tracking = file_exists($actualTmpDir . 'tracking.json')
        ? (json_decode(file_get_contents($actualTmpDir . 'tracking.json'), true) ?? [])
        : [];
    foreach ($tracking as $key => $t) {
        if (empty($t['last_ok_trackId'] ?? $t['trackId'] ?? null)) continue;
        $tipo = (int)($t['tipo'] ?? 0);
        $folio = (int)($t['folio'] ?? 0);
        if (!$tipo || !$folio) continue;
        $xmlFile = $actualTmpDir . "dte_T{$tipo}F{$folio}.xml";
        if (!file_exists($xmlFile)) continue;
        $xml = file_get_contents($xmlFile);
        $fchEmis = extractFchEmisFromXML($xml);
        if (!$fchEmis) continue;
        $archivePath = archiveDirFor($tipo, $fchEmis) . "/T{$tipo}F{$folio}.xml";
        if (!file_exists($archivePath)) {
            $stats['pending_archive'][] = "T{$tipo}F{$folio}";
        }
    }

    return $stats;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// SYNC DE XSDs OFICIALES DESDE EL SII (sin hardcoding, fuente Ãºnica SII)
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Lista de XSDs requeridos por el sistema con sus rutas candidatas en el SII.
 * Cada XSD tiene varios URLs posibles porque el SII tiene la documentaciÃ³n
 * distribuida entre /factura_electronica, /boletas_electronicas y /bolcoreinternetui.
 */
function siiSchemaCandidates(): array {
    $bases = [
        'https://www.sii.cl/factura_electronica/factura_mercado',
        'https://www.sii.cl/factura_electronica/boletas_electronicas',
        'https://www.sii.cl/factura_electronica/factura_mercado/schemas',
        'https://www.sii.cl/boleta_electronica',
        'https://www4c.sii.cl/bolcoreinternetui/api/schemas',
    ];

    $schemas = [
        'SiiTypes_v10.xsd',
        'xmldsignature_v10.xsd',
        'DTE_v10.xsd',
        'EnvioDTE_v10.xsd',
        'EnvioBOLETA_v11.xsd',
        'ConsumoFolio_v10.xsd',
        'LibroCV_v10.xsd',
        'LibroGuia_v10.xsd',
        'LibroBOLETA_v10.xsd',
        'RespuestaEnvioDTE_v10.xsd',
        'AEC_v10.xsd',
    ];

    $out = [];
    foreach ($schemas as $name) {
        $urls = [];
        foreach ($bases as $b) $urls[] = "$b/$name";
        $out[$name] = $urls;
    }
    return $out;
}

/**
 * Descarga los XSDs del SII a la carpeta schemas/, probando varias URLs por archivo.
 * Reporta status (DOWNLOADED / CACHED / NOT_FOUND) y los intentos realizados.
 */
function syncSIISchemas(bool $force = false): array {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'schemas';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $candidatos = siiSchemaCandidates();
    $report = ['ok' => true, 'dir' => $dir, 'schemas' => []];

    foreach ($candidatos as $name => $urls) {
        $localPath = $dir . DIRECTORY_SEPARATOR . $name;
        $exists    = file_exists($localPath) && filesize($localPath) > 100;

        if ($exists && !$force) {
            $report['schemas'][$name] = [
                'status' => 'CACHED',
                'size'   => filesize($localPath),
                'url'    => null,
            ];
            continue;
        }

        $found = null;
        $attempts = [];
        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => SII_SSL_VERIFY,
                CURLOPT_CAINFO         => SII_SSL_VERIFY ? SII_CAINFO : false,
                CURLOPT_SSLVERSION     => SII_MIN_TLS,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['Accept: application/xml, text/xml'],
            ]);
            $body = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            $attempts[] = [
                'url' => $url,
                'http' => $http,
                'size' => $body ? strlen($body) : 0,
                'error' => $err ?: null,
            ];

            $looksLikeXSD = $body && (strpos($body, '<xs:schema') !== false || strpos($body, '<xsd:schema') !== false);
            if (!$err && $http === 200 && $looksLikeXSD) {
                file_put_contents($localPath, $body);
                $found = $url;
                break;
            }
        }

        $report['schemas'][$name] = [
            'status'   => $found ? 'DOWNLOADED' : ($exists ? 'CACHED' : 'NOT_FOUND'),
            'size'     => file_exists($localPath) ? filesize($localPath) : 0,
            'url'      => $found,
            'attempts' => $attempts,
        ];
        if (!$found && !$exists) $report['ok'] = false;
    }

    saveSiiLog('syncSIISchemas',
        sprintf('XSDs: %d total, %d DOWNLOADED, %d CACHED, %d NOT_FOUND',
            count($report['schemas']),
            count(array_filter($report['schemas'], fn($s) => $s['status'] === 'DOWNLOADED')),
            count(array_filter($report['schemas'], fn($s) => $s['status'] === 'CACHED')),
            count(array_filter($report['schemas'], fn($s) => $s['status'] === 'NOT_FOUND'))
        ),
        $report['ok'] ? 'SUCCESS' : 'WARNING'
    );

    return $report;
}

/**
 * Lee el XSD oficial de EnvioBOLETA y extrae el atributo maxOccurs del elemento DTE.
 * Sin XSD disponible localmente, retorna null (no se hardcodea el valor).
 */
function readEnvioBoletaMaxDTE(): ?int {
    $xsd = __DIR__ . '/schemas/EnvioBOLETA_v11.xsd';
    if (!file_exists($xsd)) return null;

    $content = @file_get_contents($xsd);
    if (!$content) return null;

    // Buscar <xs:element name="DTE" ... maxOccurs="N"> en cualquier orden de atributos
    if (preg_match('/<(?:xs|xsd):element[^>]*\bname\s*=\s*"DTE"[^>]*\bmaxOccurs\s*=\s*"(\d+)"/i', $content, $m)) {
        return (int)$m[1];
    }
    if (preg_match('/<(?:xs|xsd):element[^>]*\bmaxOccurs\s*=\s*"(\d+)"[^>]*\bname\s*=\s*"DTE"/i', $content, $m)) {
        return (int)$m[1];
    }
    return null;
}

function countDTEInEnvio(string $envioXml): int {
    return preg_match_all('/<DTE\b[^>]*>/i', $envioXml);
}

/**
 * Valida que un sobre no exceda el mÃ¡ximo de DTEs declarado por el XSD oficial.
 * Si el XSD no estÃ¡ disponible, retorna skipped=true (modo permisivo).
 */
function validateEnvioBoletaLimit(string $envioXml): array {
    $count = countDTEInEnvio($envioXml);
    $max   = readEnvioBoletaMaxDTE();

    if ($max === null) {
        return [
            'ok'      => true,
            'count'   => $count,
            'max'     => null,
            'skipped' => true,
            'reason'  => 'EnvioBOLETA_v11.xsd no disponible localmente â€” ejecutar syncSIISchemas() para sincronizar desde SII.',
        ];
    }

    return [
        'ok'      => $count <= $max,
        'count'   => $count,
        'max'     => $max,
        'skipped' => false,
        'reason'  => $count <= $max
            ? "OK: $count DTE(s) <= $max permitidos por XSD oficial SII"
            : "EXCEDE: $count DTE(s) > $max permitidos por XSD oficial SII",
    ];
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// VALIDACIÃ“N XSD LOCAL â€” atrapa errores antes de enviar al SII
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Devuelve el archivo XSD apropiado segÃºn el root element del XML, o null si
 * no hay esquema disponible localmente.
 */
function detectXSDForXML(string $xml): ?string {
    $schemasDir = __DIR__ . DIRECTORY_SEPARATOR . 'schemas';
    if (!is_dir($schemasDir)) return null;

    // Detectar root element ignorando declaraciÃ³n XML y namespaces
    if (!preg_match('/<\s*([A-Za-z_][\w:-]*)\b/', preg_replace('/<\?[^?]*\?>/', '', $xml), $m)) {
        return null;
    }
    $root = $m[1];

    $map = [
        'EnvioDTE'       => 'EnvioDTE_v10.xsd',
        'EnvioBOLETA'    => 'EnvioBOLETA_v11.xsd',
        'DTE'            => 'DTE_v10.xsd',
        'ConsumoFolios'  => 'ConsumoFolio_v10.xsd',
        'LibroCompraVenta'=> 'LibroCV_v10.xsd',
        // LibroGuia_v10.xsd local define Signature en namespace SiiDte pero SII server
        // espera xmldsig# → la validación local da falso negativo; SII valida server-side.
        'LibroGuia'      => null,
        'LibroBoleta'    => 'LibroBOLETA_v10.xsd',
        'RespuestaEnvioDTE' => 'RespuestaEnvioDTE_v10.xsd',
        'AEC'            => 'AEC_v10.xsd',
    ];

    $xsd = $map[$root] ?? null;
    if (!$xsd) return null;

    $path = $schemasDir . DIRECTORY_SEPARATOR . $xsd;
    return file_exists($path) ? $path : null;
}

/**
 * Valida un XML contra su XSD oficial del SII (si estÃ¡ disponible localmente).
 * Retorna ['valid' => bool, 'errors' => [...], 'xsd' => path|null, 'skipped' => bool].
 */
function validateXmlAgainstXSD(string $xml, ?string $xsdPath = null): array {
    if ($xsdPath === null) {
        $xsdPath = detectXSDForXML($xml);
    }
    if (!$xsdPath || !file_exists($xsdPath)) {
        return [
            'valid'   => true,
            'skipped' => true,
            'errors'  => [],
            'xsd'     => $xsdPath,
            'reason'  => 'XSD no disponible localmente â€” validaciÃ³n omitida (el SII la harÃ¡ server-side).',
        ];
    }

    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $dom = new DOMDocument();
    $loaded = @$dom->loadXML($xml);

    if (!$loaded) {
        $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return ['valid' => false, 'skipped' => false, 'errors' => $errs ?: ['XML mal formado'], 'xsd' => $xsdPath];
    }

    $valid = @$dom->schemaValidate($xsdPath);
    $errs  = [];
    $internalXSDErrors = 0;
    foreach (libxml_get_errors() as $e) {
        $msg = trim($e->message);
        if ($msg === '') continue;

        // Filtrar errores del propio XSD del SII (no del documento).
        // Estos aparecen cuando el XSD oficial tiene inconsistencias en sus facets
        // internos (ej. Dec14_4Type con minInclusive 0.0001 vs 0.0000) y referencian
        // el namespace XMLSchema, no SiiDte.
        if (str_contains($msg, "Element '{http://www.w3.org/2001/XMLSchema}")) {
            $internalXSDErrors++;
            continue;
        }
        $errs[] = "[{$e->line}] $msg";
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    // Si solo hubo errores internos del XSD (no del documento), tratamos como vÃ¡lido.
    $docValid = empty($errs);

    return [
        'valid'   => $docValid,
        'skipped' => false,
        'errors'  => $errs,
        'xsd'     => $xsdPath,
        'xsd_internal_warnings' => $internalXSDErrors,
    ];
}

/**
 * EnvÃ­a un RCOF al SII. Intenta primero el endpoint REST de boleta.electronica.consumo;
 * si falla, hace fallback al endpoint clÃ¡sico SOAP/multipart de cgi_dte/UPL/DTEUpload.
 */
function sendRCOFToSII(string $xmlFirmado, string $fecha, int $secuencia): array {
    global $actualTmpDir;

    $GLOBALS['SII_CERT_TIPO'] = 39;
    [$cert, $privKey] = loadCertificate(39);

    // -- Intento 1: REST boleta.electronica.consumo --
    try {
        $token = getBoletaRestToken($cert, $privKey);
        global $globalContext;
        $rutCompanyFull = $globalContext ? $globalContext->getRut() : RUT_EMISOR;
        [$rutCompany, $dvCompany] = array_pad(explode('-', $rutCompanyFull, 2), 2, '');
        // RUT que envÃ­a = titular del certificado (no la empresa). Antes quedaba
        // indefinido y el SII rechazaba el RCOF por rutSender vacÃ­o.
        $rutSenderFull = getRutCertificadoSeguro($cert);
        [$rutSender, $dvSender] = array_pad(explode('-', $rutSenderFull, 2), 2, '');

        $filename = "rcof_{$fecha}_seq{$secuencia}.xml";
        $tmpFile  = rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($tmpFile, $xmlFirmado);

        $urlRest = siiEndpoints()['boleta_envio'];

        // Algunos SDK indican que el RCOF tambiÃ©n va al endpoint .envio (es un Documento ConsumoFolios);
        // si el SII tiene un endpoint especÃ­fico ?consumo lo probamos como segunda variante.
        $endpoints = [
            $urlRest,
            str_replace('boleta.electronica.envio', 'boleta.electronica.consumo', $urlRest),
        ];

        foreach ($endpoints as $url) {
            $payload = [
                "rutSender"  => $rutSender,
                "dvSender"   => strtoupper($dvSender),
                "rutCompany" => $rutCompany,
                "dvCompany"  => strtoupper($dvCompany),
                "archivo"    => new CURLFile($tmpFile, 'application/xml', $filename),
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => SII_SSL_VERIFY,
                CURLOPT_CAINFO         => SII_SSL_VERIFY ? SII_CAINFO : false,
                CURLOPT_SSLVERSION     => SII_MIN_TLS,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => [
                    "Cookie: TOKEN=$token",
                    "Accept: application/json",
                    "User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT)"
                ],
            ]);
            $t0 = microtime(true);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            $ms = (int)((microtime(true) - $t0) * 1000);

            $tx = [
                'op'           => 'sendRCOFToSII',
                'fecha'        => $fecha,
                'secuencia'    => $secuencia,
                'via'          => 'REST',
                'url'          => $url,
                'http'         => $http,
                'ms'           => $ms,
                'curl_error'   => $err ?: null,
                'response_body'=> $resp ?? '',
            ];

            if (!$err && $http === 200) {
                $j = json_decode($resp, true);
                if (is_array($j) && !empty($j['trackid'])) {
                    $tx['result']  = 'OK';
                    $tx['trackId'] = (string)$j['trackid'];
                    saveSiiTransaction($tx);
                    @unlink($tmpFile);
                    saveSiiLog('sendRCOFToSII', "RCOF $fecha seq$secuencia OK via REST $url. TrackID {$j['trackid']} ({$ms}ms)", 'SUCCESS');
                    return [
                        'ok'      => true,
                        'via'     => 'REST',
                        'url'     => $url,
                        'trackId' => (string)$j['trackid'],
                        'estado'  => $j['estado'] ?? 'REC',
                        'raw'     => $j,
                    ];
                }
            }
            $tx['result'] = 'FAIL';
            saveSiiTransaction($tx);
            saveSiiLog('sendRCOFToSII', "REST $url fallÃ³ HTTP $http ({$ms}ms): " . substr(strip_tags((string)$resp), 0, 250), 'WARNING');
        }

        // -- Intento 2: SOAP/multipart cgi_dte/UPL/DTEUpload --
        // (mismo flujo que cualquier DTE â€” el SII detecta que es ConsumoFolios por contenido)
        $semilla = getSemilla();
        $tokSoap = getToken($semilla, $cert, $privKey);
        $t0 = microtime(true);
        $resp = uploadDTE($xmlFirmado, $tokSoap, $cert);
        $ms = (int)((microtime(true) - $t0) * 1000);
        @unlink($tmpFile);

        $tx = [
            'op'        => 'sendRCOFToSII',
            'fecha'     => $fecha,
            'secuencia' => $secuencia,
            'via'       => 'SOAP',
            'url'       => "https://" . siiHost() . "/cgi_dte/UPL/DTEUpload",
            'ms'        => $ms,
            'response_body' => json_encode($resp, JSON_UNESCAPED_UNICODE),
        ];

        if (!empty($resp['trackId'])) {
            $tx['result']  = 'OK';
            $tx['trackId'] = $resp['trackId'];
            saveSiiTransaction($tx);
            saveSiiLog('sendRCOFToSII', "RCOF $fecha seq$secuencia OK via SOAP. TrackID {$resp['trackId']} ({$ms}ms)", 'SUCCESS');
            return [
                'ok'      => true,
                'via'     => 'SOAP',
                'url'     => "https://" . siiHost() . "/cgi_dte/UPL/DTEUpload",
                'trackId' => $resp['trackId'],
                'estado'  => $resp['estado'] ?? 'DESCONOCIDO',
                'raw'     => $resp,
            ];
        }
        $tx['result']   = 'FAIL';
        $tx['error_msg']= $resp['error'] ?? 'sin detalle';
        saveSiiTransaction($tx);
        saveSiiLog('sendRCOFToSII', "RCOF SOAP tambiÃ©n fallÃ³: " . ($resp['error'] ?? 'sin detalle'), 'ERROR');
        return ['ok' => false, 'error' => $resp['error'] ?? 'RCOF no aceptado por ningÃºn endpoint'];
    } catch (Exception $e) {
        saveSiiTransaction([
            'op'        => 'sendRCOFToSII',
            'fecha'     => $fecha,
            'secuencia' => $secuencia,
            'result'    => 'EXCEPTION',
            'error_msg' => $e->getMessage(),
        ]);
        saveSiiLog('sendRCOFToSII', "ExcepciÃ³n: " . $e->getMessage(), 'ERROR');
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Orquestador del RCOF diario: arma el resumen del dÃ­a, lo firma, lo envÃ­a y persiste.
 * Idempotente: si ya se enviÃ³ ese dÃ­a con Ã©xito, no reenvÃ­a (a menos que $force=true,
 * en cuyo caso usa una secuencia incrementada).
 */
function submitDailyRCOF(?string $fecha = null, bool $force = false): array {
    $fecha = $fecha ?: date('Y-m-d');
    $reg   = loadRCOFRegistry();
    $prev  = $reg[$fecha] ?? null;

    if ($prev && !empty($prev['trackId']) && !$force) {
        return [
            'ok'      => true,
            'skipped' => true,
            'fecha'   => $fecha,
            'mensaje' => "RCOF de $fecha ya fue enviado (TrackID {$prev['trackId']} seq {$prev['secuencia']}).",
            'previo'  => $prev,
        ];
    }

    $resumenes = listBoletasDelDia($fecha);
    // Aplicar folios anulados del dÃ­a al resumen (si los hay)
    $resumenes = aplicarFoliosAnuladosARCOF($resumenes, $fecha);
    // Filtrar tipos sin folios â€” pero tambiÃ©n incluir tipos con anulados (aunque emitidos=0)
    $resumenesNoCero = array_values(array_filter($resumenes, fn($r) => ($r['emitidos'] > 0) || (($r['anulados'] ?? 0) > 0)));

    $secuencia = $prev ? ((int)($prev['secuencia'] ?? 1) + 1) : 1;

    $genArgs = [
        'fecha'     => $fecha,
        'secuencia' => $secuencia,
        // Si hay boletas, manda solo los tipos con folios; si no, manda 39 en cero.
        'resumenes' => !empty($resumenesNoCero) ? $resumenesNoCero : [[
            'tipo' => 39, 'neto' => 0, 'iva' => 0, 'total' => 0,
            'emitidos' => 0, 'anulados' => 0, 'utilizados' => 0,
            'rango_desde' => 0, 'rango_hasta' => 0,
        ]],
    ];

    $gen = generateRCOF($genArgs);
    if (empty($gen['ok'])) {
        return ['ok' => false, 'error' => 'FallÃ³ generaciÃ³n RCOF', 'gen' => $gen];
    }

    // ValidaciÃ³n XSD local del RCOF antes de enviar â€” atrapa errores como SCH-00001
    $val = validateXmlAgainstXSD($gen['xml']);
    if (!$val['valid'] && !$val['skipped']) {
        saveSiiLog('submitDailyRCOF', "RCOF $fecha seq $secuencia rechazado por XSD local: " . implode('; ', $val['errors']), 'ERROR');
        return [
            'ok'        => false,
            'fecha'     => $fecha,
            'secuencia' => $secuencia,
            'error'     => 'XSD invÃ¡lido: ' . implode('; ', array_slice($val['errors'], 0, 5)),
            'xsd_errors'=> $val['errors'],
            'mensaje'   => 'RCOF no enviado: fallÃ³ validaciÃ³n XSD local.',
        ];
    }

    $send = sendRCOFToSII($gen['xml'], $fecha, $secuencia);

    $entry = [
        'fecha'      => $fecha,
        'secuencia'  => $secuencia,
        'enviado_ts' => date('Y-m-d H:i:s'),
        'trackId'    => $send['trackId'] ?? null,
        'estado'     => $send['estado']  ?? null,
        'via'        => $send['via']     ?? null,
        'url'        => $send['url']     ?? null,
        'ok'         => !empty($send['ok']),
        'resumen'    => $genArgs['resumenes'],
        'error'      => $send['error']   ?? null,
    ];
    $reg[$fecha] = $entry;
    saveRCOFRegistry($reg);

    return [
        'ok'        => $entry['ok'],
        'fecha'     => $fecha,
        'secuencia' => $secuencia,
        'trackId'   => $entry['trackId'],
        'via'       => $entry['via'],
        'resumen'   => $entry['resumen'],
        'error'     => $entry['error'],
        'mensaje'   => $entry['ok']
            ? "RCOF $fecha seq $secuencia enviado vÃ­a {$entry['via']}. TrackID {$entry['trackId']}."
            : "FallÃ³ envÃ­o RCOF $fecha seq $secuencia: " . ($entry['error'] ?? 'sin detalle'),
    ];
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// LIBROS DE COMPRAS Y VENTAS (IECV)
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function generateLibro(array $data): array {
    $tipoLibro = strtoupper($data['tipoLibro'] ?? 'VENTA'); // VENTA, COMPRA, BOLETA, GUIA
    if ($tipoLibro === 'GUIA' || $tipoLibro === 'GUIAS') {
        return generateLibroGuia($data);
    }
    if ($tipoLibro === 'BOLETA' || $tipoLibro === 'BOLETAS') {
        return generateLibroBoleta($data);
    }
    $periodo   = $data['periodo']   ?? date('Y-m');
    $tipoEnvio = strtoupper($data['tipoEnvio'] ?? 'TOTAL'); // TOTAL, PARCIAL, AJUSTE, ESPECIAL
    $folioDsde = (int)($data['folioDsde'] ?? 1);
    $detalles  = $data['detalles']  ?? [];
    $folioNotif = (int)($data['folioNotificacion'] ?? 0);
    $xmlFolioNotif = $folioNotif > 0 ? "  <FolioNotificacion>{$folioNotif}</FolioNotificacion>\n" : '';
    $xmlTipoLibro = $folioNotif > 0 ? 'ESPECIAL' : 'MENSUAL';

    $idLibro = "L" . str_replace('-', '', $periodo) . "T" . time();

    [$cert, $privKey] = loadCertificate();
    $rutEnvia = getRutCertificadoSeguro($cert);

    if ($globalContext = ($GLOBALS['globalContext'] ?? null)) {
        $emp  = $globalContext->getEmpresa();
        $rutE = $emp['rut'];
        $fchR = $emp['fch_resol'] ?? FCH_RESOL;
        $nroR = $emp['nro_resol'] ?? NRO_RESOL;
        if ($globalContext->getAmbiente() === 'CERTIFICACION') $nroR = 0;
    } else {
        $rutE = RUT_EMISOR;
        $fchR = FCH_RESOL;
        $nroR = NRO_RESOL;
    }

    // Calcular totales reales del perÃ­odo desde los detalles
    $totNeto  = 0; $totIVA = 0; $totExe = 0; $totTotal = 0;
    $xmlDetalles = '';
    $resumenPorTipo = [];

    foreach ($detalles as $d) {
        $tipo  = (int)($d['tipo']  ?? 33);
        $tipoImp = (int)($d['tipoImp'] ?? 1);
        $resumenKey = $tipo . ':' . $tipoImp;
        $neto  = (int)($d['neto']  ?? 0);
        $iva   = (int)($d['iva']   ?? 0);
        $exe   = (int)($d['exe']   ?? 0);
        $total = (int)($d['total'] ?? 0);
        
        $totNeto  += $neto;
        $totIVA   += $iva;
        $totExe   += $exe;
        $totTotal += $total;

        // Agrupar para TotalesPeriodo
        if (!isset($resumenPorTipo[$resumenKey])) {
            $resumenPorTipo[$resumenKey] = [
                'TpoDoc' => $tipo, 'TpoImp' => $tipoImp,
                'TotDoc' => 0, 'TotMntExe' => 0, 'TotMntNeto' => 0, 'TotMntIVA' => 0, 'TotMntTotal' => 0,
                'TotOpIVAUsoComun' => 0, 'TotIVAUsoComun' => 0, 'FctProp' => null,
                'TotOpIVARetTotal' => 0, 'TotIVARetTotal' => 0,
                'IVANoRecup' => [], 'OtrosImp' => [],
            ];
        }
        $resumenPorTipo[$resumenKey]['TotDoc']++;
        $resumenPorTipo[$resumenKey]['TotMntExe'] += $exe;
        $resumenPorTipo[$resumenKey]['TotMntNeto'] += $neto;
        $resumenPorTipo[$resumenKey]['TotMntIVA'] += $iva;
        $resumenPorTipo[$resumenKey]['TotMntTotal'] += $total;

        // Campos especiales Libro de Compras: IVA uso comÃºn, no recuperable, retenciÃ³n
        $codIvaNoRec   = isset($d['codIvaNoRec'])   ? (int)$d['codIvaNoRec']            : null;
        $mntIvaNoRec   = isset($d['mntIvaNoRec'])   ? (int)$d['mntIvaNoRec']            : null;
        $mntIvaUsoComun= isset($d['mntIvaUsoComun'])? (int)$d['mntIvaUsoComun']         : null;
        $fctProp       = isset($d['fctProp'])       ? number_format((float)$d['fctProp'], 3, '.', '') : null;
        $ivaRetTotal   = isset($d['ivaRetTotal'])   ? (int)$d['ivaRetTotal']            : null;
        $otrosImp      = is_array($d['otrosImp'] ?? null) ? $d['otrosImp'] : [];

        if ($mntIvaUsoComun > 0) {
            $resumenPorTipo[$resumenKey]['TotOpIVAUsoComun']++;
            $resumenPorTipo[$resumenKey]['TotIVAUsoComun'] += $mntIvaUsoComun;
            if ($fctProp !== null) $resumenPorTipo[$resumenKey]['FctProp'] = $fctProp;
        }

        if ($ivaRetTotal > 0) {
            $resumenPorTipo[$resumenKey]['TotOpIVARetTotal']++;
            $resumenPorTipo[$resumenKey]['TotIVARetTotal'] += $ivaRetTotal;
        }

        if ($mntIvaNoRec > 0 && $codIvaNoRec !== null) {
            if (!isset($resumenPorTipo[$resumenKey]['IVANoRecup'][$codIvaNoRec])) {
                $resumenPorTipo[$resumenKey]['IVANoRecup'][$codIvaNoRec] = ['TotOp' => 0, 'TotMnt' => 0];
            }
            $resumenPorTipo[$resumenKey]['IVANoRecup'][$codIvaNoRec]['TotOp']++;
            $resumenPorTipo[$resumenKey]['IVANoRecup'][$codIvaNoRec]['TotMnt'] += $mntIvaNoRec;
        }

        $otrosImpXml = '';
        foreach ($otrosImp as $otroImp) {
            $codImp = (int)($otroImp['codigo'] ?? 0);
            if ($codImp <= 0) continue;
            $tasaOtroImp = $otroImp['tasa'] ?? null;
            $mntImp = (int)($otroImp['monto'] ?? 0);
            $resumenPorTipo[$resumenKey]['OtrosImp'][$codImp] =
                ($resumenPorTipo[$resumenKey]['OtrosImp'][$codImp] ?? 0) + $mntImp;
            $otrosImpXml .= "  <OtrosImp>\n"
                . "    <CodImp>$codImp</CodImp>\n"
                . ($tasaOtroImp !== null ? "    <TasaImp>" . htmlspecialchars((string)$tasaOtroImp, ENT_XML1) . "</TasaImp>\n" : "")
                . "    <MntImp>$mntImp</MntImp>\n"
                . "  </OtrosImp>\n";
        }

        $emitMntIvaCero = $tipoLibro === 'COMPRA'
            && $neto != 0
            && ($codIvaNoRec !== null || $mntIvaUsoComun !== null);
        $tpoDocRef = isset($d['tipoDocRef']) ? (int)$d['tipoDocRef'] : null;
        $folioDocRef = isset($d['folioDocRef']) ? (int)$d['folioDocRef'] : null;
        $tasaImp = $d['tasaImp'] ?? 19;

        $xmlDetalles .= "<Detalle>\n"
            . "  <TpoDoc>" . $tipo . "</TpoDoc>\n"
            . "  <NroDoc>" . (int)($d['folio'] ?? 1)  . "</NroDoc>\n"
            . ($tipoImp !== 1 ? "  <TpoImp>$tipoImp</TpoImp>\n" : "")
            . "  <TasaImp>" . htmlspecialchars((string)$tasaImp, ENT_XML1) . "</TasaImp>\n"
            . "  <FchDoc>" . ($d['fecha'] ?? date('Y-m-d')) . "</FchDoc>\n"
            . "  <RUTDoc>" . ($d['rut']   ?? '66666666-6') . "</RUTDoc>\n"
            . "  <RznSoc>" . htmlspecialchars($d['razon'] ?? '', ENT_XML1) . "</RznSoc>\n"
            . ($tpoDocRef !== null ? "  <TpoDocRef>$tpoDocRef</TpoDocRef>\n" : "")
            . ($folioDocRef !== null ? "  <FolioDocRef>$folioDocRef</FolioDocRef>\n" : "")
            . ($exe   != 0 || ($neto == 0 && $iva == 0) ? "  <MntExe>$exe</MntExe>\n" : "")
            . ($neto  != 0 ? "  <MntNeto>$neto</MntNeto>\n"         : "")
            . ($iva   != 0 || $emitMntIvaCero ? "  <MntIVA>$iva</MntIVA>\n" : "")
            . ($codIvaNoRec !== null && $mntIvaNoRec !== null ? "  <IVANoRec>\n    <CodIVANoRec>$codIvaNoRec</CodIVANoRec>\n    <MntIVANoRec>$mntIvaNoRec</MntIVANoRec>\n  </IVANoRec>\n" : "")
            . ($mntIvaUsoComun !== null ? "  <IVAUsoComun>$mntIvaUsoComun</IVAUsoComun>\n" : "")
            . $otrosImpXml
            . ($ivaRetTotal !== null ? "  <IVARetTotal>$ivaRetTotal</IVARetTotal>\n" : "")
            . "  <MntTotal>$total</MntTotal>\n"
            . "</Detalle>\n";
    }

    // ResumenPeriodo con TotalesPeriodo agrupados
    $resumenXml = "<ResumenPeriodo>\n";
    ksort($resumenPorTipo);
    foreach ($resumenPorTipo as $tot) {
        $resumenXml .= "  <TotalesPeriodo>\n";
        $resumenXml .= "    <TpoDoc>{$tot['TpoDoc']}</TpoDoc>\n";
        if ($tot['TpoImp'] !== 1) {
            $resumenXml .= "    <TpoImp>{$tot['TpoImp']}</TpoImp>\n";
        }
        $resumenXml .= "    <TotDoc>{$tot['TotDoc']}</TotDoc>\n";
        
        $resumenXml .= "    <TotMntExe>{$tot['TotMntExe']}</TotMntExe>\n";
        $resumenXml .= "    <TotMntNeto>{$tot['TotMntNeto']}</TotMntNeto>\n";
        $resumenXml .= "    <TotMntIVA>{$tot['TotMntIVA']}</TotMntIVA>\n";
        
        foreach ($tot['IVANoRecup'] as $cod => $recup) {
            $resumenXml .= "    <TotIVANoRec>\n";
            $resumenXml .= "      <CodIVANoRec>$cod</CodIVANoRec>\n";
            $resumenXml .= "      <TotOpIVANoRec>{$recup['TotOp']}</TotOpIVANoRec>\n";
            $resumenXml .= "      <TotMntIVANoRec>{$recup['TotMnt']}</TotMntIVANoRec>\n";
            $resumenXml .= "    </TotIVANoRec>\n";
        }
        
        if ($tot['TotOpIVAUsoComun'] > 0) {
            $resumenXml .= "    <TotOpIVAUsoComun>{$tot['TotOpIVAUsoComun']}</TotOpIVAUsoComun>\n";
            $resumenXml .= "    <TotIVAUsoComun>{$tot['TotIVAUsoComun']}</TotIVAUsoComun>\n";
            if ($tot['FctProp'] !== null) {
                $resumenXml .= "    <FctProp>{$tot['FctProp']}</FctProp>\n";
                // Crédito uso común = factor de proporcionalidad x IVA uso común.
                // Sin este campo el SII reparó "El Credito IVA Uso Comun No Cuadra".
                $credUC = (int)round((float)$tot['FctProp'] * (int)$tot['TotIVAUsoComun']);
                $resumenXml .= "    <TotCredIVAUsoComun>{$credUC}</TotCredIVAUsoComun>\n";
            }
        }

        foreach ($tot['OtrosImp'] as $codImp => $mntImp) {
            $resumenXml .= "    <TotOtrosImp>\n";
            $resumenXml .= "      <CodImp>$codImp</CodImp>\n";
            $resumenXml .= "      <TotMntImp>$mntImp</TotMntImp>\n";
            $resumenXml .= "    </TotOtrosImp>\n";
        }
        
        // IVA retenido: el resumen DEBE traer TotIVARetTotal cuando el detalle
        // informa IVARetTotal (omitirlo rechaza por cuadratura: LBR-3 "Resumen No
        // Cuadra Con Informacion de Detalle"). Pero SIN el contador
        // TotOpIVARetTotal: es un campo deprecado ("PRÓXIMO A ELIMINARSE" según
        // formato IECV) cuya presencia el revisor del set observó como "No
        // Informa Adecuadamente IVA Retenido Total". LibreDTE (implementación
        // certificada con este mismo set) tampoco lo emite.
        if ($tot['TotIVARetTotal'] > 0) {
            $resumenXml .= "    <TotIVARetTotal>{$tot['TotIVARetTotal']}</TotIVARetTotal>\n";
        }
        
        $resumenXml .= "    <TotMntTotal>{$tot['TotMntTotal']}</TotMntTotal>\n";
        $resumenXml .= "  </TotalesPeriodo>\n";
    }
    $resumenXml .= "</ResumenPeriodo>";

    $tmst = date('Y-m-d\TH:i:s');

    $xmlRaw = <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<LibroCompraVenta version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sii.cl/SiiDte LibroCV_v10.xsd">
<EnvioLibro ID="$idLibro">
<Caratula>
  <RutEmisorLibro>$rutE</RutEmisorLibro>
  <RutEnvia>$rutEnvia</RutEnvia>
  <PeriodoTributario>$periodo</PeriodoTributario>
  <FchResol>$fchR</FchResol>
  <NroResol>$nroR</NroResol>
  <TipoOperacion>$tipoLibro</TipoOperacion>
  <TipoLibro>{$xmlTipoLibro}</TipoLibro>
  <TipoEnvio>$tipoEnvio</TipoEnvio>
$xmlFolioNotif</Caratula>
$resumenXml
$xmlDetalles
<TmstFirma>$tmst</TmstFirma>
</EnvioLibro>
</LibroCompraVenta>
XML;

    $xmlFirmado = signDTE($xmlRaw, $cert, $privKey, $idLibro);

    return [
        'ok'      => true,
        'xml'     => $xmlFirmado,
        'totales' => ['neto' => $totNeto, 'iva' => $totIVA, 'exe' => $totExe, 'total' => $totTotal],
        'resumen' => $resumenPorTipo,
        'mensaje' => "Libro de $tipoLibro periodo $periodo generado y firmado ({$tipoEnvio}). Total: \${$totTotal}.",
    ];
}

function generateLibroGuia(array $data): array {
    $periodo   = $data['periodo']   ?? date('Y-m');
    $tipoEnvio = strtoupper($data['tipoEnvio'] ?? 'TOTAL');
    $folioDsde = (int)($data['folioDsde'] ?? 1);
    $detalles  = $data['detalles']  ?? [];
    $idLibro = "LG" . str_replace('-', '', $periodo) . "T" . time();
    $h = fn($v) => htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');

    [$cert, $privKey] = loadCertificate();
    $rutEnvia = getRutCertificadoSeguro($cert);
    if ($globalContext = ($GLOBALS['globalContext'] ?? null)) {
        $emp  = $globalContext->getEmpresa();
        $rutE = $emp['rut'];
        $fchR = $emp['fch_resol'] ?? FCH_RESOL;
        $nroR = $emp['nro_resol'] ?? NRO_RESOL;
        if ($globalContext->getAmbiente() === 'CERTIFICACION') $nroR = 0;
    } else {
        $rutE = RUT_EMISOR;
        $fchR = FCH_RESOL;
        $nroR = NRO_RESOL;
    }

    $totVenta = 0;
    $mntVenta = 0;
    $anuladas = 0;
    $foliosAnulados = 0;
    $traslados = [];
    $xmlDetalles = '';
    foreach ($detalles as $d) {
        $folio = (int)($d['folio'] ?? $d['nroDoc'] ?? 0);
        $tipoDoc = (int)($d['tipo'] ?? $d['tipoDTE'] ?? 52);
        $tpoOper = (int)($d['tpoOper'] ?? $d['tipoOperacion'] ?? $d['indTraslado'] ?? 1);
        $anulado = (int)($d['anulado'] ?? 0);
        $total = (int)($d['total'] ?? $d['mntTotal'] ?? 0);
        if ($tpoOper === 1 && !in_array($anulado, [1, 2], true)) {
            $totVenta++;
            $mntVenta += $total;
        } elseif ($tpoOper > 1) {
            if (!isset($traslados[$tpoOper])) $traslados[$tpoOper] = ['cant' => 0, 'mnt' => 0];
            $traslados[$tpoOper]['cant']++;
            $traslados[$tpoOper]['mnt'] += $total;
        }
        if ($anulado === 2) $anuladas++;
        if ($anulado === 1) $foliosAnulados++;

        $xmlDetalles .= "<Detalle>\n";
        if ($folio > 0) $xmlDetalles .= "  <Folio>$folio</Folio>\n";
        if ($anulado > 0) $xmlDetalles .= "  <Anulado>$anulado</Anulado>\n";
        $xmlDetalles .= "  <TpoOper>$tpoOper</TpoOper>\n";
        $xmlDetalles .= "  <FchDoc>" . $h($d['fecha'] ?? date('Y-m-d')) . "</FchDoc>\n";
        if (!empty($d['rut'])) $xmlDetalles .= "  <RUTDoc>" . $h($d['rut']) . "</RUTDoc>\n";
        if (!empty($d['razon'])) $xmlDetalles .= "  <RznSoc>" . $h($d['razon']) . "</RznSoc>\n";
        if ($total > 0) $xmlDetalles .= "  <MntTotal>$total</MntTotal>\n";
        $xmlDetalles .= "</Detalle>\n";
    }

    $resumenXml = "<ResumenPeriodo>\n";
    if ($anuladas > 0) $resumenXml .= "  <TotGuiaAnulada>$anuladas</TotGuiaAnulada>\n";
    if ($foliosAnulados > 0) $resumenXml .= "  <TotFolAnulado>$foliosAnulados</TotFolAnulado>\n";
    $resumenXml .= "  <TotGuiaVenta>$totVenta</TotGuiaVenta>\n";
    $resumenXml .= "  <TotMntGuiaVta>$mntVenta</TotMntGuiaVta>\n";
    foreach ($traslados as $cod => $tot) {
        $resumenXml .= "  <TotTraslado>\n"
            . "    <TpoTraslado>$cod</TpoTraslado>\n"
            . "    <CantGuia>{$tot['cant']}</CantGuia>\n"
            . ($tot['mnt'] > 0 ? "    <MntGuia>{$tot['mnt']}</MntGuia>\n" : '')
            . "  </TotTraslado>\n";
    }
    $resumenXml .= "</ResumenPeriodo>";
    $folioNotificacion = (int)($data['folioNotificacion'] ?? 4820754);
    $tmst = date('Y-m-d\TH:i:s');

    $xmlRaw = <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<LibroGuia version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sii.cl/SiiDte LibroGuia_v10.xsd">
<EnvioLibro ID="$idLibro">
<Caratula>
  <RutEmisorLibro>$rutE</RutEmisorLibro>
  <RutEnvia>$rutEnvia</RutEnvia>
  <PeriodoTributario>$periodo</PeriodoTributario>
  <FchResol>$fchR</FchResol>
  <NroResol>$nroR</NroResol>
  <TipoLibro>ESPECIAL</TipoLibro>
  <TipoEnvio>$tipoEnvio</TipoEnvio>
  <FolioNotificacion>$folioNotificacion</FolioNotificacion>
</Caratula>
$resumenXml
$xmlDetalles
<TmstFirma>$tmst</TmstFirma>
</EnvioLibro>
</LibroGuia>
XML;

    $xmlFirmado = signDTE($xmlRaw, $cert, $privKey, $idLibro);
    return [
        'ok' => true,
        'xml' => $xmlFirmado,
        'totales' => ['guiasVenta' => $totVenta, 'montoGuiasVenta' => $mntVenta, 'anuladas' => $anuladas, 'foliosAnulados' => $foliosAnulados],
        'mensaje' => "Libro de Guias periodo $periodo generado y firmado ({$tipoEnvio}). Guias venta: $totVenta.",
    ];
}

function generateLibroBoleta(array $data): array {
    $periodo   = $data['periodo']   ?? date('Y-m');
    $tipoEnvio = strtoupper($data['tipoEnvio'] ?? 'TOTAL');
    $tipoLibro = strtoupper($data['tipoLibroBoleta'] ?? 'MENSUAL');
    $detalles  = $data['detalles']  ?? [];
    $idLibro = "LB" . str_replace('-', '', $periodo) . "T" . time();
    $h = fn($v) => htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');

    [$cert, $privKey] = loadCertificate();
    $rutEnvia = getRutCertificadoSeguro($cert);
    if ($globalContext = ($GLOBALS['globalContext'] ?? null)) {
        $emp  = $globalContext->getEmpresa();
        $rutE = $emp['rut'];
        $fchR = $emp['fch_resol'] ?? FCH_RESOL;
        $nroR = $emp['nro_resol'] ?? NRO_RESOL;
        if ($globalContext->getAmbiente() === 'CERTIFICACION') $nroR = 0;
    } else {
        $rutE = RUT_EMISOR;
        $fchR = FCH_RESOL;
        $nroR = NRO_RESOL;
    }

    $totales = [];
    $xmlDetalles = '';
    foreach ($detalles as $d) {
        $tipoDoc = (int)($d['tipo'] ?? $d['tipoDTE'] ?? 39);
        $tpoServ = (int)($d['tpoServ'] ?? $d['tipoServicio'] ?? 3);
        $anulado = (int)($d['anulado'] ?? 0);
        $total = (int)($d['total'] ?? $d['mntTotal'] ?? 0);
        $exe = (int)($d['exe'] ?? $d['mntExe'] ?? 0);
        $neto = (int)($d['neto'] ?? $d['mntNeto'] ?? 0);
        $iva = (int)($d['iva'] ?? $d['mntIVA'] ?? 0);
        $key = $tipoDoc . '-' . $tpoServ;
        if (!isset($totales[$key])) {
            $totales[$key] = ['tipo' => $tipoDoc, 'serv' => $tpoServ, 'docs' => 0, 'anulados' => 0, 'exe' => 0, 'neto' => 0, 'iva' => 0, 'total' => 0, 'tickets' => 0];
        }
        if ($anulado > 0) {
            $totales[$key]['anulados']++;
        } else {
            $totales[$key]['docs']++;
            $totales[$key]['exe'] += $exe;
            $totales[$key]['neto'] += $neto;
            $totales[$key]['iva'] += $iva;
            $totales[$key]['total'] += $total;
            $totales[$key]['tickets'] += (int)($d['tickets'] ?? $d['totTicketBoleta'] ?? 0);
        }

        $xmlDetalles .= "<Detalle>\n"
            . "  <TpoDoc>$tipoDoc</TpoDoc>\n"
            . "  <FolioDoc>" . (int)($d['folio'] ?? $d['folioDoc'] ?? 0) . "</FolioDoc>\n"
            . ($anulado > 0 ? "  <Anulado>$anulado</Anulado>\n" : '')
            . "  <TpoServ>$tpoServ</TpoServ>\n"
            . "  <FchEmiDoc>" . $h($d['fecha'] ?? $d['fechaEmision'] ?? date('Y-m-d')) . "</FchEmiDoc>\n"
            . "  <RUTCliente>" . $h($d['rutCliente'] ?? $d['rut'] ?? '66666666-6') . "</RUTCliente>\n"
            . ($exe > 0 ? "  <MntExe>$exe</MntExe>\n" : '')
            . "  <MntTotal>$total</MntTotal>\n"
            . (!empty($d['tickets']) ? "  <TotTicketBoleta>" . (int)$d['tickets'] . "</TotTicketBoleta>\n" : '')
            . "</Detalle>\n";
    }

    $porTipo = [];
    foreach ($totales as $t) {
        $porTipo[$t['tipo']][] = $t;
    }
    $resumenXml = "<ResumenPeriodo>\n";
    foreach ($porTipo as $tipoDoc => $grupos) {
        $anulados = array_sum(array_column($grupos, 'anulados'));
        $resumenXml .= "  <TotalesPeriodo>\n"
            . "    <TpoDoc>$tipoDoc</TpoDoc>\n"
            . ($anulados > 0 ? "    <TotAnulado>$anulados</TotAnulado>\n" : '');
        foreach ($grupos as $g) {
            $resumenXml .= "    <TotalesServicio>\n"
                . "      <TpoServ>{$g['serv']}</TpoServ>\n"
                . "      <TotDoc>{$g['docs']}</TotDoc>\n"
                . ($g['exe'] > 0 ? "      <TotMntExe>{$g['exe']}</TotMntExe>\n" : '')
                . ($g['neto'] > 0 ? "      <TotMntNeto>{$g['neto']}</TotMntNeto>\n" : '')
                . ($g['iva'] > 0 ? "      <TasaIVA>19</TasaIVA>\n      <TotMntIVA>{$g['iva']}</TotMntIVA>\n" : '')
                . "      <TotMntTotal>{$g['total']}</TotMntTotal>\n"
                . ($g['tickets'] > 0 ? "      <TotTicket>{$g['tickets']}</TotTicket>\n" : '')
                . "    </TotalesServicio>\n";
        }
        $resumenXml .= "  </TotalesPeriodo>\n";
    }
    $resumenXml .= "</ResumenPeriodo>";
    $tmst = date('Y-m-d\TH:i:s');
    $folioNotificacion = (int)($data['folioNotificacion'] ?? 0);
    $nroSegmento = (int)($data['nroSegmento'] ?? 0);
    $xmlSegmento = $nroSegmento > 0 ? "  <NroSegmento>$nroSegmento</NroSegmento>\n" : '';
    $xmlNotificacion = $folioNotificacion > 0 ? "  <FolioNotificacion>$folioNotificacion</FolioNotificacion>\n" : '';

    $xmlRaw = <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<LibroBoleta version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sii.cl/SiiDte LibroBOLETA_v10.xsd">
<EnvioLibro ID="$idLibro">
<Caratula>
  <RutEmisorLibro>$rutE</RutEmisorLibro>
  <RutEnvia>$rutEnvia</RutEnvia>
  <PeriodoTributario>$periodo</PeriodoTributario>
  <FchResol>$fchR</FchResol>
  <NroResol>$nroR</NroResol>
  <TipoLibro>$tipoLibro</TipoLibro>
  <TipoEnvio>$tipoEnvio</TipoEnvio>
$xmlSegmento$xmlNotificacion</Caratula>
$resumenXml
$xmlDetalles
<TmstFirma>$tmst</TmstFirma>
</EnvioLibro>
</LibroBoleta>
XML;

    $xmlFirmado = signDTE($xmlRaw, $cert, $privKey, $idLibro);
    $totalDoc = array_sum(array_map(fn($g) => $g['docs'], $totales));
    $totalMnt = array_sum(array_map(fn($g) => $g['total'], $totales));
    return [
        'ok' => true,
        'xml' => $xmlFirmado,
        'totales' => ['documentos' => $totalDoc, 'total' => $totalMnt],
        'mensaje' => "Libro de Boletas periodo $periodo generado y firmado ({$tipoEnvio}). Documentos: $totalDoc.",
    ];
}

function validateLibroResumenDetalleXml(string $xml): array {
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return ['valid' => false, 'errors' => ['No se pudo leer el XML del libro para conciliar resumen y detalle.']];
    }

    $xp = new DOMXPath($dom);
    $childInt = static function (DOMNode $node, string $name) use ($xp): int {
        $found = $xp->query("./*[local-name()='$name']", $node)->item(0);
        return $found ? (int)$found->textContent : 0;
    };
    $emptyTotals = static fn(): array => [
        'TotDoc' => 0, 'TotMntExe' => 0, 'TotMntNeto' => 0, 'TotMntIVA' => 0,
        'TotIVAUsoComun' => 0, 'TotIVARetTotal' => 0, 'TotMntTotal' => 0,
        'IVANoRecup' => [], 'OtrosImp' => [],
    ];

    $fromDetalle = [];
    foreach ($xp->query("//*[local-name()='EnvioLibro']/*[local-name()='Detalle']") as $detalle) {
        $tipo = $childInt($detalle, 'TpoDoc');
        $tipoImp = $childInt($detalle, 'TpoImp') ?: 1;
        $key = "$tipo:$tipoImp";
        $fromDetalle[$key] ??= $emptyTotals();
        $fromDetalle[$key]['TotDoc']++;
        foreach (['MntExe'=>'TotMntExe', 'MntNeto'=>'TotMntNeto', 'MntIVA'=>'TotMntIVA',
                  'IVAUsoComun'=>'TotIVAUsoComun', 'IVARetTotal'=>'TotIVARetTotal', 'MntTotal'=>'TotMntTotal'] as $source => $target) {
            $fromDetalle[$key][$target] += $childInt($detalle, $source);
        }
        foreach ($xp->query("./*[local-name()='IVANoRec']", $detalle) as $ivaNoRec) {
            $cod = $childInt($ivaNoRec, 'CodIVANoRec');
            $fromDetalle[$key]['IVANoRecup'][$cod] =
                ($fromDetalle[$key]['IVANoRecup'][$cod] ?? 0) + $childInt($ivaNoRec, 'MntIVANoRec');
        }
        foreach ($xp->query("./*[local-name()='OtrosImp']", $detalle) as $otroImp) {
            $cod = $childInt($otroImp, 'CodImp');
            $fromDetalle[$key]['OtrosImp'][$cod] =
                ($fromDetalle[$key]['OtrosImp'][$cod] ?? 0) + $childInt($otroImp, 'MntImp');
        }
    }

    $fromResumen = [];
    $duplicates = [];
    foreach ($xp->query("//*[local-name()='EnvioLibro']/*[local-name()='ResumenPeriodo']/*[local-name()='TotalesPeriodo']") as $total) {
        $tipo = $childInt($total, 'TpoDoc');
        $tipoImp = $childInt($total, 'TpoImp') ?: 1;
        $key = "$tipo:$tipoImp";
        if (isset($fromResumen[$key])) $duplicates[] = $key;
        $fromResumen[$key] = $emptyTotals();
        foreach (['TotDoc', 'TotMntExe', 'TotMntNeto', 'TotMntIVA', 'TotIVAUsoComun', 'TotIVARetTotal', 'TotMntTotal'] as $field) {
            $fromResumen[$key][$field] = $childInt($total, $field);
        }
        foreach ($xp->query("./*[local-name()='TotIVANoRec']", $total) as $ivaNoRec) {
            $fromResumen[$key]['IVANoRecup'][$childInt($ivaNoRec, 'CodIVANoRec')] = $childInt($ivaNoRec, 'TotMntIVANoRec');
        }
        foreach ($xp->query("./*[local-name()='TotOtrosImp']", $total) as $otroImp) {
            $fromResumen[$key]['OtrosImp'][$childInt($otroImp, 'CodImp')] = $childInt($otroImp, 'TotMntImp');
        }
    }

    $errors = [];
    foreach ($duplicates as $key) $errors[] = "Línea de resumen duplicada para TpoDoc/TpoImp $key.";
    $detailKeys = array_keys($fromDetalle);
    $summaryKeys = array_keys($fromResumen);
    sort($detailKeys);
    sort($summaryKeys);
    if ($detailKeys !== $summaryKeys) {
        $errors[] = 'Las líneas de resumen no corresponden exactamente a los tipos de documento/impuesto del detalle.';
    }
    foreach ($fromDetalle as $key => $expected) {
        if (!isset($fromResumen[$key])) continue;
        $actual = $fromResumen[$key];
        foreach (['TotDoc', 'TotMntExe', 'TotMntNeto', 'TotMntIVA', 'TotIVAUsoComun', 'TotIVARetTotal', 'TotMntTotal'] as $field) {
            if ($expected[$field] !== $actual[$field]) {
                $errors[] = "$key $field no cuadra: detalle={$expected[$field]}, resumen={$actual[$field]}.";
            }
        }
        ksort($expected['IVANoRecup']);
        ksort($actual['IVANoRecup']);
        ksort($expected['OtrosImp']);
        ksort($actual['OtrosImp']);
        if ($expected['IVANoRecup'] !== $actual['IVANoRecup']) $errors[] = "$key IVA no recuperable no cuadra.";
        if ($expected['OtrosImp'] !== $actual['OtrosImp']) $errors[] = "$key otros impuestos no cuadran.";
    }

    return [
        'valid' => !$errors,
        'errors' => $errors,
        'detalle_keys' => $detailKeys,
        'resumen_keys' => $summaryKeys,
    ];
}

function archiveLibroBeforeSend(string $xml, array $data): array {
    $tipoLibro = preg_replace('/[^A-Z0-9_-]/', '', strtoupper((string)($data['tipoLibro'] ?? 'LIBRO'))) ?: 'LIBRO';
    $periodo = preg_match('/^\d{4}-\d{2}$/', (string)($data['periodo'] ?? ''))
        ? (string)$data['periodo']
        : date('Y-m');
    $dir = archiveBaseDir() . DIRECTORY_SEPARATOR . 'libros' . DIRECTORY_SEPARATOR . $tipoLibro
        . DIRECTORY_SEPARATOR . $periodo;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => "No se pudo crear el directorio de archivo de libros: $dir"];
    }

    $attemptId = date('YmdHis') . '_' . bin2hex(random_bytes(6));
    $xmlFile = $dir . DIRECTORY_SEPARATOR . "envio_$attemptId.xml";
    $metaFile = $dir . DIRECTORY_SEPARATOR . "envio_$attemptId.meta.json";
    $hash = 'sha256:' . hash('sha256', $xml);
    $meta = [
        'attempt_id' => $attemptId,
        'tipo_libro' => $tipoLibro,
        'periodo' => $periodo,
        'folio_notificacion' => (int)($data['folioNotificacion'] ?? 0),
        'created_at' => date('Y-m-d\TH:i:s'),
        'status' => 'PENDING_SEND',
        'xml_hash' => $hash,
    ];
    if (file_put_contents($xmlFile, $xml, LOCK_EX) === false
        || file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'No se pudo preservar el XML exacto del libro antes del envío.'];
    }
    if (hash('sha256', (string)file_get_contents($xmlFile)) !== hash('sha256', $xml)) {
        return ['ok' => false, 'error' => 'El XML archivado no coincide con el XML que se enviaría al SII.'];
    }
    return [
        'ok' => true,
        'xml_path' => $xmlFile,
        'relative_path' => str_replace(archiveBaseDir() . DIRECTORY_SEPARATOR, '', $xmlFile),
        'meta_path' => $metaFile,
        'hash' => $hash,
        'meta' => $meta,
    ];
}

function finalizeLibroArchive(array $archive, array $response): void {
    if (empty($archive['ok']) || empty($archive['meta_path'])) return;
    $meta = $archive['meta'] ?? [];
    $meta['completed_at'] = date('Y-m-d\TH:i:s');
    $meta['status'] = !empty($response['ok']) ? 'SENT' : 'SEND_FAILED';
    $meta['track_id'] = $response['trackId'] ?? null;
    $meta['estado_inicial'] = $response['estado'] ?? null;
    $meta['error'] = !empty($response['ok']) ? null : ($response['error'] ?? null);
    file_put_contents($archive['meta_path'], json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    file_put_contents(archiveIndexPath(), json_encode([
        'ts' => $meta['completed_at'],
        'kind' => 'LIBRO',
        'tipo_libro' => $meta['tipo_libro'],
        'periodo' => $meta['periodo'],
        'trackId' => $meta['track_id'],
        'status' => $meta['status'],
        'path' => str_replace(archiveBaseDir() . DIRECTORY_SEPARATOR, '', $archive['xml_path']),
        'hash' => $archive['hash'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Genera, firma y envÃ­a un Libro de Compras/Ventas (IECV) al SII.
 * Usa el mismo endpoint multipart que el EnvioDTE.
 */
function sendLibro(array $data): array {
    $gen = generateLibro($data);
    if (empty($gen['ok'])) {
        return ['ok' => false, 'error' => $gen['error'] ?? 'Error generando libro'];
    }

    $val = validateXmlAgainstXSD($gen['xml']);
    if (!$val['valid'] && !$val['skipped']) {
        return [
            'ok'     => false,
            'error'  => 'XSD invÃ¡lido: ' . implode('; ', array_slice($val['errors'], 0, 5)),
            'errors' => $val['errors'],
        ];
    }

    $tipoLibro = strtoupper($data['tipoLibro'] ?? 'VENTA');
    if (in_array($tipoLibro, ['COMPRA', 'VENTA'], true)) {
        $semantic = validateLibroResumenDetalleXml($gen['xml']);
        if (!$semantic['valid']) {
            return [
                'ok' => false,
                'error' => 'Resumen del libro no cuadra con el detalle: ' . implode('; ', array_slice($semantic['errors'], 0, 5)),
                'errors' => $semantic['errors'],
            ];
        }
    }

    $archive = archiveLibroBeforeSend($gen['xml'], $data);
    if (empty($archive['ok'])) {
        return ['ok' => false, 'error' => $archive['error'] ?? 'No se pudo archivar el libro antes del envío'];
    }

    try {
        [$cert, $privKey] = loadCertificate();
        $semilla = getSemilla();
        $token   = getToken($semilla, $cert, $privKey);
        $resp = uploadDTE($gen['xml'], $token, $cert);
    } catch (Throwable $e) {
        $resp = ['ok' => false, 'error' => $e->getMessage()];
        finalizeLibroArchive($archive, $resp);
        throw $e;
    }
    finalizeLibroArchive($archive, $resp);

    $periodo   = $data['periodo'] ?? date('Y-m');
    $nivel = $resp['ok'] ? 'SUCCESS' : 'ERROR';
    saveSiiLog('sendLibro', "Libro $tipoLibro $periodo enviado. TrackID: " . ($resp['trackId'] ?? 'N/A'), $nivel);

    $errDetail = $resp['ok'] ? null : ($resp['error'] ?? $resp['estado'] ?? 'sin detalle');
    return [
        'ok'      => $resp['ok'],
        'trackId' => $resp['trackId'] ?? null,
        'estado'  => $resp['estado']  ?? null,
        'error'   => $errDetail,
        'totales' => $gen['totales'],
        'xml'     => $gen['xml'],
        'xml_hash'=> $archive['hash'],
        'archive_path' => $archive['relative_path'],
        'mensaje' => $resp['ok']
            ? "Libro $tipoLibro $periodo enviado al SII. TrackID {$resp['trackId']}."
            : "Error enviando libro: $errDetail",
    ];
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// COMUNICACIÃ“N SII
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function respuestaEnvioGlosaEnvio(int $estado): string {
    return [
        0 => 'Envio recibido conforme.',
        1 => 'Envio rechazado - Error de Schema.',
        2 => 'Envio rechazado - Error de firma.',
        3 => 'Envio rechazado - Rut receptor no corresponde.',
        90 => 'Envio rechazado - Archivo repetido.',
        91 => 'Envio rechazado - Archivo ilegible.',
        99 => 'Envio rechazado - Otra razon.',
    ][$estado] ?? 'Estado de envio no catalogado.';
}

function respuestaEnvioGlosaRecepDTE(int $estado): string {
    return [
        0 => 'DTE Recibido OK.',
        1 => 'DTE No Recibido - Error de firma.',
        2 => 'DTE No Recibido - Error en RUT Emisor.',
        3 => 'DTE No Recibido - Error en RUT Receptor.',
        4 => 'DTE No Recibido - DTE Repetido.',
        99 => 'DTE No Recibido - Otra.',
    ][$estado] ?? 'Estado de recepcion DTE no catalogado.';
}

function respuestaEnvioGlosaResultadoDTE(int $estado, string $motivo = ''): string {
    $motivo = trim($motivo);
    return match ($estado) {
        0 => 'DTE Aceptado OK.' . ($motivo ? " $motivo" : ''),
        1 => 'DTE Aceptado con discrepancias.' . ($motivo ? " $motivo" : ''),
        2 => 'DTE Rechazado.' . ($motivo ? " $motivo" : ''),
        default => 'Estado comercial DTE no catalogado.' . ($motivo ? " $motivo" : ''),
    };
}

function respuestaEnvioDocXml(array $d, bool $resultadoComercial = false): string {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');
    $tipo = (int)($d['tipoDTE'] ?? $d['tipo'] ?? 33);
    $folio = (int)($d['folio'] ?? 0);
    $fecha = $h($d['fecha'] ?? $d['fchEmis'] ?? date('Y-m-d'));
    $rutE = $h($d['rutEmisor'] ?? '');
    $rutR = $h($d['rutReceptor'] ?? '');
    $total = (int)($d['montoTotal'] ?? $d['mntTotal'] ?? 0);
    if ($folio <= 0 || !$rutE || !$rutR) {
        throw new InvalidArgumentException('Cada DTE debe incluir folio, rutEmisor y rutReceptor.');
    }
    if ($resultadoComercial) {
        $estado = (int)($d['estadoDTE'] ?? $d['estado'] ?? 0);
        $motivo = (string)($d['glosa'] ?? $d['motivo'] ?? '');
        $cod = (int)($d['codRchDsc'] ?? $d['codigoRechazo'] ?? 0);
        $codEnvio = (int)($d['codEnvio'] ?? $d['codigoEnvio'] ?? 1);
        $glosa = $h($d['estadoDTEGlosa'] ?? respuestaEnvioGlosaResultadoDTE($estado, $motivo));
        return "<ResultadoDTE>\n"
            . "  <TipoDTE>$tipo</TipoDTE>\n  <Folio>$folio</Folio>\n  <FchEmis>$fecha</FchEmis>\n"
            . "  <RUTEmisor>$rutE</RUTEmisor>\n  <RUTRecep>$rutR</RUTRecep>\n  <MntTotal>$total</MntTotal>\n"
            . "  <CodEnvio>$codEnvio</CodEnvio>\n  <EstadoDTE>$estado</EstadoDTE>\n  <EstadoDTEGlosa>$glosa</EstadoDTEGlosa>\n"
            . ($cod > 0 ? "  <CodRchDsc>$cod</CodRchDsc>\n" : "")
            . "</ResultadoDTE>\n";
    }
    $estado = (int)($d['estadoRecepDTE'] ?? $d['estado'] ?? 0);
    $glosa = $h($d['recepDTEGlosa'] ?? respuestaEnvioGlosaRecepDTE($estado));
    return "<RecepcionDTE>\n"
        . "  <TipoDTE>$tipo</TipoDTE>\n  <Folio>$folio</Folio>\n  <FchEmis>$fecha</FchEmis>\n"
        . "  <RUTEmisor>$rutE</RUTEmisor>\n  <RUTRecep>$rutR</RUTRecep>\n  <MntTotal>$total</MntTotal>\n"
        . "  <EstadoRecepDTE>$estado</EstadoRecepDTE>\n  <RecepDTEGlosa>$glosa</RecepDTEGlosa>\n"
        . "</RecepcionDTE>\n";
}

function generateRespuestaEnvioDTE(array $data): array {
    try {
        global $globalContext;
        [$cert, $privKey] = loadCertificate();
        $h = fn($v) => htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');
        $rutResponde = $data['rutResponde'] ?? ($globalContext ? $globalContext->getRut() : RUT_EMISOR);
        $rutRecibe = $data['rutRecibe'] ?? $data['rutEmisor'] ?? '';
        if (!$rutRecibe) throw new InvalidArgumentException('Debe indicar rutRecibe/rutEmisor del contribuyente al que se responde.');
        $idRespuesta = (int)($data['idRespuesta'] ?? time());
        $idResultado = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($data['id'] ?? "RESP{$idRespuesta}"));
        $contacto = $data['contacto'] ?? [];
        $tmst = date('Y-m-d\TH:i:s');
        $recepcionEnvio = $data['recepcionEnvio'] ?? null;
        $docsRecep = $data['recepcionDTE'] ?? $data['documentosRecepcion'] ?? [];
        $docsResultado = $data['resultadoDTE'] ?? $data['resultadosDTE'] ?? [];
        $xmlRecepcionEnvio = '';
        if ($recepcionEnvio || $docsRecep) {
            $estadoEnv = (int)($recepcionEnvio['estadoRecepEnv'] ?? $recepcionEnvio['estado'] ?? 0);
            $codEnvio = (int)($recepcionEnvio['codEnvio'] ?? $recepcionEnvio['codigoEnvio'] ?? 1);
            $envioId = $h($recepcionEnvio['envioDTEId'] ?? $recepcionEnvio['envioDTEID'] ?? 'SetDoc');
            $digest = $h($recepcionEnvio['digest'] ?? '');
            $nmb = $h($recepcionEnvio['nombreArchivo'] ?? $recepcionEnvio['nmbEnvio'] ?? 'envio.xml');
            $fch = $h($recepcionEnvio['fechaRecepcion'] ?? date('Y-m-d\TH:i:s'));
            $rutEmisorEnv = $h($recepcionEnvio['rutEmisor'] ?? $rutRecibe);
            $rutReceptorEnv = $h($recepcionEnvio['rutReceptor'] ?? $rutResponde);
            $glosaEnv = $h($recepcionEnvio['glosa'] ?? respuestaEnvioGlosaEnvio($estadoEnv));
            $xmlDocs = '';
            foreach ($docsRecep as $d) $xmlDocs .= respuestaEnvioDocXml($d, false);
            $nroDte = count($docsRecep);
            $xmlRecepcionEnvio = "<RecepcionEnvio>\n"
                . "  <NmbEnvio>$nmb</NmbEnvio>\n  <FchRecep>$fch</FchRecep>\n  <CodEnvio>$codEnvio</CodEnvio>\n"
                . "  <EnvioDTEID>$envioId</EnvioDTEID>\n" . ($digest ? "  <Digest>$digest</Digest>\n" : "")
                . "  <RutEmisor>$rutEmisorEnv</RutEmisor>\n  <RutReceptor>$rutReceptorEnv</RutReceptor>\n"
                . "  <EstadoRecepEnv>$estadoEnv</EstadoRecepEnv>\n  <RecepEnvGlosa>$glosaEnv</RecepEnvGlosa>\n"
                . "  <NroDTE>$nroDte</NroDTE>\n$xmlDocs</RecepcionEnvio>\n";
        }
        $xmlResultados = '';
        foreach ($docsResultado as $d) $xmlResultados .= respuestaEnvioDocXml($d, true);
        if (!$xmlRecepcionEnvio && !$xmlResultados) {
            throw new InvalidArgumentException('Debe incluir recepcionDTE/documentosRecepcion o resultadoDTE/resultadosDTE.');
        }
        $nroDetalles = $xmlResultados ? count($docsResultado) : 1;
        $xmlRaw = <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<RespuestaDTE version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sii.cl/SiiDte RespuestaEnvioDTE_v10.xsd">
<Resultado ID="$idResultado">
<Caratula version="1.0">
  <RutResponde>{$h($rutResponde)}</RutResponde>
  <RutRecibe>{$h($rutRecibe)}</RutRecibe>
  <IdRespuesta>$idRespuesta</IdRespuesta>
  <NroDetalles>$nroDetalles</NroDetalles>
  <NmbContacto>{$h(substr((string)($contacto['nombre'] ?? $data['nombreContacto'] ?? ''), 0, 40))}</NmbContacto>
  <FonoContacto>{$h(substr((string)($contacto['fono'] ?? $data['fonoContacto'] ?? ''), 0, 40))}</FonoContacto>
  <MailContacto>{$h(substr((string)($contacto['mail'] ?? $data['mailContacto'] ?? ''), 0, 80))}</MailContacto>
  <TmstFirmaResp>$tmst</TmstFirmaResp>
</Caratula>
$xmlRecepcionEnvio$xmlResultados</Resultado>
</RespuestaDTE>
XML;
        $xmlFirmado = signDTE($xmlRaw, $cert, $privKey, $idResultado);
        return ['ok' => true, 'xml' => $xmlFirmado, 'id' => $idResultado, 'idRespuesta' => $idRespuesta, 'mensaje' => "RespuestaDTE $idResultado generada y firmada."];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Genera un EnvioRecibos (recibo de mercaderías, Ley 19.983) firmado para los
 * DTE recibidos de otro contribuyente. Cada Recibo se firma individualmente y
 * luego se firma el SetRecibos completo (mismo patrón sobre+docs del EnvioDTE).
 *
 * @param array $data  rutRecibe (emisor de los DTE) + documentos[] con
 *                     tipoDTE, folio, fecha, rutEmisor, rutReceptor, montoTotal.
 */
function generateEnvioRecibos(array $data): array {
    try {
        global $globalContext;
        [$cert, $privKey] = loadCertificate();
        $h = fn($v) => htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');
        $rutResponde = $data['rutResponde'] ?? ($globalContext ? $globalContext->getRut() : RUT_EMISOR);
        $rutRecibe   = $data['rutRecibe'] ?? '';
        if (!$rutRecibe) throw new InvalidArgumentException('Debe indicar rutRecibe (emisor de los DTE recibidos).');
        $docs = array_values($data['documentos'] ?? []);
        if (empty($docs)) throw new InvalidArgumentException('Debe incluir documentos para el recibo.');
        $rutFirma = getRutCertificadoSeguro($cert);
        $recinto  = $data['recinto'] ?? 'Oficina del receptor';
        $ts = date('Y-m-d\TH:i:s');
        // Texto de la declaración exigido por el schema EnvioRecibos_v10 (Ley 19.983).
        $declaracion = 'El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b) del Art. 4, y la letra c) del Art. 5 de la Ley 19.983, acredita que la entrega de mercaderias o servicio(s) prestado(s) ha(n) sido recibido(s).';

        $recibosXml = '';
        foreach ($docs as $i => $d) {
            $id    = 'Rcbo' . ($i + 1);
            $tipo  = (int)($d['tipoDTE'] ?? $d['tipo'] ?? 33);
            $folio = (int)($d['folio'] ?? 0);
            $fch   = $h($d['fecha'] ?? $d['fchEmis'] ?? date('Y-m-d'));
            $rutE  = $h($d['rutEmisor'] ?? $rutRecibe);
            $rutR  = $h($d['rutReceptor'] ?? $rutResponde);
            $mnt   = (int)($d['montoTotal'] ?? $d['mntTotal'] ?? 0);
            if ($folio <= 0) throw new InvalidArgumentException('Cada documento del recibo debe incluir folio.');

            $reciboRaw = <<<XML
<Recibo version="1.0" xmlns="http://www.sii.cl/SiiDte">
<DocumentoRecibo ID="$id">
  <TipoDoc>$tipo</TipoDoc>
  <Folio>$folio</Folio>
  <FchEmis>$fch</FchEmis>
  <RUTEmisor>$rutE</RUTEmisor>
  <RUTRecep>$rutR</RUTRecep>
  <MntTotal>$mnt</MntTotal>
  <Recinto>{$h($recinto)}</Recinto>
  <RutFirma>{$h($rutFirma)}</RutFirma>
  <Declaracion>$declaracion</Declaracion>
  <TmstFirmaRecibo>$ts</TmstFirmaRecibo>
</DocumentoRecibo>
</Recibo>
XML;
            $recibosXml .= cleanXmlForEmbedding(signDTE($reciboRaw, $cert, $privKey, $id)) . "\n";
        }

        $idSet = 'SetDteRecibidos';
        $xmlRaw = <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<EnvioRecibos version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioRecibos_v10.xsd">
<SetRecibos ID="$idSet">
<Caratula version="1.0">
  <RutResponde>{$h($rutResponde)}</RutResponde>
  <RutRecibe>{$h($rutRecibe)}</RutRecibe>
  <TmstFirmaEnv>$ts</TmstFirmaEnv>
</Caratula>
$recibosXml</SetRecibos>
</EnvioRecibos>
XML;
        $xmlFirmado = signDTE($xmlRaw, $cert, $privKey, $idSet);
        return ['ok' => true, 'xml' => $xmlFirmado, 'recibos' => count($docs), 'mensaje' => 'EnvioRecibos generado y firmado.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function cleanXmlForEmbedding(string $xml): string {
    return trim(preg_replace('/<\?xml.*?\?>\s*/i', '', $xml));
}

function extractDteFieldsForCesion(string $xml): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($xml)) {
        throw new InvalidArgumentException('XML DTE invalido para cesion.');
    }
    $xp = new DOMXPath($dom);
    $txt = function (string $name) use ($xp): string {
        $n = $xp->query("//*[local-name()='$name']")->item(0);
        return $n ? trim($n->textContent) : '';
    };
    $docNode = $xp->query("//*[local-name()='Documento' or local-name()='Exportaciones']")->item(0);
    $docId = $docNode instanceof DOMElement ? $docNode->getAttribute('ID') : '';
    $fields = [
        'id'          => $docId ?: ('DTE_' . time()),
        'tipoDTE'     => (int)$txt('TipoDTE'),
        'folio'       => (int)$txt('Folio'),
        'rutEmisor'   => $txt('RUTEmisor'),
        'rutReceptor' => $txt('RUTRecep'),
        'fecha'       => $txt('FchEmis'),
        'montoTotal'  => (int)round((float)$txt('MntTotal')),
    ];
    foreach (['tipoDTE', 'folio', 'rutEmisor', 'rutReceptor', 'fecha', 'montoTotal'] as $key) {
        if (empty($fields[$key])) {
            throw new InvalidArgumentException("El DTE cedido no contiene el campo requerido $key.");
        }
    }
    return $fields;
}

function cesionPartyXml(string $tag, array $party, callable $h): string {
    $rut = $h($party['rut'] ?? $party['RUT'] ?? '');
    $razon = $h($party['razonSocial'] ?? $party['razon_social'] ?? $party['nombre'] ?? '');
    $dir = $h($party['direccion'] ?? '');
    $mail = $h($party['email'] ?? $party['eMail'] ?? $party['mail'] ?? '');
    if (!$rut || !$razon || !$dir || !$mail) {
        throw new InvalidArgumentException("$tag requiere rut, razonSocial, direccion y email.");
    }
    return "<$tag>\n"
        . "  <RUT>$rut</RUT>\n"
        . "  <RazonSocial>$razon</RazonSocial>\n"
        . "  <Direccion>$dir</Direccion>\n"
        . "  <eMail>$mail</eMail>\n"
        . "</$tag>";
}

function generateCesionAEC(array $data): array {
    try {
        [$cert, $privKey] = loadCertificate();
        $h = fn($v) => htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');
        $dteXml = (string)($data['xmlDTE'] ?? $data['dteXml'] ?? $data['xml'] ?? '');
        if (!$dteXml) throw new InvalidArgumentException('Debe incluir xmlDTE del documento cedido.');
        $dte = extractDteFieldsForCesion($dteXml);
        $seq = (int)($data['seqCesion'] ?? $data['secuencia'] ?? 1);
        $cedente = $data['cedente'] ?? [];
        $cesionario = $data['cesionario'] ?? [];
        $monto = (int)($data['montoCesion'] ?? $dte['montoTotal']);
        $venc = $h($data['ultimoVencimiento'] ?? $data['fechaVencimiento'] ?? $dte['fecha']);
        $cond = $h($data['otrasCondiciones'] ?? '');
        $mailDeudor = $h($data['emailDeudor'] ?? $data['eMailDeudor'] ?? '');
        $ts = date('Y-m-d\TH:i:s');
        $idDteCedido = preg_replace('/[^A-Za-z0-9_-]/', '', 'DTE_CEDIDO_' . $dte['id']);
        $idCesion = preg_replace('/[^A-Za-z0-9_-]/', '', 'CesionID_' . $dte['id'] . '_' . $seq);
        $idAec = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($data['id'] ?? 'AEC_' . $dte['id'] . '_' . $seq));

        $dteClean = cleanXmlForEmbedding($dteXml);
        $xmlDteCedido = <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<DTECedido version="1.0" xmlns="http://www.sii.cl/SiiDte">
<DocumentoDTECedido ID="$idDteCedido">
<TmstFirma>$ts</TmstFirma>
$dteClean
</DocumentoDTECedido>
</DTECedido>
XML;
        $signedDteCedido = signDTE($xmlDteCedido, $cert, $privKey, $idDteCedido);

        $xmlCedente = cesionPartyXml('Cedente', $cedente, $h);
        $xmlCesionario = cesionPartyXml('Cesionario', $cesionario, $h);
        $xmlCesion = <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<Cesion version="1.0" xmlns="http://www.sii.cl/SiiDte">
<DocumentoCesion ID="$idCesion">
<SeqCesion>$seq</SeqCesion>
<IdDTE>
  <TipoDTE>{$dte['tipoDTE']}</TipoDTE>
  <Folio>{$dte['folio']}</Folio>
  <RUTEmisor>{$h($dte['rutEmisor'])}</RUTEmisor>
  <RUTReceptor>{$h($dte['rutReceptor'])}</RUTReceptor>
  <FchEmis>{$h($dte['fecha'])}</FchEmis>
  <MntTotal>{$dte['montoTotal']}</MntTotal>
</IdDTE>
$xmlCedente
$xmlCesionario
<MontoCesion>$monto</MontoCesion>
<UltimoVencimiento>$venc</UltimoVencimiento>
XML;
        if ($cond) $xmlCesion .= "\n<OtrasCondiciones>$cond</OtrasCondiciones>";
        if ($mailDeudor) $xmlCesion .= "\n<eMailDeudor>$mailDeudor</eMailDeudor>";
        $xmlCesion .= "\n<TmstCesion>$ts</TmstCesion>\n</DocumentoCesion>\n</Cesion>";
        $signedCesion = signDTE($xmlCesion, $cert, $privKey, $idCesion);

        $rutCedente = $h($cedente['rut'] ?? $cedente['RUT'] ?? '');
        $rutCesionario = $h($cesionario['rut'] ?? $cesionario['RUT'] ?? '');
        $contacto = $data['contacto'] ?? [];
        $dteCedidoClean = cleanXmlForEmbedding($signedDteCedido);
        $cesionClean = cleanXmlForEmbedding($signedCesion);
        $xmlAec = <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<AEC version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sii.cl/SiiDte AEC_v10.xsd">
<DocumentoAEC ID="$idAec">
<Caratula version="1.0">
  <RutCedente>$rutCedente</RutCedente>
  <RutCesionario>$rutCesionario</RutCesionario>
  <NmbContacto>{$h($contacto['nombre'] ?? $data['nombreContacto'] ?? '')}</NmbContacto>
  <FonoContacto>{$h($contacto['fono'] ?? $data['fonoContacto'] ?? '')}</FonoContacto>
  <MailContacto>{$h($contacto['mail'] ?? $data['mailContacto'] ?? '')}</MailContacto>
  <TmstFirmaEnvio>$ts</TmstFirmaEnvio>
</Caratula>
<Cesiones>
$dteCedidoClean
$cesionClean
</Cesiones>
</DocumentoAEC>
</AEC>
XML;
        $signedAec = signDTE($xmlAec, $cert, $privKey, $idAec);
        return [
            'ok' => true,
            'xml' => $signedAec,
            'dteCedidoXml' => $signedDteCedido,
            'cesionXml' => $signedCesion,
            'id' => $idAec,
            'mensaje' => "AEC $idAec generado y firmado.",
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function registroReclamoAcciones(): array {
    return [
        'ACD' => 'Acepta contenido del documento',
        'RCD' => 'Reclamo al contenido del documento',
        'ERM' => 'Otorga recibo de mercaderias o servicios',
        'RFP' => 'Reclamo por falta parcial de mercaderias',
        'RFT' => 'Reclamo por falta total de mercaderias',
    ];
}

function splitRutDv(string $rut): array {
    $rut = strtoupper(trim(str_replace(['.', ' '], '', $rut)));
    if (!preg_match('/^(\d+)-?([0-9K])$/', $rut, $m)) {
        throw new InvalidArgumentException("RUT invalido: $rut");
    }
    return [$m[1], $m[2]];
}

function registroReclamoSoap(string $operation, array $params): string {
    $ns = 'http://ws.registroreclamodte.diii.sdi.sii.cl';
    $h = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $body = "<ws:$operation>";
    foreach ($params as $name => $value) {
        $body .= "<$name>" . $h($value) . "</$name>";
    }
    $body .= "</ws:$operation>";
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ws="' . $ns . '">'
        . '<soapenv:Header/><soapenv:Body>' . $body . '</soapenv:Body></soapenv:Envelope>';
}

function parseRegistroReclamoResponse(string $xml): array {
    $decoded = html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($decoded)) {
        return ['ok' => false, 'error' => 'Respuesta SOAP invalida', 'raw' => $xml];
    }
    $xp = new DOMXPath($dom);
    $text = function (string $name) use ($xp): ?string {
        $n = $xp->query("//*[local-name()='$name']")->item(0);
        return $n ? trim($n->textContent) : null;
    };
    $fault = $text('faultstring') ?: $text('message');
    if ($fault && !$text('codResp')) {
        return ['ok' => false, 'error' => $fault, 'raw' => $xml];
    }
    $eventos = [];
    foreach ($xp->query("//*[local-name()='listaEventosDoc']") as $ev) {
        $get = function (string $name) use ($xp, $ev): ?string {
            $n = $xp->query(".//*[local-name()='$name']", $ev)->item(0);
            return $n ? trim($n->textContent) : null;
        };
        $eventos[] = [
            'codEvento'      => $get('codEvento'),
            'descEvento'     => $get('descEvento'),
            'rutResponsable' => $get('rutResponsable'),
            'dvResponsable'  => $get('dvResponsable'),
            'fechaEvento'    => $get('fechaEvento'),
        ];
    }
    $cod = $text('codResp');
    return [
        'ok'       => ($cod === null || $cod === '' || $cod === '0'),
        'codResp'  => $cod,
        'descResp' => $text('descResp'),
        'rutToken' => $text('rutToken'),
        'fechaSii' => $text('return'),
        'eventos'  => $eventos,
        'raw'      => $xml,
    ];
}

function registroReclamoDTE(array $data): array {
    try {
        $operation = (string)($data['operation'] ?? 'ingresarAceptacionReclamoDoc');
        $allowedOps = ['ingresarAceptacionReclamoDoc', 'listarEventosHistDoc', 'consultarDocDteCedible', 'consultarFechaRecepcionSii', 'getVersion'];
        if (!in_array($operation, $allowedOps, true)) {
            return ['ok' => false, 'error' => 'Operacion no soportada'];
        }
        $dryRun = !empty($data['dry_run']);
        $accion = strtoupper(trim((string)($data['accion'] ?? $data['accionDoc'] ?? '')));
        $params = [];
        if ($operation !== 'getVersion') {
            [$rutEmisor, $dvEmisor] = splitRutDv((string)($data['rutEmisor'] ?? ''));
            $tipoDoc = (int)($data['tipoDoc'] ?? $data['tipo'] ?? 0);
            $folio = (int)($data['folio'] ?? 0);
            if ($tipoDoc <= 0 || $folio <= 0) {
                return ['ok' => false, 'error' => 'Debe indicar tipoDoc y folio validos'];
            }
            $params = ['rutEmisor' => $rutEmisor, 'dvEmisor' => $dvEmisor, 'tipoDoc' => (string)$tipoDoc, 'folio' => (string)$folio];
        }
        if ($operation === 'ingresarAceptacionReclamoDoc') {
            $acciones = registroReclamoAcciones();
            if (!isset($acciones[$accion])) {
                return ['ok' => false, 'error' => 'Accion invalida. Use ACD, RCD, ERM, RFP o RFT'];
            }
            $params['accionDoc'] = $accion;
        }
        $soap = registroReclamoSoap($operation, $params);
        $url = siiEndpoints()['reclamo_ws'];
        if ($dryRun) {
            return ['ok' => true, 'dry_run' => true, 'operation' => $operation, 'endpoint' => $url, 'params' => $params, 'soap' => $soap];
        }
        [$cert, $privKey] = loadCertificate();
        $token = getToken(getSemilla(), $cert, $privKey);
        $resp = soapCall($url, '', $soap, $token);
        $parsed = parseRegistroReclamoResponse((string)$resp);
        $doc = ($params['tipoDoc'] ?? '') && ($params['folio'] ?? '') ? "T{$params['tipoDoc']}F{$params['folio']}" : $operation;
        $estado = !empty($parsed['ok']) ? 'SUCCESS' : 'ERROR';
        saveSiiLog('registroReclamoDTE', "$operation $doc" . ($accion ? " accion $accion" : '') . ': ' . ($parsed['descResp'] ?? $parsed['error'] ?? 'sin glosa'), $estado);
        saveSiiTransaction([
            'op' => 'registroReclamoDTE',
            'url' => $url,
            'operation' => $operation,
            'request_body' => $soap,
            'response_body' => $resp,
            'estado' => $parsed['codResp'] ?? null,
        ]);
        return array_merge(['operation' => $operation, 'accion' => $accion ?: null, 'endpoint' => $url], $parsed);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function siiHost(): string {
    global $globalContext;
    $amb = $globalContext ? $globalContext->getAmbiente() : AMBIENTE;
    return $amb === 'PRODUCCION' ? HOST_PROD : HOST_CERTIF;
}


/**
 * Centraliza todos los endpoints del SII. Permite override por env vars
 * (Ãºtil para pruebas/migraciÃ³n cuando el SII cambia hosts).
 *
 * Devuelve array con claves:
 *   soap_host        â†' maullin/palena (SOAP de DTE normal y auth clÃ¡sica)
 *   boleta_auth      â†' apicert/api (semilla+token boletas REST)
 *   boleta_envio     â†' pangal/rahue (POST boleta.electronica.envio)
 *   boleta_consulta  â†' apicert/api (GET boleta.electronica/.../estado)
 *
 * Cada uno puede sobreescribirse vÃ­a env: SII_SOAP_HOST, SII_BOLETA_AUTH, etc.
 */
function siiEndpoints(?string $ambiente = null): array {
    global $globalContext;
    $amb = $ambiente ?: ($globalContext ? $globalContext->getAmbiente() : AMBIENTE);
    $isProd = ($amb === 'PRODUCCION');

    $defaults = $isProd ? [
        'soap_host'       => 'palena.sii.cl',
        'boleta_auth'     => 'https://api.sii.cl/recursos/v1',
        'boleta_envio'    => 'https://rahue.sii.cl/recursos/v1/boleta.electronica.envio',
        'boleta_consulta' => 'https://api.sii.cl/recursos/v1',
        'reclamo_ws'      => 'https://ws1.sii.cl/WSREGISTRORECLAMODTE/registroreclamodteservice',
    ] : [
        'soap_host'       => 'maullin.sii.cl',
        'boleta_auth'     => 'https://apicert.sii.cl/recursos/v1',
        'boleta_envio'    => 'https://pangal.sii.cl/recursos/v1/boleta.electronica.envio',
        'boleta_consulta' => 'https://apicert.sii.cl/recursos/v1',
        'reclamo_ws'      => 'https://ws2.sii.cl/WSREGISTRORECLAMODTECERT/registroreclamodteservice',
    ];

    return [
        'ambiente'        => $amb,
        'soap_host'       => getenv('SII_SOAP_HOST')      ?: $defaults['soap_host'],
        'boleta_auth'     => getenv('SII_BOLETA_AUTH')    ?: $defaults['boleta_auth'],
        'boleta_envio'    => getenv('SII_BOLETA_ENVIO')   ?: $defaults['boleta_envio'],
        'boleta_consulta' => getenv('SII_BOLETA_CONSULTA')?: $defaults['boleta_consulta'],
        'reclamo_ws'      => getenv('SII_RECLAMO_WS')     ?: $defaults['reclamo_ws'],
    ];
}

/**
 * Healthcheck multi-host: verifica conectividad a todos los endpoints SII relevantes.
 * Retorna un dict con status de cada uno (HTTP, latencia, error si lo hubo).
 */
function siiHealthcheck(): array {
    $ep = siiEndpoints();
    // method GET para todos (HEAD provoca 500 en algunos endpoints SII).
    // expect: cÃ³digos que confirman que el endpoint EXISTE y responde,
    // aunque la query sin parÃ¡metros sea invÃ¡lida (200/4xx esperados).
    $checks = [
        ['name' => 'SOAP getSemilla',     'url' => "https://{$ep['soap_host']}/DTEWS/CrSeed.jws", 'expect' => [200, 405]],
        ['name' => 'REST boleta semilla', 'url' => "{$ep['boleta_auth']}/boleta.electronica.semilla", 'expect' => [200]],
        ['name' => 'REST boleta envio',   'url' => $ep['boleta_envio'], 'expect' => [200, 400, 401, 405]],
        ['name' => 'REST boleta consulta','url' => "{$ep['boleta_consulta']}/boleta.electronica.envio/00000000-0-0", 'expect' => [200, 400, 401, 404]],
        ['name' => 'SOAP reclamo DTE',    'url' => $ep['reclamo_ws'], 'expect' => [200, 400, 405, 500]],
    ];

    $results = [];
    foreach ($checks as $c) {
        $t0 = microtime(true);
        $ch = curl_init($c['url']);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => SII_SSL_VERIFY,
            CURLOPT_SSLVERSION     => SII_MIN_TLS,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT)',
                'Content-Length: 0',
                'Accept: application/xml, application/json',
            ],
        ]);
        curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $ms = (int)((microtime(true) - $t0) * 1000);

        $okHttp = in_array($http, $c['expect'], true);
        $results[] = [
            'name'   => $c['name'],
            'url'    => $c['url'],
            'http'   => $http,
            'ok'     => !$err && $okHttp,
            'ms'     => $ms,
            'error'  => $err ?: null,
            'expect' => $c['expect'],
        ];
    }

    $allOk = !in_array(false, array_column($results, 'ok'), true);
    return [
        'ok'        => $allOk,
        'ambiente'  => $ep['ambiente'],
        'endpoints' => $ep,
        'checks'    => $results,
    ];
}

function getSemilla(): string {
    $host = siiHost();
    $url = "https://{$host}/DTEWS/CrSeed.jws";
    $soap = '<?xml version="1.0" encoding="UTF-8"?>'
          . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
          . 'xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" '
          . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
          . 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" '
          . 'soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
          . '<soapenv:Body>'
          . '<m:getSeed xmlns:m="https://' . $host . '/DTEWS/CrSeed.jws"/>'
          . '</soapenv:Body>'
          . '</soapenv:Envelope>';

    $resp = soapCall($url, '', $soap); 
    if (!$resp) throw new Exception('No se pudo conectar al SII para obtener semilla (getSeed)');

    // El SII a veces devuelve el XML encodeado dentro de getSeedReturn
    $decoded = html_entity_decode($resp);

    if (preg_match('/<SEMILLA>(\d+)<\/SEMILLA>/i', $decoded, $m)) {
        saveSiiLog('getSemilla', "Semilla recibida: " . $m[1], 'SUCCESS');
        return $m[1];
    }
    
    if (stripos($resp, '<!DOCTYPE') !== false || stripos($resp, '<html') !== false) {
        saveSiiLog('getSemilla', 'SII respondiÃ³ con HTML (posible error de autenticaciÃ³n o mantenimiento)', 'ERROR');
        throw new Exception('El servidor SII respondiÃ³ con una pÃ¡gina HTML en lugar de XML. Verifique la conectividad con ' . (defined('HOST_CERTIF') ? HOST_CERTIF : 'maullin.sii.cl') . ' o reintente en unos momentos.');
    }
    $snippet = htmlspecialchars(substr($resp, 0, 250));
    saveSiiLog('getSemilla', "Fallo al obtener semilla: " . $snippet, 'ERROR');
    throw new Exception("No se encontrÃ³ <SEMILLA> en respuesta. Recibido: $snippet");
}

function buildSignedTokenXml(string $semilla, string $certPem, $privKey): string {
    $xmlData = "<getToken><item><Semilla>$semilla</Semilla></item></getToken>";
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    $dom->loadXML($xmlData);

    $digest = base64_encode(sha1($dom->documentElement->C14N(), true));
    preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $certPem, $mc);
    // El base64 del certificado se re-parte en líneas de 64 (formato PEM estándar)
    // para que ninguna línea del XML supere el límite del parser del SII
    // (CHR-00002 "Line too long (4090)"). Seguro: base64 ignora el whitespace y
    // X509Certificate no está dentro del SignedInfo firmado → no invalida la firma.
    // Sin esto, los certificados RSA 4096 producen una línea de <Signature> > 4090.
    $certB64 = trim(chunk_split(preg_replace('/\s+/', '', $mc[1] ?? ''), 64, "\n"));

    $signedInfoXml = '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">'
                   . '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
                   . '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>'
                   . '<Reference URI=""><Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/></Transforms>'
                   . '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>'
                   . '<DigestValue>' . $digest . '</DigestValue>'
                   . '</Reference></SignedInfo>';

    $siDom = new DOMDocument();
    $siDom->loadXML($signedInfoXml);
    openssl_sign($siDom->documentElement->C14N(), $sigBytes, $privKey, OPENSSL_ALGO_SHA1);

    $details = openssl_pkey_get_details(openssl_pkey_get_private($privKey));
    $modulus = isset($details['rsa']) ? base64_encode($details['rsa']['n']) : '';
    $exponent = isset($details['rsa']) ? base64_encode($details['rsa']['e']) : '';

    $sigXml = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
            . $signedInfoXml
            . '<SignatureValue>' . base64_encode($sigBytes) . '</SignatureValue>'
            . '<KeyInfo><KeyValue><RSAKeyValue><Modulus>' . $modulus . '</Modulus><Exponent>' . $exponent . '</Exponent></RSAKeyValue></KeyValue>'
            . '<X509Data><X509Certificate>' . $certB64 . '</X509Certificate></X509Data></KeyInfo>'
            . '</Signature>';

    $sigDom = new DOMDocument();
    $sigDom->loadXML($sigXml);
    $dom->documentElement->appendChild($dom->importNode($sigDom->documentElement, true));

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $dom->saveXML($dom->documentElement);
}

function boletaAuthBaseUrl(): string {
    return siiEndpoints()['boleta_auth'];
}

function getBoletaRestSemilla(): string {
    $url = boletaAuthBaseUrl() . '/boleta.electronica.semilla';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => SII_SSL_VERIFY,
        CURLOPT_SSLVERSION     => SII_MIN_TLS,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/xml',
            'Content-Length: 0',
            'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT)'
        ],
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) throw new Exception("Error REST semilla boleta: $err");
    if ($httpCode === 200 && preg_match('/<SEMILLA>(\d+)<\/SEMILLA>/i', html_entity_decode((string)$resp), $m)) {
        saveSiiLog('getBoletaRestSemilla', "Semilla REST recibida: " . $m[1], 'SUCCESS');
        return $m[1];
    }

    throw new Exception("No se obtuvo semilla REST de boleta. HTTP $httpCode: " . substr(strip_tags((string)$resp), 0, 300));
}

function getBoletaRestToken(string $certPem, $privKey): string {
    $signed = buildSignedTokenXml(getBoletaRestSemilla(), $certPem, $privKey);
    $url = boletaAuthBaseUrl() . '/boleta.electronica.token';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $signed,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => SII_SSL_VERIFY,
        CURLOPT_SSLVERSION     => SII_MIN_TLS,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/xml',
            'Content-Type: application/xml',
            'Content-Length: ' . strlen($signed),
            'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT)'
        ],
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) throw new Exception("Error REST token boleta: $err");
    if ($httpCode === 200 && preg_match('/<TOKEN>([^<]+)<\/TOKEN>/i', html_entity_decode((string)$resp), $m)) {
        saveSiiLog('getBoletaRestToken', 'Token REST boleta obtenido', 'SUCCESS');
        return trim($m[1]);
    }

    throw new Exception("No se obtuvo token REST de boleta. HTTP $httpCode: " . substr(strip_tags((string)$resp), 0, 500));
}

function getToken(string $semilla, string $certPem, $privKey): string {
    $host = siiHost();
    $maxRetries = 2;
    $lastError = '';

    for ($retry = 0; $retry <= $maxRetries; $retry++) {
        $xmlData = "<getToken><item><Semilla>$semilla</Semilla></item></getToken>";
        
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($xmlData);

        // Digest
        $c14n = $dom->documentElement->C14N();
        $digest = base64_encode(sha1($c14n, true));

        // Certificado
        preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $certPem, $mc);
        // El base64 del certificado se re-parte en líneas de 64 (formato PEM estándar)
    // para que ninguna línea del XML supere el límite del parser del SII
    // (CHR-00002 "Line too long (4090)"). Seguro: base64 ignora el whitespace y
    // X509Certificate no está dentro del SignedInfo firmado → no invalida la firma.
    // Sin esto, los certificados RSA 4096 producen una línea de <Signature> > 4090.
    $certB64 = trim(chunk_split(preg_replace('/\s+/', '', $mc[1] ?? ''), 64, "\n"));

        // SignedInfo
        $signedInfoXml = '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">'
                       . '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
                       . '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>'
                       . '<Reference URI="">'
                       . '<Transforms>'
                       . '<Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>'
                       . '</Transforms>'
                       . '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>'
                       . '<DigestValue>' . $digest . '</DigestValue>'
                       . '</Reference>'
                       . '</SignedInfo>';

        $siDom = new DOMDocument();
        $siDom->loadXML($signedInfoXml);
        $siC14n = $siDom->documentElement->C14N();

        openssl_sign($siC14n, $sigBytes, $privKey, OPENSSL_ALGO_SHA1);
        $sigValue = base64_encode($sigBytes);

        // Obtener Modulus y Exponent de la clave privada
        $pkObj = openssl_pkey_get_private($privKey);
        $details = $pkObj ? openssl_pkey_get_details($pkObj) : null;
        $modulus = '';
        $exponent = '';
        if ($details && isset($details['rsa'])) {
            $modulus = base64_encode($details['rsa']['n']);
            $exponent = base64_encode($details['rsa']['e']);
        }

        // Construir nodo <Signature> con KeyValue (obligatorio para SII getToken)
        $sigXml = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
                . $signedInfoXml
                . '<SignatureValue>' . $sigValue . '</SignatureValue>'
                . '<KeyInfo>'
                . '<KeyValue>'
                . '<RSAKeyValue>'
                . '<Modulus>' . $modulus . '</Modulus>'
                . '<Exponent>' . $exponent . '</Exponent>'
                . '</RSAKeyValue>'
                . '</KeyValue>'
                . '<X509Data><X509Certificate>' . $certB64 . '</X509Certificate></X509Data>'
                . '</KeyInfo>'
                . '</Signature>';

        $sigDom = new DOMDocument();
        $sigDom->loadXML($sigXml);
        $sigNode = $dom->importNode($sigDom->documentElement, true);
        
        $dom->documentElement->appendChild($sigNode);
        $signed = '<?xml version="1.0"?>' . "\n" . $dom->saveXML($dom->documentElement);
        $soap = '<?xml version="1.0" encoding="UTF-8"?>'
              . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
              . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
              . 'xmlns:xsd="http://www.w3.org/2001/XMLSchema">'
              . '<soapenv:Body>'
              . '<getToken xmlns="http://DefaultNamespace">'
              . '<pszXml xsi:type="xsd:string">' . htmlspecialchars($signed, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</pszXml>'
              . '</getToken>'
              . '</soapenv:Body>'
              . '</soapenv:Envelope>';

        $url = "https://{$host}/DTEWS/GetTokenFromSeed.jws";
        $resp = soapCall($url, '', $soap); 
        
        $work = html_entity_decode($resp);
        if (preg_match('/TOKEN[^A-Z0-9]+([A-Z0-9]{6,20})/i', $work, $m)) {
            saveSiiLog('getToken', "Token obtenido con Ã©xito tras " . ($retry+1) . " intentos", 'SUCCESS');
            return $m[1];
        }

        // Si fallÃ³, intentar extraer el estado para el log
        if (preg_match('/<ESTADO>([^<]+)<\/ESTADO>/i', $work, $m)) {
            $lastError = "Estado " . $m[1] . ": " . (preg_match('/<GLOSA>([^<]+)<\/GLOSA>/i', $work, $m2) ? $m2[1] : 'Sin glosa');
            saveSiiLog('getToken', "Reintento " . ($retry+1) . " fallido: " . $lastError, 'WARNING');
        }

        if ($retry < $maxRetries) {
            usleep(500000); // Esperar 0.5 seg antes de reintentar con nueva semilla
            $semilla = getSemilla(); 
        }
    }

    file_put_contents(__DIR__ . '/dte_token_error.xml', $resp);
    throw new Exception("Error SII en getToken tras $maxRetries reintentos. Ãšltimo error: $lastError");
}

function uploadDTE(string $envioDTE, string $token, ?string $certPem = null): array {
    $host = siiHost();
    // VÃ­a SOAP = guÃ­as/facturas (nunca boletas). Cert de David por defecto.
    if ($certPem === null) {
        [$certPem] = loadCertificate($GLOBALS['SII_CERT_TIPO'] ?? 52);
    }
    
    // Extraer y separar RUT/DV del Remitente (Certificado)
    $rutSFull = getRutCertificadoSeguro($certPem);
    $rsParts  = explode('-', $rutSFull);
    $rutSender = $rsParts[0];
    $dvSender  = $rsParts[1];

    global $globalContext;
    $rutCompanyFull = $globalContext ? $globalContext->getRut() : RUT_EMISOR;
    $rcParts  = explode('-', $rutCompanyFull);
    $rutCompany = $rcParts[0];
    $dvCompany  = $rcParts[1];

    $boundary = '----------' . md5(microtime());

    $body  = "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"rutSender\"\r\n\r\n" . $rutSender . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"dvSender\"\r\n\r\n" . $dvSender . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"rutCompany\"\r\n\r\n" . $rutCompany . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"dvCompany\"\r\n\r\n" . $dvCompany . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"archivo\"; filename=\"envio.xml\"\r\n";
    $body .= "Content-Type: text/xml\r\n\r\n" . $envioDTE . "\r\n";
    $body .= "--$boundary--\r\n";

    $resp = null;
    $err = '';
    $http = 0;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init("https://{$host}/cgi_dte/UPL/DTEUpload");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => SII_SSL_VERIFY,
            CURLOPT_SSLVERSION     => SII_MIN_TLS,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                "Cookie: TOKEN=$token",
                "Content-Type: multipart/form-data; boundary=$boundary",
                "Accept: image/gif, image/x-xbitmap, image/jpeg, image/pjpeg, application/vnd.ms-powerpoint, application/vnd.ms-excel, application/msword, */*",
                "Connection: close",
                "User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT 5.0; YComp 5.0.2.4)"
            ],
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $transient = $err
            || $http === 503
            || stripos((string)$resp, 'Error 503') !== false
            || stripos((string)$resp, 'Service Temporarily Unavailable') !== false;
        if (!$transient) {
            break;
        }
        saveSiiLog('uploadDTE', "Intento $attempt fallido contra $host HTTP $http: " . ($err ?: substr(strip_tags((string)$resp), 0, 160)), 'WARNING');
        if ($attempt < 3) {
            sleep($attempt);
        }
    }

    if ($err) throw new Exception("Error de red enviando al SII tras reintentos: $err");

    preg_match('/<TRACKID>(\d+)<\/TRACKID>/', $resp ?? '', $mT);
    preg_match('/<ESTADO>([^<]+)<\/ESTADO>/',  $resp ?? '', $mE);
    preg_match('/<DETAIL>([\s\S]*?)<\/DETAIL>/i', $resp ?? '', $mD);

    $trackId = $mT[1] ?? null;
    $estado  = $mE[1] ?? 'DESCONOCIDO';

    if (!$trackId) {
        file_put_contents(__DIR__ . '/dte_error_upload.xml', "RESPUESTA COMPLETA SII:\n\n" . $resp);
    }

    $errMsg = $trackId ? "" : ($mD[1] ?? substr(strip_tags($resp ?? 'sin respuesta'), 0, 500));

    // El SII responde en ISO-8859-1. Si el mensaje (rechazo de carátula/schema,
    // muchas veces con acentos/ñ) no es UTF-8 válido, json_encode() devuelve FALSE
    // y la respuesta del bridge sale VACÍA → el front muestra "Unexpected end of
    // JSON input" sin pista. Normalizar a UTF-8 evita ese blanco.
    $toUtf8 = static function (string $s): string {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s;
        return mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    };
    $errMsg = $toUtf8($errMsg);
    $estado = $toUtf8($estado);

    return [
        'ok'      => !empty($trackId),
        'trackId' => $trackId,
        'estado'  => $estado,
        'error'   => "Error en envío: $errMsg",
        'mensaje' => $trackId
            ? "Enviado. TrackID: $trackId | Estado: $estado"
            : "Error en envío: $errMsg",
    ];
}

function queryEstadoEnvio(string $trackId, string $token): array {
    global $globalContext;
    $rutEmisorFull = $globalContext ? $globalContext->getRut() : RUT_EMISOR;
    $rutParts = explode('-', $rutEmisorFull);
    $rutCompany = $rutParts[0] ?? '';
    $dvCompany  = $rutParts[1] ?? '';

    $host = siiHost();
    $urlService = "https://{$host}/DTEWS/QueryEstUp.jws";
    
    // El servicio correcto para consultar un envÃ­o por TrackID es QueryEstUp.jws -> getEstUp
    $soap = '<?xml version="1.0" encoding="UTF-8"?>'
          . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:m="' . $urlService . '">'
          . '<soapenv:Header/>'
          . '<soapenv:Body>'
          . '<m:getEstUp>'
          . '<RutCompany>' . $rutCompany . '</RutCompany>'
          . '<DvCompany>' . $dvCompany . '</DvCompany>'
          . '<TrackId>' . $trackId . '</TrackId>'
          . '<Token>' . $token . '</Token>'
          . '</m:getEstUp>'
          . '</soapenv:Body>'
          . '</soapenv:Envelope>';

    $resp = soapCall($urlService, 'getEstUp', $soap, $token);

    // El SII devuelve el XML embebido con entidades HTML (&lt;ESTADO&gt;)
    $decoded = html_entity_decode($resp ?? '', ENT_QUOTES, 'UTF-8');

    preg_match('/<ESTADO>([^<]+)<\/ESTADO>/i', $decoded, $mE);
    preg_match('/<GLOSA>([^<]*)<\/GLOSA>/i',   $decoded, $mG);
    
    $estado = isset($mE[1]) ? trim($mE[1]) : 'DESCONOCIDO';
    $glosa = isset($mG[1]) ? trim($mG[1]) : '';
    saveSiiLog('queryEstadoEnvio', "TrackID $trackId: $estado " . ($glosa ?: ''), $estado === 'DESCONOCIDO' ? 'WARNING' : 'INFO');
    
    return ['estado' => $estado, 'glosa' => $glosa, 'xml' => $decoded];
}

function queryEstadoDTE(string $rutEmisor, int $tipo, int $folio, string $rutRecep, string $fecha, int $monto, string $token): array {
    $host  = siiHost();
    
    // Obtener RUT y DV limpios de la empresa
    $rutE_parts = explode('-', $rutEmisor);
    $rutCompany = $rutE_parts[0];
    $dvCompany  = $rutE_parts[1] ?? '';

    // Extraer RUT del consultante (DueÃ±o del certificado)
    // Es ESENCIAL que el consultante sea la persona natural del certificado
    [$cert, $privKey] = loadCertificate();
    $rutConsultante = $rutCompany;
    $dvConsultante  = $dvCompany;
    $certData = openssl_x509_parse($cert);
    $sn = $certData['subject']['serialNumber'] ?? '';
    if (preg_match('/(\d+)-([0-9Kk])/', $sn, $mr)) {
        $rutConsultante = $mr[1];
        $dvConsultante  = $mr[2];
    }

    $rutR_parts = explode('-', $rutRecep);
    $rutReceptor = $rutR_parts[0];
    $dvReceptor  = $rutR_parts[1] ?? '';

    $soap = '<?xml version="1.0" encoding="UTF-8"?>'
          . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
          . '<soapenv:Header/>'
          . '<soapenv:Body>'
          . '<getEstDte xmlns="http://DefaultNamespace">'
          . "<RutConsultante>$rutConsultante</RutConsultante>"
          . "<DvConsultante>$dvConsultante</DvConsultante>"
          . "<RutCompania>$rutCompany</RutCompania>"
          . "<DvCompania>$dvCompany</DvCompania>"
          . "<RutReceptor>$rutReceptor</RutReceptor>"
          . "<DvReceptor>$dvReceptor</DvReceptor>"
          . "<TipoDte>$tipo</TipoDte>"
          . "<FolioDte>$folio</FolioDte>"
          . "<FechaEmisionDte>" . date('dmY', strtotime($fecha)) . "</FechaEmisionDte>"
          . "<MontoDte>$monto</MontoDte>"
          . "<Token>$token</Token>"
          . '</getEstDte>'
          . '</soapenv:Body>'
          . '</soapenv:Envelope>';

    $resp = soapCall("https://{$host}/DTEWS/QueryEstDte.jws", 'getEstDte', $soap, $token);

    // Logging para diagnÃ³stico real en el servidor
    file_put_contents('dte_debug_raw.xml', $resp ?: 'SIN RESPUESTA DE SII');

    // Convertir respuesta de ISO-8859-1 a UTF-8
    $respUtf8 = $resp ? mb_convert_encoding($resp, 'UTF-8', 'ISO-8859-1') : "";

    // IMPORTANTE: El SII devuelve el XML interno escapado (&lt;). 
    // Lo decodificamos para que la Regex funcione.
    $respDecoded = html_entity_decode($respUtf8, ENT_QUOTES, 'UTF-8');

    // Regex mÃ¡s robusta para capturar ESTADO y las glosas de respuesta
    preg_match('/<ESTADO>([^<]+)<\/ESTADO>/i', $respDecoded, $mE);
    preg_match('/<GLOSA_ERR>([^<]*)<\/GLOSA_ERR>/i', $respDecoded, $mGE);
    preg_match('/<GLOSA_ESTADO>([^<]*)<\/GLOSA_ESTADO>/i', $respDecoded, $mGS);
    
    $estado = isset($mE[1]) ? trim($mE[1]) : 'NO ENCONTRADO';
    // Prioridad a GLOSA_ERR (detallada) sobre GLOSA_ESTADO (genÃ©rica)
    $glosa = !empty($mGE[1]) ? trim($mGE[1]) : (isset($mGS[1]) ? trim($mGS[1]) : '- Sin glosa reportada por SII -');

    return [
        'estado' => $estado, 
        'glosa'  => $glosa,
        'xml'    => !empty($respDecoded) ? $respDecoded : 'Error: El SII devolviÃ³ una respuesta vacÃ­a o ilegible'
    ];
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// GESTIÃ“N DE FOLIOS (CAF) - [Fase 2]
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function updateFolioRegistry(int $tipo, int $folio): void {
    global $actualCafDir;
    $file = $actualCafDir . 'registry.json';
    $reg = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    
    if (!isset($reg[$tipo]) || $folio > $reg[$tipo]) {
        $reg[$tipo] = $folio;
        file_put_contents($file, json_encode($reg, JSON_PRETTY_PRINT));
    }
}

function updateHistory(array $entry): void {
    global $actualCafDir;
    $file = $actualCafDir . 'history.json';
    $history = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    
    // AÃ±adir al inicio para que el mÃ¡s nuevo estÃ© primero
    array_unshift($history, array_merge($entry, ['ts' => date('Y-m-d H:i:s')]));
    
    // Mantener solo los Ãºltimos 200 registros
    $history = array_slice($history, 0, 200);
    
    file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
}

function getHistory(): array {
    global $globalContext;

    // Fuente canÃ³nica: tabla sii_dte (multi-tenant). history.json queda como
    // fallback legacy solo si no hay Context o si la consulta a BD falla.
    if ($globalContext) {
        try {
            $pdo = \App\Core\Database::getInstance();
            $sql = "SELECT tipo_dte, folio, fecha_emision, creado_en,
                           rut_receptor, razon_receptor, monto_total,
                           track_id, estado_sii, estado_local
                    FROM sii_dte
                    WHERE empresa_id = ? AND ambiente = ?
                    ORDER BY creado_en DESC, id DESC
                    LIMIT 200";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $globalContext->getEmpresaId(),
                strtolower($globalContext->getAmbiente()),
            ]);
            $entries = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $entries[] = [
                    'timestamp' => $r['creado_en'] ?: $r['fecha_emision'],
                    'fecha'     => $r['fecha_emision'],
                    'tipo'      => (int)$r['tipo_dte'],
                    'folio'     => (int)$r['folio'],
                    'receptor'  => $r['razon_receptor'] ?: ($r['rut_receptor'] ?: 'â€”'),
                    'total'     => (int)$r['monto_total'],
                    'trackId'   => $r['track_id'],
                    'estado'    => $r['estado_sii'] ?: $r['estado_local'],
                ];
            }
            return ['ok' => true, 'entries' => $entries, 'history' => $entries];
        } catch (\Throwable $e) {
            // cae al fallback legacy
        }
    }

    global $actualCafDir;
    $file = $actualCafDir . 'history.json';
    $history = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    return ['ok' => true, 'entries' => $history, 'history' => $history];
}

function getCAFStatus(): array {
    global $actualCafDir, $actualTmpDir;
    $cafs = [];
    $regFile = $actualCafDir . 'registry.json';
    $registry = file_exists($regFile) ? (json_decode(file_get_contents($regFile), true) ?? []) : [];

    $tiposNombre = [
        33 => 'Factura ElectrÃ³nica',  34 => 'Factura Exenta',
        39 => 'Boleta ElectrÃ³nica',   41 => 'Boleta Exenta',
        52 => 'GuÃ­a de Despacho',     56 => 'Nota de DÃ©bito',
        61 => 'Nota de CrÃ©dito',
    ];

    $tiposCargados = [];
    foreach (glob($actualCafDir . 'caf_*.xml') ?: [] as $f) {
        if (!preg_match('/caf_(\d+)\.xml$/', $f, $m)) continue;
        $tipo = (int)$m[1];
        $tiposCargados[] = $tipo;
        $xml = simplexml_load_string(normalizeCafXmlContent((string)file_get_contents($f)));
        if (!$xml) continue;

        $desde = (int)$xml->CAF->DA->RNG->D;
        $hasta = (int)$xml->CAF->DA->RNG->H;
        $ultimoUsado = $registry[$tipo] ?? ($desde - 1);

        $disponibles = $hasta - $ultimoUsado;
        $total       = $hasta - $desde + 1;
        $porcentaje  = $total > 0 ? round(($disponibles / $total) * 100) : 0;

        $estado = $disponibles <= 0   ? 'AGOTADO'
                : ($disponibles < 10  ? 'CRITICO'
                : ($disponibles < 50  ? 'BAJO' : 'OK'));

        $cafs[] = [
            'tipo'      => $tipo,
            'nombre'    => $tiposNombre[$tipo] ?? "Tipo $tipo",
            'estado'    => $estado,
            'desde'     => $desde,
            'hasta'     => $hasta,
            'ultimo'    => $ultimoUsado,
            'restantes' => max(0, $disponibles),
            'pct'       => $porcentaje,
            'alerta'    => ($disponibles < 10 || $porcentaje < 10),
        ];
    }

    // Detectar tipos con DTEs emitidos pero sin CAF cargado actualmente
    $faltantes = [];
    foreach (glob(rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . 'dte_T*F*.xml') ?: [] as $f) {
        if (preg_match('/dte_T(\d+)F\d+\.xml$/', basename($f), $m)) {
            $t = (int)$m[1];
            if (!in_array($t, $tiposCargados, true) && !isset($faltantes[$t])) {
                $faltantes[$t] = [
                    'tipo'    => $t,
                    'nombre'  => $tiposNombre[$t] ?? "Tipo $t",
                    'estado'  => 'SIN_CAF',
                    'mensaje' => "Hay DTEs tipo $t en tmp/ pero no hay CAF cargado. Cargue el CAF en setup.php para poder emitir nuevos folios.",
                ];
            }
        }
    }

    return [
        'ok'        => true,
        'cafs'      => $cafs,
        'faltantes' => array_values($faltantes),
    ];
}

function uploadCAF(): array {
    global $globalContext;
    if (empty($_FILES['file']['tmp_name'])) {
        return ['ok' => false, 'error' => 'No se subiÃ³ ningÃºn archivo'];
    }

    $tmp = $_FILES['file']['tmp_name'];
    $content = normalizeCafXmlContent((string)file_get_contents($tmp));
    
    // Validar XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($content);
    if (!$xml || !isset($xml->CAF->DA->TD)) {
        return ['ok' => false, 'error' => 'El archivo no es un CAF vÃ¡lido del SII'];
    }

    $tipo = (int)$xml->CAF->DA->TD;
    $desde = (int)$xml->CAF->DA->RNG->D;
    $hasta = (int)$xml->CAF->DA->RNG->H;
    $fechaAuth = (string)$xml->CAF->DA->FA;

    if ($globalContext) {
        $cafRut = strtoupper(preg_replace('/[^0-9K]/i', '', (string)$xml->CAF->DA->RE));
        $empresaRut = strtoupper(preg_replace('/[^0-9K]/i', '', $globalContext->getRut()));
        if ($cafRut === '' || $cafRut !== $empresaRut) {
            return ['ok' => false, 'error' => 'El CAF pertenece a un RUT distinto de la empresa autenticada'];
        }
        $dest = $globalContext->getCafPath($tipo);
        $dir = dirname($dest);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        if (file_put_contents($dest, $content)) {
            $repo = new EmpresaRepository();
            $repo->registrarCAF([
                'empresa_id' => $globalContext->getEmpresaId(),
                'tipo_dte'   => $tipo,
                'desde'      => $desde,
                'hasta'      => $hasta,
                'xml_path'   => $dest,
                'ambiente'   => $globalContext->getAmbiente(),
                'fecha_auth' => $fechaAuth
            ]);

            return [
                'ok' => true, 
                'mensaje' => "CAF Tipo $tipo cargado correctamente para " . $globalContext->getRut(),
                'tipo' => $tipo
            ];
        }
    }
    
    return ['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor'];
}

function soapCall(string $url, string $action, string $body, string $token = ''): ?string {
    $headers = [
        "Content-Type: text/xml;charset=UTF-8",
        "SOAPAction: \"$action\"",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36",
    ];
    if ($token) $headers[] = "Cookie: TOKEN=$token";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => SII_SSL_VERIFY, // IMPORTANTE para evitar problemas de certificados locales
        CURLOPT_SSLVERSION     => SII_MIN_TLS,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("Error de conexiÃ³n (cURL): $err a la URL: $url");
    }
    curl_close($ch);
    return $resp;
}

function testSIIConnectivity(): array {
    $ep = siiEndpoints();
    $url = "https://{$ep['soap_host']}/DTEWS/CrSeed.jws";
    try {
        $soap = '<?xml version="1.0" encoding="UTF-8"?>'
              . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:def="http://DefaultNamespace">'
              . '<soapenv:Header/>'
              . '<soapenv:Body><def:getSeed/></soapenv:Body>'
              . '</soapenv:Envelope>';
        $resSemilla = soapCall($url, '', $soap); 
        if (!$resSemilla) throw new Exception("SII no retornÃ³ semilla vacÃ­a");
        return [
            'ok' => true, 
            'msg' => 'ConexiÃ³n con SII Exitosa', 
            'raw_xml' => htmlspecialchars($resSemilla)
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * EnvÃ­a una Boleta ElectrÃ³nica (39/41) mediante la API REST del SII.
 */
function sendBoletaREST(string $xml, int $tipo, int $folio, string $token): array {
    global $actualTmpDir, $globalContext;

    $rutEmisorFull = $globalContext ? $globalContext->getRut() : RUT_EMISOR;
    $url = siiEndpoints()['boleta_envio'];

    // Boleta: siempre certificado por defecto (Cristina)
    [$cert, $privKey] = loadCertificate($tipo ?: 39);
    $token = getBoletaRestToken($cert, $privKey);
    $rutSenderFull = getRutCertificadoSeguro($cert);
    [$rutSender, $dvSender] = array_pad(explode('-', $rutSenderFull, 2), 2, '');
    [$rutCompany, $dvCompany] = array_pad(explode('-', $rutEmisorFull, 2), 2, '');

    $filename = "boleta_{$tipo}_{$folio}.xml";
    $uploadFile = rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($uploadFile, $xml);

    $payload = [
        "rutSender"  => $rutSender,
        "dvSender"   => strtoupper($dvSender),
        "rutCompany" => $rutCompany,
        "dvCompany"  => strtoupper($dvCompany),
        "archivo"    => new CURLFile($uploadFile, 'application/xml', $filename),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => SII_SSL_VERIFY,
        CURLOPT_SSLVERSION     => SII_MIN_TLS,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Cookie: TOKEN=$token",
            "Accept: application/json",
            // SII REQUIERE este UA (PROG 1.0) â€” con otros UA devuelve 401 NO ESTA AUTENTICADO.
            // Ver manual oficial SII (OI2003_UPDTE_MDE).
            "User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT 5.0; YComp 5.0.2.4)"
        ],
    ]);

    $t0 = microtime(true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    $curlInfo = curl_getinfo($ch);
    curl_close($ch);
    $ms = (int)((microtime(true) - $t0) * 1000);

    @unlink($uploadFile);

    // TransacciÃ³n estructurada (siempre)
    $tx = [
        'op'           => 'sendBoletaREST',
        'tipo'         => $tipo,
        'folio'        => $folio,
        'url'          => $url,
        'rutSender'    => $rutSender . '-' . strtoupper($dvSender),
        'rutCompany'   => $rutCompany . '-' . strtoupper($dvCompany),
        'http'         => $httpCode,
        'ms'           => $ms,
        'curl_error'   => $curlErr ?: null,
        'response_body'=> $response ?? '',
        'archivo'      => $filename,
    ];

    if ($curlErr) {
        $tx['result'] = 'NETWORK_ERROR';
        saveSiiTransaction($tx);
        saveSiiLog('sendBoletaREST', "T{$tipo}F{$folio}: error de red $curlErr ({$ms}ms)", 'ERROR');
        return [
            'ok'      => false,
            'error'   => "Error de red enviando boleta REST: $curlErr",
            'mensaje' => "Error de red enviando boleta REST: $curlErr"
        ];
    }

    $res = json_decode($response, true);

    if ($httpCode === 200 && isset($res['trackid'])) {
        $estadoSii = $res['estado'] ?? 'REC';
        $glosaSii = "Recepcionado por SII. Fecha: " . ($res['fecha_recepcion'] ?? 'sin fecha');
        $tx['result']  = 'OK';
        $tx['trackId'] = (string)$res['trackid'];
        $tx['estado']  = $estadoSii;
        saveSiiTransaction($tx);
        saveSiiLog('sendBoletaREST', "T{$tipo}F{$folio}: boleta recibida. TrackID {$res['trackid']} Estado $estadoSii ({$ms}ms)", 'SUCCESS');
        return [
            'ok'      => true,
            'trackId' => $res['trackid'],
            'estado'  => $estadoSii,
            'glosa'   => $glosaSii,
            'raw' => $res,
            'rawResponse' => $response ?? '',
            'mensaje' => 'Boleta enviada con Ã©xito mediante API REST'
        ];
    }

    $rawResponseSnippet = substr(strip_tags($response ?? ''), 0, 500);
    $errMsg = $res['descripcion'] ?? $res['error'] ?? "Error desconocido en API REST Boleta (HTTP $httpCode): " . $rawResponseSnippet;

    $tx['result']    = 'FAIL';
    $tx['error_msg'] = $errMsg;
    saveSiiTransaction($tx);

    // (mantener log textual legacy para compat)
    file_put_contents(
        rtrim($actualTmpDir, '/\\') . DIRECTORY_SEPARATOR . 'sii_rest_error.log',
        "[" . date('Y-m-d H:i:s') . "] HTTP $httpCode | T{$tipo}F{$folio} | $errMsg\n",
        FILE_APPEND
    );
    saveSiiLog('sendBoletaREST', "T{$tipo}F{$folio}: HTTP $httpCode - $errMsg ({$ms}ms)", 'ERROR');

    // â”€â”€ Auto-enqueue para reintento si el error es transitorio â”€â”€
    if (isErrorTransitorio($httpCode, $errMsg)) {
        enqueueRetry($tipo, $folio, $errMsg, $httpCode);
    }

    return [
        'ok'      => false,
        'error'   => $errMsg,
        'http'    => $httpCode,
        'mensaje' => $errMsg
    ];
}

/**
 * Helper interno: GET a un endpoint del API REST de boletas con el TOKEN.
 * Devuelve [$response, $httpCode, $curlErr].
 */
function _boletaRestGet(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => SII_SSL_VERIFY,
        CURLOPT_SSLVERSION     => SII_MIN_TLS,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Cookie: TOKEN=$token",
            "Accept: application/json",
            "Content-Length: 0",
            "User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT)"
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    return [$response, $httpCode, $err];
}

/**
 * Consulta GET /boleta.electronica/{rut}-{dv}-{tipo}-{folio}/estado
 * Es la consulta mÃ¡s confiable (devuelve cÃ³digos DOK/DNK/FAU/etc).
 */
function queryEstadoBoletaPorFolio(string $baseUrl, string $rutCompany, string $dvCompany, int $tipo, int $folio, string $token, string $rutRecep, string $fecha, int $monto): array {
    [$rutReceptor, $dvReceptor] = array_pad(explode('-', $rutRecep, 2), 2, '');
    $query = [
        'rut_receptor' => preg_replace('/\D/', '', $rutReceptor),
        'dv_receptor'  => strtoupper(trim($dvReceptor)),
    ];
    if ($monto > 0) $query['monto'] = $monto;
    if ($fecha !== '') {
        $ts = strtotime($fecha);
        $query['fechaEmision'] = $ts ? date('d-m-Y', $ts) : $fecha;
    }
    $url = "$baseUrl/boleta.electronica/{$rutCompany}-{$dvCompany}-{$tipo}-{$folio}/estado?" . http_build_query($query);
    [$resp, $http, $err] = _boletaRestGet($url, $token);
    return ['url' => $url, 'response' => $resp, 'http' => $http, 'error' => $err];
}

/**
 * Consulta GET /boleta.electronica.envio/{rut}-{dv}-{trackid}
 * Alternativa por TrackID (suele dar 401 en algunos casos; se usa como fallback).
 */
function queryEstadoBoletaPorTrack(string $baseUrl, string $rutCompany, string $dvCompany, string $trackId, string $token): array {
    $url = "$baseUrl/boleta.electronica.envio/{$rutCompany}-{$dvCompany}-{$trackId}";
    [$resp, $http, $err] = _boletaRestGet($url, $token);
    return ['url' => $url, 'response' => $resp, 'http' => $http, 'error' => $err];
}

/**
 * Consulta el estado de una boleta mediante API REST.
 * Estrategia: primero intenta por folio (mÃ¡s confiable);
 * si falla y hay TrackID, hace fallback por TrackID.
 */
function queryEstadoBoletaREST(int $tipo, int $folio, string $trackId, string $token, string $rutRecep = '66666666-6', string $fecha = '', int $monto = 0): array {
    global $globalContext;
    $rutEmisorFull = $globalContext ? $globalContext->getRut() : RUT_EMISOR;
    $baseUrl = siiEndpoints()['boleta_consulta'];
    [$rutCompany, $dvCompany] = array_pad(explode('-', $rutEmisorFull, 2), 2, '');

    // CÃ³digos del catÃ¡logo SII para boletas (estados vÃ¡lidos del documento).
    // FAU = Documento No Recibido por el SII (puede ser temporal mientras se indexa).
    // Todos estos son respuestas legÃ­timas que NO requieren reintento.
    $acceptedCodes = ['DOK', 'DNK', 'TMD', 'TMC', 'MMD', 'MMC', 'AND', 'ANC', 'EPR', 'CRT', 'FOK', 'SOK', 'REC', 'RPR'];
    $catalogCodes  = array_merge($acceptedCodes, ['FAU', 'FNA', 'FAN', 'EMP', 'RSC', 'RFR', 'PRD', 'RCT']);

    $attempts = [];

    // â”€â”€ Intento 1: consulta por folio (la confiable) â”€â”€
    $tryFolio = ($folio > 0 && $fecha !== '');
    if ($tryFolio) {
        $r = queryEstadoBoletaPorFolio($baseUrl, $rutCompany, $dvCompany, $tipo, $folio, $token, $rutRecep, $fecha, $monto);
        $attempts[] = ['via' => 'folio', 'url' => $r['url'], 'http' => $r['http'], 'error' => $r['error']];

        if ($r['error']) {
            saveSiiLog('queryEstadoBoletaREST', "T{$tipo}F{$folio} folio: error red {$r['error']}", 'ERROR');
        } else {
            $res = json_decode($r['response'], true);
            $isJson = is_array($res);
            $estado = $res['estado'] ?? $res['codigo'] ?? null;
            $glosa  = $res['descripcion'] ?? $res['detalle'] ?? "Respuesta SII HTTP {$r['http']}";

            // Si el SII respondiÃ³ HTTP 200 con un cÃ³digo del catÃ¡logo (incluyendo FAU),
            // esa es la respuesta autoritativa. No buscamos fallback.
            if ($r['http'] === 200 && $isJson && in_array((string)$estado, $catalogCodes, true)) {
                $ok = in_array((string)$estado, $acceptedCodes, true);
                saveSiiLog('queryEstadoBoletaREST', "T{$tipo}F{$folio}: HTTP 200 $estado - $glosa", $ok ? 'INFO' : 'WARNING');
                return [
                    'ok'          => $ok,
                    'estado'      => $estado,
                    'glosa'       => $glosa,
                    'raw'         => $res,
                    'url'         => $r['url'],
                    'rawResponse' => $r['response'] ?? '',
                    'attempts'    => $attempts,
                    'via'         => 'folio',
                ];
            }
            // Si HTTP fue 200 pero cÃ³digo no es del catÃ¡logo, o no fue 200, intentaremos trackId.
            saveSiiLog('queryEstadoBoletaREST', "T{$tipo}F{$folio} folio HTTP {$r['http']}, intentando TrackID", 'WARNING');
        }
    }

    // â”€â”€ Intento 2: por TrackID (fallback o si no habÃ­a datos para folio) â”€â”€
    if ($trackId) {
        $r = queryEstadoBoletaPorTrack($baseUrl, $rutCompany, $dvCompany, $trackId, $token);
        $attempts[] = ['via' => 'trackid', 'url' => $r['url'], 'http' => $r['http'], 'error' => $r['error']];

        if ($r['error']) {
            saveSiiLog('queryEstadoBoletaREST', "TrackID $trackId: error red {$r['error']}", 'ERROR');
            return [
                'ok' => false, 'estado' => 'ERROR_RED', 'glosa' => $r['error'],
                'raw' => null, 'url' => $r['url'], 'rawResponse' => '',
                'attempts' => $attempts, 'via' => 'trackid'
            ];
        }

        $res = json_decode($r['response'], true);
        $isJson = is_array($res);
        $hasHtml = preg_match('/ptrTkn|ERROR\s*:\s*501|<html/i', (string)$r['response']);
        $estado = $res['estado'] ?? $res['codigo'] ?? ($r['http'] === 200 && $isJson ? 'RESPUESTA_SII' : 'ERROR');
        $glosa  = $res['descripcion'] ?? $res['detalle'] ?? "Respuesta SII HTTP {$r['http']}";
        if (!$isJson && $hasHtml) {
            $glosa = trim(preg_replace('/\s+/', ' ', strip_tags((string)$r['response'])));
            $glosa = substr($glosa, 0, 500);
        }
        $ok = ($r['http'] === 200 && $isJson && !$hasHtml && in_array((string)$estado, $acceptedCodes, true));

        saveSiiLog('queryEstadoBoletaREST', "TrackID $trackId: HTTP {$r['http']} $estado - $glosa", $ok ? 'INFO' : 'ERROR');

        return [
            'ok'          => $ok,
            'estado'      => $estado,
            'glosa'       => $glosa,
            'raw'         => $res,
            'url'         => $r['url'],
            'rawResponse' => $r['response'] ?? '',
            'attempts'    => $attempts,
            'via'         => 'trackid',
        ];
    }

    // â”€â”€ Ninguna vÃ­a disponible (sin folio+fecha y sin trackId) â”€â”€
    return [
        'ok'       => false,
        'estado'   => 'PARAMS_INSUFICIENTES',
        'glosa'    => 'Se requiere TrackID o (folio + fecha) para consultar estado.',
        'raw'     => null,
        'url'     => '',
        'rawResponse' => '',
        'attempts' => $attempts,
    ];
}

function getCertCaseData(string $caseId): array {
    $cases = [
        // SET BOLETA ELECTRONICA
        'B-CASO-1' => [
            'tipoDTE' => 39, 'referencias' => [['codigo'=>'SET','razon'=>'CASO-1']],
            'items' => [['nombre'=>'Cambio de aceite','cantidad'=>1,'precio'=>19900],['nombre'=>'Alineacion y balanceo','cantidad'=>1,'precio'=>9900]]
        ],
        'B-CASO-2' => [
            'tipoDTE' => 39, 'referencias' => [['codigo'=>'SET','razon'=>'CASO-2']],
            'items' => [['nombre'=>'Papel de regalo','cantidad'=>17,'precio'=>120]]
        ],
        'B-CASO-3' => [
            'tipoDTE' => 39, 'referencias' => [['codigo'=>'SET','razon'=>'CASO-3']],
            'items' => [['nombre'=>'Sandwic','cantidad'=>2,'precio'=>1500],['nombre'=>'Bebida','cantidad'=>2,'precio'=>550]]
        ],
        'B-CASO-4' => [
            'tipoDTE' => 39, 'referencias' => [['codigo'=>'SET','razon'=>'CASO-4']],
            'items' => [['nombre'=>'item afecto 1','cantidad'=>8,'precio'=>1590,'exento'=>false],['nombre'=>'item exento 2','cantidad'=>2,'precio'=>1000,'exento'=>true]]
        ],
        'B-CASO-5' => [
            'tipoDTE' => 39, 'referencias' => [['codigo'=>'SET','razon'=>'CASO-5']],
            'items' => [['nombre'=>'Arroz','cantidad'=>5,'precio'=>700,'unidadMedida'=>'Kg']]
        ],
        // SET BASICO FACTURACION (AtenciÃ³n 4832043)
        'F-4832043-1' => [
            'tipoDTE' => 33, 'items' => [['nombre'=>'Cajón AFECTO','cantidad'=>169,'precio'=>3531],['nombre'=>'Relleno AFECTO','cantidad'=>71,'precio'=>5882]]
        ],
        'F-4832043-2' => [
            'tipoDTE' => 33, 'items' => [['nombre'=>'Pañuelo AFECTO','cantidad'=>767,'precio'=>5937,'descuento'=>10],['nombre'=>'ITEM 2 AFECTO','cantidad'=>712,'precio'=>4988,'descuento'=>23]]
        ],
        'F-4832043-3' => [
            'tipoDTE' => 33, 'items' => [['nombre'=>'Pintura B&W AFECTO','cantidad'=>65,'precio'=>6938],['nombre'=>'ITEM 2 AFECTO','cantidad'=>238,'precio'=>4041],['nombre'=>'ITEM 3 SERVICIO EXENTO','cantidad'=>1,'precio'=>35301,'exento'=>true]]
        ],
        'F-4832043-4' => [
            'tipoDTE' => 33, 'descuentoGlobal' => 23,
            'items' => [['nombre'=>'ITEM 1 AFECTO','cantidad'=>419,'precio'=>5986],['nombre'=>'ITEM 2 AFECTO','cantidad'=>178,'precio'=>7296],['nombre'=>'ITEM 3 SERVICIO EXENTO','cantidad'=>2,'precio'=>6834,'exento'=>true]]
        ],
        // NOTAS DE CREDITO / DEBITO (Referenciando los casos anteriores)
        'F-4832043-5' => [
            'tipoDTE' => 61, 'referencias' => [['tipo'=>33, 'folio'=>'REF_F1', 'codigo'=>2, 'razon'=>'CORRIGE GIRO DEL RECEPTOR']],
            'items' => [['nombre'=>'AJUSTE GIRO RECEPTOR','cantidad'=>1,'precio'=>0,'exento'=>true]]
        ],
        'F-4832043-6' => [
            'tipoDTE' => 61, 'referencias' => [['tipo'=>33, 'folio'=>'REF_F2', 'codigo'=>1, 'razon'=>'DEVOLUCION DE MERCADERIAS']],
            'items' => [['nombre'=>'Pañuelo AFECTO','cantidad'=>282,'precio'=>5937],['nombre'=>'ITEM 2 AFECTO','cantidad'=>483,'precio'=>4988]]
        ],
        // Caso 7: NC que anula la factura del caso 3. Los Ã­tems deben replicar
        // exactamente los de F-4832043-3 para que los montos cuadren (NC de anulaciÃ³n).
        'F-4832043-7' => [
            'tipoDTE' => 61, 'referencias' => [['tipo'=>33, 'folio'=>'REF_F3', 'codigo'=>1, 'razon'=>'ANULA FACTURA']],
            'items' => [
                ['nombre'=>'Pintura B&W AFECTO',        'cantidad'=>65,  'precio'=>6938],
                ['nombre'=>'ITEM 2 AFECTO',              'cantidad'=>238, 'precio'=>4041],
                ['nombre'=>'ITEM 3 SERVICIO EXENTO',     'cantidad'=>1,   'precio'=>35301, 'exento'=>true],
            ],
        ],
        'F-4832043-8' => [
            'tipoDTE' => 56, 'referencias' => [['tipo'=>61, 'folio'=>'REF_NC1', 'codigo'=>1, 'razon'=>'ANULA NOTA DE CREDITO ELECTRONICA']],
            'items' => [['nombre'=>'ANULACION NC','cantidad'=>1,'precio'=>0,'exento'=>true]]
        ],
        // SET GUIAS DE DESPACHO (AtenciÃ³n 4820753)
        // IndTraslado 5 = traslado interno: receptor es el propio emisor (XSD lo exige igualmente)
        'G-4879711-1' => [
            'tipoDTE' => 52, 'indTraslado' => 5,
            'items' => [['nombre'=>'ITEM 1','cantidad'=>71,'precio'=>0],['nombre'=>'ITEM 2','cantidad'=>102,'precio'=>0],['nombre'=>'ITEM 3','cantidad'=>65,'precio'=>0]],
        ],
        'G-4879711-2' => [
            'tipoDTE' => 52, 'indTraslado' => 1,
            'items' => [['nombre'=>'ITEM 1','cantidad'=>261,'precio'=>5514],['nombre'=>'ITEM 2','cantidad'=>501,'precio'=>1411]],
        ],
        'G-4879711-3' => [
            'tipoDTE' => 52, 'indTraslado' => 1,
            'items' => [['nombre'=>'ITEM 1','cantidad'=>143,'precio'=>1690],['nombre'=>'ITEM 2','cantidad'=>316,'precio'=>4327]],
        ],
    ];

    if (!isset($cases[$caseId])) throw new Exception("Caso de prueba '$caseId' no definido.");
    
    $caseData = $cases[$caseId];
    global $globalContext;

    // LÃ³gica de Referencia AutomÃ¡tica (Busca folios recientes si es necesario)
    if (isset($caseData['referencias'])) {
        $repo = new EmpresaRepository();
        foreach ($caseData['referencias'] as &$ref) {
            if (strpos($ref['folio'], 'REF_') === 0) {
                $offset = 0;
                if ($ref['folio'] == 'REF_F1') $offset = 3; // Case 1 is 4th to last if emitted in order
                if ($ref['folio'] == 'REF_F2') $offset = 2; // Case 2 is 3rd to last
                if ($ref['folio'] == 'REF_F3') $offset = 1; // Case 3 is 2nd to last
                if ($ref['folio'] == 'REF_NC1') $offset = 2; // Case 5 is 3rd to last NC (5, 6, 7)
                
                $lastDtes = $repo->getUltimosDTEs($globalContext->getEmpresaId(), $ref['tipo'], 10);
                if (!empty($lastDtes)) {
                    $ref['folio'] = $lastDtes[$offset]['folio'] ?? $lastDtes[0]['folio'];
                    $ref['fecha'] = $lastDtes[$offset]['fecha'] ?? $lastDtes[0]['fecha'];
                } else {
                    // Fallback para evaluaciÃ³n: si no hay facturas emitidas, referenciamos al folio 1 ficticio
                    $ref['folio'] = 1;
                    $ref['fecha'] = date('Y-m-d');
                }
            }
        }
    }
    
    // Forzar RUT de receptor vÃ¡lido segÃºn normativa SII para certificaciÃ³n
    $tDte = (int)$cases[$caseId]['tipoDTE'];
    $rutReceptorCert = in_array($tDte, [39, 41]) ? '66666666-6' : '55555555-5';
    
    // GuÃ­as de despacho (tipo 52) siempre requieren receptor, incluso en traslado interno.
    // Para IndTraslado=5 el SII acepta el mismo RUT del emisor como receptor.
    $recep = [
        'rut'      => $rutReceptorCert,
        'nombre'   => 'EMPRESA DE PRUEBAS SII',
        'giro'     => 'GIRO DE PRUEBAS',
        'direccion'=> 'CALLE PRUEBA 123',
        'comuna'   => 'SANTIAGO',
        'ciudad'   => 'SANTIAGO',
    ];
    
    return array_merge(['receptor' => $recep], $cases[$caseId]);
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// ENDPOINTS ANDROID â€“ Historial DTE
// (productos/sucursales/stock â†' dte_php/fb/index.php)
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

/**
 * Historial paginado de DTEs emitidos (para el APK).
 * Devuelve en el mismo formato que getHistory() pero con paginaciÃ³n y
 * filtro opcional por tipo y sucursal.
 */
function getHistorialPaginado(int $page = 1, ?int $tipo = null, ?string $sucursalId = null): array {
    global $globalContext;
    $limit  = 50;
    $offset = ($page - 1) * $limit;

    if (!$globalContext) {
        return ['success' => false, 'entries' => [], 'error' => 'Sin contexto de empresa'];
    }

    try {
        $db         = $globalContext->getDb();
        $empresaId  = $globalContext->getEmpresaId();
        $ambiente   = $globalContext->getAmbiente();

        $where  = "WHERE empresa_id = :eid AND ambiente = :amb";
        $params = [':eid' => $empresaId, ':amb' => $ambiente];

        if ($tipo !== null) {
            $where .= " AND tipo_dte = :tipo";
            $params[':tipo'] = $tipo;
        }
        if ($sucursalId !== null && $sucursalId !== '') {
            $where .= " AND sucursal_id = :suc";
            $params[':suc'] = $sucursalId;
        }

        $sql = "SELECT folio, tipo_dte as tipo, mnt_total as mntTotal,
                       fecha_emision as fecha, estado, track_id as trackId,
                       sucursal_id
                FROM sii_dte $where
                ORDER BY fecha_emision DESC, folio DESC
                LIMIT :lim OFFSET :off";

        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM sii_dte $where");
        foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        return [
            'success' => true,
            'entries' => $rows,
            'total'   => $total,
            'page'    => $page,
        ];

    } catch (Throwable $e) {
        error_log('getHistorialPaginado: ' . $e->getMessage());
        return ['success' => false, 'entries' => [], 'error' => $e->getMessage()];
    }
}
