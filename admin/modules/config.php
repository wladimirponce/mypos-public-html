<?php
/**
 * Módulo Configuración — Certificados, CAF y Sistema
 */
$cCertPfx = $globalContext ? $globalContext->getCertPath() : CERT_PFX;
$cCafDir  = $globalContext ? dirname($globalContext->getCafPath(0)) . '/' : CAF_DIR;

if (!function_exists('normalizeCafXmlContent')) {
    function normalizeCafXmlContent(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (!preg_match('//u', $content)) {
            $enc = function_exists('mb_detect_encoding')
                ? (mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) ?: 'Windows-1252')
                : 'Windows-1252';
            $content = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($content, 'UTF-8', $enc)
                : iconv($enc, 'UTF-8//IGNORE', $content);
        }

        $updated = preg_replace(
            '/<\?xml([^>]*?)encoding=["\'][^"\']+["\']([^>]*?)\?>/i',
            '<?xml$1encoding="UTF-8"$2?>',
            $content,
            1,
            $count
        );

        return $count > 0 ? $updated : $content;
    }
}

// Procesar acciones POST
if (isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'upload_cert' && !empty($_FILES['cert_file']['tmp_name'])) {
            $pass = $_POST['cert_pass'] ?? '';
            $tmp = $_FILES['cert_file']['tmp_name'];
            $certs = [];
            $converted = false;
            
            if (!openssl_pkcs12_read(file_get_contents($tmp), $certs, $pass)) {
                if (function_exists('exec') && stristr(PHP_OS, 'LINUX')) {
                    $tmpOut = $tmp . '_modern.pfx';
                    $cmd = "openssl pkcs12 -in " . escapeshellarg($tmp) . " -passin pass:" . escapeshellarg($pass) . " -nodes -legacy 2>/dev/null | openssl pkcs12 -export -out " . escapeshellarg($tmpOut) . " -passout pass:" . escapeshellarg($pass) . " 2>/dev/null";
                    exec($cmd, $output, $returnVar);
                    if ($returnVar === 0 && file_exists($tmpOut) && openssl_pkcs12_read(file_get_contents($tmpOut), $certs, $pass)) {
                        $tmp = $tmpOut;
                        $converted = true;
                    }
                }
            } else { $converted = true; }
            
            if ($converted || !empty($certs)) {
                $dir = dirname($cCertPfx);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (!copy($tmp, $cCertPfx)) throw new Exception("No se pudo copiar el certificado a $cCertPfx");
                
                file_put_contents(dirname($cCertPfx) . '/cert.conf', json_encode([
                    'pass' => $pass, 'uploaded' => date('Y-m-d H:i:s'), 'original' => $_FILES['cert_file']['name']
                ]));

                // Registrar en BD
                if ($globalContext) {
                    $repo = new \App\Repositories\EmpresaRepository();
                    $data = openssl_x509_parse($certs['cert']);
                    $rutTitular = "";
                    if (preg_match('/(\d{7,8})-([0-9Kk])/', $data['subject']['serialNumber'] ?? '', $m)) {
                        $rutTitular = $m[1].'-'.strtoupper($m[2]);
                    }
                    
                    $repo->registrarCertificado([
                        'empresa_id' => $globalContext->getEmpresaId(),
                        'original'   => $_FILES['cert_file']['name'],
                        'ruta_pfx'   => $cCertPfx,
                        'pass'       => $pass,
                        'rut'        => $rutTitular,
                        'nombre'     => $data['subject']['CN'] ?? 'Titular',
                        'desde'      => date('Y-m-d', $data['validFrom_time_t']),
                        'hasta'      => date('Y-m-d', $data['validTo_time_t'])
                    ]);
                }
            } else {
                throw new Exception("La contraseña del certificado es incorrecta o el archivo está dañado.");
            }
        }
        
        if ($_POST['action'] === 'upload_caf' && !empty($_FILES['caf_file']['tmp_name'])) {
            $tmp = $_FILES['caf_file']['tmp_name'];
            $content = normalizeCafXmlContent((string)file_get_contents($tmp));
            $xml = simplexml_load_string($content);
            if (!$xml || !isset($xml->CAF->DA->TD)) throw new Exception("El archivo no parece ser un CAF válido.");

            $tipo = (int)$xml->CAF->DA->TD;
            $dest = $globalContext ? $globalContext->getCafPath($tipo) : CAF_DIR . "caf_{$tipo}.xml";
            $dir = dirname($dest);
            
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (!file_put_contents($dest, $content)) throw new Exception("Error al escribir el archivo CAF en $dest. Verifique permisos.");
            
            if ($globalContext) {
                $repo = new \App\Repositories\EmpresaRepository();
                $repo->registrarCAF([
                    'empresa_id' => $globalContext->getEmpresaId(),
                    'tipo_dte'   => $tipo,
                    'desde'      => (int)$xml->CAF->DA->RNG->D,
                    'hasta'      => (int)$xml->CAF->DA->RNG->H,
                    'xml_path'   => $dest,
                    'ambiente'   => $globalContext->getAmbiente(),
                    'fecha_auth' => (string)$xml->CAF->DA->FA
                ]);
            }
        }
        
        if ($_POST['action'] === 'delete_caf') {
            $tipo = (int)($_POST['tipo'] ?? 0);
            $file = $cCafDir . "caf_{$tipo}.xml";
            if (file_exists($file)) unlink($file);
        }

        // Redirigir solo si todo salió bien
        header('Location: dashboard.php?module=config&ok=1');
        exit;

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

