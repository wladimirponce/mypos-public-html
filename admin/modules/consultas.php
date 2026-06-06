<?php
/**
 * Módulo Consultas — Estados SII y TrackID
 */
?>
<div class="row g-4">
    <!-- Consulta de DTE Específico -->
    <div class="col-lg-6">
        <div class="d-card">
            <div class="d-card-header"><i class="bi bi-file-earmark-check"></i> Consultar Estado de un DTE</div>
            <div class="d-card-body">
                <p style="font-size:.78rem; color:var(--c-text-muted)">Consulte facturas, guias y notas. Las boletas se consultan por folio en el panel de boletas.</p>
                <form id="form-consulta-dte" onsubmit="event.preventDefault(); consultarDTE();">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="d-label">RUT Receptor</label>
                            <input type="text" id="cdte_rut" class="d-input" placeholder="12345678-9" required>
                        </div>
                        <div class="col-md-6">
                            <label class="d-label">Tipo DTE</label>
                            <select id="cdte_tipo" class="d-input d-select" required>
                                <option value="33">33 - Factura</option>
                                <option value="52">52 - Guía Despacho</option>
                                <option value="56">56 - Nota Débito</option>
                                <option value="61">61 - Nota Crédito</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="d-label">Folio</label>
                            <input type="number" id="cdte_folio" class="d-input" required min="1">
                        </div>
                        <div class="col-md-4">
                            <label class="d-label">Fecha Emisión</label>
                            <input type="date" id="cdte_fecha" class="d-input" required>
                        </div>
                        <div class="col-md-4">
                            <label class="d-label">Monto Total</label>
                            <input type="number" id="cdte_monto" class="d-input" required min="0">
                        </div>
                    </div>
                    <button type="submit" class="d-btn d-btn-primary w-100" id="btn-consulta-dte">
                        <i class="bi bi-cloud-arrow-down"></i> Consultar Estado
                    </button>
                </form>
                <div id="resultado-dte" style="display:none; margin-top:16px">
                    <div class="d-alert info" id="res-dte-estado">Esperando respuesta...</div>
                    <p style="font-weight:600; font-size:.78rem; color:var(--c-text-muted)">Glosa SII:</p>
                    <p id="res-dte-glosa" style="color:var(--c-text-secondary); font-size:.82rem">...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Consulta Boleta Electronica -->
    <div class="col-lg-6">
        <div class="d-card">
            <div class="d-card-header" style="border-left:3px solid var(--c-primary)">
                <i class="bi bi-receipt"></i> Consultar Estado de Boleta
            </div>
            <div class="d-card-body">
                <p style="font-size:.78rem; color:var(--c-text-muted)">Consulta directa al SII por tipo y folio de boleta.</p>
                <form id="form-consulta-boleta" onsubmit="event.preventDefault(); consultarBoleta();">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="d-label">Tipo Boleta</label>
                            <select id="cbol_tipo" class="d-input d-select" required>
                                <option value="39">39 - Boleta Electronica</option>
                                <option value="41">41 - Boleta Exenta</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="d-label">Folio</label>
                            <input type="number" id="cbol_folio" class="d-input" required min="1" placeholder="Ej: 12317072">
                        </div>
                        <div class="col-md-6">
                            <label class="d-label">RUT Receptor</label>
                            <input type="text" id="cbol_rut" class="d-input" required value="66666666-6">
                        </div>
                        <div class="col-md-3">
                            <label class="d-label">Fecha Emision</label>
                            <input type="date" id="cbol_fecha" class="d-input" required>
                        </div>
                        <div class="col-md-3">
                            <label class="d-label">Monto Total</label>
                            <input type="number" id="cbol_monto" class="d-input" required min="1" placeholder="Ej: 1300">
                        </div>
                    </div>
                    <button type="submit" class="d-btn d-btn-primary w-100" id="btn-consulta-boleta">
                        <i class="bi bi-cloud-check"></i> Consultar Boleta en SII
                    </button>
                </form>
                <div id="resultado-boleta" style="display:none; margin-top:16px">
                    <div class="d-alert info" id="res-boleta-estado">Esperando respuesta...</div>
                    <p id="res-boleta-glosa" style="color:var(--c-text-secondary); font-size:.82rem">...</p>
                    <pre id="res-boleta-raw" style="white-space:pre-wrap; word-break:break-word; font-size:.72rem; background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; max-height:260px; overflow:auto"></pre>
                </div>
            </div>
        </div>
    </div>

    <!-- Consulta TrackID -->
    <div class="col-lg-6">
        <div class="d-card">
            <div class="d-card-header" style="border-left:3px solid var(--c-success)">
                <i class="bi bi-box-seam"></i> Consultar Estado de Envío (TrackID)
            </div>
            <div class="d-card-body">
                <p style="font-size:.78rem; color:var(--c-text-muted)">Consulta directa al SII por TrackID del envio.</p>
                <form id="form-consulta-track" onsubmit="event.preventDefault(); consultarTrackID();">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="d-label">Tipo de envio</label>
                            <select id="ctrack_tipo" class="d-input d-select" required>
                                <option value="39">Boleta electronica (39/41)</option>
                                <option value="52">DTE normal / guia / factura</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                        <label class="d-label">Número de TrackID</label>
                        <input type="text" id="ctrack_id" class="d-input" placeholder="Ej: 22028417727" required>
                        </div>
                    </div>
                    <button type="submit" class="d-btn d-btn-success w-100" id="btn-consulta-track">
                        <i class="bi bi-envelope-check"></i> Consultar Estado Envío
                    </button>
                </form>
                <div id="resultado-track" style="display:none; margin-top:16px">
                    <div class="d-alert success" id="res-track-estado">Esperando respuesta...</div>
                    <p id="res-track-glosa" style="color:var(--c-text-secondary); font-size:.82rem">...</p>
                    <pre id="res-track-raw" style="white-space:pre-wrap; word-break:break-word; font-size:.72rem; background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; max-height:260px; overflow:auto"></pre>
                </div>
            </div>
        </div>
    </div>
    <!-- Aceptacion / Reclamo DTE recibido -->
    <div class="col-lg-6">
        <div class="d-card">
            <div class="d-card-header" style="border-left:3px solid var(--c-warning)">
                <i class="bi bi-exclamation-diamond"></i> Aceptacion / Reclamo DTE Recibido
            </div>
            <div class="d-card-body">
                <form id="form-reclamo-dte" onsubmit="event.preventDefault(); registrarReclamoDTE(false);">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="d-label">RUT Emisor del DTE</label>
                            <input type="text" id="rec_rut" class="d-input" placeholder="76543210-9" required>
                        </div>
                        <div class="col-md-3">
                            <label class="d-label">Tipo</label>
                            <input type="number" id="rec_tipo" class="d-input" value="33" required min="1">
                        </div>
                        <div class="col-md-3">
                            <label class="d-label">Folio</label>
                            <input type="number" id="rec_folio" class="d-input" required min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="d-label">Operacion</label>
                            <select id="rec_operation" class="d-input d-select" onchange="toggleReclamoAccion()">
                                <option value="ingresarAceptacionReclamoDoc">Registrar accion</option>
                                <option value="listarEventosHistDoc">Listar eventos</option>
                                <option value="consultarDocDteCedible">Consultar cedible</option>
                                <option value="consultarFechaRecepcionSii">Fecha recepcion SII</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="rec_accion_wrap">
                            <label class="d-label">Accion</label>
                            <select id="rec_accion" class="d-input d-select">
                                <option value="ACD">ACD - Acepta contenido</option>
                                <option value="ERM">ERM - Recibo mercaderias/servicios</option>
                                <option value="RCD">RCD - Reclamo contenido</option>
                                <option value="RFP">RFP - Falta parcial</option>
                                <option value="RFT">RFT - Falta total</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px">
                        <button type="button" class="d-btn d-btn-outline flex-fill" id="btn-reclamo-dry" onclick="registrarReclamoDTE(true)">
                            <i class="bi bi-eye"></i> Dry-run
                        </button>
                        <button type="submit" class="d-btn d-btn-primary flex-fill" id="btn-reclamo-dte">
                            <i class="bi bi-send-check"></i> Enviar al SII
                        </button>
                    </div>
                </form>
                <div id="resultado-reclamo" style="display:none; margin-top:16px">
                    <div class="d-alert info" id="res-reclamo-estado">Esperando respuesta...</div>
                    <pre id="res-reclamo-raw" style="white-space:pre-wrap; word-break:break-word; font-size:.72rem; background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; max-height:260px; overflow:auto"></pre>
                </div>
            </div>
        </div>
    </div>
    <!-- Respuesta de envio entre contribuyentes -->
    <div class="col-lg-6">
        <div class="d-card">
            <div class="d-card-header" style="border-left:3px solid var(--c-primary)">
                <i class="bi bi-reply-all"></i> Generar RespuestaDTE
            </div>
            <div class="d-card-body">
                <form id="form-respuesta-envio" onsubmit="event.preventDefault(); generarRespuestaEnvio();">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="d-label">RUT al que se responde</label>
                            <input type="text" id="resp_rut_recibe" class="d-input" placeholder="RUT receptor" required>
                        </div>
                        <div class="col-md-6">
                            <label class="d-label">Tipo respuesta</label>
                            <select id="resp_modo" class="d-input d-select">
                                <option value="resultado">Resultado comercial</option>
                                <option value="recepcion">Recepcion tecnica</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="d-label">Tipo DTE</label>
                            <input type="number" id="resp_tipo" class="d-input" value="33" min="1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="d-label">Folio</label>
                            <input type="number" id="resp_folio" class="d-input" min="1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="d-label">Fecha</label>
                            <input type="date" id="resp_fecha" class="d-input" required>
                        </div>
                        <div class="col-md-3">
                            <label class="d-label">Monto</label>
                            <input type="number" id="resp_monto" class="d-input" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="d-label">RUT Emisor DTE</label>
                            <input type="text" id="resp_rut_emisor" class="d-input" required>
                        </div>
                        <div class="col-md-6">
                            <label class="d-label">RUT Receptor DTE</label>
                            <input type="text" id="resp_rut_receptor" class="d-input" required>
                        </div>
                        <div class="col-md-6">
                            <label class="d-label">Estado</label>
                            <select id="resp_estado" class="d-input d-select">
                                <option value="0">0 - OK / Aceptado</option>
                                <option value="1">1 - Reparos / Error firma</option>
                                <option value="2">2 - Rechazado / Error RUT emisor</option>
                                <option value="3">3 - Error RUT receptor</option>
                                <option value="4">4 - DTE repetido</option>
                                <option value="99">99 - Otra razon</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="d-label">Glosa / Motivo</label>
                            <input type="text" id="resp_glosa" class="d-input" placeholder="Opcional">
                        </div>
                    </div>
                    <button type="submit" class="d-btn d-btn-primary w-100" id="btn-respuesta-envio">
                        <i class="bi bi-file-earmark-code"></i> Generar XML firmado
                    </button>
                </form>
                <div id="resultado-respuesta-envio" style="display:none; margin-top:16px">
                    <div class="d-alert info" id="res-respuesta-estado">Esperando respuesta...</div>
                    <pre id="res-respuesta-xml" style="white-space:pre-wrap; word-break:break-word; font-size:.72rem; background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; max-height:260px; overflow:auto"></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
