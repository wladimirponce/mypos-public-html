<?php
/**
 * Módulo Historial — Documentos emitidos y archivos XML
 */
?>
<style>
    @media print {
        body * { visibility: hidden !important; }
        #zona-impresion, #zona-impresion * { visibility: visible !important; }
        #zona-impresion { position: absolute; top: 0; left: 0; width: 100%; margin: 0; padding: 0; background: white; }
        @page { margin: 0; }
    }
</style>

<div class="d-card">
    <div class="d-card-header">
        <i class="bi bi-clock-history"></i> Documentos Emitidos
        <div style="margin-left:auto; display:flex; gap:8px">
            <button class="d-btn d-btn-sm d-btn-outline" onclick="cargarHistorial()"><i class="bi bi-arrow-clockwise"></i> Actualizar</button>
        </div>
    </div>
    <div class="d-card-body" style="padding:0">
        <div class="table-responsive">
            <table class="d-table" id="table-history">
                <thead>
                    <tr>
                        <th>Fecha / Hora</th>
                        <th>Tipo</th>
                        <th>Folio</th>
                        <th>Receptor</th>
                        <th style="text-align:right">Monto</th>
                        <th style="text-align:center">Acciones</th>
                    </tr>
                </thead>
                <tbody id="history-body">
                    <tr><td colspan="6" style="text-align:center; padding:40px; color:var(--c-text-muted)">
                        <i class="bi bi-hourglass-split" style="font-size:1.5rem"></i>
                        <p class="mt-2">Cargando historial...</p>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Archivos en servidor -->
<div class="d-card mt-4">
    <div class="d-card-header">
        <i class="bi bi-folder2-open"></i> Archivos XML en Servidor (tmp/)
        <button class="d-btn d-btn-sm d-btn-outline" style="margin-left:auto" onclick="cargarArchivosServidor()"><i class="bi bi-arrow-clockwise"></i></button>
    </div>
    <div class="d-card-body" style="padding:0">
        <div class="table-responsive">
            <table class="d-table" id="table-archivos">
                <thead>
                    <tr>
                        <th>Fecha Modif.</th>
                        <th>Tipo</th>
                        <th>Folio</th>
                        <th>TrackID SII</th>
                        <th>Nombre Archivo</th>
                        <th style="text-align:center">Acciones</th>
                    </tr>
                </thead>
                <tbody id="archivos-body">
                    <tr><td colspan="5" style="text-align:center; padding:40px; color:var(--c-text-muted)">Cargando archivos...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Logs de interacción SII -->
<div class="d-card mt-4">
    <div class="d-card-header">
        <i class="bi bi-terminal text-primary"></i> Logs de Interacción con el SII
        <button class="d-btn d-btn-sm d-btn-outline" style="margin-left:auto" onclick="cargarSiiLogs()"><i class="bi bi-arrow-clockwise"></i></button>
    </div>
    <div class="d-card-body" style="padding:0">
        <div class="table-responsive">
            <table class="d-table" id="table-logs">
                <thead>
                    <tr>
                        <th style="width:180px">Fecha / Hora</th>
                        <th style="width:120px">Acción</th>
                        <th>Detalle</th>
                        <th style="width:100px; text-align:center">Estado</th>
                    </tr>
                </thead>
                <tbody id="logs-body">
                    <tr><td colspan="4" style="text-align:center; padding:20px; color:var(--c-text-muted)">Cargando logs...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Nota de Crédito sobre boleta -->
