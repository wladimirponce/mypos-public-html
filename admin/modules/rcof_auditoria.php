<?php
/**
 * Auditoria RCOF multiempresa.
 */
?>
<style>
.rcof-kpi {
    border: 1px solid var(--c-border);
    border-radius: 8px;
    padding: 14px 16px;
    background: var(--c-surface);
    min-height: 86px;
}
</style>
<div class="d-card">
    <div class="d-card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div><i class="bi bi-clipboard2-check"></i> Auditoria RCOF multiempresa</div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <input type="date" id="rcof_audit_fecha" class="d-input" style="width:150px">
            <button type="button" class="d-btn d-btn-outline" onclick="rcofAuditLoad()">
                <i class="bi bi-arrow-clockwise"></i> Actualizar
            </button>
            <button type="button" class="d-btn d-btn-outline" onclick="rcofAuditRun(true)">
                <i class="bi bi-search"></i> Dry-run
            </button>
            <button type="button" class="d-btn d-btn-primary" onclick="rcofAuditRun(false)">
                <i class="bi bi-send-check"></i> Ejecutar RCOF
            </button>
        </div>
    </div>
    <div class="d-card-body">
        <div id="rcof_audit_alert" class="d-alert info" style="display:none"></div>

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="rcof-kpi">
                    <div class="kpi-label">Empresas</div>
                    <div class="kpi-value" id="rcof_kpi_total">0</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="rcof-kpi">
                    <div class="kpi-label">Pendientes</div>
                    <div class="kpi-value" id="rcof_kpi_pendientes">0</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="rcof-kpi">
                    <div class="kpi-label">Errores</div>
                    <div class="kpi-value" id="rcof_kpi_errores">0</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="rcof-kpi">
                    <div class="kpi-label">Fecha auditada</div>
                    <div class="kpi-value" id="rcof_kpi_fecha" style="font-size:1.1rem">-</div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Empresa</th>
                        <th>Ambiente</th>
                        <th>Estado</th>
                        <th>TrackID</th>
                        <th>Seq</th>
                        <th>Respaldo</th>
                        <th>Ultimo registro</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody id="rcof_audit_rows">
                    <tr><td colspan="8" class="text-muted">Cargando...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="rcofAuditDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detalle auditoria RCOF</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <pre id="rcof_audit_detail" style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:.72rem;max-height:70vh;overflow:auto"></pre>
            </div>
        </div>
    </div>
</div>

<script>
const rcofAuditState = { rows: [] };

function rcofAuditEsc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}

function rcofAuditDefaultDate() {
    const d = new Date();
    d.setDate(d.getDate() - 1);
    return d.toISOString().slice(0, 10);
}

function rcofAuditBadge(estado) {
    const s = String(estado || 'PENDIENTE').toUpperCase();
    const cls = {
        OK: 'success',
        YA_ENVIADO: 'info',
        DRY_RUN: 'info',
        PENDIENTE: 'warning',
        ERROR: 'danger',
        NO_APLICA: 'secondary'
    }[s] || 'secondary';
    return `<span class="d-badge ${cls}">${rcofAuditEsc(s)}</span>`;
}

function rcofAuditAlert(msg, type = 'info') {
    const el = document.getElementById('rcof_audit_alert');
    el.className = 'd-alert ' + type;
    el.innerHTML = msg;
    el.style.display = msg ? 'block' : 'none';
}

