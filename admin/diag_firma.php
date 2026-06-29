<?php
/**
 * Diagnóstico de firma para RFR ("Rechazado por Error en Firma") en guías 52.
 * Verifica las 3 causas deterministas posibles:
 *   A) El certificado de David ¿su clave privada corresponde al certificado?
 *   B) El CAF 52 ¿su RSASK corresponde a su RSAPK?
 *   C) El TED del último DTE generado ¿su FRMT valida con la RSAPK del CAF?
 *   D) La firma XMLDSig del <Documento> ¿el digest y SignatureValue son válidos?
 *
 * USO: php diag_firma.php [archivoDTE.xml]
 *   requiere la ruta explicita del XML a diagnosticar
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI\n"); }

session_start();
$_SESSION['active_empresa_id'] = 1;
require __DIR__ . '/api.php';

$GLOBALS['SII_CERT_TIPO'] = 52;

function ok($b){ return $b ? "OK" : "FALLA"; }

echo "===== A) Cert David: clave privada <-> certificado =====\n";
try {
    [$cert, $key] = loadCertificate(52);
    $msg = 'prueba-' . bin2hex(random_bytes(8));
    openssl_sign($msg, $sig, $key, OPENSSL_ALGO_SHA1);
    $pub = openssl_pkey_get_public($cert);
    $verif = openssl_verify($msg, $sig, $pub, OPENSSL_ALGO_SHA1);
    echo "Firma con clave / verifica con cert: " . ok($verif === 1) . "  (verify=$verif)\n";
    $x = openssl_x509_parse($cert);
    echo "Cert CN: " . ($x['subject']['CN'] ?? '?') . " | vence: " . date('Y-m-d', $x['validTo_time_t'])
       . ($x['validTo_time_t'] < time() ? " (VENCIDO!)" : " (vigente)") . "\n";
} catch (Throwable $e) { echo "ERROR A: " . $e->getMessage() . "\n"; }

echo "\n===== B) CAF 52: RSASK <-> RSAPK =====\n";
try {
    $caf = loadCAF(52);
    $cafKey = openssl_pkey_get_private($caf['privKey']);
    if (!$cafKey) { echo "ERROR: RSASK del CAF no es una clave válida\n"; }
    else {
        $d = openssl_pkey_get_details($cafKey);
        $modKey = base64_encode($d['rsa']['n']);
        // RSAPK declarado en el bloque CAF
        preg_match('#<RSAPK>\s*<M>([^<]+)</M>\s*<E>([^<]+)</E>#s', $caf['xml'], $mk);
        $modCaf = trim($mk[1] ?? '');
        echo "Modulus clave privada == Modulus RSAPK del CAF: " . ok($modKey === $modCaf) . "\n";
        if ($modKey !== $modCaf) {
            echo "  privKey.n (b64, 40): " . substr($modKey, 0, 40) . "...\n";
            echo "  CAF.RSAPK.M (b64,40): " . substr($modCaf, 0, 40) . "...\n";
        }
        // Firma de prueba con RSASK y verificación con RSAPK reconstruida
        $msg = 'ted-test';
        openssl_sign($msg, $s2, $cafKey, OPENSSL_ALGO_SHA1);
        // Reconstruir public key desde M/E del CAF
        $pubPem = caf_pub_from_me($modCaf, trim($mk[2] ?? 'Aw=='));
        $v2 = $pubPem ? openssl_verify($msg, $s2, $pubPem, OPENSSL_ALGO_SHA1) : -9;
        echo "RSASK firma / RSAPK verifica: " . ok($v2 === 1) . "  (verify=$v2)\n";
        echo "CAF rango: {$caf['desde']}-{$caf['hasta']}\n";
    }
} catch (Throwable $e) { echo "ERROR B: " . $e->getMessage() . "\n"; }

echo "\n===== C/D) DTE generado: TED FRMT y XMLDSig =====\n";
$file = $argv[1] ?? '';
if ($file === '') exit("Uso: php diag_firma.php <ruta-xml>\n");
if (!is_file($file)) { echo "No existe $file (genere primero con emitir_guia.php)\n"; exit; }
$doc = file_get_contents($file);
echo "Archivo: $file\n";

// C) FRMT del TED
if (preg_match('#<TED[^>]*>(<DD>.*?</DD>)<FRMT[^>]*>([^<]+)</FRMT></TED>#s', $doc, $mt)) {
    $dd   = $mt[1];
    $frmt = base64_decode(trim($mt[2]));
    preg_match('#<RSAPK>\s*<M>([^<]+)</M>\s*<E>([^<]+)</E>#s', $doc, $mp);
    $pub = caf_pub_from_me(trim($mp[1] ?? ''), trim($mp[2] ?? 'Aw=='));
    $ddIso = mb_convert_encoding($dd, 'ISO-8859-1', 'UTF-8');
    $vf = $pub ? openssl_verify($ddIso, $frmt, $pub, OPENSSL_ALGO_SHA1) : -9;
    echo "TED FRMT valida con RSAPK del CAF embebido: " . ok($vf === 1) . "  (verify=$vf)\n";
} else {
    echo "No se pudo extraer TED/FRMT del documento\n";
}

// D) XMLDSig del Documento
try {
    $dom = new DOMDocument('1.0', 'ISO-8859-1');
    @$dom->loadXML($doc);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $xp->registerNamespace('s',  'http://www.sii.cl/SiiDte');
    $documento = $xp->query("//s:Documento")->item(0);
    $digestNode = $xp->query("//ds:Reference/ds:DigestValue")->item(0);
    $sigValNode = $xp->query("//ds:SignatureValue")->item(0);
    $siNode     = $xp->query("//ds:SignedInfo")->item(0);
    $x509Node   = $xp->query("//ds:X509Certificate")->item(0);
    if ($documento && $digestNode) {
        $calc = base64_encode(sha1($documento->C14N(), true));
        $decl = trim($digestNode->textContent);
        echo "Digest <Documento> recalculado == DigestValue: " . ok($calc === $decl) . "\n";
        if ($calc !== $decl) echo "  calc=$calc\n  decl=$decl\n";
    }
    if ($siNode && $sigValNode && $x509Node) {
        $certPem = "-----BEGIN CERTIFICATE-----\n"
                 . chunk_split(preg_replace('/\s+/', '', $x509Node->textContent), 64, "\n")
                 . "-----END CERTIFICATE-----\n";
        $pub = openssl_pkey_get_public($certPem);
        $v = openssl_verify($siNode->C14N(), base64_decode(trim($sigValNode->textContent)), $pub, OPENSSL_ALGO_SHA1);
        echo "SignatureValue valida con X509Certificate: " . ok($v === 1) . "  (verify=$v)\n";
    }
} catch (Throwable $e) { echo "ERROR D: " . $e->getMessage() . "\n"; }

echo "\n===== E) SOBRE EnvioDTE: firma SetDTE + Caratula + XSD =====\n";
try {
    [$cert, $key] = loadCertificate(52);
    $envio   = buildEnvioDTE($doc, 52, 192313, $cert);
    $firmado = signDTE($envio, $cert, $key, 'SetDoc');
    file_put_contents(__DIR__ . '/tmp/_sobre_diag_192313.xml', $firmado);

    // RutEnvia declarado en la Caratula
    preg_match('#<RutEnvia>([^<]+)</RutEnvia>#', $firmado, $mre);
    $rutEnvia = trim($mre[1] ?? '');
    echo "Caratula RutEnvia: $rutEnvia\n";

    // RUT del certificado firmante (config para tipo 52)
    echo "RUT cert firmante (getRutCertificadoSeguro): " . getRutCertificadoSeguro($cert) . "\n";

    $d = new DOMDocument('1.0', 'ISO-8859-1');
    @$d->loadXML($firmado);
    $xp = new DOMXPath($d);
    $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $xp->registerNamespace('s',  'http://www.sii.cl/SiiDte');

    // Hay 2 Signatures: la del Documento y la del SetDTE. Tomamos la del SetDoc:
    $setNode = $xp->query("//s:SetDTE[@ID='SetDoc']")->item(0);
    // La Signature del sobre es hija directa de EnvioDTE (después de SetDTE)
    $sig = null;
    foreach ($xp->query("/s:EnvioDTE/ds:Signature") as $n) { $sig = $n; }
    if ($setNode && $sig) {
        $ref = $xp->query(".//ds:Reference/ds:DigestValue", $sig)->item(0);
        $si  = $xp->query(".//ds:SignedInfo", $sig)->item(0);
        $sv  = $xp->query(".//ds:SignatureValue", $sig)->item(0);
        $x5  = $xp->query(".//ds:X509Certificate", $sig)->item(0);

        $calc = base64_encode(sha1($setNode->C14N(), true));
        $decl = trim($ref->textContent);
        echo "Digest SetDTE recalculado == DigestValue: " . ok($calc === $decl) . "\n";
        if ($calc !== $decl) echo "  calc=$calc\n  decl=$decl\n";

        $certPem = "-----BEGIN CERTIFICATE-----\n"
                 . chunk_split(preg_replace('/\s+/', '', $x5->textContent), 64, "\n")
                 . "-----END CERTIFICATE-----\n";
        $pub = openssl_pkey_get_public($certPem);
        $v = openssl_verify($si->C14N(), base64_decode(trim($sv->textContent)), $pub, OPENSSL_ALGO_SHA1);
        echo "SetDTE SignatureValue valida con su X509Certificate: " . ok($v === 1) . " (verify=$v)\n";
        $xc = openssl_x509_parse($certPem);
        echo "Cert que firma el SOBRE: CN=" . ($xc['subject']['CN'] ?? '?')
           . " | serialNumber=" . ($xc['subject']['serialNumber'] ?? '(ninguno)') . "\n";
    } else {
        echo "No se ubicó SetDTE o Signature del sobre\n";
    }

    // Cert que firma el DOCUMENTO interno (para comparar)
    $docSig = $xp->query("//s:Documento/following-sibling::ds:Signature | //s:DTE/ds:Signature")->item(0);
    if ($docSig) {
        $x5d = $xp->query(".//ds:X509Certificate", $docSig)->item(0);
        if ($x5d) {
            $cpd = "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', $x5d->textContent), 64, "\n") . "-----END CERTIFICATE-----\n";
            $xcd = openssl_x509_parse($cpd);
            echo "Cert que firma el DOCUMENTO: CN=" . ($xcd['subject']['CN'] ?? '?') . "\n";
        }
    }

    $val = validateXmlAgainstXSD($firmado);
    echo "XSD estricto EnvioDTE: " . ($val['valid'] ? 'VALIDO' : 'INVALIDO')
       . (empty($val['errors']) ? '' : ' | ' . implode(' || ', array_slice($val['errors'], 0, 6))) . "\n";
} catch (Throwable $e) { echo "ERROR E: " . $e->getMessage() . "\n"; }

// Helper: reconstruye PEM de public key RSA desde Modulus/Exponent base64 (DER)
function caf_pub_from_me(string $mB64, string $eB64) {
    $n = base64_decode($mB64); $e = base64_decode($eB64);
    if ($n === false || $e === false || $n === '') return false;
    $encLen = function($n){ // longitud DER
        if (strlen($n) < 128) return chr(strlen($n));
        $len = strlen($n); $bytes = '';
        while ($len > 0) { $bytes = chr($len & 0xff) . $bytes; $len >>= 8; }
        return chr(0x80 | strlen($bytes)) . $bytes;
    };
    $intgr = function($x) use ($encLen){
        if (ord($x[0]) > 0x7f) $x = "\x00" . $x;
        return "\x02" . $encLen($x) . $x;
    };
    $seq = $intgr($n) . $intgr($e);
    $seq = "\x30" . $encLen($seq) . $seq;
    $bit = "\x00" . $seq;
    $bit = "\x03" . $encLen($bit) . $bit;
    $algo = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $spki = $algo . $bit;
    $spki = "\x30" . $encLen($spki) . $spki;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem) ?: false;
}