<div class="modal fade" id="modalNC" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:var(--radius-lg); overflow:hidden">
            <div class="modal-header" style="background:linear-gradient(135deg, #d97706, #f59e0b); color:#fff; border:none">
                <h5 class="modal-title">
                    <i class="bi bi-receipt-cutoff"></i> Emitir Nota de Crédito (Tipo 61)
                    <small style="opacity:.85; font-weight:400; margin-left:6px" id="nc-doc-label"></small>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="nc-tipo-orig">
                <input type="hidden" id="nc-folio-orig">

                <div id="nc-info"></div>

                <div class="mb-3">
                    <label class="d-label">Motivo de la Nota de Crédito</label>
                    <select class="d-input d-select" id="nc-cod-ref" onchange="onNCCodChange()">
                        <option value="1">1 — Anula documento completo</option>
                        <option value="2">2 — Corrige texto (monto $0)</option>
                        <option value="3">3 — Corrige montos del documento</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="d-label">Razón (obligatoria, mín 5 caracteres)</label>
                    <input type="text" id="nc-razon" class="d-input" maxlength="90"
                           placeholder="Ej: Cliente solicita anulación por compra duplicada"
                           oninput="validateNCForm()">
                    <small style="color:var(--c-text-muted); font-size:.7rem">Aparecerá como glosa de referencia en la NC ante el SII</small>
                </div>

                <div id="nc-result" style="margin-top:14px"></div>
            </div>
            <div class="modal-footer" style="background:#f8fafc">
                <button type="button" class="d-btn d-btn-outline" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="d-btn d-btn-outline" id="nc-btn-dry" onclick="ejecutarNC(true)">
                    <i class="bi bi-eye"></i> Vista previa (dry-run)
                </button>
                <button type="button" class="d-btn d-btn-primary" id="nc-btn-emit" onclick="ejecutarNC(false)" disabled
                        style="background:var(--c-warning); border-color:var(--c-warning)">
                    <i class="bi bi-receipt-cutoff"></i> Emitir NC al SII
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Reenvío con defensas (force + dry-run + motivo) -->
<div class="modal fade" id="modalReenvio" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:var(--radius-lg); overflow:hidden">
            <div class="modal-header" style="background:linear-gradient(135deg, #1a56a0, #2563eb); color:#fff; border:none">
                <h5 class="modal-title">
                    <i class="bi bi-send-arrow-up"></i> Reenviar DTE al SII
                    <small style="opacity:.85; font-weight:400; margin-left:6px" id="resend-doc-label"></small>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="resend-tipo">
                <input type="hidden" id="resend-folio">

                <div id="resend-warn"></div>

                <div id="resend-force-block" style="display:none">
                    <div class="form-check mb-3" style="padding:10px 14px; background:#fff7ed; border-left:3px solid var(--c-danger); border-radius:6px">
                        <input class="form-check-input" type="checkbox" id="resend-force" onchange="onResendForceChange()">
                        <label class="form-check-label" for="resend-force" style="font-weight:600">
                            Forzar reenvío de todos modos
                        </label>
                        <div style="font-size:.75rem; color:var(--c-text-muted); margin-top:4px">
                            Reenviar un documento ya aceptado genera un nuevo TrackID en el SII pero NO duplica el efecto tributario.
                            Documenta siempre la razón.
                        </div>
                    </div>
                </div>

                <div id="resend-reason-block" style="display:none">
                    <label class="d-label">Motivo del reenvío (obligatorio, mín 5 caracteres)</label>
                    <input type="text" id="resend-reason" class="d-input" maxlength="200"
                           placeholder="Ej: SII reportó NO encontrado, replicación al ambiente B, etc."
                           oninput="validateResendForm()">
                </div>

                <div id="resend-confirm-block" style="display:none; margin-top:10px">
                    <label class="d-label">Para confirmar, escriba <code style="background:#0f172a; color:#fbbf24; padding:2px 6px; border-radius:4px">REENVIAR</code> en mayúsculas</label>
                    <input type="text" id="resend-confirm" class="d-input" placeholder="REENVIAR"
                           oninput="validateResendForm()">
                </div>

                <div id="resend-result" style="margin-top:14px"></div>
            </div>
            <div class="modal-footer" style="background:#f8fafc">
                <button type="button" class="d-btn d-btn-outline" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="d-btn d-btn-outline" id="resend-btn-dry" onclick="ejecutarReenvio(true)">
                    <i class="bi bi-eye"></i> Dry-run (sin enviar)
                </button>
                <button type="button" class="d-btn d-btn-primary" id="resend-btn-send" onclick="ejecutarReenvio(false)">
                    <i class="bi bi-send"></i> Reenviar al SII
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para ver XML -->
<div class="modal fade" id="modalXML" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="xmlModalTitle">Visor XML</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light">
                <pre id="xmlContent" style="font-size:12px; white-space:pre-wrap; word-break:break-all; border:1px solid #ddd; padding:15px; background:#fff"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="d-btn d-btn-outline" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