// Leer certificado
$certExists = file_exists($cCertPfx);
$certConf = [];
$certInfo = null;
if ($certExists) {
    $confFile = dirname($cCertPfx) . '/cert.conf';
    if (file_exists($confFile)) $certConf = json_decode(file_get_contents($confFile), true) ?? [];
    if (!empty($certConf['pass'])) {
        $c2 = [];
        if (openssl_pkcs12_read(file_get_contents($cCertPfx), $c2, $certConf['pass'])) {
            $parsed = openssl_x509_parse($c2['cert']);
            $certInfo = [
                'cn' => $parsed['subject']['CN'] ?? ($parsed['subject']['O'] ?? '—'),
                'validTo' => date('d/m/Y', $parsed['validTo_time_t'] ?? 0),
                'dias' => max(0, intdiv(($parsed['validTo_time_t'] ?? 0) - time(), 86400)),
                'size' => round(filesize($cCertPfx) / 1024, 1) . ' KB',
                'original' => $certConf['original'] ?? 'firma.pfx',
            ];
        }
    }
}

// Leer CAFs
$cafsData = [];
$tiposN = [33=>'Factura',34=>'Factura Exenta',39=>'Boleta',41=>'Boleta Exenta',52=>'Guía Despacho',56=>'Nota Débito',61=>'Nota Crédito'];
foreach (glob($cCafDir . 'caf_*.xml') as $f) {
    preg_match('/caf_(\d+)\.xml$/', $f, $m);
    $tipo = (int)($m[1] ?? 0);
    $content = normalizeCafXmlContent((string)file_get_contents($f));
    $xml = @simplexml_load_string($content);    if (!$xml) continue;
    $cafsData[] = [
        'tipo' => $tipo, 'nombre' => $tiposN[$tipo] ?? "Tipo $tipo",
        'desde' => (int)$xml->CAF->DA->RNG->D, 'hasta' => (int)$xml->CAF->DA->RNG->H,
        'folios' => (int)$xml->CAF->DA->RNG->H - (int)$xml->CAF->DA->RNG->D + 1,
        'fecha' => (string)$xml->CAF->DA->FA,
    ];
}
?>

