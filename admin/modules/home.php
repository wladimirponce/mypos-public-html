<?php
/**
 * Módulo Home — Dashboard KPIs
 */

// ── Leer estado de CAFs ──
$cafStatus = [];
$tiposNombre = [
    33 => 'Factura', 34 => 'Factura Exenta', 39 => 'Boleta',
    41 => 'Boleta Exenta', 52 => 'Guía Despacho', 56 => 'Nota Débito', 61 => 'Nota Crédito',
];

foreach ($cafFiles as $f) {
    preg_match('/caf_(\d+)\.xml$/', $f, $m);
    $tipo = (int)($m[1] ?? 0);
    $xml = @simplexml_load_file($f);
    if (!$xml) continue;
    $desde = (int)$xml->CAF->DA->RNG->D;
    $hasta = (int)$xml->CAF->DA->RNG->H;
    $total = $hasta - $desde + 1;

    // Leer último folio usado
    $regFile = $globalContext ? ($actualCafDir . 'registry.json') : '';
    $last = 0;
    if ($regFile !== '' && file_exists($regFile)) {
        $reg = json_decode(file_get_contents($regFile), true);
        $last = (int)($reg[$tipo] ?? 0);
    }
    $used = $last > 0 ? max(0, $last - $desde + 1) : 0;
    $available = $total - $used;
    $pct = $total > 0 ? round(($used / $total) * 100) : 0;

    $cafStatus[] = [
        'tipo' => $tipo,
        'nombre' => $tiposNombre[$tipo] ?? "Tipo $tipo",
        'desde' => $desde,
        'hasta' => $hasta,
        'total' => $total,
        'usado' => $used,
        'disponible' => $available,
        'pct' => $pct,
        'alerta' => $available <= 50 ? 'danger' : ($available <= 200 ? 'warning' : 'success'),
    ];
}

// ── Certificado info ──
$certOk = false;
$certDias = 0;
$certNombre = '—';
if ($globalContext && file_exists($actualCertPfx)) {
    $confFile = dirname($actualCertPfx) . '/cert.conf';
    if (file_exists($confFile)) {
        $conf = json_decode(file_get_contents($confFile), true) ?? [];
        if (!empty($conf['pass'])) {
            $certs = [];
            if (openssl_pkcs12_read(file_get_contents($actualCertPfx), $certs, $conf['pass'])) {
                $parsed = openssl_x509_parse($certs['cert']);
                $certOk = true;
                $certDias = max(0, intdiv(($parsed['validTo_time_t'] ?? 0) - time(), 86400));
                $certNombre = $parsed['subject']['CN'] ?? $parsed['subject']['O'] ?? 'Desconocido';
            }
        }
    }
}

// ── Documentos recientes ──
$recentDocs = [];
$histFile = $globalContext ? ($actualTmpDir . 'history.json') : '';
if ($histFile !== '' && file_exists($histFile)) {
    $history = json_decode(file_get_contents($histFile), true) ?? [];
    $recentDocs = array_slice($history, 0, 8);
}

// ── Métricas SaaS (Super Admin) ──
$mrrTotal = 0;
if ($dbOk) {
    try {
        $mrrTotal = (float)$db->query("SELECT SUM(cuota_mensual) FROM saas_suscripcion WHERE estado_pago != 'suspendido'")->fetchColumn();
    } catch (\Exception $e) {}
}
?>

<!-- ══ KPI Cards ══ -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-icon blue"><i class="bi bi-file-earmark-text"></i></div>
        <div class="kpi-content">
            <div class="kpi-value"><?= $tmpCount ?></div>
            <div class="kpi-label">Documentos Generados</div>
            <div class="kpi-trend up"><i class="bi bi-folder2"></i> En carpeta tmp/</div>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon green"><i class="bi bi-stack"></i></div>
        <div class="kpi-content">
            <div class="kpi-value"><?= $cafCount ?></div>
            <div class="kpi-label">Tipos de CAF Cargados</div>
            <div class="kpi-trend up"><i class="bi bi-check-circle"></i> Activos</div>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon <?= $certOk ? ($certDias < 30 ? 'yellow' : 'green') : 'red' ?>">
            <i class="bi bi-<?= $certOk ? 'shield-check' : 'shield-x' ?>"></i>
        </div>
        <div class="kpi-content">
            <div class="kpi-value"><?= $certOk ? $certDias . 'd' : 'N/A' ?></div>
            <div class="kpi-label">Certificado Digital</div>
            <div class="kpi-trend <?= $certOk && $certDias >= 30 ? 'up' : 'down' ?>">
                <?= $certOk ? $certNombre : 'Sin certificado' ?>
            </div>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon <?= $dbOk ? 'green' : 'red' ?>">
            <i class="bi bi-currency-dollar"></i>
        </div>
        <div class="kpi-content">
            <div class="kpi-value">$<?= number_format($mrrTotal, 0, ',', '.') ?></div>
            <div class="kpi-label">MRR (Ingreso Mensual)</div>
            <div class="kpi-trend up">
                <?= count($empresas) ?> Empresas activas
            </div>
        </div>
    </div>