const tiposNombre = {33:'Factura',34:'Factura Exenta',39:'Boleta',41:'Boleta Exenta',52:'Guía Despacho',56:'Nota Débito',61:'Nota Crédito'};

function esc(s) {
    return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}

async function leerJsonApi(resp) {
    const contentType = resp.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
        const text = await resp.text();
        throw new Error(resp.redirected
            ? 'No hay una empresa activa para consultar.'
            : 'El servidor no devolvio JSON' + (text ? ': ' + text.slice(0, 80) : '.'));
    }
    return resp.json();
}

async function cargarHistorial() {
    try {
        const resp = await fetch('api.php?action=history');
        const data = await leerJsonApi(resp);
        const tbody = document.getElementById('history-body');
        if (!data.ok || !data.entries?.length) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:40px; color:var(--c-text-muted)">Sin registros</td></tr>';
            return;
        }
        tbody.innerHTML = data.entries.map(d => `
            <tr>
                <td style="font-size:.78rem">${d.timestamp || d.fecha || '—'}</td>
                <td><span class="d-badge info">T${d.tipo}</span> ${tiposNombre[d.tipo] || ''}</td>
                <td style="font-weight:600">${d.folio}</td>
                <td>${d.receptor || '—'}</td>
                <td style="text-align:right; font-weight:600">$${Number(d.total||0).toLocaleString('es-CL')}</td>
                <td style="text-align:center">
                    <button class="d-btn d-btn-sm d-btn-outline" onclick="reenviarFolio(${d.tipo},${d.folio})" title="Re-enviar">
                        <i class="bi bi-send"></i>
                    </button>
                    <div class="dropdown d-inline-block">
                        <button class="d-btn d-btn-sm d-btn-outline dropdown-toggle" type="button" data-bs-toggle="dropdown" title="Imprimir">
                            <i class="bi bi-printer"></i>
                        </button>
                        <ul class="dropdown-menu">
                            <li><h6 class="dropdown-header">Carta / Oficio</h6></li>
                            <li><a class="dropdown-item" href="#" onclick="imprimirHistorial(${d.tipo},${d.folio},'letter',false)"><i class="bi bi-file-earmark-pdf me-2"></i>Tributario</a></li>
                            <li><a class="dropdown-item" href="#" onclick="imprimirHistorial(${d.tipo},${d.folio},'letter',true)"><i class="bi bi-file-earmark-ruled me-2"></i>Cedible</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header">Papel continuo 80mm</h6></li>
                            <li><a class="dropdown-item" href="#" onclick="imprimirHistorial(${d.tipo},${d.folio},'ticket',false)"><i class="bi bi-receipt me-2"></i>Tributario</a></li>
                            <li><a class="dropdown-item" href="#" onclick="imprimirHistorial(${d.tipo},${d.folio},'ticket',true)"><i class="bi bi-receipt-cutoff me-2"></i>Cedible</a></li>
                        </ul>
                    </div>
                </td>
            </tr>
        `).join('');
    } catch(e) {
        document.getElementById('history-body').innerHTML = '<tr><td colspan="6" class="text-danger p-3">Error: '+e.message+'</td></tr>';
    }
}