async function consultarDTE() {
    const btn = document.getElementById('btn-consulta-dte');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Consultando...';
    const res = document.getElementById('resultado-dte');
    res.style.display = 'block';

    try {
        const resp = await fetch('api.php?action=validate', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                tipo: document.getElementById('cdte_tipo').value,
                folio: document.getElementById('cdte_folio').value,
                rutReceptor: document.getElementById('cdte_rut').value,
                fecha: document.getElementById('cdte_fecha').value,
                monto: document.getElementById('cdte_monto').value
            })
        });
        const data = await resp.json();
        document.getElementById('res-dte-estado').className = 'd-alert ' + (data.ok ? 'success' : 'danger');
        document.getElementById('res-dte-estado').innerHTML = '<i class="bi bi-' + (data.ok ? 'check-circle' : 'x-circle') + '"></i> ' + (data.estado || (data.ok ? 'Aceptado' : 'Error'));
        document.getElementById('res-dte-glosa').textContent = data.glosa || data.error || '—';
    } catch(e) {
        document.getElementById('res-dte-estado').className = 'd-alert danger';
        document.getElementById('res-dte-estado').textContent = 'Error: ' + e.message;
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-cloud-arrow-down"></i> Consultar Estado';
}

document.addEventListener('DOMContentLoaded', () => {
    const fechaBoleta = document.getElementById('cbol_fecha');
    if (fechaBoleta && !fechaBoleta.value) {
        fechaBoleta.value = new Date().toISOString().slice(0, 10);
    }
    const fechaResp = document.getElementById('resp_fecha');
    if (fechaResp && !fechaResp.value) {
        fechaResp.value = new Date().toISOString().slice(0, 10);
    }
});