function rcofAuditRender(data) {
    rcofAuditState.rows = data.rows || [];
    document.getElementById('rcof_kpi_total').textContent = data.total || 0;
    document.getElementById('rcof_kpi_pendientes').textContent = data.pendientes || 0;
    document.getElementById('rcof_kpi_errores').textContent = data.errores || 0;
    document.getElementById('rcof_kpi_fecha').textContent = data.fecha || '-';

    const tbody = document.getElementById('rcof_audit_rows');
    if (!rcofAuditState.rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-muted">Sin empresas activas.</td></tr>';
        return;
    }

    tbody.innerHTML = rcofAuditState.rows.map((r, idx) => {
        const tracking = r.tracking || {};
        const after = tracking.after || tracking;
        const backup = after.tracking_path || tracking.tracking_path || '';
        const files = after.dte_files_fecha ?? tracking.dte_files_fecha ?? '-';
        const updated = r.finished_at || r.started_at || '-';
        const detailTitle = r.error ? r.error : (r.mensaje || '');
        return `<tr>
            <td>
                <strong>${rcofAuditEsc(r.razon_social)}</strong><br>
                <span class="text-muted">${rcofAuditEsc(r.rut)}</span>
            </td>
            <td>${rcofAuditEsc(r.ambiente || '-')}<br><span class="text-muted">${rcofAuditEsc(r.modo || '')}</span></td>
            <td>${rcofAuditBadge(r.estado)}</td>
            <td>${rcofAuditEsc(r.track_id || '-')}</td>
            <td>${rcofAuditEsc(r.secuencia || '-')}</td>
            <td>
                <span title="${rcofAuditEsc(backup)}">${rcofAuditEsc(files)} XML</span>
                ${backup ? '<br><span class="text-muted">tracking.json</span>' : ''}
            </td>
            <td>${rcofAuditEsc(updated)}</td>
            <td>
                <button type="button" class="d-btn d-btn-outline d-btn-sm" title="${rcofAuditEsc(detailTitle)}" onclick="rcofAuditShow(${idx})">
                    <i class="bi bi-eye"></i>
                </button>
            </td>
        </tr>`;
    }).join('');
}

async function rcofAuditLoad() {
    const fecha = document.getElementById('rcof_audit_fecha').value || rcofAuditDefaultDate();
    rcofAuditAlert('<span class="spinner-border spinner-border-sm"></span> Cargando auditoria...', 'info');
    try {
        const resp = await fetch('api.php?action=rcof_audit&fecha=' + encodeURIComponent(fecha));
        const data = await resp.json();
        if (!data.ok) throw new Error(data.error || 'No se pudo cargar la auditoria');
        rcofAuditRender(data);
        rcofAuditAlert('', 'info');
    } catch (e) {
        rcofAuditAlert('<i class="bi bi-x-circle"></i> ' + rcofAuditEsc(e.message), 'danger');
    }
}

async function rcofAuditRun(dryRun) {
    const fecha = document.getElementById('rcof_audit_fecha').value || rcofAuditDefaultDate();
    if (!dryRun && !confirm('Esto enviara el RCOF al SII para todas las empresas aplicables en PRODUCCION. Continuar?')) {
        return;
    }
    rcofAuditAlert('<span class="spinner-border spinner-border-sm"></span> Ejecutando ' + (dryRun ? 'dry-run' : 'envio') + ' multiempresa...', 'info');
    try {
        const resp = await fetch('api.php?action=rcof_submit_all', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({fecha, dry_run: dryRun})
        });
        const data = await resp.json();
        if (!data.ok && !dryRun) {
            rcofAuditAlert('<i class="bi bi-exclamation-triangle"></i> Run ' + rcofAuditEsc(data.run_id) + ': ' + rcofAuditEsc(data.error_count) + ' error(es). Revise la tabla.', 'danger');
        } else {
            rcofAuditAlert('<i class="bi bi-check-circle"></i> Run ' + rcofAuditEsc(data.run_id) + ' registrado. OK: ' + rcofAuditEsc(data.ok_count) + ', omitidas: ' + rcofAuditEsc(data.skipped_count) + ', errores: ' + rcofAuditEsc(data.error_count) + '.', data.error_count ? 'warning' : 'success');
        }
        await rcofAuditLoad();
    } catch (e) {
        rcofAuditAlert('<i class="bi bi-x-circle"></i> ' + rcofAuditEsc(e.message), 'danger');
    }
}

function rcofAuditShow(idx) {
    const row = rcofAuditState.rows[idx] || {};
    document.getElementById('rcof_audit_detail').textContent = JSON.stringify(row, null, 2);
    new bootstrap.Modal(document.getElementById('rcofAuditDetailModal')).show();
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('rcof_audit_fecha').value = rcofAuditDefaultDate();
    rcofAuditLoad();
});
</script>