async function cargarArchivosServidor() {
    try {
        const resp = await fetch('api.php?action=list_files');
        const data = await leerJsonApi(resp);
        const tbody = document.getElementById('archivos-body');
        if (!data.ok || !data.files?.length) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:40px; color:var(--c-text-muted)">Sin archivos XML</td></tr>';
            return;
        }
        tbody.innerHTML = data.files.map(f => `
            <tr>
                <td style="font-size:.78rem">${f.ts}</td>
                <td><span class="d-badge info">T${f.tipo}</span></td>
                <td style="font-weight:600">${f.folio}</td>
                <td>
                    ${f.trackId 
                        ? `<span class="d-badge prod" title="Enviado: ${f.enviado || ''}">${f.trackId}</span>` 
                        : '<span class="d-badge danger">No enviado</span>'}
                </td>
                <td style="font-size:.78rem; font-family:monospace">
                    <a href="javascript:void(0)" onclick="verXML(${f.tipo}, ${f.folio})" class="text-decoration-none">
                        <i class="bi bi-filetype-xml"></i> ${f.file}
                    </a>
                </td>
                <td style="text-align:center">
                    <button class="d-btn d-btn-sm d-btn-outline" onclick="reenviarFolio(${f.tipo},${f.folio})" title="Re-enviar al SII">
                        <i class="bi bi-send"></i>
                    </button>
                    ${(f.tipo == 39 || f.tipo == 41) ? `
                    <button class="d-btn d-btn-sm d-btn-outline" style="border-color:var(--c-warning); color:var(--c-warning)" onclick="abrirModalNC(${f.tipo},${f.folio})" title="Emitir Nota de Crédito">
                        <i class="bi bi-receipt-cutoff"></i>
                    </button>` : ''}
                    <div class="dropdown d-inline-block">
                        <button class="d-btn d-btn-sm d-btn-outline dropdown-toggle" type="button" data-bs-toggle="dropdown" title="Imprimir">
                            <i class="bi bi-printer"></i>
                        </button>
                        <ul class="dropdown-menu">
                            <li><h6 class="dropdown-header">Carta / Oficio</h6></li>
                            <li><a class="dropdown-item" href="#" onclick="imprimirHistorial(${f.tipo},${f.folio},'letter',false)"><i class="bi bi-file-earmark-pdf me-2"></i>Tributario</a></li>
                            <li><a class="dropdown-item" href="#" onclick="imprimirHistorial(${f.tipo},${f.folio},'letter',true)"><i class="bi bi-file-earmark-ruled me-2"></i>Cedible</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header">Papel continuo 80mm</h6></li>
                            <li><a class="dropdown-item" href="#" onclick="imprimirHistorial(${f.tipo},${f.folio},'ticket',false)"><i class="bi bi-receipt me-2"></i>Tributario</a></li>
                            <li><a class="dropdown-item" href="#" onclick="imprimirHistorial(${f.tipo},${f.folio},'ticket',true)"><i class="bi bi-receipt-cutoff me-2"></i>Cedible</a></li>
                        </ul>
                    </div>
                </td>
            </tr>
        `).join('');
    } catch(e) {
        document.getElementById('archivos-body').innerHTML = '<tr><td colspan="5" class="text-danger p-3">Error: '+e.message+'</td></tr>';
    }
}

async function verXML(tipo, folio) {
    try {
        const resp = await fetch('api.php?action=get_xml', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({tipo, folio})
        });
        const data = await resp.json();
        if (data.ok) {
            document.getElementById('xmlModalTitle').textContent = `Archivo: dte_T${tipo}F${folio}.xml`;
            document.getElementById('xmlContent').textContent = data.xml;
            const modal = new bootstrap.Modal(document.getElementById('modalXML'));
            modal.show();
        } else {
            alert('Error al leer XML: ' + data.error);
        }
    } catch(e) { alert('Error: ' + e.message); }
}

