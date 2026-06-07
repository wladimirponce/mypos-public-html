<?php
/**
 * Módulo Certificación SII — flujo secuencial Paso 1 → 7
 */

// Carga dinámica de casos desde el set vinculado (si está disponible)
$setCases = ['boletas' => [], 'generales' => []];
if (isset($globalContext)) {
    try {
        $setMgrPhp = new \App\Services\CertSetManager($globalContext);
        $setData   = $setMgrPhp->load();
        if ($setData) {
            foreach ($setData['boletas'] ?? [] as $b) {
                $cid = 'B-' . $b['caso'];
                $items = implode(', ', array_column($b['items'] ?? [], 'nombre'));
                $setCases['boletas'][$cid] = $b['caso'] . ($items ? ' — ' . mb_substr($items, 0, 50) : '');
            }
            foreach ($setData['facturas'] ?? [] as $f) {
                $t   = (int)($f['tipoDTE'] ?? 33);
                $pfx = match($t) { 52=>'G-', 43=>'L-', 46=>'C-', default=>'F-' }; // F- para T33/T61/T56 (igual que CertificationManager)
                $lbl = match($t) { 33=>'Factura T33', 61=>'NC T61', 56=>'ND T56', 52=>'Guía T52', 46=>'FCA T46', default=>'DTE T'.$t };
                $cid = $pfx . $f['caso'];
                $setCases['generales'][$cid] = $f['caso'] . ' — ' . $lbl;
            }
        }
    } catch (\Throwable $e) { /* sin set cargado aún */ }
}

// Fallback estático si no hay set cargado
if (empty($setCases['boletas'])) {
    $setCases['boletas'] = [
        'B-CASO-1' => 'CASO-1 — Cambio aceite + Alineación',
        'B-CASO-2' => 'CASO-2 — Papel regalo',
        'B-CASO-3' => 'CASO-3 — Sandwic + Bebida',
        'B-CASO-4' => 'CASO-4 — Afecto + Exento mixto',
        'B-CASO-5' => 'CASO-5 — Arroz (Kg)',
    ];
}
if (empty($setCases['generales'])) {
    $setCases['generales'] = [
        'F-4832043-1' => '…-1 — Factura T33', 'F-4832043-2' => '…-2 — Factura T33 (descuentos)',
        'F-4832043-3' => '…-3 — Factura T33 (exento)', 'F-4832043-4' => '…-4 — Factura T33 (dto. global)',
        'F-4832043-5' => '…-5 — NC T61', 'F-4832043-6' => '…-6 — NC T61 (dev.)',
        'F-4832043-7' => '…-7 — NC T61 (anula)', 'F-4832043-8' => '…-8 — ND T56',
    ];
}

$allCasesJson = json_encode(array_keys(array_merge($setCases['boletas'], $setCases['generales'])));
?>