</div>

<!-- ══ Folios & Historial ══ -->
<div class="row g-4">
    <!-- Estado de Folios -->
    <div class="col-lg-7">
        <div class="d-card">
            <div class="d-card-header">
                <i class="bi bi-bar-chart-line"></i> Estado de Folios por Tipo
            </div>
            <div class="d-card-body" style="padding:0">
                <?php if (empty($cafStatus)): ?>
                    <div style="padding:40px; text-align:center; color:var(--c-text-muted)">
                        <i class="bi bi-inbox" style="font-size:2rem"></i>
                        <p class="mt-2">No hay CAFs cargados. <a href="dashboard.php?module=config">Configurar →</a></p>
                    </div>
                <?php else: ?>
                    <table class="d-table">
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Rango</th>
                                <th>Uso</th>
                                <th>Disponibles</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cafStatus as $c): ?>
                            <tr>
                                <td>
                                    <strong>T<?= $c['tipo'] ?></strong>
                                    <span style="color:var(--c-text-muted); margin-left:4px"><?= $c['nombre'] ?></span>
                                </td>
                                <td style="font-size:.75rem; color:var(--c-text-secondary)">
                                    <?= number_format($c['desde']) ?> — <?= number_format($c['hasta']) ?>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:8px">
                                        <div style="flex:1; height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden; min-width:60px">
                                            <div style="width:<?= $c['pct'] ?>%; height:100%; background:<?= $c['alerta'] === 'danger' ? 'var(--c-danger)' : ($c['alerta'] === 'warning' ? 'var(--c-warning)' : 'var(--c-success)') ?>; border-radius:3px; transition:width .6s"></div>
                                        </div>
                                        <span style="font-size:.7rem; color:var(--c-text-muted)"><?= $c['pct'] ?>%</span>
                                    </div>
                                </td>
                                <td><strong><?= number_format($c['disponible']) ?></strong></td>
                                <td>
                                    <span class="d-badge <?= $c['alerta'] === 'danger' ? 'danger' : ($c['alerta'] === 'warning' ? 'cert' : 'prod') ?>">
                                        <?= $c['alerta'] === 'danger' ? '⚠ Crítico' : ($c['alerta'] === 'warning' ? '⚡ Bajo' : '✓ OK') ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Documentos Recientes -->
    <div class="col-lg-5">
        <div class="d-card">
            <div class="d-card-header">
                <i class="bi bi-clock-history"></i> Últimos Documentos
                <a href="dashboard.php?module=historial" style="margin-left:auto; font-size:.75rem; color:var(--c-primary); text-decoration:none">Ver todos →</a>
            </div>
            <div class="d-card-body" style="padding:0">
                <?php if (empty($recentDocs)): ?>
                    <div style="padding:40px; text-align:center; color:var(--c-text-muted)">
                        <i class="bi bi-inbox" style="font-size:2rem"></i>
                        <p class="mt-2">Sin documentos recientes</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recentDocs as $doc): ?>
                    <div style="display:flex; align-items:center; padding:10px 16px; border-bottom:1px solid #f1f5f9; gap:12px">
                        <div style="width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700; background:var(--c-primary-light); color:var(--c-primary)">
                            T<?= $doc['tipo'] ?? '?' ?>
                        </div>
                        <div style="flex:1; min-width:0">
                            <div style="font-weight:600; font-size:.8rem"><?= htmlspecialchars($doc['receptor'] ?? '—') ?></div>
                            <div style="font-size:.7rem; color:var(--c-text-muted)">Folio <?= $doc['folio'] ?? '—' ?> · <?= $doc['fecha'] ?? '—' ?></div>
                        </div>
                        <div style="font-weight:700; font-size:.82rem">
                            $<?= number_format($doc['total'] ?? 0, 0, ',', '.') ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══ Widget Estado SII ══ -->