// Reenviar DTE: abre modal con info del tracking previo y permite force + dry-run
async function reenviarFolio(tipo, folio) {
    // 1. Cargar tracking previo para decidir qué mostrar en el modal
    let tracking = null;
    try {
        const resp = await fetch('api.php?action=dte_tracking', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({tipo, folio})
        });
        const data = await resp.json();
        tracking = data.tracking || null;
    } catch(e) { /* sin datos previos */ }

    document.getElementById('resend-tipo').value = tipo;
    document.getElementById('resend-folio').value = folio;
    document.getElementById('resend-doc-label').textContent = `T${tipo} · Folio ${folio}`;

    const okEstados = ['REC','DOK','EPR','FOK','SOK','CRT'];
    const yaOk = tracking && okEstados.includes(tracking.estado) && tracking.last_ok_trackId;
    const esBoleta = (tipo === 39 || tipo === 41);

    // Bloque de advertencia
    const warn = document.getElementById('resend-warn');
    if (yaOk) {
        const txtBoleta = esBoleta
            ? '<br><strong class="text-danger">Las boletas NO se pueden anular.</strong> Si fue emitida con error, debe emitir una nota de crédito (tipo 61) en lugar de reenviar esta.'
            : '';
        warn.innerHTML = `
            <div class="d-alert danger" style="margin-bottom:12px">
                <i class="bi bi-exclamation-octagon-fill"></i>
                <strong>Este documento ya fue aceptado por el SII.</strong><br>
                Último TrackID: <strong>${tracking.last_ok_trackId}</strong> · Estado: <strong>${tracking.estado}</strong> · ${tracking.intentos?.length || 1} intento(s).
                ${txtBoleta}<br>
                Reenviar generará un nuevo TrackID en el SII (no es un duplicado lógico, pero contaminará el historial).
            </div>`;
        document.getElementById('resend-force-block').style.display = 'block';
        document.getElementById('resend-force').checked = false;
        document.getElementById('resend-btn-send').disabled = true;
    } else if (tracking && tracking.intentos?.length > 0) {
        const ult = tracking.intentos[tracking.intentos.length-1];
        warn.innerHTML = `
            <div class="d-alert warning" style="margin-bottom:12px">
                <i class="bi bi-info-circle"></i>
                Intento previo fallido: <strong>${ult.result || '—'}</strong> el ${ult.ts || '—'}.
                Total intentos previos: ${tracking.intentos.length}.
            </div>`;
        document.getElementById('resend-force-block').style.display = 'none';
        document.getElementById('resend-btn-send').disabled = false;
    } else {
        warn.innerHTML = `
            <div class="d-alert info" style="margin-bottom:12px">
                <i class="bi bi-send"></i> Primer envío de este folio.
            </div>`;
        document.getElementById('resend-force-block').style.display = 'none';
        document.getElementById('resend-btn-send').disabled = false;
    }

    document.getElementById('resend-reason').value = '';
    document.getElementById('resend-confirm').value = '';
    document.getElementById('resend-result').innerHTML = '';

    const modal = new bootstrap.Modal(document.getElementById('modalReenvio'));
    modal.show();
}

function onResendForceChange() {
    const force = document.getElementById('resend-force').checked;
    const reasonBlock = document.getElementById('resend-reason-block');
    const confirmBlock = document.getElementById('resend-confirm-block');
    reasonBlock.style.display = force ? 'block' : 'none';
    confirmBlock.style.display = force ? 'block' : 'none';
    validateResendForm();
}

function validateResendForm() {
    const force = document.getElementById('resend-force').checked;
    const reason = document.getElementById('resend-reason').value.trim();
    const confirm = document.getElementById('resend-confirm').value.trim().toUpperCase();
    const btnSend = document.getElementById('resend-btn-send');
    const btnDry = document.getElementById('resend-btn-dry');

    if (!force) {
        // Sin force: el botón ya estaba habilitado o no (depende de si yaOk)
        // Dry-run siempre habilitado
        btnDry.disabled = false;
        return;
    }
    const valid = reason.length >= 5 && confirm === 'REENVIAR';
    btnSend.disabled = !valid;
    btnDry.disabled = reason.length < 5;
}