<style>
/* ── Paso card ── */
.paso-card { border:1px solid var(--c-border); border-radius:10px; margin-bottom:18px; overflow:hidden; }
.paso-header { display:flex; align-items:center; gap:12px; padding:12px 16px;
  background:var(--c-bg-subtle,#f8f9fa); border-bottom:1px solid var(--c-border); cursor:pointer;
  user-select:none; }
.paso-header:hover { background:#edf0f5; }
.paso-num { width:30px; height:30px; border-radius:50%; display:flex; align-items:center;
  justify-content:center; font-weight:800; font-size:.85rem; flex-shrink:0;
  color:#fff; background:var(--c-primary,#3b6aec); }
.paso-num.done  { background:#27ae60; }
.paso-num.fail  { background:#e74c3c; }
.paso-num.skip  { background:#95a5a6; }
.paso-title { font-weight:700; font-size:.88rem; flex:1; }
.paso-desc  { font-size:.72rem; color:var(--c-text-muted); font-weight:400; margin-left:4px; }
.paso-body  { padding:16px; }
.paso-body.hidden { display:none; }
/* ── Casos ── */
.cert-case-row { display:flex; align-items:center; gap:8px; padding:5px 10px;
  border-bottom:1px solid var(--c-border); font-size:.78rem; }
.cert-case-row:last-child { border-bottom:none; }
.cert-badge { display:inline-flex; align-items:center; justify-content:center;
  min-width:72px; font-size:.68rem; padding:2px 7px; border-radius:10px; font-weight:600;
  white-space:nowrap; flex-shrink:0; }
.cb-ok      { background:#d4edda; color:#155724; }
.cb-failed  { background:#f8d7da; color:#721c24; }
.cb-pending { background:transparent; color:transparent; min-width:0; padding:0; pointer-events:none; }
.cb-running { background:#fff3cd; color:#856404; }
.cert-folio { font-size:.7rem; color:var(--c-text-muted); flex:1; }
.cert-error-tip { font-size:.68rem; color:#c0392b; cursor:help;
  border-bottom:1px dashed #c0392b; word-break:break-all; }
/* ── Libros ── */
.libro-card { border:1px solid var(--c-border); border-radius:8px; padding:12px; }
/* ── Progress ── */
.cert-progress-bar  { height:6px; background:#eee; border-radius:3px; overflow:hidden; margin-top:4px; }
.cert-progress-fill { height:100%; background:#27ae60; border-radius:3px; transition:width .4s; }
/* ── Banner de estado ── */
.cert-status-ok   { display:flex; align-items:center; gap:10px; padding:12px 16px;
  background:#d4edda; border:1px solid #c3e6cb; border-radius:8px; color:#155724;
  font-weight:600; font-size:.85rem; }
.cert-status-err  { display:flex; align-items:flex-start; gap:10px; padding:12px 16px;
  background:#f8d7da; border:1px solid #f5c6cb; border-radius:8px; color:#721c24; }
.cert-status-err  .err-list { font-size:.78rem; margin-top:4px; }
.cert-status-err  .err-list li { margin-bottom:2px; }
.cert-status-idle { display:flex; align-items:center; gap:10px; padding:10px 16px;
  background:#e9ecef; border:1px solid var(--c-border); border-radius:8px;
  color:var(--c-text-muted); font-size:.82rem; }
/* ── Grupo tipo ── */
.caso-group-header { font-size:.72rem; font-weight:700; color:var(--c-text-muted);
  padding:4px 10px; background:#f4f5f7; border-bottom:1px solid var(--c-border);
  text-transform:uppercase; letter-spacing:.04em; }
</style>

<!-- ══ Toolbar ══════════════════════════════════════════════════════════════ -->
<div class="d-card mb-3">
  <div class="d-card-body" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:10px 14px">
    <strong style="flex:1; min-width:160px; font-size:.88rem">
      <i class="bi bi-patch-check-fill" style="color:var(--c-primary)"></i>
      Certificación SII — Flujo completo
    </strong>
    <button class="d-btn d-btn-sm d-btn-outline" onclick="loadState()">
      <i class="bi bi-arrow-repeat"></i> Actualizar estado
    </button>
    <button class="d-btn d-btn-sm d-btn-warning" onclick="certRetry()" id="btn-retry" style="display:none">
      <i class="bi bi-arrow-clockwise"></i> Reintentar fallidos
    </button>
    <button class="d-btn d-btn-sm d-btn-outline" onclick="certReset()" style="color:#e74c3c">
      <i class="bi bi-trash3"></i> Reiniciar estado
    </button>
  </div>
</div>

<!-- Banner de estado global -->
<div id="cert-status-banner" class="mb-3"></div>

<div id="cert-run-status" class="mb-3"></div>
<!-- IDs ocultos para compatibilidad interna -->
<span id="sum-total" style="display:none"></span>
<span id="sum-ok"    style="display:none"></span>
<span id="sum-fail"  style="display:none"></span>
<span id="sum-pend"  style="display:none"></span>

<!-- ══ PASO 1 — Sets de Prueba ══════════════════════════════════════════════ -->
<div class="paso-card">
  <div class="paso-header" onclick="togglePaso(1)">
    <div class="paso-num" id="pnum-1">1</div>
    <div class="paso-title">Sets de Prueba
      <span class="paso-desc">Vincular archivos .txt oficiales del SII</span>
    </div>
    <span id="pbadge-1" class="d-badge">Cargando…</span>
    <i class="bi bi-chevron-down ms-2" id="pchev-1"></i>
  </div>
  <div class="paso-body" id="pbody-1">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="d-label"><i class="bi bi-receipt"></i> Set Boletas — <code>Set Prueba BE.txt</code></label>
        <div style="display:flex; gap:6px">
          <input type="file" id="set-file-boletas" accept=".txt" class="d-input" style="font-size:.78rem">
          <button class="d-btn d-btn-outline d-btn-sm" onclick="certUploadSet('boletas')">
            <i class="bi bi-upload"></i> Subir
          </button>
        </div>
      </div>
      <div class="col-md-6">
        <label class="d-label"><i class="bi bi-file-earmark-text"></i> Set Básico — <code>SIISetDePruebas{RUT}.txt</code></label>
        <div style="display:flex; gap:6px">
          <input type="file" id="set-file-basico" accept=".txt" class="d-input" style="font-size:.78rem">
          <button class="d-btn d-btn-outline d-btn-sm" onclick="certUploadSet('basico')">
            <i class="bi bi-upload"></i> Subir
          </button>
        </div>
      </div>
    </div>
    <div id="set-info" style="font-size:.75rem; color:var(--c-text-muted); margin-top:10px">
      <span class="spinner-border spinner-border-sm"></span> Cargando set vinculado…
    </div>
  </div>
</div>

<!-- ══ PASO 2 — Boletas Electrónicas ════════════════════════════════════════ -->
<div class="paso-card">
  <div class="paso-header" onclick="togglePaso(2)">
    <div class="paso-num" id="pnum-2">2</div>
    <div class="paso-title">Boletas Electrónicas (T39)
      <span class="paso-desc">Sobre EnvioBOLETA + RCOF al SII</span>
    </div>
    <span id="pbadge-2" class="d-badge"></span>
    <i class="bi bi-chevron-down ms-2" id="pchev-2"></i>
  </div>
  <div class="paso-body" id="pbody-2">
    <p style="font-size:.78rem; color:var(--c-text-muted); margin-bottom:12px">
      Genera las <?= count($setCases['boletas']) ?> boletas del set con los folios del CAF y las envía
      en un único sobre firmado. El RCOF se adjunta automáticamente.
      Reutiliza los mismos folios (reintentable hasta aprobación del SII).
    </p>
    <div style="display:flex; gap:10px; flex-wrap:wrap">
      <button class="d-btn d-btn-primary" id="btn-cert-boletas" onclick="certBoletas()">
        <i class="bi bi-rocket-takeoff-fill"></i> Enviar Boletas al SII
      </button>
      <button class="d-btn d-btn-outline" onclick="certMuestras()">
        <i class="bi bi-printer"></i> Muestras PDF
      </button>
    </div>
    <div id="cert-boletas-result" style="margin-top:12px"></div>
    <!-- Casos boletas (solo estado, se envían en bloque) -->
    <div class="mt-3" style="border:1px solid var(--c-border); border-radius:6px; overflow:hidden">
      <div class="caso-group-header">Casos de Boleta</div>
      <?php foreach ($setCases['boletas'] as $cid => $name): ?>
      <div class="cert-case-row" id="row-<?= $cid ?>">
        <span class="cert-badge cb-pending" id="badge-<?= $cid ?>">Pendiente</span>
        <span style="flex:1"><?= htmlspecialchars($name) ?></span>
        <span class="cert-folio" id="folio-<?= $cid ?>"></span>
        <span style="font-size:.68rem; color:var(--c-text-muted)">Sobre SII</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ══ PASO 3 — Facturas, NC, ND y Guías ════════════════════════════════════ -->
<div class="paso-card">
  <div class="paso-header" onclick="togglePaso(3)">
    <div class="paso-num" id="pnum-3">3</div>
    <div class="paso-title">Facturas, NC, ND y Guías (Set Básico)
      <span class="paso-desc">T33 · T61 · T56 · T52 — cada documento en sobre individual</span>
    </div>
    <span id="pbadge-3" class="d-badge"></span>
    <i class="bi bi-chevron-down ms-2" id="pchev-3"></i>
  </div>
  <div class="paso-body" id="pbody-3">
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px">
      <button class="d-btn d-btn-success" onclick="certRunAll()">
        <i class="bi bi-play-fill"></i> Ejecutar todos los casos
      </button>
      <button class="d-btn d-btn-outline" onclick="certMuestras()">
        <i class="bi bi-printer"></i> Muestras PDF
      </button>
    </div>
    <!-- Casos generales (dinámicos) -->
    <div id="casos-generales-container" style="border:1px solid var(--c-border); border-radius:6px; overflow:hidden">
      <?php
      // Agrupar por tipo
      $grupos = ['F-' => ['T33/46/56/61 — Facturas, NC, ND', []], 'G-' => ['T52 — Guías de Despacho', []]];
      foreach ($setCases['generales'] as $cid => $name) {
          $pfx = str_starts_with($cid, 'G-') ? 'G-' : 'F-';
          $grupos[$pfx][1][$cid] = $name;
      }
      foreach ($grupos as [$grpLabel, $grpCases]):
          if (empty($grpCases)) continue;
      ?>
      <div class="caso-group-header"><?= $grpLabel ?></div>
      <?php foreach ($grpCases as $cid => $name): ?>
      <div class="cert-case-row" id="row-<?= $cid ?>">
        <span class="cert-badge cb-pending" id="badge-<?= $cid ?>">Pendiente</span>
        <span style="flex:1"><?= htmlspecialchars($name) ?></span>
        <span class="cert-folio" id="folio-<?= $cid ?>"></span>
        <button class="d-btn d-btn-sm d-btn-outline" onclick="certRunCase('<?= $cid ?>')" title="Ejecutar solo este caso">
          <i class="bi bi-play"></i>
        </button>
      </div>
      <?php endforeach; ?>
      <?php endforeach; ?>
      <div id="casos-extra-rows"></div><!-- filas dinámicas para IDs no en PHP -->
    </div>
  </div>
</div>

<!-- ══ PASO 4 — Libros Tributarios ══════════════════════════════════════════ -->
<div class="paso-card">
  <div class="paso-header" onclick="togglePaso(4)">
    <div class="paso-num" id="pnum-4">4</div>
    <div class="paso-title">Libros Tributarios
      <span class="paso-desc">Ventas · Compras · Guías — enviar después del Paso 3</span>
    </div>
    <span id="pbadge-4" class="d-badge"></span>
    <i class="bi bi-chevron-down ms-2" id="pchev-4"></i>
  </div>
  <div class="paso-body" id="pbody-4">
    <div class="row g-3">

      <!-- Libro Ventas -->
      <div class="col-md-4">
        <div class="libro-card h-100" style="border-left:3px solid #27ae60">
          <div style="font-weight:700; font-size:.82rem; margin-bottom:2px">
            <i class="bi bi-graph-up" style="color:#27ae60"></i> Libro de Ventas
            <span id="libro-ventas-at" style="font-size:.68rem; color:var(--c-text-muted); font-weight:400; display:block"></span>
          </div>
          <div style="font-size:.72rem; color:var(--c-text-muted); margin-bottom:10px">
            <span id="libro-ventas-desc">Construido con los casos del Set Básico (facturas, NC, ND).</span>
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

      <!-- Libro Compras -->
      <div class="col-md-4">
        <div class="libro-card h-100" style="border-left:3px solid #e67e22">
          <div style="font-weight:700; font-size:.82rem; margin-bottom:2px">
            <i class="bi bi-cart" style="color:#e67e22"></i> Libro de Compras
            <span id="libro-compras-at" style="font-size:.68rem; color:var(--c-text-muted); font-weight:400; display:block"></span>
          </div>
          <div style="font-size:.72rem; color:var(--c-text-muted); margin-bottom:10px">
            Documentos papel y electrónicos del set oficial. Incluye IVA uso común y no recuperable.
          </div>
          <div style="display:flex; align-items:center; gap:8px">
            <button class="d-btn d-btn-sm flex-fill" style="background:#e67e22;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:.78rem;cursor:pointer" onclick="certLibro('compras')">
              <i class="bi bi-send"></i> Enviar
            </button>
            <span class="cert-badge cb-pending" id="libro-compras-badge">Pendiente</span>
          </div>
          <div id="libro-compras-info" style="font-size:.68rem; color:var(--c-text-muted); margin-top:6px"></div>
        </div>
      </div>

      <!-- Libro Guías -->
      <div class="col-md-4">
        <div class="libro-card h-100" style="border-left:3px solid #8e44ad">
          <div style="font-weight:700; font-size:.82rem; margin-bottom:2px">
            <i class="bi bi-truck" style="color:#8e44ad"></i> Libro de Guías
            <span id="libro-guias-at" style="font-size:.68rem; color:var(--c-text-muted); font-weight:400; display:block"></span>
          </div>
          <div style="font-size:.72rem; color:var(--c-text-muted); margin-bottom:10px">
            Requiere guías T52 generadas en el Paso 3. Solo en sets con guías de despacho.
          </div>
          <div style="display:flex; align-items:center; gap:8px">
            <button class="d-btn d-btn-sm flex-fill" style="background:#8e44ad;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:.78rem;cursor:pointer" onclick="certLibro('guias')">
              <i class="bi bi-send"></i> Enviar
            </button>
            <span class="cert-badge cb-pending" id="libro-guias-badge">Pendiente</span>
          </div>
          <div id="libro-guias-info" style="font-size:.68rem; color:var(--c-text-muted); margin-top:6px"></div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ══ PASO 5 — Simulación ════════════════════════════════════════════════════ -->
<div class="paso-card">
  <div class="paso-header" onclick="togglePaso(5)">
    <div class="paso-num" id="pnum-5">5</div>
    <div class="paso-title">Simulación
      <span class="paso-desc">Etapa 2 SII — Enviar 50 T33 + 50 T39 al ambiente de certificación</span>
    </div>
    <span id="pbadge-5" class="d-badge"></span>
    <i class="bi bi-chevron-down ms-2" id="pchev-5"></i>
  </div>
  <div class="paso-body hidden" id="pbody-5">
    <p style="font-size:.78rem; color:var(--c-text-muted); margin-bottom:14px">
      El SII exige enviar <strong>50 documentos de prueba por tipo</strong> antes de
      aprobar la certificación. Se envían al servidor <code>maullin.sii.cl</code>
      (ambiente de certificación). Si ya está completa, no se vuelve a ejecutar.
    </p>
    <div class="row g-3 mb-3">
      <!-- T33 Facturas -->
      <div class="col-md-6">
        <div class="libro-card h-100" style="border-left:3px solid #3b6aec">
          <div style="font-weight:700; font-size:.82rem; margin-bottom:2px">
            <i class="bi bi-file-earmark-text" style="color:#3b6aec"></i> T33 — Facturas
          </div>
          <div style="font-size:.72rem; color:var(--c-text-muted); margin-bottom:8px">
            50 facturas de prueba al ambiente de certificación SII.
          </div>
          <div style="display:flex; align-items:center; gap:8px">
            <button class="d-btn d-btn-sm d-btn-outline flex-fill" onclick="certSimulacion(33)">
              <i class="bi bi-play-fill"></i> Ejecutar T33
            </button>
            <span class="cert-badge cb-pending" id="sim-t33-badge">Pendiente</span>
          </div>
          <div id="sim-t33-info" style="font-size:.68rem; color:var(--c-text-muted); margin-top:6px"></div>
        </div>
      </div>
      <!-- T39 Boletas -->
      <div class="col-md-6">
        <div class="libro-card h-100" style="border-left:3px solid #e67e22">
          <div style="font-weight:700; font-size:.82rem; margin-bottom:2px">
            <i class="bi bi-receipt" style="color:#e67e22"></i> T39 — Boletas
          </div>
          <div style="font-size:.72rem; color:var(--c-text-muted); margin-bottom:8px">
            50 boletas de prueba al ambiente de certificación SII.
          </div>
          <div style="display:flex; align-items:center; gap:8px">
            <button class="d-btn d-btn-sm d-btn-outline flex-fill" onclick="certSimulacion(39)">
              <i class="bi bi-play-fill"></i> Ejecutar T39
            </button>
            <span class="cert-badge cb-pending" id="sim-t39-badge">Pendiente</span>
          </div>
          <div id="sim-t39-info" style="font-size:.68rem; color:var(--c-text-muted); margin-top:6px"></div>
        </div>
      </div>
    </div>
    <button class="d-btn d-btn-primary" onclick="certSimulacionAll()">
      <i class="bi bi-rocket-takeoff-fill"></i> Ejecutar Simulación Completa (T33 + T39)
    </button>
    <div id="sim-status" style="margin-top:12px"></div>
  </div>
</div>

<!-- ══ PASO 6 — Intercambio DTE ══════════════════════════════════════════════ -->
<div class="paso-card">
  <div class="paso-header" onclick="togglePaso(6)">
    <div class="paso-num" id="pnum-6">6</div>
    <div class="paso-title">Intercambio DTE
      <span class="paso-desc">Etapa 3 SII — Responder XML enviado por maullin.sii.cl</span>
    </div>
    <span id="pbadge-6" class="d-badge"></span>
    <i class="bi bi-chevron-down ms-2" id="pchev-6"></i>
  </div>
  <div class="paso-body hidden" id="pbody-6">
    <p style="font-size:.78rem; color:var(--c-text-muted); margin-bottom:10px">
      Pegue o suba el XML de intercambio que el SII le envió en el ambiente de certificación.
      El sistema generará y enviará la respuesta automáticamente.
    </p>
    <textarea id="intercambio-xml" class="d-input"
      style="width:100%; height:90px; font-size:.7rem; font-family:monospace; resize:vertical"
      placeholder="&lt;EnvioDTE xmlns=&quot;http://www.sii.cl/SiiDte&quot;...&gt;"></textarea>
    <div style="display:flex; gap:8px; margin-top:8px">
      <input type="file" id="intercambio-file" accept=".xml" style="display:none"
        onchange="loadIntercambioFile(this)">
      <button class="d-btn d-btn-sm d-btn-outline flex-fill"
        onclick="document.getElementById('intercambio-file').click()">
        <i class="bi bi-upload"></i> Subir XML
      </button>
      <button class="d-btn d-btn-sm d-btn-primary flex-fill" onclick="certIntercambio()">
        <i class="bi bi-send-check"></i> Responder al SII
      </button>
    </div>
    <div id="intercambio-status" class="mt-2"></div>
  </div>
</div>

<!-- ══ PASO 7 — Muestras Impresas ═══════════════════════════════════════════ -->
<div class="paso-card">
  <div class="paso-header" onclick="togglePaso(7)">
    <div class="paso-num" id="pnum-7">7</div>
    <div class="paso-title">Muestras Impresas
      <span class="paso-desc">Etapa 4 SII — PDF con PDF417; subir en pdfdteInternet</span>
    </div>
    <span id="pbadge-7" class="d-badge"></span>
    <i class="bi bi-chevron-down ms-2" id="pchev-7"></i>
  </div>
  <div class="paso-body hidden" id="pbody-7">
    <p style="font-size:.78rem; color:var(--c-text-muted); margin-bottom:12px">
      Genera todos los DTEs del Set de Pruebas con timbre PDF417.
      Use <strong>Ctrl+P → Guardar como PDF</strong> en el navegador para obtener el archivo.
      Luego súbalo en
      <a href="https://www4.sii.cl/pdfdteInternet/" target="_blank">www4.sii.cl/pdfdteInternet</a>
      como evidencia impresa.
    </p>
    <button class="d-btn d-btn-info" onclick="certMuestras()">
      <i class="bi bi-printer-fill"></i> Generar e Imprimir Muestras
    </button>
    <a href="https://www4.sii.cl/pdfdteInternet/" target="_blank"
       class="d-btn d-btn-outline d-btn-sm ms-2">
      <i class="bi bi-box-arrow-up-right"></i> Portal SII — Subir PDF
    </a>
  </div>
</div>

<!-- ══ Log de ejecución ══════════════════════════════════════════════════════ -->
<div class="d-card mt-2">
  <div class="d-card-header" style="display:flex; justify-content:space-between; padding:8px 14px">
    <span style="font-size:.82rem; font-weight:600"><i class="bi bi-terminal"></i> Log de ejecución</span>
    <button class="d-btn d-btn-sm d-btn-outline" onclick="document.getElementById('cert-log').innerHTML=''">Limpiar</button>
  </div>
  <div id="cert-log" style="padding:10px 14px; font-size:.72rem; font-family:monospace;
    max-height:260px; overflow-y:auto; background:var(--c-bg-subtle,#f8f9fa)">
    <span style="color:#888">— Log de certificación —</span>
  </div>
</div>

<script>
// ── Constantes ────────────────────────────────────────────────────────────────
const ALL_CASES = <?= $allCasesJson ?>;

// ── Collapsible pasos ─────────────────────────────────────────────────────────
function togglePaso(n) {
  const body = document.getElementById('pbody-'+n);
  const chev = document.getElementById('pchev-'+n);
  if (!body) return;
  const hidden = body.classList.toggle('hidden');
  if (chev) chev.className = 'bi ms-2 ' + (hidden ? 'bi-chevron-right' : 'bi-chevron-down');
}

// ── Log ───────────────────────────────────────────────────────────────────────
function log(msg, type='info') {
  const el = document.getElementById('cert-log');
  const ts = new Date().toLocaleTimeString();
  const colors = { ok:'#27ae60', error:'#e74c3c', info:'#3498db', warn:'#f39c12' };
  el.innerHTML += `<div style="color:${colors[type]||'#aaa'}">[${ts}] ${msg}</div>`;
  el.scrollTop = el.scrollHeight;
}

// ── applyState ────────────────────────────────────────────────────────────────
function applyState(estado) {
  if (!estado) return;
  const pruebas = estado.pruebas || {};
  let ok=0, fail=0, pend=0;

  // Limpiar filas dinámicas de runs anteriores para evitar acumulación de
  // casos de sets viejos (F-4832043-X, etc.) que ya no son relevantes.
  const extraContainer = document.getElementById('casos-extra-rows');
  if (extraContainer) extraContainer.innerHTML = '';

  // Solo IDs del set actual — los IDs de sets anteriores son ruido histórico
  // y no deben afectar el banner ni el conteo del paso actual.
  const currentSetIds = new Set(ALL_CASES.filter(id => !id.startsWith('B-')));
  const nonBIds = [...currentSetIds];

  nonBIds.forEach(id => {
    // Crear fila dinámica si no existe aún en el DOM
    if (!document.getElementById('row-'+id)) {
      const container = document.getElementById('casos-extra-rows');
      if (container) {
        const row = document.createElement('div');
        row.className = 'cert-case-row';
        row.id = 'row-'+id;
        row.innerHTML = `<span class="cert-badge cb-pending" id="badge-${id}">Pendiente</span>`
          + `<span style="flex:1">${id}</span>`
          + `<span class="cert-folio" id="folio-${id}"></span>`
          + `<button class="d-btn d-btn-sm d-btn-outline" onclick="certRunCase('${id}')" title="Ejecutar"><i class="bi bi-play"></i></button>`;
        container.appendChild(row);
      }
    }

    const c = pruebas[id];
    const badge   = document.getElementById('badge-'+id);
    const folioEl = document.getElementById('folio-'+id);
    if (!badge) return;

    if (!c || c.status === 'pending') {
      badge.className = 'cert-badge cb-pending'; badge.textContent = 'Pendiente'; pend++;
    } else if (c.status === 'ok') {
      badge.className = 'cert-badge cb-ok'; badge.textContent = '✓ OK'; ok++;
      if (folioEl) folioEl.textContent = 'Folio ' + c.folio + (c.trackId ? ' · TRK '+String(c.trackId).slice(0,8) : '');
    } else if (c.status === 'failed') {
      const errMsg = c.error || 'Error desconocido';
      const transitorio = /\b503\b|unexpected eof|SSL_read|HTML en lugar|p[áa]gina HTML|no disponible|Error de red|timeout|Service Unavailable/i.test(errMsg);
      fail++;
      badge.className = 'cert-badge ' + (transitorio ? 'cb-running' : 'cb-failed');
      badge.textContent = transitorio ? '⏳ SII' : '✗ Error';
      if (folioEl) {
        const tip = (transitorio ? 'Transitorio — reintente. ' : '') + errMsg;
        folioEl.innerHTML = `<span class="cert-error-tip" title="${tip.replace(/"/g,'&quot;')}">${errMsg.slice(0,100)}</span>`;
      }
    } else if (c.status === 'running') {
      badge.className = 'cert-badge cb-running'; badge.textContent = '⟳ Env...';
    }
  });

  // ── Boletas (B-CASO-*): estado en estado.boletas, no en pruebas ───────────
  const bs     = estado.boletas || {};
  const bSent  = !!bs.ts;                         // se ejecutó alguna vez
  const bOk    = bSent && bs.sobre_ok === true;
  const bFail  = bSent && !bOk;
  const bTrack = bs.sobre_trackId || '';
  const bCasos = bs.casos || [];
  const bIds   = ALL_CASES.filter(id => id.startsWith('B-'));

  bIds.forEach(id => {
    const badge   = document.getElementById('badge-'+id);
    const folioEl = document.getElementById('folio-'+id);
    const casoId  = id.replace(/^B-/, '');
    const cd      = bCasos.find(c => c.caso === casoId);
    if (!badge) return;
    if (bOk) {
      badge.className = 'cert-badge cb-ok'; badge.textContent = '✓ OK'; ok++;
      if (folioEl) folioEl.textContent = (cd ? 'Folio '+cd.folio : '') + (bTrack ? ' · TRK '+String(bTrack).slice(0,8) : '');
    } else if (bFail) {
      badge.className = 'cert-badge cb-failed'; badge.textContent = '✗ Error'; fail++;
      if (folioEl && cd) folioEl.textContent = 'Folio '+cd.folio;
    } else {
      badge.className = 'cert-badge cb-pending'; badge.textContent = 'Pendiente'; pend++;
    }
  });

  // Contadores (para btn-retry y banner)
  document.getElementById('btn-retry').style.display = fail > 0 ? '' : 'none';

  // ── Banner de estado global ───────────────────────────────────────────────
  _updateBanner(ok, fail, nonBIds.length + bIds.length);

  // Paso 2 (boletas) — badge
  const pb2 = document.getElementById('pbadge-2');
  if (pb2) {
    if (bOk) {
      pb2.textContent='✓ OK'; pb2.className='d-badge success'; setPasoNum(2,'done');
    } else if (bFail) {
      pb2.textContent='✗ Error'; pb2.className='d-badge danger'; setPasoNum(2,'fail');
    } else {
      pb2.textContent=''; pb2.className='d-badge'; setPasoNum(2,'');
    }
  }

  // Paso 3 (generales) — badge
  const genOk = nonBIds.filter(id => pruebas[id]?.status === 'ok').length;
  _setPasoBadge(3, genOk, nonBIds.length);

  // Paso 4 — Libros
  const libros = estado.libros || {};
  ['ventas','compras','guias'].forEach(t => {
    const lb    = libros[t];
    const badge = document.getElementById(`libro-${t}-badge`);
    const info  = document.getElementById(`libro-${t}-info`);
    if (!badge) return;
    if (!lb)                      { badge.className='cert-badge cb-pending'; badge.textContent='Pendiente'; }
    else if (lb.status==='ok')    { badge.className='cert-badge cb-ok';      badge.textContent='✓ OK';     if(info) info.textContent='TrackID: '+(lb.trackId||'—'); }
    else if (lb.status==='failed'){ badge.className='cert-badge cb-failed';  badge.textContent='✗ Error';  if(info) info.textContent=lb.error||''; }
  });
  const librosOk = ['ventas','compras','guias'].filter(t=>libros[t]?.status==='ok').length;
  const librosFail = ['ventas','compras','guias'].filter(t=>libros[t]?.status==='failed').length;
  const pb4 = document.getElementById('pbadge-4');
  if (pb4) {
    if (librosOk === 3) {
      pb4.textContent='✓ OK'; pb4.className='d-badge success'; setPasoNum(4,'done');
    } else if (librosFail > 0) {
      pb4.textContent=librosFail+' error(es)'; pb4.className='d-badge danger'; setPasoNum(4,'fail');
    } else if (librosOk > 0) {
      pb4.textContent=librosOk+'/3 OK'; pb4.className='d-badge warning'; setPasoNum(4,'');
    } else {
      pb4.textContent=''; pb4.className='d-badge'; setPasoNum(4,'');
    }
  }

  // Paso 5 — Simulación
  const sim   = estado.simulacion || {};
  const s33   = sim.t33 || {};
  const s39   = sim.t39 || {};
  const simOk = s33.status==='ok' && s39.status==='ok';
  const simPb = document.getElementById('pbadge-5');
  if (simPb) {
    if (simOk) {
      simPb.textContent='✓ Completada'; simPb.className='d-badge success'; setPasoNum(5,'done');
    } else if (s33.status || s39.status) {
      const n33=(s33.folios_ok||[]).length, n39=(s39.folios_ok||[]).length;
      simPb.textContent=`T33: ${n33}/50 · T39: ${n39}/50`; simPb.className='d-badge warning'; setPasoNum(5,'');
    } else {
      simPb.textContent='Pendiente'; simPb.className='d-badge'; setPasoNum(5,'');
    }
  }
  _updSimCard('t33', s33);
  _updSimCard('t39', s39);

  // Paso 6 — Intercambio
  const ic  = estado.intercambio || {};
  const pb6 = document.getElementById('pbadge-6');
  if (pb6) {
    if (ic.status === 'responded') {
      pb6.textContent='✓ OK'; pb6.className='d-badge success'; setPasoNum(6,'done');
    } else if (ic.status === 'failed') {
      pb6.textContent='✗ Error'; pb6.className='d-badge danger'; setPasoNum(6,'fail');
    } else {
      pb6.textContent=''; pb6.className='d-badge'; setPasoNum(6,'');
    }
  }
}

function _updateBanner(ok, fail, total) {
  const banner = document.getElementById('cert-status-banner');
  if (!banner) return;

  if (total === 0) {
    // Sin estado cargado aún
    banner.innerHTML = `<div class="cert-status-idle">
      <i class="bi bi-hourglass-split"></i>
      <span>Cargando estado de certificación…</span></div>`;
    return;
  }

  if (fail === 0 && ok === 0) {
    // Nada ejecutado aún
    banner.innerHTML = '';
    return;
  }

  if (fail === 0) {
    // Todo ejecutado OK — celebrar
    banner.innerHTML = `<div class="cert-status-ok">
      <i class="bi bi-patch-check-fill" style="font-size:1.4rem"></i>
      <div>
        <div>Certificación en orden — sin errores</div>
        <div style="font-weight:400; font-size:.75rem; margin-top:2px">${ok} documento(s) enviados correctamente al SII.</div>
      </div></div>`;
    return;
  }

  // Hay errores — listarlos
  const errRows = [];
  document.querySelectorAll('.cert-case-row').forEach(row => {
    const badge = row.querySelector('.cert-badge');
    const folio = row.querySelector('.cert-folio');
    if (!badge || !badge.classList.contains('cb-failed')) return;
    const label = row.querySelector('span:nth-child(2)')?.textContent?.trim() || row.id.replace('row-','');
    const err   = folio?.querySelector('.cert-error-tip')?.getAttribute('title') || folio?.textContent || '';
    errRows.push(`<li><strong>${label}</strong>${err ? ' — '+err.slice(0,120) : ''}</li>`);
  });

  banner.innerHTML = `<div class="cert-status-err">
    <i class="bi bi-exclamation-triangle-fill" style="font-size:1.4rem; flex-shrink:0; margin-top:2px"></i>
    <div style="flex:1">
      <div style="font-weight:700; font-size:.88rem">${fail} error(es) — corrija antes de continuar</div>
      ${errRows.length ? '<ul class="err-list mb-0 ps-3">'+errRows.join('')+'</ul>' : ''}
    </div></div>`;
}

function _updSimCard(key, s) {
  const badge = document.getElementById('sim-'+key+'-badge');
  const info  = document.getElementById('sim-'+key+'-info');
  if (!badge) return;
  if (s.status === 'ok') {
    badge.className='cert-badge cb-ok'; badge.textContent='✓ OK ('+((s.folios_ok||[]).length)+')';
    if (info) info.textContent='Completada · '+((s.ts||'').slice(0,16).replace('T',' '));
  } else if (s.status === 'running' || s.status === 'partial') {
    const n=(s.folios_ok||[]).length, f=(s.folios_failed||[]).length;
    badge.className='cert-badge cb-running'; badge.textContent=`⟳ ${n}/50`;
    if (info) info.textContent=`${n} ok · ${f} fallidos`;
  } else if (s.status) {
    badge.className='cert-badge cb-failed'; badge.textContent='✗ Error';
    if (info) info.textContent=s.error||'';
  } else {
    badge.className='cert-badge cb-pending'; badge.textContent='Pendiente';
    if (info) info.textContent='';
  }
}

function _setPasoBadge(n, ok, tot) {
  const badge = document.getElementById('pbadge-'+n);
  if (!badge) return;
  if (tot === 0) { badge.textContent=''; badge.className='d-badge'; return; }
  if (ok === 0) {
    // Nada ejecutado — no mostrar nada (no "Pendiente")
    badge.textContent = ''; badge.className = 'd-badge';
    setPasoNum(n, '');
    return;
  }
  if (ok === tot) {
    badge.textContent = '✓ OK'; badge.className = 'd-badge success';
    setPasoNum(n, 'done');
  } else {
    // Algunos OK, algunos no — mostrar progreso solo si hay algo ejecutado
    badge.textContent = ok+'/'+tot+' OK'; badge.className = 'd-badge warning';
    setPasoNum(n, '');
  }
}

function setPasoNum(n, cls) {
  const el = document.getElementById('pnum-'+n);
  if (el) el.className = 'paso-num ' + (cls||'');
}

function setBadge(id, text, type) {
  const el = document.getElementById(id);
  if (!el) return;
  el.className = 'd-badge '+(type==='success'?'success':type==='danger'?'danger':type==='warning'?'warning':'');
  el.textContent = text;
}

// ── API ───────────────────────────────────────────────────────────────────────
async function api(action, extra={}) {
  const params = new URLSearchParams({ action, ...extra });
  const r = await fetch(`cert_bridge.php?${params}`);
  return r.json();
}

// ── Set de Prueba (upload + info) ─────────────────────────────────────────────
async function certLoadSetInfo() {
  const el = document.getElementById('set-info');
  try {
    const res = await api('cert_set_get');
    if (res.ok && res.set) {
      const s = res.set;
      const nB = (s.boletas||[]).length;
      const nF = (s.facturas||[]).length;
      const nG = (s.facturas||[]).filter(f=>f.tipoDTE==52).length;
      el.innerHTML = `<i class="bi bi-check-circle" style="color:#27ae60"></i> `
        + `<strong>Set vinculado:</strong> ${nB} boleta(s) · ${nF-nG} factura(s)/NC/ND · ${nG} guía(s)`
        + (s.atencion_basico  ? ` · At. básico <strong>${s.atencion_basico}</strong>` : '')
        + (s.origen_boletas   ? ` · <em style="color:#888">${s.origen_boletas}</em>` : '')
        + (s.origen_basico    ? ` / <em style="color:#888">${s.origen_basico}</em>` : '');
      // Paso 1 badge
      const pb1 = document.getElementById('pbadge-1');
      if (pb1) { pb1.textContent = 'Vinculado'; pb1.className = 'd-badge success'; }
      setPasoNum(1, 'done');
      // Descripción dinámica Libro Ventas
      const descEl = document.getElementById('libro-ventas-desc');
      if (descEl) {
        const nVenta = nF - nG;
        descEl.textContent = `Construido con los ${nVenta} caso${nVenta!==1?'s':''} del Set Básico (facturas, NC, ND).`;
      }
      // Atenciones libros
      _setAt('libro-ventas-at',  s.atencion_ventas,      'Lib. Ventas');
      _setAt('libro-compras-at', s.atencion_compras,     'Lib. Compras');
      _setAt('libro-guias-at',   s.atencion_libro_guias || s.atencion_guias, 'Lib. Guías');
    } else {
      el.innerHTML = '<i class="bi bi-exclamation-triangle" style="color:#e67e22"></i> Sin set vinculado. Suba el .txt del SII.';
      const pb1 = document.getElementById('pbadge-1');
      if (pb1) { pb1.textContent = 'Sin set'; pb1.className = 'd-badge danger'; }
    }
  } catch(e) { el.textContent = 'No se pudo cargar el set: ' + e.message; }
}

function _setAt(elId, val, label) {
  const el = document.getElementById(elId);
  if (!el) return;
  el.textContent = val ? ('At. ' + val) : '';
}

async function certUploadSet(tipo='boletas') {
  const inputId = tipo === 'basico' ? 'set-file-basico' : 'set-file-boletas';
  const input   = document.getElementById(inputId);
  const el      = document.getElementById('set-info');
  if (!input.files.length) { alert('Seleccione el archivo .txt del SII'); return; }
  const fd = new FormData();
  fd.append('action', 'cert_set_upload');
  fd.append('set_file', input.files[0]);
  el.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Procesando…';
  try {
    const r = await fetch('cert_bridge.php', { method:'POST', body: fd });
    const res = await r.json();
    if (res.ok) {
      log(`Set vinculado: ${res.boletas||0} boleta(s), ${res.facturas||0} caso(s).`, 'ok');
      certLoadSetInfo();
      await loadState();
    } else {
      el.innerHTML = `<span style="color:#e74c3c"><i class="bi bi-x-circle"></i> ${res.error||'Error al procesar el set'}</span>`;
    }
  } catch(e) { el.innerHTML = `<span style="color:#e74c3c">Error: ${e.message}</span>`; }
}

// ── Boletas ───────────────────────────────────────────────────────────────────
async function certBoletas() {
  const btn = document.getElementById('btn-cert-boletas');
  const out = document.getElementById('cert-boletas-result');
  btn.disabled = true;
  out.innerHTML = '<div class="d-alert info"><span class="spinner-border spinner-border-sm me-2"></span> Generando boletas y sobre, enviando al SII…</div>';
  log('Iniciando certificación de boletas (set en sobre SII)…', 'info');
  try {
    const res = await api('cert_boletas');
    if (res.error && !res.sobre) {
      out.innerHTML = `<div class="d-alert danger"><i class="bi bi-x-circle"></i> ${res.error}</div>`;
      log('Error boletas: '+res.error, 'error');
    } else {
      const s = res.sobre||{}, rc = res.rcof||{};
      const badge = ok => ok ? '<span class="cert-badge cb-ok">✓ OK</span>' : '<span class="cert-badge cb-failed">✗</span>';
      out.innerHTML = `<div class="d-alert ${res.ok?'success':'warning'}">
        <div><strong>Folios usados:</strong> ${(res.folios||[]).join(', ')}</div>
        <div style="margin-top:6px">${badge(s.ok)} <strong>Sobre boletas</strong> — TRK: <code>${s.trackId||'—'}</code> ${s.estado?'· '+s.estado:''}</div>
        <div style="margin-top:4px">${badge(rc.ok)} <strong>RCOF</strong> — TRK: <code>${rc.trackId||'—'}</code> ${rc.skipped?'(omitido)':''}</div>
        <div style="margin-top:6px; font-size:.72rem; color:var(--c-text-muted)">
          Informe el Track ID en <strong>www.sii.cl → Menú Postulantes → Boletas electrónicas</strong>.
        </div></div>`;
      log(`Boletas: sobre TRK ${s.trackId||'-'} (${s.ok?'OK':'FALLA'}), RCOF ${rc.skipped?'omitido':('TRK '+(rc.trackId||'-'))}`, res.ok?'ok':'warn');
      setPasoNum(2, res.ok ? 'done' : 'fail');
      setBadge('pbadge-2', res.ok ? '✓ Enviado' : 'Error', res.ok ? 'success' : 'danger');
    }
    await loadState();
  } catch(e) {
    out.innerHTML = `<div class="d-alert danger"><i class="bi bi-x-circle"></i> Error: ${e.message}</div>`;
  } finally { btn.disabled = false; }
}

// ── Estado global ─────────────────────────────────────────────────────────────
async function loadState() {
  try {
    const res = await api('cert_state');
    if (res.ok) applyState(res.estado);
    else document.getElementById('cert-run-status').innerHTML =
      `<div class="d-alert danger"><i class="bi bi-x-circle"></i> Error al cargar estado: ${res.error||'?'}</div>`;
  } catch(e) {
    document.getElementById('cert-run-status').innerHTML =
      `<div class="d-alert danger"><i class="bi bi-x-circle"></i> Error: ${e.message}</div>`;
  }
}

// ── Run All (Paso 3) ──────────────────────────────────────────────────────────
async function certRunAll() {
  const status = document.getElementById('cert-run-status');
  status.innerHTML = '<div class="d-alert info"><span class="spinner-border spinner-border-sm me-2"></span> Ejecutando Paso 3: facturas, NC, ND y guías… puede tardar varios minutos.</div>';
  log('Iniciando Paso 3: set básico completo…', 'info');
  try {
    const res = await api('cert_run_pruebas', { skip_boletas: 1 });
    applyState(res.estado);
    const r = res.resultados || {};
    Object.entries(r).forEach(([k,v]) => {
      if (!v || typeof v !== 'object') return;
      if ('status' in v || 'folio' in v) {
        log(`${k}: ${v.status||'?'} - folio ${v.folio||'-'} ${v.error?'| '+v.error:''}`, v.status==='ok'?'ok':'error');
      } else {
        Object.entries(v).forEach(([cid,cr]) => {
          if (!cr || typeof cr !== 'object') return;
          log(`${cid}: ${cr.status||'?'} - folio ${cr.folio||'-'} ${cr.error?'| '+cr.error:''}`, cr.status==='ok'?'ok':'error');
        });
      }
    });
    status.innerHTML = '<div class="d-alert success"><i class="bi bi-check-circle"></i> Paso 3 ejecutado. Revise los resultados.</div>';
  } catch(e) {
    status.innerHTML = `<div class="d-alert danger">${e.message}</div>`;
    log('Error Paso 3: '+e.message, 'error');
  }
}

async function certRunCase(cid) {
  if (cid.startsWith('B-')) {
    log('Boletas no se envían individualmente. Use el botón del Paso 2.', 'warn');
    return;
  }
  const badge = document.getElementById('badge-'+cid);
  if (badge) { badge.className='cert-badge cb-running'; badge.textContent='⟳...'; }
  log(`Ejecutando ${cid}…`, 'info');
  try {
    const res = await api('cert_case', { cid });
    const envio = res.envio || {};
    log(`${cid}: ${envio.ok?'ok':'failed'} — folio ${res.folio||'-'} TRK: ${envio.trackId||'-'} ${envio.error||''}`, envio.ok?'ok':'error');
  } catch(e) { log(`${cid}: error — ${e.message}`, 'error'); }
  await loadState();
}

// ── Retry / Reset ─────────────────────────────────────────────────────────────
async function certRetry() {
  log('Reintentando todos los casos fallidos…', 'warn');
  document.getElementById('cert-run-status').innerHTML =
    '<div class="d-alert warning"><span class="spinner-border spinner-border-sm me-2"></span> Reintentando fallidos…</div>';
  try {
    const res = await api('cert_retry');
    applyState(res.estado);
    document.getElementById('cert-run-status').innerHTML = '<div class="d-alert success">Reintento completado.</div>';
    log('Reintento finalizado.', 'ok');
  } catch(e) { log('Error retry: '+e.message, 'error'); }
}

async function certReset() {
  if (!confirm('¿Reiniciar TODO el estado de certificación? Se borra el progreso guardado.')) return;
  await api('cert_reset');
  log('Estado reiniciado.', 'warn');
  await loadState();
}

// ── Intercambio ───────────────────────────────────────────────────────────────
function loadIntercambioFile(input) {
  const file = input.files[0]; if (!file) return;
  const reader = new FileReader();
  reader.onload = e => { document.getElementById('intercambio-xml').value = e.target.result; };
  reader.readAsText(file, 'ISO-8859-1');
}

async function certIntercambio() {
  const xml    = document.getElementById('intercambio-xml').value.trim();
  const status = document.getElementById('intercambio-status');
  if (!xml) { status.innerHTML = '<div class="d-alert danger">Ingrese el XML de intercambio.</div>'; return; }
  status.innerHTML = '<div class="d-alert info"><span class="spinner-border spinner-border-sm me-2"></span> Procesando…</div>';
  log('Respondiendo intercambio…', 'info');
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

// ── Libros ────────────────────────────────────────────────────────────────────
async function certLibro(tipo) {
  const actionMap = { ventas:'cert_libro_ventas', compras:'cert_libro_compras', guias:'cert_libro_guias' };
  const action = actionMap[tipo];
  const badge  = document.getElementById(`libro-${tipo}-badge`);
  const info   = document.getElementById(`libro-${tipo}-info`);
  if (badge) { badge.className='cert-badge cb-running'; badge.textContent='⟳...'; }
  const label = tipo.charAt(0).toUpperCase() + tipo.slice(1);
  log(`Enviando Libro de ${label}…`, 'info');
  try {
    const res = await api(action);
    if (res.ok) {
      if (badge) { badge.className='cert-badge cb-ok'; badge.textContent='✓ OK'; }
      if (info)  info.textContent = 'TrackID: ' + (res.trackId||'—');
      log(`Libro ${tipo} enviado OK. TrackID: ${res.trackId||'-'}`, 'ok');
    } else {
      if (badge) { badge.className='cert-badge cb-failed'; badge.textContent='✗ Error'; }
      if (info)  info.textContent = res.error||'Error desconocido';
      log(`Libro ${tipo} falló: ${res.error||'?'}`, 'error');
    }
    await loadState();
  } catch(e) {
    if (badge) { badge.className='cert-badge cb-failed'; badge.textContent='✗ Error'; }
    log(`Error libro ${tipo}: ${e.message}`, 'error');
  }
}

// ── Simulación ────────────────────────────────────────────────────────────────
async function certSimulacion(tipo) {
  const statusEl = document.getElementById('sim-status');
  const badge    = document.getElementById('sim-t'+tipo+'-badge');
  const info     = document.getElementById('sim-t'+tipo+'-info');
  if (badge) { badge.className='cert-badge cb-running'; badge.textContent='⟳ Enviando…'; }
  if (info)  info.textContent = '';
  if (statusEl) statusEl.innerHTML = `<div class="d-alert info"><span class="spinner-border spinner-border-sm me-2"></span> Enviando simulación T${tipo} (50 docs)… puede tardar varios minutos.</div>`;
  log(`Iniciando simulación T${tipo} (50 docs)…`, 'info');
  try {
    const res = await api('cert_run_sim', { tipo });
    if (res.ok) {
      if (badge) { badge.className='cert-badge cb-ok'; badge.textContent=`✓ OK (${res.enviados||0})`; }
      if (info)  info.textContent = `${res.enviados||0} enviados · ${res.fallidos||0} fallidos`;
      if (statusEl) statusEl.innerHTML = `<div class="d-alert success"><i class="bi bi-check-circle"></i> Simulación T${tipo} completada: ${res.enviados||0} docs OK.</div>`;
      log(`Simulación T${tipo}: ${res.enviados||0} OK, ${res.fallidos||0} fallidos.`, 'ok');
    } else {
      if (badge) { badge.className='cert-badge cb-failed'; badge.textContent='✗ Error'; }
      if (statusEl) statusEl.innerHTML = `<div class="d-alert danger">${res.error||'Error desconocido'}</div>`;
      log(`Simulación T${tipo} error: ${res.error||'?'}`, 'error');
    }
    await loadState();
  } catch(e) {
    if (badge) { badge.className='cert-badge cb-failed'; badge.textContent='✗ Error'; }
    if (statusEl) statusEl.innerHTML = `<div class="d-alert danger">${e.message}</div>`;
    log(`Error simulación T${tipo}: ${e.message}`, 'error');
  }
}

async function certSimulacionAll() {
  const statusEl = document.getElementById('sim-status');
  if (statusEl) statusEl.innerHTML = '<div class="d-alert info"><span class="spinner-border spinner-border-sm me-2"></span> Ejecutando simulación completa T33 + T39 (100 docs total)…</div>';
  log('Iniciando simulación completa T33 + T39…', 'info');
  try {
    const res = await api('cert_sim_all');
    const r33 = res.resultados?.sim_33 || {}, r39 = res.resultados?.sim_39 || {};
    const ok33 = r33.ok || r33.skipped, ok39 = r39.ok || r39.skipped;
    if (ok33 && ok39) {
      if (statusEl) statusEl.innerHTML = '<div class="d-alert success"><i class="bi bi-check-circle"></i> Simulación completa: T33 OK · T39 OK.</div>';
      log('Simulación completa exitosa.', 'ok');
    } else {
      if (statusEl) statusEl.innerHTML = `<div class="d-alert warning">T33: ${ok33?'OK':'Error'} · T39: ${ok39?'OK':'Error'}</div>`;
      log(`Simulación: T33 ${ok33?'ok':'fail'}, T39 ${ok39?'ok':'fail'}`, ok33&&ok39?'ok':'warn');
    }
    applyState(res.estado);
  } catch(e) {
    if (statusEl) statusEl.innerHTML = `<div class="d-alert danger">${e.message}</div>`;
    log('Error simulación: '+e.message, 'error');
  }
}

// ── Muestras ──────────────────────────────────────────────────────────────────
async function certMuestras() {
  if (typeof DTE === 'undefined' || !DTE.renderMuestras) {
    alert('No se encontró el motor de impresión (jscript.js).');
    return;
  }
  log('Generando muestras impresas…', 'info');
  try {
    const res = await api('cert_muestras_xml');
    if (!res.ok || !Array.isArray(res.dtes) || !res.dtes.length) {
      alert('No hay documentos para muestras. Ejecute primero el Set de Pruebas.');
      return;
    }
    DTE.renderMuestras(res.dtes, res.opts || {});
    setBadge('pbadge-7', '✓ Generado', 'success');
    setPasoNum(7, 'done');
    log(`Muestras generadas: ${res.dtes.length} documento(s).`, 'ok');
  } catch(e) { alert('Error generando muestras: ' + e.message); }
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadState();
  certLoadSetInfo();
  log('Módulo de certificación cargado.', 'info');
});
</script>