async function consultarBoleta() {
    const btn = document.getElementById('btn-consulta-boleta');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Consultando...';
    const res = document.getElementById('resultado-boleta');
    res.style.display = 'block';

    try {
        const resp = await fetch('api.php?action=validate', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                tipo: document.getElementById('cbol_tipo').value,
                folio: document.getElementById('cbol_folio').value,
                rutReceptor: document.getElementById('cbol_rut').value,
                fecha: document.getElementById('cbol_fecha').value,
                monto: document.getElementById('cbol_monto').value
            })
        });
        const data = await resp.json();
        document.getElementById('res-boleta-estado').className = 'd-alert ' + (data.ok ? 'success' : 'danger');
        document.getElementById('res-boleta-estado').innerHTML = '<i class="bi bi-' + (data.ok ? 'check-circle' : 'x-circle') + '"></i> ' + (data.estado || 'Sin respuesta');
        document.getElementById('res-boleta-glosa').textContent = data.glosa || data.error || '—';
        document.getElementById('res-boleta-raw').textContent = JSON.stringify({
            url: data.url || '',
            attempts: data.attempts || [],
            raw: data.raw || null,
            rawResponse: data.rawResponse || ''
        }, null, 2);
    } catch(e) {
        document.getElementById('res-boleta-estado').className = 'd-alert danger';
        document.getElementById('res-boleta-estado').textContent = 'Error: ' + e.message;
        document.getElementById('res-boleta-raw').textContent = '';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-cloud-check"></i> Consultar Boleta en SII';
}