async function ejecutarReenvio(dryRun = false) {
    const tipo = parseInt(document.getElementById('resend-tipo').value);
    const folio = parseInt(document.getElementById('resend-folio').value);
    const force = document.getElementById('resend-force').checked;
    const reason = document.getElementById('resend-reason').value.trim();
    const resultDiv = document.getElementById('resend-result');
    const btn = dryRun ? document.getElementById('resend-btn-dry') : document.getElementById('resend-btn-send');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Procesando...';

    try {
        const resp = await fetch('api.php?action=resend', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({tipo, folio, force, reason, dry_run: dryRun})
        });
        const data = await resp.json();

        if (data.ok && data.dry_run) {
            resultDiv.innerHTML = `<div class="d-alert success">
                <i class="bi bi-check-circle"></i> <strong>Dry-run OK:</strong> ${data.mensaje}
                <br><small>Hash XML: ${data.xml_hash || '—'} · Hash match: ${data.hash_match?'sí':'no'} · XSD válido: sí</small>
            </div>`;
        } else if (data.ok) {
            resultDiv.innerHTML = `<div class="d-alert success">
                <i class="bi bi-check-circle"></i> <strong>Reenviado.</strong> Nuevo TrackID: <strong>${data.trackId || '—'}</strong> · Estado: ${data.estado || '—'}
            </div>`;
            setTimeout(() => {
                cargarArchivosServidor();
                cargarSiiLogs();
            }, 600);
        } else {
            const codeBadge = data.code ? `<span class="d-badge danger">${data.code}</span> ` : '';
            resultDiv.innerHTML = `<div class="d-alert danger">
                <i class="bi bi-x-circle"></i> ${codeBadge}<strong>Bloqueado:</strong> ${data.error || 'Fallo desconocido'}
            </div>`;
        }
    } catch(e) {
        resultDiv.innerHTML = `<div class="d-alert danger"><i class="bi bi-x-circle"></i> Error: ${e.message}</div>`;
    } finally {
        btn.innerHTML = origText;
        btn.disabled = false;
        validateResendForm();
    }
}

// ════════════════════════════════════════════════════
// NOTA DE CRÉDITO (tipo 61) sobre boleta — modal
// ════════════════════════════════════════════════════

async function abrirModalNC(tipo, folio) {
    document.getElementById('nc-tipo-orig').value = tipo;
    document.getElementById('nc-folio-orig').value = folio;
    document.getElementById('nc-doc-label').textContent = `sobre T${tipo} · Folio ${folio}`;
    document.getElementById('nc-razon').value = '';
    document.getElementById('nc-cod-ref').value = '1';
    document.getElementById('nc-result').innerHTML = '';
    document.getElementById('nc-btn-emit').disabled = true;

    // Cargar info de la boleta para mostrar al usuario
    const info = document.getElementById('nc-info');
    info.innerHTML = '<div class="d-alert info"><span class="spinner-border spinner-border-sm"></span> Cargando datos de la boleta...</div>';

    try {
        const resp = await fetch('api.php?action=dte_tracking', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({tipo, folio})
        });
        const data = await resp.json();
        const tr = data.tracking || {};
        const trackId = tr.last_ok_trackId || tr.trackId || '—';
        const estado  = tr.estado || '—';

        info.innerHTML = `
            <div class="d-alert warning" style="margin-bottom:14px">
                <i class="bi bi-info-circle"></i>
                <strong>Está por emitir una Nota de Crédito Electrónica (tipo 61)</strong> que referencia esta boleta.<br>
                <small>
                    Boleta original: <strong>T${tipo}F${folio}</strong> · TrackID SII: <strong>${trackId}</strong> · Estado: <strong>${esc(estado)}</strong>.<br>
                    La NC será firmada con su certificado y enviada al SII por el canal SOAP estándar. Quedará archivada legalmente.
                </small>
            </div>`;
    } catch(e) {
        info.innerHTML = `<div class="d-alert warning">No se pudo cargar tracking: ${esc(e.message)}</div>`;
    }

    const modal = new bootstrap.Modal(document.getElementById('modalNC'));
    modal.show();
}

function onNCCodChange() {
    validateNCForm();
}

function validateNCForm() {
    const razon = document.getElementById('nc-razon').value.trim();
    document.getElementById('nc-btn-emit').disabled = razon.length < 5;
}

