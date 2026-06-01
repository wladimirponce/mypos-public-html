<?php
/**
 * Módulo Certificación SII — Pool de Pruebas completo con seguimiento de estado.
 */

$certCases = [
    'B' => [
        'nombre' => 'Boleta Electrónica',
        'casos'  => [
            'B-CASO-1' => 'Caso 1 — Cambio aceite + Alineación',
            'B-CASO-2' => 'Caso 2 — Papel regalo (17 un.)',
            'B-CASO-3' => 'Caso 3 — Sandwic + Bebida',
            'B-CASO-4' => 'Caso 4 — Afecto + Exento mixto',
            'B-CASO-5' => 'Caso 5 — Arroz (Unidad: Kg)',
        ],
    ],
    'F' => [
        'nombre' => 'Facturación',
        'casos'  => [
            'F-4832043-1' => 'Caso 1 — Factura (Cajón + Relleno)',
            'F-4832043-2' => 'Caso 2 — Factura (Descuentos por ítem)',
            'F-4832043-3' => 'Caso 3 — Factura (Afecto + Exento)',
            'F-4832043-4' => 'Caso 4 — Factura (Descuento Global 23%)',
            'F-4832043-5' => 'Caso 5 — NC Corrige Giro',
            'F-4832043-6' => 'Caso 6 — NC Devolución mercadería',
            'F-4832043-7' => 'Caso 7 — NC Anula factura',
            'F-4832043-8' => 'Caso 8 — ND Anula NC',
        ],
    ],
];
?>