async function consultarTrackID() {
    const btn = document.getElementById('btn-consulta-track');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Consultando...';
    const res = document.getElementById('resultado-track');
    res.style.display = 'block';

    try {
        const resp = await fetch('api.php?action=validate', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                tipo: document.getElementById('ctrack_tipo').value,
                folio: 0,
                trackId: document.getElementById('ctrack_id').value
            })
        });
        const data = await resp.json();
        document.getElementById('res-track-estado').className = 'd-alert ' + (data.ok ? 'success' : 'danger');
        document.getElementById('res-track-estado').innerHTML = '<i class="bi bi-' + (data.ok ? 'check-circle' : 'x-circle') + '"></i> ' + (data.estado || 'Sin respuesta');
        document.getElementById('res-track-glosa').textContent = data.glosa || data.error || '—';
        document.getElementById('res-track-raw').textContent = JSON.stringify({
            url: data.url || '',
            attempts: data.attempts || [],
            raw: data.raw || null,
            rawResponse: data.rawResponse || data.xml || ''
        }, null, 2);
    } catch(e) {
        document.getElementById('res-track-estado').className = 'd-alert danger';
        document.getElementById('res-track-estado').textContent = 'Error: ' + e.message;
        document.getElementById('res-track-raw').textContent = '';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-envelope-check"></i> Consultar Estado Envío';
}
function toggleReclamoAccion() {
    const op = document.getElementById('rec_operation').value;
    document.getElementById('rec_accion_wrap').style.display = op === 'ingresarAceptacionReclamoDoc' ? '' : 'none';
}