<div class="row g-4 mt-2">
    <!-- Healthcheck SII -->
    <div class="col-lg-6">
        <div class="d-card">
            <div class="d-card-header" style="display:flex; align-items:center">
                <i class="bi bi-activity"></i>&nbsp;Conectividad SII
                <span id="sii-health-badge" class="d-badge info" style="margin-left:10px">…</span>
                <button class="d-btn d-btn-sm d-btn-outline" style="margin-left:auto" onclick="reloadSIIWidgets()">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
            <div class="d-card-body" style="padding:0">
                <table class="d-table" id="sii-health-table">
                    <thead>
                        <tr>
                            <th style="width:55%">Endpoint</th>
                            <th style="text-align:center">HTTP</th>
                            <th style="text-align:right">Latencia</th>
                            <th style="text-align:center; width:80px">Estado</th>
                        </tr>
                    </thead>
                    <tbody id="sii-health-body">
                        <tr><td colspan="4" style="text-align:center; padding:18px; color:var(--c-text-muted)">
                            <span class="spinner-border spinner-border-sm"></span> Probando endpoints…
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Último RCOF + CAFs Faltantes -->
    <div class="col-lg-6">
        <div class="d-card mb-3">
            <div class="d-card-header"><i class="bi bi-journal-check"></i>&nbsp;Último RCOF Enviado</div>
            <div class="d-card-body" id="sii-rcof-body" style="padding:14px 18px">
                <div style="text-align:center; color:var(--c-text-muted)">
                    <span class="spinner-border spinner-border-sm"></span> Cargando…
                </div>
            </div>
        </div>

        <div class="d-card">
            <div class="d-card-header"><i class="bi bi-exclamation-triangle"></i>&nbsp;Alertas de CAF</div>
            <div class="d-card-body" id="sii-cafalert-body" style="padding:10px 18px">
                <div style="text-align:center; color:var(--c-text-muted); padding:8px">
                    <span class="spinner-border spinner-border-sm"></span> Cargando…
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Últimas Transacciones SII -->
<div class="row g-3 mt-2">
    <div class="col-12">
        <div class="d-card">
            <div class="d-card-header">
                <i class="bi bi-terminal"></i>&nbsp;Últimas transacciones con SII
                <a href="dashboard.php?module=historial" style="margin-left:auto; font-size:.75rem; color:var(--c-primary); text-decoration:none">Ver historial completo →</a>
            </div>
            <div class="d-card-body" style="padding:0">
                <table class="d-table" id="sii-tx-table">
                    <thead>
                        <tr>
                            <th>Cuándo</th>
                            <th>Operación</th>
                            <th>Detalle</th>
                            <th style="text-align:center">Vía</th>
                            <th style="text-align:center">HTTP</th>
                            <th style="text-align:right">ms</th>
                            <th style="text-align:center">Resultado</th>
                        </tr>
                    </thead>
                    <tbody id="sii-tx-body">
                        <tr><td colspan="7" style="text-align:center; padding:18px; color:var(--c-text-muted)">
                            <span class="spinner-border spinner-border-sm"></span> Cargando transacciones…
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function fmtTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return iso;
    const now = new Date();
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60)    return diff + 's atrás';
    if (diff < 3600)  return Math.floor(diff/60) + 'm atrás';
    if (diff < 86400) return Math.floor(diff/3600) + 'h atrás';
    return d.toLocaleString('es-CL', {dateStyle:'short', timeStyle:'short'});
}
function esc(s) { return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

async function loadSIIHealth() {
    const body  = document.getElementById('sii-health-body');
    const badge = document.getElementById('sii-health-badge');
    try {
        const r = await fetch('api.php?action=health_sii');
        const d = await r.json();
        badge.className = 'd-badge ' + (d.ok ? 'prod' : 'danger');
        badge.innerHTML = (d.ok ? '<i class="bi bi-check-circle"></i> Operativo' : '<i class="bi bi-x-circle"></i> Degradado') + ' · ' + esc(d.ambiente);

        body.innerHTML = d.checks.map(c => `
            <tr>
                <td>
                    <strong style="font-size:.78rem">${esc(c.name)}</strong>
                    <div style="font-size:.65rem; color:var(--c-text-muted); font-family:monospace; word-break:break-all">${esc(c.url)}</div>
                </td>
                <td style="text-align:center; font-family:monospace">${c.http || '—'}</td>
                <td style="text-align:right; color:var(--c-text-muted); font-size:.78rem">${c.ms}ms</td>
                <td style="text-align:center">
                    <span class="d-badge ${c.ok ? 'prod' : 'danger'}">${c.ok ? 'OK' : 'FAIL'}</span>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        body.innerHTML = `<tr><td colspan="4" style="padding:14px; color:var(--c-danger)">Error: ${esc(e.message)}</td></tr>`;
        badge.className = 'd-badge danger';
        badge.textContent = 'Error';
    }
}

async function loadSIIRcof() {
    const el = document.getElementById('sii-rcof-body');
    try {
        const r = await fetch('api.php?action=rcof_log');
        const d = await r.json();
        const log = d.log || {};
        const fechas = Object.keys(log).sort().reverse();
        if (!fechas.length) {
            el.innerHTML = `<div style="text-align:center; color:var(--c-text-muted)">Aún no se ha enviado ningún RCOF.</div>`;
            return;
        }
        const last = log[fechas[0]];
        const numEntries = fechas.length;
        const okIcon = last.ok ? '<i class="bi bi-check-circle" style="color:var(--c-success)"></i>' : '<i class="bi bi-x-circle" style="color:var(--c-danger)"></i>';
        const folios = (last.resumen || []).map(r => `T${r.tipo}:${r.emitidos}`).join(', ') || '—';
        el.innerHTML = `
            <div style="display:flex; align-items:center; gap:12px">
                <div style="font-size:1.8rem">${okIcon}</div>
                <div style="flex:1">
                    <div style="font-weight:700; font-size:.95rem">RCOF ${esc(last.fecha)} <small style="font-weight:400; color:var(--c-text-muted)">seq ${last.secuencia}</small></div>
                    <div style="font-size:.75rem; color:var(--c-text-secondary)">TrackID: <strong>${esc(last.trackId || '—')}</strong> · vía ${esc(last.via || '—')}</div>
                    <div style="font-size:.7rem; color:var(--c-text-muted); margin-top:2px">${esc(folios)} · enviado ${fmtTime(last.enviado_ts)}</div>
                </div>
                <div style="text-align:right">
                    <div style="font-size:.65rem; color:var(--c-text-muted); text-transform:uppercase">Total RCOFs</div>
                    <div style="font-weight:800; font-size:1.3rem">${numEntries}</div>
                </div>
            </div>
        `;
    } catch (e) {
        el.innerHTML = `<div style="color:var(--c-danger); font-size:.8rem">Error: ${esc(e.message)}</div>`;
    }
}

async function loadSIICafAlerts() {
    const el = document.getElementById('sii-cafalert-body');
    try {
        const r = await fetch('api.php?action=caf_status');
        const d = await r.json();
        const alertas = [];

        for (const c of (d.cafs || [])) {
            if (c.estado === 'AGOTADO' || c.estado === 'CRITICO' || c.estado === 'BAJO') {
                const color = c.estado === 'AGOTADO' || c.estado === 'CRITICO' ? 'danger' : 'cert';
                alertas.push(`<div style="padding:6px 0; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:8px">
                    <span class="d-badge ${color}">${esc(c.estado)}</span>
                    <strong style="font-size:.82rem">T${c.tipo} ${esc(c.nombre)}</strong>
                    <span style="margin-left:auto; font-size:.72rem; color:var(--c-text-muted)">${c.restantes} folios restantes</span>
                </div>`);
            }
        }
        for (const f of (d.faltantes || [])) {
            alertas.push(`<div style="padding:6px 0; border-bottom:1px solid #f1f5f9; display:flex; align-items:flex-start; gap:8px">
                <span class="d-badge danger">SIN CAF</span>
                <div style="flex:1; font-size:.75rem">
                    <strong>T${f.tipo} ${esc(f.nombre)}</strong>
                    <div style="color:var(--c-text-muted); font-size:.7rem; margin-top:2px">${esc(f.mensaje)}</div>
                </div>
            </div>`);
        }

        if (!alertas.length) {
            el.innerHTML = `<div style="display:flex; align-items:center; gap:8px; color:var(--c-success); font-size:.85rem">
                <i class="bi bi-check-circle"></i> Sin alertas — todos los CAFs cargados están en buen estado.
            </div>`;
        } else {
            el.innerHTML = alertas.join('');
        }
    } catch (e) {
        el.innerHTML = `<div style="color:var(--c-danger); font-size:.8rem">Error: ${esc(e.message)}</div>`;
    }
}

async function loadSIITransactions() {
    const body = document.getElementById('sii-tx-body');
    try {
        const r = await fetch('api.php?action=sii_transactions&limit=10');
        const d = await r.json();
        const tx = d.transactions || [];
        if (!tx.length) {
            body.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:14px; color:var(--c-text-muted)">Sin transacciones registradas aún.</td></tr>`;
            return;
        }
        body.innerHTML = tx.map(t => {
            const okClass = t.result === 'OK' ? 'prod' : (t.result === 'FAIL' ? 'danger' : (t.result === 'EXCEPTION' ? 'danger' : 'cert'));
            const detalle = t.trackId
                ? `TrackID <strong>${esc(t.trackId)}</strong>`
                : (t.fecha ? `Fecha ${esc(t.fecha)} seq${esc(t.secuencia ?? '')}` : (t.folio ? `T${esc(t.tipo)}F${esc(t.folio)}` : '—'));
            return `<tr>
                <td style="font-size:.72rem; white-space:nowrap">${fmtTime(t.ts)}</td>
                <td><strong style="font-size:.75rem">${esc(t.op)}</strong></td>
                <td style="font-size:.75rem">${detalle}</td>
                <td style="text-align:center"><span class="d-badge info">${esc(t.via || '—')}</span></td>
                <td style="text-align:center; font-family:monospace">${t.http ?? '—'}</td>
                <td style="text-align:right; font-size:.72rem; color:var(--c-text-muted)">${t.ms ?? '—'}</td>
                <td style="text-align:center"><span class="d-badge ${okClass}">${esc(t.result || '—')}</span></td>
            </tr>`;
        }).join('');
    } catch (e) {
        body.innerHTML = `<tr><td colspan="7" style="padding:14px; color:var(--c-danger)">Error: ${esc(e.message)}</td></tr>`;
    }
}

function reloadSIIWidgets() {
    loadSIIHealth();
    loadSIIRcof();
    loadSIICafAlerts();
    loadSIITransactions();
}

document.addEventListener('DOMContentLoaded', reloadSIIWidgets);
</script>

<!-- ══ Acciones Rápidas ══ -->
<div class="row g-3 mt-2">
    <div class="col-md-3">
        <a href="dashboard.php?module=emision" class="d-card" style="text-decoration:none; display:flex; align-items:center; padding:16px 20px; gap:14px; cursor:pointer">
            <div class="kpi-icon blue"><i class="bi bi-plus-circle"></i></div>
            <div>
                <div style="font-weight:700; font-size:.85rem; color:var(--c-text)">Nuevo DTE</div>
                <div style="font-size:.72rem; color:var(--c-text-secondary)">Emitir documento</div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="dashboard.php?module=consultas" class="d-card" style="text-decoration:none; display:flex; align-items:center; padding:16px 20px; gap:14px; cursor:pointer">
            <div class="kpi-icon green"><i class="bi bi-search"></i></div>
            <div>
                <div style="font-weight:700; font-size:.85rem; color:var(--c-text)">Consultar Estado</div>
                <div style="font-size:.72rem; color:var(--c-text-secondary)">Verificar en SII</div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="dashboard.php?module=libros" class="d-card" style="text-decoration:none; display:flex; align-items:center; padding:16px 20px; gap:14px; cursor:pointer">
            <div class="kpi-icon yellow"><i class="bi bi-journal-code"></i></div>
            <div>
                <div style="font-weight:700; font-size:.85rem; color:var(--c-text)">Generar Libro</div>
                <div style="font-size:.72rem; color:var(--c-text-secondary)">RCOF / IECV</div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="dashboard.php?module=config" class="d-card" style="text-decoration:none; display:flex; align-items:center; padding:16px 20px; gap:14px; cursor:pointer">
            <div class="kpi-icon red"><i class="bi bi-gear"></i></div>
            <div>
                <div style="font-weight:700; font-size:.85rem; color:var(--c-text)">Configuración</div>
                <div style="font-size:.72rem; color:var(--c-text-secondary)">Cert. y CAF</div>
            </div>
        </a>
    </div>
</div>