<style>
.cert-progress-bar { height:6px; background:#eee; border-radius:3px; overflow:hidden; margin-top:4px; }
.cert-progress-fill { height:100%; background:var(--c-success); border-radius:3px; transition:width .4s; }
.cert-case-row { display:flex; align-items:flex-start; gap:8px; padding:5px 12px;
  border-bottom:1px solid var(--c-border); font-size:.78rem; flex-wrap:wrap; }
.cert-case-row:last-child { border-bottom:none; }
.cert-badge { display:inline-flex; align-items:center; justify-content:center;
  width:72px; font-size:.68rem; padding:2px 6px; border-radius:10px; font-weight:600;
  white-space:nowrap; }
.cb-ok      { background:#d4edda; color:#155724; }
.cb-failed  { background:#f8d7da; color:#721c24; }
.cb-pending { background:#e2e3e5; color:#495057; }
.cb-running { background:#fff3cd; color:#856404; }
.cert-folio { font-size:.7rem; color:var(--c-text-muted); flex:1; min-width:0; }
.cert-error-tip { font-size:.68rem; color:#c0392b; cursor:help;
  border-bottom:1px dashed #c0392b; word-break:break-word; }
.cert-stage-header { font-weight:700; font-size:.82rem; padding:10px 14px;
  background:var(--c-bg-subtle,#f8f9fa); border-bottom:1px solid var(--c-border);
  display:flex; align-items:center; gap:8px; }
.cert-summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
  gap:10px; margin-bottom:18px; }
.cert-summary-card { background:#fff; border:1px solid var(--c-border); border-radius:8px;
  padding:10px 14px; text-align:center; }
.cert-summary-card .num { font-size:22pt; font-weight:800; }
.cert-summary-card .lbl { font-size:.72rem; color:var(--c-text-muted); }
.cert-summary-card.ok    .num { color:#27ae60; }
.cert-summary-card.fail  .num { color:#e74c3c; }
.cert-summary-card.pend  .num { color:#95a5a6; }
.cert-summary-card.run   .num { color:#f39c12; }
</style>

<!-- Barra superior con acciones globales -->
<div class="d-card mb-4">
  <div class="d-card-body" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center">
    <div style="flex:1; min-width:200px">
      <strong>Pool de Certificación SII</strong>
      <div style="font-size:.75rem; color:var(--c-text-muted)">
        Solo disponible en ambiente CERTIFICACIÓN. El estado se guarda automáticamente.
      </div>
    </div>
    <button class="d-btn d-btn-success" onclick="certRunAll()">
      <i class="bi bi-play-fill"></i> Ejecutar Todo
    </button>
    <button class="d-btn d-btn-warning" onclick="certRetry()" id="btn-retry" style="display:none">
      <i class="bi bi-arrow-clockwise"></i> Reintentar Fallidos
    </button>
    <button class="d-btn d-btn-info" onclick="certMuestras()">
      <i class="bi bi-printer"></i> Muestras Impresas (PDF)
    </button>
    <button class="d-btn d-btn-outline" onclick="certReset()" title="Reiniciar todo el estado">
      <i class="bi bi-trash3"></i> Reiniciar
    </button>
    <button class="d-btn d-btn-outline" onclick="loadState()">
      <i class="bi bi-arrow-repeat"></i> Actualizar
    </button>
  </div>
</div>

<!-- Resumen global -->
<div class="cert-summary" id="cert-summary">
  <div class="cert-summary-card pend"><div class="num" id="sum-total">—</div><div class="lbl">Total casos</div></div>
  <div class="cert-summary-card ok">  <div class="num" id="sum-ok">—</div>  <div class="lbl">Exitosos</div></div>
  <div class="cert-summary-card fail"><div class="num" id="sum-fail">—</div><div class="lbl">Fallidos</div></div>
  <div class="cert-summary-card pend"><div class="num" id="sum-pend">—</div><div class="lbl">Pendientes</div></div>
  <div class="cert-summary-card run"> <div class="num" id="sim-total">—</div><div class="lbl">Docs simulación</div></div>
</div>

<!-- Estado general de ejecución -->
<div id="cert-run-status" class="mb-3"></div>

<div class="row g-4">

  <!-- ── ETAPA 1: Set de Pruebas ─────────────────────────────── -->
  <div class="col-lg-8">
    <div class="d-card">
      <div class="cert-stage-header">
        <i class="bi bi-file-earmark-check"></i>
        Etapa 1 — Set de Pruebas Asignado
        <span id="stage1-badge" class="d-badge ms-auto">Cargando...</span>
      </div>
      <div class="d-card-body" style="padding:0">
        <?php foreach ($certCases as $setKey => $set): ?>
        <div class="cert-stage-header" style="background:transparent; font-size:.75rem; font-weight:600; padding:6px 14px; border-top:1px solid var(--c-border)">
          <?= htmlspecialchars($set['nombre']) ?>
          <button class="d-btn d-btn-sm d-btn-outline ms-auto"
            onclick="certRunSet('<?= $setKey ?>')">
            <i class="bi bi-send"></i> Enviar set
          </button>
        </div>
        <?php foreach ($set['casos'] as $caseId => $caseName): ?>
        <div class="cert-case-row" id="row-<?= $caseId ?>">
          <span class="cert-badge cb-pending" id="badge-<?= $caseId ?>">Pendiente</span>
          <span><?= htmlspecialchars($caseName) ?></span>
          <span class="cert-folio" id="folio-<?= $caseId ?>"></span>
          <button class="d-btn d-btn-sm d-btn-outline" onclick="certRunCase('<?= $caseId ?>')" title="Ejecutar solo este caso">
            <i class="bi bi-play"></i>
          </button>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ── Panel derecho ─────────────────────────────────────────── -->
  <div class="col-lg-4">

    <!-- Etapa 2: Simulación -->
    <div class="d-card mb-4">
      <div class="cert-stage-header">
        <i class="bi bi-rocket-takeoff"></i> Etapa 2 — Simulación
        <span id="stage2-badge" class="d-badge ms-auto">Pendiente</span>
      </div>
      <div class="d-card-body" style="padding:12px">
        <div id="sim-detail">
          <?php foreach ([33 => 'Factura (33)', 39 => 'Boleta (39)'] as $t => $n): ?>
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px">
            <span style="font-size:.78rem"><?= $n ?></span>
            <span class="cert-badge cb-pending" id="sim-badge-<?= $t ?>">0/50</span>
          </div>
          <div class="cert-progress-bar">
            <div class="cert-progress-fill" id="sim-bar-<?= $t ?>" style="width:0%"></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="mt-3 d-flex gap-2">
          <button class="d-btn d-btn-sm d-btn-primary flex-fill" onclick="certRunSim(33)">T.33</button>
          <button class="d-btn d-btn-sm d-btn-primary flex-fill" onclick="certRunSim(39)">T.39</button>
        </div>
      </div>
    </div>

    <!-- Etapa 3: Intercambio -->
    <div class="d-card mb-4">
      <div class="cert-stage-header">
        <i class="bi bi-arrow-left-right"></i> Etapa 3 — Intercambio
        <span id="stage3-badge" class="d-badge ms-auto">Pendiente</span>
      </div>
      <div class="d-card-body" style="padding:12px">
        <p style="font-size:.75rem; color:var(--c-text-muted); margin-bottom:10px">
          Pegue o suba el XML que el SII le envió en el ambiente de certificación
          (<em>maullin.sii.cl</em>). El sistema generará y enviará la respuesta automáticamente.
        </p>
        <textarea id="intercambio-xml" class="d-input"
          style="width:100%; height:80px; font-size:.7rem; font-family:monospace"
          placeholder="&lt;EnvioDTE...&gt;"></textarea>
        <div style="display:flex; gap:8px; margin-top:8px">
          <input type="file" id="intercambio-file" accept=".xml" style="display:none"
            onchange="loadIntercambioFile(this)">
          <button class="d-btn d-btn-sm d-btn-outline flex-fill"
            onclick="document.getElementById('intercambio-file').click()">
            <i class="bi bi-upload"></i> Subir XML
          </button>
          <button class="d-btn d-btn-sm d-btn-primary flex-fill" onclick="certIntercambio()">
            <i class="bi bi-send-check"></i> Responder
          </button>
        </div>
        <div id="intercambio-status" class="mt-2"></div>
      </div>
    </div>

    <!-- Etapa 4: Muestras Impresas -->
    <div class="d-card">
      <div class="cert-stage-header">
        <i class="bi bi-file-pdf"></i> Etapa 4 — Muestras Impresas
        <span id="stage4-badge" class="d-badge ms-auto">Disponible</span>
      </div>
      <div class="d-card-body" style="padding:12px; font-size:.78rem">
        <p style="color:var(--c-text-muted); margin-bottom:10px">
          Genera un documento HTML con todos los DTEs del Set de Pruebas + 10 de
          Simulación, con timbre PDF417. Use <strong>Ctrl+P → Guardar como PDF</strong>
          en el navegador para obtener el archivo a enviar al SII.
        </p>
        <button class="d-btn d-btn-info w-100" onclick="certMuestras()">
          <i class="bi bi-printer-fill"></i> Generar e Imprimir
        </button>
      </div>
    </div>

  </div><!-- /col-lg-4 -->
</div><!-- /row -->

<!-- ── LIBROS DE CERTIFICACIÓN ─────────────────────────────────────── -->
<div class="d-card mt-4">
  <div class="cert-stage-header">
    <i class="bi bi-journal-text"></i> Libros de Certificación
    <span style="font-size:.72rem; color:var(--c-text-muted); margin-left:8px">
      Generar y enviar después de completar el Set de Pruebas
    </span>
  </div>
  <div class="d-card-body">
    <div class="row g-3">

      <!-- Libro de Ventas 4832044 -->
      <div class="col-md-6">
        <div class="d-card h-100" style="border-left:3px solid #27ae60">
          <div class="d-card-body" style="padding:12px">
            <div style="font-weight:700; font-size:.82rem; margin-bottom:4px">
              Libro de Ventas
              <span style="font-size:.68rem; color:var(--c-text-muted); font-weight:400">N° At. 4832044</span>
            </div>
            <div style="font-size:.74rem; color:var(--c-text-muted); margin-bottom:10px">
              Construido con los 8 casos del Set Básico (facturas, NC, ND).
              Requiere tener todos los casos F-4832043-* generados.
            </div>
            <div style="display:flex; align-items:center; gap:8px">
              <button class="d-btn d-btn-sm d-btn-success flex-fill" onclick="certLibro('ventas')">
                <i class="bi bi-send"></i> Enviar
              </button>
              <span class="cert-badge cb-pending" id="libro-ventas-badge">Pendiente</span>
            </div>
            <div id="libro-ventas-info" style="font-size:.68rem; color:var(--c-text-muted); margin-top:6px"></div>
          </div>
        </div>
      </div>

      <!-- Libro de Compras 4832045 -->
      <div class="col-md-6">
        <div class="d-card h-100" style="border-left:3px solid #e67e22">
          <div class="d-card-body" style="padding:12px">
            <div style="font-weight:700; font-size:.82rem; margin-bottom:4px">
              Libro de Compras
              <span style="font-size:.68rem; color:var(--c-text-muted); font-weight:400">N° At. 4832045</span>
            </div>
            <div style="font-size:.74rem; color:var(--c-text-muted); margin-bottom:6px">
              Documentos en papel y electrónicos según el set oficial.<br>
              Incluye IVA uso común (fct. prop. 0.60) e IVA no recuperable.
            </div>
            <details style="font-size:.68rem; margin-bottom:8px">
              <summary style="cursor:pointer; color:var(--c-text-muted)">Ver documentos incluidos</summary>
              <table style="width:100%; margin-top:6px; border-collapse:collapse; font-size:.67rem">
                <tr style="background:#f8f8f8"><th style="text-align:left; padding:2px 4px">Doc</th><th>Folio</th><th>Neto</th><th>Obs.</th></tr>
                <tr><td>Factura</td><td>234</td><td>$11.563</td><td>Crédito pleno</td></tr>
                <tr><td>FE</td><td>32</td><td>$5.021</td><td>+Exento $8.299</td></tr>
                <tr><td>Factura</td><td>781</td><td>$29.668</td><td>Uso común 60%</td></tr>
                <tr><td>NC</td><td>451</td><td>-$2.655</td><td>Dto. f.234</td></tr>
                <tr><td>FE</td><td>67</td><td>$9.383</td><td>Entrega gratuita</td></tr>
                <tr><td>FCA-e</td><td>9</td><td>$9.253</td><td>Retención total</td></tr>
                <tr><td>NC</td><td>211</td><td>-$3.068</td><td>Dto. FE 32</td></tr>
              </table>
            </details>
            <div style="display:flex; align-items:center; gap:8px">
              <button class="d-btn d-btn-sm flex-fill" style="background:#e67e22;color:#fff;border:none" onclick="certLibro('compras')">
                <i class="bi bi-send"></i> Enviar
              </button>
              <span class="cert-badge cb-pending" id="libro-compras-badge">Pendiente</span>
            </div>
            <div id="libro-compras-info" style="font-size:.68rem; color:var(--c-text-muted); margin-top:6px"></div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Log de ejecución -->
<div class="d-card mt-4">
  <div class="d-card-header" style="display:flex; justify-content:space-between">
    <span><i class="bi bi-terminal"></i> Log de ejecución</span>
    <button class="d-btn d-btn-sm d-btn-outline" onclick="document.getElementById('cert-log').innerHTML=''">Limpiar</button>
  </div>
  <div id="cert-log" style="padding:10px 14px; font-size:.72rem; font-family:monospace;
    max-height:280px; overflow-y:auto; background:var(--c-bg-subtle,#f8f9fa)">
    <span style="color:#888">— Log de certificación —</span>
  </div>
</div>

<script>
// ── Estado en UI ──────────────────────────────────────────────────────────────
const ALL_CASES = <?= json_encode(array_keys(array_merge(
    $certCases['B']['casos'],
    $certCases['F']['casos']
))) ?>;

function log(msg, type='info') {
  const el = document.getElementById('cert-log');
  const ts = new Date().toLocaleTimeString();
  const colors = { ok:'#27ae60', error:'#e74c3c', info:'#3498db', warn:'#f39c12' };
  el.innerHTML += `<div style="color:${colors[type]||'#aaa'}">[${ts}] ${msg}</div>`;
  el.scrollTop = el.scrollHeight;
}

function applyState(estado) {
  if (!estado) return;
  const pruebas = estado.pruebas || {};
  let ok=0, fail=0, pend=0;

  ALL_CASES.forEach(id => {
    const c = pruebas[id];
    const badge = document.getElementById('badge-'+id);
    const folioEl = document.getElementById('folio-'+id);
    if (!badge) return;

    if (!c || c.status === 'pending') {
      badge.className = 'cert-badge cb-pending'; badge.textContent = 'Pendiente'; pend++;
    } else if (c.status === 'ok') {
      badge.className = 'cert-badge cb-ok'; badge.textContent = '✓ OK'; ok++;
      if (folioEl) folioEl.textContent = 'Folio ' + c.folio + (c.trackId ? ' · TRK '+String(c.trackId).substring(0,8) : '');
    } else if (c.status === 'failed') {
      badge.className = 'cert-badge cb-failed'; badge.textContent = '✗ Error'; fail++;
      if (folioEl) {
        const errTxt = (c.error || 'Error desconocido').substring(0, 120);
        folioEl.innerHTML = `<span class="cert-error-tip" title="${(c.error||'').replace(/"/g,'&quot;')}">${errTxt}</span>`;
      }
    } else if (c.status === 'running') {
      badge.className = 'cert-badge cb-running'; badge.textContent = '⟳ Env...';
    }
  });

  document.getElementById('sum-total').textContent = ALL_CASES.length;
  document.getElementById('sum-ok').textContent   = ok;
  document.getElementById('sum-fail').textContent  = fail;
  document.getElementById('sum-pend').textContent  = pend;

  // Botón retry
  document.getElementById('btn-retry').style.display = fail > 0 ? '' : 'none';

  // Simulación
  const sim = estado.simulacion || {};
  let simTot = 0;
  [33,39].forEach(t => {
    const s = sim['t'+t];
    const cnt = s ? s.folios_ok.length : 0;
    simTot += cnt;
    const pct = Math.round(cnt/50*100);
    const bar  = document.getElementById('sim-bar-'+t);
    const sbadge = document.getElementById('sim-badge-'+t);
    if (bar) bar.style.width = pct+'%';
    if (sbadge) {
      sbadge.textContent = cnt+'/50';
      sbadge.className = 'cert-badge ' + (s?.status==='ok'?'cb-ok': s?.status==='partial'?'cb-running':'cb-pending');
    }
  });
  document.getElementById('sim-total').textContent = simTot;

  // Stage badges
  const s1 = ok===ALL_CASES.length ? 'success' : fail>0 ? 'danger' : ok>0 ? 'warning' : 'secondary';
  const b1 = ok===ALL_CASES.length ? '✓ Completo' : fail>0 ? `${fail} fallidos` : ok>0 ? `${ok}/${ALL_CASES.length}` : 'Pendiente';
  setBadge('stage1-badge', b1, s1);

  const s2 = simTot>=100 ? 'success' : simTot>0 ? 'warning' : 'secondary';
  setBadge('stage2-badge', simTot>=100 ? '✓ Completo' : simTot+'/100', s2);

  // Libros
  const libros = estado.libros || {};
  ['ventas','compras'].forEach(t => {
    const lb = libros[t];
    const badge = document.getElementById(`libro-${t}-badge`);
    const info  = document.getElementById(`libro-${t}-info`);
    if (!badge) return;
    if (!lb)                    { badge.className='cert-badge cb-pending'; badge.textContent='Pendiente'; return; }
    if (lb.status === 'ok')     { badge.className='cert-badge cb-ok'; badge.textContent='✓ OK'; if(info) info.textContent='TrackID: '+(lb.trackId||'—'); }
    else if (lb.status==='failed'){ badge.className='cert-badge cb-failed'; badge.textContent='✗ Error'; if(info) info.textContent=lb.error||''; }
  });

  const ic = estado.intercambio || {};
  setBadge('stage3-badge',
    ic.status==='responded' ? '✓ Respondido' : ic.status==='failed' ? 'Error' : 'Pendiente',
    ic.status==='responded' ? 'success' : ic.status==='failed' ? 'danger' : 'secondary'
  );
}

function setBadge(id, text, type) {
  const el = document.getElementById(id);
  if (!el) return;
  const map = { success:'success', danger:'danger', warning:'warning', secondary:'' };
  el.className = 'd-badge ' + (map[type]||'');
  el.textContent = text;
}

// ── API calls ─────────────────────────────────────────────────────────────────
async function api(action, extra={}) {
  const params = new URLSearchParams({ action, ...extra });
  const r = await fetch(`cert_bridge.php?${params}`);
  return r.json();
}

async function loadState() {
  try {
    const res = await api('cert_state');
    if (res.ok) {
      applyState(res.estado);
    } else {
      document.getElementById('cert-run-status').innerHTML =
        `<div class="d-alert danger"><i class="bi bi-x-circle"></i> Error al cargar estado: ${res.error||'respuesta inválida del servidor'}</div>`;
    }
  } catch(e) {
    document.getElementById('cert-run-status').innerHTML =
      `<div class="d-alert danger"><i class="bi bi-x-circle"></i> Error de comunicación con cert_bridge.php: ${e.message}</div>`;
  }
}

async function certRunAll() {
  const status = document.getElementById('cert-run-status');
  status.innerHTML = '<div class="d-alert info"><span class="spinner-border spinner-border-sm me-2"></span> Ejecutando pool completo... puede tardar varios minutos.</div>';
  log('Iniciando ejecución completa del pool...', 'info');
  try {
    const res = await api('cert_run_all');
    applyState(res.estado);
    const r = res.resultados || {};
    Object.entries(r).forEach(([k,v]) => {
      if (typeof v === 'object') {
        Object.entries(v).forEach(([cid, cr]) => {
          log(`${cid}: ${cr.status||'?'} — folio ${cr.folio||'-'} ${cr.error?'| '+cr.error:''}`,
            cr.status==='ok'?'ok':'error');
        });
      }
    });
    status.innerHTML = '<div class="d-alert success"><i class="bi bi-check-circle"></i> Pool ejecutado. Revise los resultados arriba.</div>';
  } catch(e) {
    status.innerHTML = `<div class="d-alert danger">${e.message}</div>`;
    log('Error: '+e.message, 'error');
  }
}

async function certRunSet(setKey) {
  const caseMaps = <?= json_encode($certCases) ?>;
  const caseIds = Object.keys(caseMaps[setKey]?.casos || {});
  log(`Ejecutando set ${setKey} (${caseIds.length} casos)...`, 'info');
  for (const cid of caseIds) {
    const badge = document.getElementById('badge-'+cid);
    if (badge?.textContent === '✓ OK') { log(`${cid}: ya OK, omitido.`, 'ok'); continue; }
    if (badge) { badge.className='cert-badge cb-running'; badge.textContent='⟳ Env...'; }
    try {
      const res = await api('cert_case', { cid });
      const envio = res.envio || {};
      const st = envio.ok ? 'ok' : 'failed';
      if (badge) {
        badge.className = 'cert-badge ' + (st==='ok'?'cb-ok':'cb-failed');
        badge.textContent = st==='ok' ? '✓ OK' : '✗ Error';
      }
      log(`${cid}: ${st} — folio ${res.folio} ${envio.error?'| '+envio.error:''}`, st==='ok'?'ok':'error');
    } catch(e) {
      if (badge) { badge.className='cert-badge cb-failed'; badge.textContent='✗ Error'; }
      log(`${cid}: error — ${e.message}`, 'error');
    }
    await loadState();
  }
}

async function certRunCase(cid) {
  const badge = document.getElementById('badge-'+cid);
  if (badge) { badge.className='cert-badge cb-running'; badge.textContent='⟳...'; }
  log(`Ejecutando ${cid}...`, 'info');
  try {
    const res = await api('cert_case', { cid });
    const envio = res.envio || {};
    const st = envio.ok ? 'ok' : 'failed';
    log(`${cid}: ${st} — folio ${res.folio||'-'} trackId: ${envio.trackId||'-'} ${envio.error||''}`, st==='ok'?'ok':'error');
  } catch(e) {
    log(`${cid}: error — ${e.message}`, 'error');
  }
  await loadState();
}

async function certRunSim(tipo) {
  log(`Iniciando simulación tipo ${tipo} (50 docs)...`, 'info');
  const b = document.getElementById('sim-badge-'+tipo);
  if (b) { b.className='cert-badge cb-running'; b.textContent='...'; }
  try {
    const res = await api('cert_run_sim', { tipo, cantidad:50 });
    log(`Simulación T${tipo}: enviados=${res.enviados||0} fallidos=${res.fallidos||0}`, res.ok?'ok':'warn');
  } catch(e) { log('Error simulación: '+e.message, 'error'); }
  await loadState();
}

async function certRetry() {
  log('Reintentando todos los casos fallidos...', 'warn');
  document.getElementById('cert-run-status').innerHTML =
    '<div class="d-alert warning"><span class="spinner-border spinner-border-sm me-2"></span> Reintentando fallidos...</div>';
  try {
    const res = await api('cert_retry');
    applyState(res.estado);
    document.getElementById('cert-run-status').innerHTML =
      '<div class="d-alert success">Reintento completado.</div>';
    log('Reintento finalizado.', 'ok');
  } catch(e) { log('Error retry: '+e.message, 'error'); }
}

async function certReset() {
  if (!confirm('¿Reiniciar TODO el estado de certificación? Esto borra el progreso guardado.')) return;
  await api('cert_reset');
  log('Estado reiniciado.', 'warn');
  await loadState();
}

function certMuestras() {
  window.open('cert_bridge.php?action=cert_muestras', '_blank');
  document.getElementById('stage4-badge').textContent = '✓ Generado';
}

// ── Intercambio ───────────────────────────────────────────────────────────────
function loadIntercambioFile(input) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => { document.getElementById('intercambio-xml').value = e.target.result; };
  reader.readAsText(file, 'ISO-8859-1');
}

async function certIntercambio() {
  const xml = document.getElementById('intercambio-xml').value.trim();
  const status = document.getElementById('intercambio-status');
  if (!xml) { status.innerHTML = '<div class="d-alert danger">Ingrese el XML de intercambio.</div>'; return; }
  status.innerHTML = '<div class="d-alert info"><span class="spinner-border spinner-border-sm me-2"></span> Procesando...</div>';
  log('Respondiendo intercambio...', 'info');
  try {
    const fd = new FormData();
    fd.append('action', 'cert_intercambio');
    fd.append('xml', xml);
    const r = await fetch('cert_bridge.php', { method:'POST', body:fd });
    const res = await r.json();
    if (res.ok) {
      status.innerHTML = `<div class="d-alert success"><i class="bi bi-check-circle"></i> Respuesta enviada. TrackID: ${res.trackId||'—'}</div>`;
      log(`Intercambio respondido OK. TrackID: ${res.trackId||'-'}`, 'ok');
    } else {
      status.innerHTML = `<div class="d-alert danger">${res.error||'Error'}</div>`;
      log('Error intercambio: '+(res.error||'?'), 'error');
    }
    await loadState();
  } catch(e) {
    status.innerHTML = `<div class="d-alert danger">${e.message}</div>`;
    log('Error: '+e.message, 'error');
  }
}

// ── Libros ───────────────────────────────────────────────────────────────────
async function certLibro(tipo) {
  const actionMap = { ventas:'cert_libro_ventas', guias:'cert_libro_guias', compras:'cert_libro_compras' };
  const action = actionMap[tipo];
  const badge  = document.getElementById(`libro-${tipo}-badge`);
  const info   = document.getElementById(`libro-${tipo}-info`);

  badge.className = 'cert-badge cb-running'; badge.textContent = '⟳...';
  log(`Enviando Libro de ${tipo.charAt(0).toUpperCase()+tipo.slice(1)}...`, 'info');

  try {
    const res = await api(action);
    if (res.ok) {
      badge.className = 'cert-badge cb-ok'; badge.textContent = '✓ OK';
      info.textContent = 'TrackID: ' + (res.trackId || '—');
      log(`Libro ${tipo} enviado OK. TrackID: ${res.trackId||'-'}`, 'ok');
    } else {
      badge.className = 'cert-badge cb-failed'; badge.textContent = '✗ Error';
      info.textContent = res.error || 'Error desconocido';
      log(`Libro ${tipo} falló: ${res.error||'?'}`, 'error');
    }
    await loadState();
  } catch(e) {
    badge.className = 'cert-badge cb-failed'; badge.textContent = '✗ Error';
    log(`Error libro ${tipo}: ${e.message}`, 'error');
  }
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadState();
  log('Módulo de certificación cargado.', 'info');
});
</script>