async function ejecutarNC(dryRun = false) {
    const tipoOrig = parseInt(document.getElementById('nc-tipo-orig').value);
    const folioOrig = parseInt(document.getElementById('nc-folio-orig').value);
    const codRef = parseInt(document.getElementById('nc-cod-ref').value);
    const razon = document.getElementById('nc-razon').value.trim();
    const resultDiv = document.getElementById('nc-result');
    const btn = dryRun ? document.getElementById('nc-btn-dry') : document.getElementById('nc-btn-emit');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Procesando...';

    try {
        const resp = await fetch('api.php?action=emit_nc', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({
                tipo_orig: tipoOrig, folio_orig: folioOrig,
                cod_ref: codRef, razon, dry_run: dryRun
            })
        });
        const data = await resp.json();

        if (data.ok && data.dry_run) {
            const items = (data.payload?.items || []).map(it => `${esc(it.nombre)} × ${it.cantidad} = $${Number(it.precio).toLocaleString('es-CL')}`).join('<br>');
            const refs = (data.payload?.referencias || []).map(r => `T${r.tipo}F${r.folio} cod=${r.codigo} (${esc(r.razon)})`).join('<br>');
            resultDiv.innerHTML = `<div class="d-alert success">
                <i class="bi bi-check-circle"></i> <strong>Vista previa OK:</strong> ${esc(data.mensaje)}
                <hr style="margin:8px 0">
                <small><strong>Ítems:</strong><br>${items}<br><strong>Referencia:</strong><br>${refs}</small>
            </div>`;
        } else if (data.ok) {
            resultDiv.innerHTML = `<div class="d-alert success">
                <i class="bi bi-check-circle"></i> <strong>Nota de Crédito emitida.</strong><br>
                NC Folio: <strong>${data.nc_folio || '—'}</strong> · TrackID: <strong>${data.trackId || '—'}</strong> · Estado: ${esc(data.estado || '—')}<br>
                <small>Boleta referida: ${esc(data.boleta_referida || '—')} · cod ${data.cod_ref}</small>
            </div>`;
            setTimeout(() => {
                cargarArchivosServidor();
                cargarSiiLogs();
            }, 800);
        } else {
            resultDiv.innerHTML = `<div class="d-alert danger">
                <i class="bi bi-x-circle"></i> <strong>Error:</strong> ${esc(data.error || 'Fallo desconocido')}
            </div>`;
        }
    } catch(e) {
        resultDiv.innerHTML = `<div class="d-alert danger"><i class="bi bi-x-circle"></i> Error: ${esc(e.message)}</div>`;
    } finally {
        btn.innerHTML = origText;
        btn.disabled = false;
        validateNCForm();
    }
}

async function imprimirHistorial(tipo, folio, format, cedible = false) {
    try {
        const resp = await fetch('api.php?action=get_xml', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({tipo, folio})
        });
        const data = await resp.json();
        if (data.ok && data.xml) {
            if (typeof DTE === 'undefined' || !DTE.printFromXML) {
                alert('El motor de impresión aún no está cargado. Recarga la página e intenta nuevamente.');
                return;
            }
            DTE.printFromXML(data.xml, format, { cedible });
        } else {
            alert('No se pudo cargar el XML del documento: ' + (data.error || 'No encontrado'));
        }
    } catch(e) { alert('Error: ' + e.message); }
}

async function cargarSiiLogs() {
    try {
        const resp = await fetch('api.php?action=get_sii_logs');
        const data = await leerJsonApi(resp);
        const tbody = document.getElementById('logs-body');
        if (!data.ok || !data.logs?.length) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:var(--c-text-muted)">Sin logs registrados</td></tr>';
            return;
        }
        const badgeClass = {SUCCESS:'prod', ERROR:'danger', WARNING:'warning', INFO:'info'};
        tbody.innerHTML = data.logs.map(l => `
            <tr>
                <td style="font-size:.78rem">${l.ts}</td>
                <td style="font-weight:600">${l.accion}</td>
                <td style="font-size:.82rem">${l.mensaje}</td>
                <td style="text-align:center">
                    <span class="d-badge ${badgeClass[l.estado] || 'info'}">${l.estado}</span>
                </td>
            </tr>
        `).join('');
    } catch(e) {
        document.getElementById('logs-body').innerHTML = '<tr><td colspan="4" class="text-danger p-3">Error: '+e.message+'</td></tr>';
    }
}

// Cargar al iniciar
cargarHistorial();
cargarArchivosServidor();
cargarSiiLogs();
</script>