<?php if (isset($errorMsg)): ?>
    <div class="d-alert danger"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<?php if (isset($_GET['ok'])): ?>
    <div class="d-alert success"><i class="bi bi-check-circle"></i> Configuración actualizada correctamente.</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Información de Empresa Activa -->
    <div class="col-12 mb-2">
        <div class="d-card" style="background: var(--c-surface); border-left: 4px solid var(--c-primary);">
            <div class="d-card-body py-3 d-flex align-items-center">
                <div style="flex:1">
                    <h5 class="mb-0 fw-bold"><?= $razonSocial ?></h5>
                    <div style="font-size:.75rem; color:var(--c-text-muted)">Configurando parámetros para RUT: <strong><?= $rutEmisor ?></strong></div>
                </div>
                <?php if (!$globalContext): ?>
                    <span class="d-badge warning"><i class="bi bi-exclamation-triangle"></i> Modo Legacy (Global)</span>
                <?php else: ?>
                    <span class="d-badge prod"><i class="bi bi-building-check"></i> Empresa Individual</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Estado del Sistema -->
    <div class="col-12">
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon <?= $certExists && $certInfo ? ($certInfo['dias'] < 30 ? 'yellow' : 'green') : 'red' ?>">
                    <i class="bi bi-shield-<?= $certExists ? 'check' : 'x' ?>"></i>
                </div>
                <div class="kpi-content">
                    <div class="kpi-value"><?= $certInfo ? $certInfo['dias'] . 'd' : '—' ?></div>
                    <div class="kpi-label">Certificado Digital</div>
                    <div class="kpi-trend <?= $certExists ? 'up' : 'down' ?>"><?= $certInfo ? $certInfo['cn'] : 'No instalado' ?></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="bi bi-file-earmark-code"></i></div>
                <div class="kpi-content">
                    <div class="kpi-value"><?= count($cafsData) ?></div>
                    <div class="kpi-label">Tipos CAF Cargados</div>
                    <div class="kpi-trend up"><?= implode(', ', array_map(fn($c) => 'T'.$c['tipo'], $cafsData)) ?: 'Ninguno' ?></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="bi bi-globe"></i></div>
                <div class="kpi-content">
                    <div class="kpi-value"><?= $ambiente === 'PRODUCCION' ? 'PROD' : 'CERT' ?></div>
                    <div class="kpi-label">Ambiente Activo</div>
                    <div class="kpi-trend <?= $ambiente === 'PRODUCCION' ? 'up' : 'down' ?>"><?= $ambiente === 'PRODUCCION' ? 'palena.sii.cl' : 'maullin.sii.cl' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Certificado PFX -->
    <div class="col-md-6">
        <div class="d-card">
            <div class="d-card-header"><i class="bi bi-key"></i> Certificado Digital (.pfx / .p12)</div>
            <div class="d-card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_cert">
                    <div style="border:2px dashed var(--c-border); border-radius:var(--radius-lg); padding:24px; text-align:center; cursor:pointer; transition:var(--transition); background:#fafbfc" onclick="document.getElementById('cert_file_d').click()" id="drop-cert-d">
                        <?php if ($certExists && $certInfo): ?>
                            <i class="bi bi-shield-check" style="font-size:2rem; color:var(--c-success)"></i>
                            <div style="font-weight:600; margin-top:6px; color:var(--c-success)"><?= htmlspecialchars($certInfo['original']) ?></div>
                            <div style="font-size:.72rem; color:var(--c-text-muted)">Vence: <?= $certInfo['validTo'] ?> (<?= $certInfo['dias'] ?> días)</div>
                        <?php else: ?>
                            <i class="bi bi-upload" style="font-size:2rem; color:var(--c-primary)"></i>
                            <div style="color:var(--c-text-muted); margin-top:6px">Arrastre su archivo .pfx aquí</div>
                        <?php endif; ?>
                    </div>
                    <input type="file" name="cert_file" id="cert_file_d" accept=".pfx,.p12" style="display:none">
                    <div class="mt-3">
                        <label class="d-label">Contraseña del certificado</label>
                        <input type="password" name="cert_pass" class="d-input" value="<?= htmlspecialchars($certConf['pass'] ?? '') ?>" placeholder="Contraseña del .pfx">
                    </div>
                    <button type="submit" class="d-btn d-btn-primary w-100 mt-3">
                        <i class="bi bi-cloud-upload"></i> Instalar Certificado
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- CAF -->
    <div class="col-md-6">
        <div class="d-card">
            <div class="d-card-header"><i class="bi bi-file-earmark-plus"></i> Archivos CAF (Folios SII)</div>
            <div class="d-card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_caf">
                    <div style="border:2px dashed var(--c-border); border-radius:var(--radius-lg); padding:24px; text-align:center; cursor:pointer; background:#fafbfc" onclick="document.getElementById('caf_file_d').click()">
                        <i class="bi bi-file-earmark-code" style="font-size:2rem; color:var(--c-success)"></i>
                        <div style="color:var(--c-text-muted); margin-top:6px">Arrastre su archivo CAF .xml aquí</div>
                        <div style="font-size:.7rem; color:var(--c-text-muted)">El tipo se detecta automáticamente</div>
                    </div>
                    <input type="file" name="caf_file" id="caf_file_d" accept=".xml" style="display:none">
                    <button type="submit" class="d-btn d-btn-success w-100 mt-3">
                        <i class="bi bi-cloud-upload"></i> Instalar CAF
                    </button>
                </form>

                <?php if (!empty($cafsData)): ?>
                <hr style="border-color:var(--c-border); margin:16px 0">
                <div style="font-weight:600; font-size:.78rem; margin-bottom:8px">CAFs instalados:</div>
                <?php foreach ($cafsData as $c): ?>
                <div style="display:flex; align-items:center; padding:10px 12px; border:1px solid var(--c-border); border-radius:var(--radius); margin-bottom:6px; background:#fafbfc">
                    <div style="flex:1">
                        <span class="d-badge info">T<?= $c['tipo'] ?></span>
                        <strong style="margin-left:6px"><?= $c['nombre'] ?></strong><br>
                        <span style="font-size:.72rem; color:var(--c-text-muted)">
                            Folios: <?= number_format($c['desde']) ?> — <?= number_format($c['hasta']) ?> (<?= number_format($c['folios']) ?>) | <?= $c['fecha'] ?>
                        </span>
                    </div>
                    <form method="POST" style="margin-left:8px" onsubmit="return confirm('¿Eliminar CAF T<?= $c['tipo'] ?>?')">
                        <input type="hidden" name="action" value="delete_caf">
                        <input type="hidden" name="tipo" value="<?= $c['tipo'] ?>">
                        <button type="submit" class="d-btn d-btn-sm" style="background:var(--c-danger-light); color:var(--c-danger)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
