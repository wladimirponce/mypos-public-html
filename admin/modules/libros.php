<?php
/**
 * Módulo Libros & RCOF — Reportes tributarios
 */
?>
<div class="row g-4">
    <!-- RCOF -->
    <div class="col-lg-6">
        <div class="d-card">
            <div class="d-card-header"><i class="bi bi-receipt"></i> Generar RCOF (Boletas)</div>
            <div class="d-card-body">
                <p style="font-size:.78rem; color:var(--c-text-muted)">Resumen de Consumo de Folios diario obligatorio para Boletas Electrónicas.</p>
                <form id="form-rcof" onsubmit="event.preventDefault(); generarRCOF();">
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="d-label">Fecha RCOF</label>
                            <input type="date" id="rcof_fecha" class="d-input" required>
                        </div>
                        <div class="col-6">
                            <label class="d-label">Secuencia Envío</label>
                            <input type="number" id="rcof_secuencia" class="d-input" value="1" required min="1">
                        </div>
                        <div class="col-6">
                            <label class="d-label">Neto Total</label>
                            <input type="number" id="rcof_neto" class="d-input" value="0">
                        </div>
                        <div class="col-6">
                            <label class="d-label">IVA Total</label>
                            <input type="number" id="rcof_iva" class="d-input" value="0">
                        </div>
                        <div class="col-4">
                            <label class="d-label">Emitidas</label>
                            <input type="number" id="rcof_em" class="d-input" value="0">
                        </div>
                        <div class="col-4">
                            <label class="d-label">Folio Inicial</label>
                            <input type="number" id="rcof_fi" class="d-input" value="0">
                        </div>
                        <div class="col-4">
                            <label class="d-label">Folio Final</label>
                            <input type="number" id="rcof_ff" class="d-input" value="0">
                        </div>
                    </div>
                    <button type="submit" class="d-btn d-btn-primary w-100" id="btn-rcof">
                        <i class="bi bi-file-earmark-code"></i> Generar XML RCOF
                    </button>
                </form>
                <div id="res-rcof" style="display:none; margin-top:16px">
                    <div class="d-alert success" id="res-rcof-msg"></div>
                    <pre id="xml-rcof" style="background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; font-size:.7rem; max-height:200px; overflow:auto"></pre>
                </div>
            </div>
        </div>
    </div>

    <!-- Libro Compra/Venta -->
    <div class="col-lg-6">
        <div class="d-card">
            <div class="d-card-header"><i class="bi bi-journal-text"></i> Generar Libro (IECV)</div>
            <div class="d-card-body">
                <p style="font-size:.78rem; color:var(--c-text-muted)">Emita el Libro Electrónico de Compras o Ventas consolidado.</p>
                <form id="form-libro" onsubmit="event.preventDefault(); generarLibro();">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="d-label">Tipo de Libro</label>
                            <select id="libro_tipo" class="d-input d-select" required>
                                <option value="VENTA">Libro de Ventas</option>
                                <option value="COMPRA">Libro de Compras</option>
                                <option value="BOLETA">Libro de Boletas</option>
                                <option value="GUIA">Libro de Guias</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="d-label">Período Tributario</label>
                            <input type="month" id="libro_periodo" class="d-input" required>
                        </div>
                    </div>
                    <button type="submit" class="d-btn d-btn-primary w-100" id="btn-libro">
                        <i class="bi bi-journal-code"></i> Generar XML Libro
                    </button>
                </form>
                <div id="res-libro" style="display:none; margin-top:16px">
                    <div class="d-alert success" id="res-libro-msg"></div>
                    <pre id="xml-libro" style="background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; font-size:.7rem; max-height:200px; overflow:auto"></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
async function generarRCOF() {
    const btn = document.getElementById('btn-rcof');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generando...';
    try {
        const resp = await fetch('api.php?action=rcof', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({
                fecha: document.getElementById('rcof_fecha').value,
                secuencia: +document.getElementById('rcof_secuencia').value,
                neto: +document.getElementById('rcof_neto').value,
                iva: +document.getElementById('rcof_iva').value,
                emitidas: +document.getElementById('rcof_em').value,
                folioInicial: +document.getElementById('rcof_fi').value,
                folioFinal: +document.getElementById('rcof_ff').value
            })
        });
        const data = await resp.json();
        document.getElementById('res-rcof').style.display = 'block';
        document.getElementById('res-rcof-msg').innerHTML = data.ok ? '<i class="bi bi-check-circle"></i> ' + data.mensaje : data.error;
        document.getElementById('res-rcof-msg').className = 'd-alert ' + (data.ok ? 'success' : 'danger');
        if (data.xml) document.getElementById('xml-rcof').textContent = data.xml;
    } catch(e) { alert('Error: ' + e.message); }
    btn.disabled = false; btn.innerHTML = '<i class="bi bi-file-earmark-code"></i> Generar XML RCOF';
}

async function generarLibro() {
    const btn = document.getElementById('btn-libro');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generando...';
    try {
        const resp = await fetch('api.php?action=libro', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({
                tipoLibro: document.getElementById('libro_tipo').value,
                periodo: document.getElementById('libro_periodo').value
            })
        });
        const data = await resp.json();
        document.getElementById('res-libro').style.display = 'block';
        document.getElementById('res-libro-msg').innerHTML = data.ok ? '<i class="bi bi-check-circle"></i> ' + data.mensaje : data.error;
        document.getElementById('res-libro-msg').className = 'd-alert ' + (data.ok ? 'success' : 'danger');
        if (data.xml) document.getElementById('xml-libro').textContent = data.xml;
    } catch(e) { alert('Error: ' + e.message); }
    btn.disabled = false; btn.innerHTML = '<i class="bi bi-journal-code"></i> Generar XML Libro';
}
</script>