async function registrarReclamoDTE(dryRun = false) {
    const btn = document.getElementById(dryRun ? 'btn-reclamo-dry' : 'btn-reclamo-dte');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Procesando...';
    document.getElementById('resultado-reclamo').style.display = 'block';
    try {
        const op = document.getElementById('rec_operation').value;
        if (!dryRun && op === 'ingresarAceptacionReclamoDoc') {
            const accion = document.getElementById('rec_accion').value;
            if (!confirm(`Confirma enviar accion ${accion} al SII para este DTE recibido?`)) return;
        }
        const resp = await fetch('api.php?action=reclamo_dte', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                operation: op,
                rutEmisor: document.getElementById('rec_rut').value,
                tipoDoc: document.getElementById('rec_tipo').value,
                folio: document.getElementById('rec_folio').value,
                accion: document.getElementById('rec_accion').value,
                dry_run: dryRun
            })
        });
        const data = await resp.json();
        document.getElementById('res-reclamo-estado').className = 'd-alert ' + (data.ok ? 'success' : 'danger');
        document.getElementById('res-reclamo-estado').innerHTML = '<i class="bi bi-' + (data.ok ? 'check-circle' : 'x-circle') + '"></i> ' + (data.descResp || data.error || (dryRun ? 'Dry-run OK' : 'Respuesta recibida'));
        document.getElementById('res-reclamo-raw').textContent = JSON.stringify(data, null, 2);
    } catch(e) {
        document.getElementById('res-reclamo-estado').className = 'd-alert danger';
        document.getElementById('res-reclamo-estado').textContent = 'Error: ' + e.message;
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

toggleReclamoAccion();

async function generarRespuestaEnvio() {
    const btn = document.getElementById('btn-respuesta-envio');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generando...';
    document.getElementById('resultado-respuesta-envio').style.display = 'block';
    try {
        const modo = document.getElementById('resp_modo').value;
        const doc = {
            tipo: document.getElementById('resp_tipo').value,
            folio: document.getElementById('resp_folio').value,
            fecha: document.getElementById('resp_fecha').value,
            rutEmisor: document.getElementById('resp_rut_emisor').value,
            rutReceptor: document.getElementById('resp_rut_receptor').value,
            montoTotal: document.getElementById('resp_monto').value,
            estado: document.getElementById('resp_estado').value,
            glosa: document.getElementById('resp_glosa').value
        };
        const payload = {
            rutRecibe: document.getElementById('resp_rut_recibe').value,
        };
        if (modo === 'recepcion') {
            payload.recepcionEnvio = {
                nombreArchivo: 'envio.xml',
                codEnvio: 1,
                envioDTEId: 'SetDoc',
                rutEmisor: doc.rutEmisor,
                rutReceptor: doc.rutReceptor,
                estado: 0
            };
            payload.recepcionDTE = [doc];
        } else {
            payload.resultadosDTE = [doc];
        }

        const resp = await fetch('api.php?action=respuesta_envio', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await resp.json();
        document.getElementById('res-respuesta-estado').className = 'd-alert ' + (data.ok ? 'success' : 'danger');
        document.getElementById('res-respuesta-estado').innerHTML = '<i class="bi bi-' + (data.ok ? 'check-circle' : 'x-circle') + '"></i> ' + (data.mensaje || data.error || 'Respuesta generada');
        document.getElementById('res-respuesta-xml').textContent = data.xml || JSON.stringify(data, null, 2);
    } catch(e) {
        document.getElementById('res-respuesta-estado').className = 'd-alert danger';
        document.getElementById('res-respuesta-estado').textContent = 'Error: ' + e.message;
        document.getElementById('res-respuesta-xml').textContent = '';
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}
</script>
