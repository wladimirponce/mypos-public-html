<?php
/**
 * Módulo Emisión — Formulario DTE
 * Reutiliza la lógica de index.php dentro del dashboard
 */
?>
<style>
    #items-table th { font-size: .72rem; white-space: nowrap; text-transform: uppercase; letter-spacing: .04em; color: var(--c-text-secondary); }
    #items-table td { vertical-align: middle; padding: .35rem .5rem; }
    #panel-resultado { display: none; }
    #xml-preview { font-size: .68rem; max-height: 280px; overflow-y: auto; background: #0f172a; color: #e2e8f0; padding: .75rem; border-radius: 8px; white-space: pre-wrap; word-break: break-all; }
    .total-row { font-size: .85rem; }
    .total-row.grand { font-size: 1.15rem; font-weight: 800; color: var(--c-primary); }
    @media print {
        body * { visibility: hidden !important; }
        #zona-impresion, #zona-impresion * { visibility: visible !important; }
        #zona-impresion { position: fixed; top: 0; left: 0; width: 100%; padding: 20px; background: white; }
    }
</style>

<div class="row g-4">
    <!-- ══ Columna principal ══ -->
    <div class="col-lg-8">
        <!-- Tipo de documento -->
        <div class="d-card mb-4">
            <div class="d-card-header"><i class="bi bi-file-earmark"></i> Documento</div>
            <div class="d-card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="d-label">Tipo de DTE</label>
                        <select class="d-input d-select" id="tipoDTE" onchange="DTE.onTipoDTEChange()">
                            <option value="33">33 — Factura Electrónica</option>
                            <option value="34">34 — Factura No Afecta o Exenta</option>
                            <option value="39">39 — Boleta Electrónica</option>
                            <option value="41">41 — Boleta No Afecta o Exenta</option>
                            <option value="52" selected>52 — Guía de Despacho Electrónica</option>
                            <option value="56">56 — Nota de Débito Electrónica</option>
                            <option value="61">61 — Nota de Crédito Electrónica</option>
                            <option value="110">110 - Factura de Exportacion Electronica</option>
                            <option value="111">111 - Nota de Debito de Exportacion</option>
                            <option value="112">112 - Nota de Credito de Exportacion</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="d-label">Folio</label>
                        <input type="number" class="d-input" id="folio" placeholder="Auto (CAF)" min="1">
                        <div class="small mt-1" id="folio-help" style="font-size:.65rem; color:var(--c-text-muted)"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="d-label">Fecha Emisión</label>
                        <input type="date" class="d-input" id="fechaEmision">
                    </div>
                </div>

                <!-- Campos Guía de Despacho -->
                <div id="seccion-guia" class="row g-3 mt-2" style="display:none">
                    <div class="col-md-6">
                        <label class="d-label">Indicador de Traslado</label>
                        <select class="d-input d-select" id="indTraslado">
                            <option value="1">1 — Operación constituye venta</option>
                            <option value="2">2 — Ventas por efectuar</option>
                            <option value="3">3 — Consignaciones</option>
                            <option value="4">4 — Entrega gratuita</option>
                            <option value="5" selected>5 — Traslado interno</option>
                            <option value="6">6 — Otros traslados no venta</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="d-label">Tipo de Despacho</label>
                        <select class="d-input d-select" id="tipoDespacho">
                            <option value="1">1 — Por cuenta del Receptor</option>
                            <option value="2" selected>2 — Por cuenta del Emisor</option>
                            <option value="3">3 — Por cuenta de un Tercero</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="d-label">Patente Vehículo</label>
                        <input type="text" class="d-input" id="patente" placeholder="ABCD-12">
                    </div>
                    <div class="col-md-4">
                        <label class="d-label">RUT Transportista</label>
                        <input type="text" class="d-input" id="rutTranspor" placeholder="12345678-9">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:.8rem; font-weight:500">
                            <input type="checkbox" id="mismoReceptor" onchange="DTE.toggleMismoReceptor()"> Mismo que Emisor
                        </label>
                    </div>
                </div>

                <div id="seccion-exportacion" class="row g-3 mt-2" style="display:none">
                    <div class="col-md-4">
                        <label class="d-label">Moneda Aduana</label>
                        <select class="d-input d-select" id="exp_moneda">
                            <option value="DOLAR USA">DOLAR USA</option>
                            <option value="EURO">EURO</option>
                            <option value="PESO CL">PESO CL</option>
                            <option value="OTRAS MONEDAS">OTRAS MONEDAS</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="d-label">Forma Pago Exportacion</label>
                        <input type="number" class="d-input" id="exp_forma_pago" min="1" max="99" placeholder="Opcional">
                    </div>
                    <div class="col-md-4">
                        <label class="d-label">Tipo Cambio a CLP</label>
                        <input type="number" class="d-input" id="exp_tipo_cambio" min="0" step="0.0001" placeholder="Opcional">
                    </div>
                    <div class="col-md-4">
                        <label class="d-label">ID Extranjero</label>
                        <input type="text" class="d-input" id="exp_num_id" maxlength="20" placeholder="Tax ID / Passport">
                    </div>
                    <div class="col-md-4">
                        <label class="d-label">Nacionalidad</label>
                        <input type="text" class="d-input" id="exp_nacionalidad" maxlength="3" placeholder="Cod. pais">
                    </div>
                    <div class="col-md-4">
                        <label class="d-label">Pais Destino</label>
                        <input type="number" class="d-input" id="exp_pais_destino" min="1" max="999" placeholder="Cod. Aduana">
                    </div>
                    <div class="col-md-3">
                        <label class="d-label">Modalidad Venta</label>
                        <input type="number" class="d-input" id="exp_mod_venta" min="1" max="99" placeholder="Cod.">
                    </div>
                    <div class="col-md-3">
                        <label class="d-label">Clausula Venta</label>
                        <input type="number" class="d-input" id="exp_clau_venta" min="1" max="99" placeholder="Cod.">
                    </div>
                    <div class="col-md-3">
                        <label class="d-label">Via Transporte</label>
                        <input type="number" class="d-input" id="exp_via_transp" min="1" max="99" placeholder="Cod.">
                    </div>
                    <div class="col-md-3">
                        <label class="d-label">Puerto Embarque</label>
                        <input type="number" class="d-input" id="exp_pto_emb" min="1" max="9999" placeholder="Cod.">
                    </div>
                </div>
            </div>
        </div>

        <!-- Receptor -->
        <div class="d-card mb-4">
            <div class="d-card-header"><i class="bi bi-person"></i> Receptor</div>
            <div class="d-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="d-label">RUT Receptor</label>
                        <input type="text" class="d-input" id="rRut" placeholder="12345678-9" oninput="DTE.resetState()">
                    </div>
                    <div class="col-md-8">
                        <label class="d-label">Razón Social / Nombre</label>
                        <input type="text" class="d-input" id="rNombre" placeholder="Nombre del receptor" oninput="DTE.resetState()">
                    </div>
                    <div class="col-md-6 campo-factura">
                        <label class="d-label">Giro</label>
                        <input type="text" class="d-input" id="rGiro" placeholder="Giro comercial">
                    </div>
                    <div class="col-md-6 campo-factura">
                        <label class="d-label">Dirección</label>
                        <input type="text" class="d-input" id="rDireccion" placeholder="Dirección">
                    </div>
                    <div class="col-md-4 campo-factura">
                        <label class="d-label">Comuna</label>
                        <input type="text" class="d-input" id="rComuna" placeholder="Comuna">
                    </div>
                    <div class="col-md-4 campo-factura">
                        <label class="d-label">Ciudad</label>
                        <input type="text" class="d-input" id="rCiudad" placeholder="Ciudad">
                    </div>
                </div>
            </div>
        </div>

        <!-- Detalle de ítems -->
        <div class="d-card mb-4">
            <div class="d-card-header">
                <i class="bi bi-list-ul"></i> Detalle de Ítems
                <button class="d-btn d-btn-sm d-btn-primary" style="margin-left:auto" onclick="DTE.addItem()">
                    <i class="bi bi-plus-circle"></i> Agregar
                </button>
            </div>
            <div class="d-card-body" style="padding:0">
                <div class="table-responsive">
                    <table class="d-table" id="items-table">
                        <thead>
                            <tr>
                                <th style="width:4%; text-align:center">#</th>
                                <th style="width:22%">Nombre</th>
                                <th style="width:28%">Descripción</th>
                                <th style="width:9%">Cant.</th>
                                <th style="width:14%">P. Unitario</th>
                                <th style="width:8%">Desc.%</th>
                                <th style="width:12%">Total</th>
                                <th style="width:3%"></th>
                            </tr>
                        </thead>
                        <tbody id="items-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ Columna lateral ══ -->
    <div class="col-lg-4">
        <!-- Emisor -->
        <div class="d-card mb-4">
            <div class="d-card-header"><i class="bi bi-building"></i> Emisor</div>
            <div class="d-card-body">
                <table style="width:100%; font-size:.8rem">
                    <tr><td style="width:35%; color:var(--c-text-muted); padding:4px 0">RUT</td><td id="info-rut" style="font-weight:600">—</td></tr>
                    <tr><td style="color:var(--c-text-muted); padding:4px 0">Razón Social</td><td id="info-rs">—</td></tr>
                    <tr><td style="color:var(--c-text-muted); padding:4px 0">Giro</td><td id="info-giro">—</td></tr>
                    <tr><td style="color:var(--c-text-muted); padding:4px 0">Dirección</td><td id="info-dir">—</td></tr>
                </table>
            </div>
        </div>

        <!-- Descuento / Recargo Global (opcional) -->
        <div class="d-card mb-4">
            <div class="d-card-header" style="font-size:.78rem">
                <i class="bi bi-percent"></i> Descuento / Recargo Global (opcional)
            </div>
            <div class="d-card-body" style="padding:10px 14px">
                <div class="row g-2">
                    <div class="col-5">
                        <label class="d-label" style="font-size:.65rem">Tipo</label>
                        <select id="dscGlbTipo" class="d-input d-select" style="font-size:.78rem" onchange="DTE.calculateTotals()">
                            <option value="">— Ninguno —</option>
                            <option value="D%">Descuento %</option>
                            <option value="D$">Descuento $</option>
                            <option value="R%">Recargo %</option>
                            <option value="R$">Recargo $</option>
                        </select>
                    </div>
                    <div class="col-3">
                        <label class="d-label" style="font-size:.65rem">Valor</label>
                        <input type="number" id="dscGlbValor" class="d-input" min="0" step="0.01" placeholder="0" style="font-size:.78rem" oninput="DTE.calculateTotals()">
                    </div>
                    <div class="col-4">
                        <label class="d-label" style="font-size:.65rem">Glosa</label>
                        <input type="text" id="dscGlbGlosa" class="d-input" placeholder="Promoción / Recargo" style="font-size:.78rem" maxlength="40">
                    </div>
                </div>
            </div>
        </div>

        <!-- Totales -->
        <div class="d-card mb-4" style="background:linear-gradient(135deg, #f8fafc, #eef2ff)">
            <div class="d-card-header"><i class="bi bi-calculator"></i> Totales</div>
            <div class="d-card-body">
                <div class="d-flex justify-content-between total-row mb-1" id="row-neto">
                    <span style="color:var(--c-text-secondary)">Neto</span>
                    <span id="total-neto" style="font-weight:600">$0</span>
                </div>
                <div class="d-flex justify-content-between total-row mb-1" id="row-iva">
                    <span style="color:var(--c-text-secondary)">IVA (19%)</span>
                    <span id="total-iva" style="font-weight:600">$0</span>
                </div>
                <div class="d-flex justify-content-between total-row mb-1" id="row-exento" style="display:none!important">
                    <span style="color:var(--c-text-secondary)">Exento</span>
                    <span id="total-exento" style="font-weight:600">$0</span>
                </div>
                <div class="d-flex justify-content-between total-row mb-1" id="row-dscglb" style="display:none">
                    <span style="color:var(--c-text-secondary)" id="row-dscglb-label">Descuento</span>
                    <span id="total-dscglb" style="font-weight:600; color:var(--c-warning)">-$0</span>
                </div>
                <hr style="border-color:var(--c-border); margin:10px 0">
                <div class="d-flex justify-content-between total-row grand">
                    <span>TOTAL</span>
                    <span id="total-final">$0</span>
                </div>
            </div>
        </div>

        <!-- Procesamiento -->
        <div class="d-card mb-4" style="border:2px solid var(--c-primary)">
            <div class="d-card-body" style="padding:16px">
                <button class="d-btn d-btn-primary d-btn-lg w-100 mb-3" id="btn-procesar-todo" onclick="DTE.processAll()" style="justify-content:center">
                    <i class="bi bi-rocket-takeoff-fill" style="font-size:1.2rem"></i>
                    <div style="text-align:left">
                        <div style="font-weight:800">GENERAR Y EMITIR</div>
                        <div style="font-size:.68rem; font-weight:400; opacity:.8">Generar, Enviar, Verificar e Imprimir</div>
                    </div>
                </button>
                <div style="display:flex; gap:8px">
                    <button class="d-btn d-btn-outline flex-fill d-btn-sm" id="btn-generar" onclick="DTE.generate()" style="flex-direction:column; padding:10px">
                        <i class="bi bi-file-earmark-code" style="font-size:1.1rem"></i>
                        <span style="font-size:.6rem">GENERAR</span>
                    </button>
                    <button class="d-btn d-btn-outline flex-fill d-btn-sm" id="btn-enviar" onclick="DTE.send()" disabled style="flex-direction:column; padding:10px">
                        <i class="bi bi-send" style="font-size:1.1rem"></i>
                        <span style="font-size:.6rem">ENVIAR</span>
                    </button>
                    <button class="d-btn d-btn-outline flex-fill d-btn-sm" id="btn-validar" onclick="DTE.validate()" disabled style="flex-direction:column; padding:10px">
                        <i class="bi bi-shield-check" style="font-size:1.1rem"></i>
                        <span style="font-size:.6rem">ESTADO</span>
                    </button>
                </div>
                <div style="display:flex; gap:8px; margin-top:8px">
                    <div class="dropdown flex-fill">
                        <button class="d-btn d-btn-outline d-btn-sm w-100 dropdown-toggle" id="btn-imprimir-carta" type="button" data-bs-toggle="dropdown" disabled>
                            <i class="bi bi-file-earmark-pdf"></i> Carta
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="DTE.print('letter',{cedible:false});return false;"><i class="bi bi-file-earmark me-2"></i>Ejemplar Tributario</a></li>
                            <li><a class="dropdown-item" href="#" onclick="DTE.print('letter',{cedible:true});return false;"><i class="bi bi-file-earmark-ruled me-2"></i>Ejemplar Cedible</a></li>
                        </ul>
                    </div>
                    <div class="dropdown flex-fill">
                        <button class="d-btn d-btn-outline d-btn-sm w-100 dropdown-toggle" id="btn-imprimir-ticket" type="button" data-bs-toggle="dropdown" disabled>
                            <i class="bi bi-receipt"></i> 80mm
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="DTE.print('ticket',{cedible:false});return false;"><i class="bi bi-receipt me-2"></i>Vale Tributario</a></li>
                            <li><a class="dropdown-item" href="#" onclick="DTE.print('ticket',{cedible:true});return false;"><i class="bi bi-receipt-cutoff me-2"></i>Vale Cedible</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Panel de resultados -->
<div id="panel-resultado" class="d-card mt-3" style="display:none">
    <div class="d-card-header"><i class="bi bi-terminal"></i> Consola de Resultados</div>
    <div class="d-card-body">
        <div id="alerta-resultado"></div>
        <div id="seccion-xml" style="display:none">
            <div style="display:flex; align-items:center; margin-bottom:6px">
                <strong style="font-size:.75rem; color:var(--c-text-muted)">XML FIRMADO</strong>
                <button class="d-btn d-btn-sm d-btn-outline" style="margin-left:auto" data-bs-toggle="collapse" data-bs-target="#xml-preview-wrap">Ver/Ocultar</button>
            </div>
            <div class="collapse" id="xml-preview-wrap">
                <pre id="xml-preview"></pre>
            </div>
        </div>
        <div id="seccion-estado" style="display:none">
            <hr style="border-color:var(--c-border)">
            <strong style="font-size:.75rem; color:var(--c-text-muted)">ESTADO SII:</strong>
            <p id="estado-sii" style="margin-top:6px; font-weight:600"></p>
        </div>
    </div>
</div>

<!-- Modal de Errores -->
<div class="modal fade" id="modalError" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="border:none; border-radius:var(--radius-lg); overflow:hidden">
      <div class="modal-header" style="background:var(--c-danger); color:#fff; border:none">
        <h5 class="modal-title"><i class="bi bi-exclamation-octagon-fill me-2"></i> Error</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <pre id="error-details" style="background:#0f172a; color:#fbbf24; padding:16px; border-radius:8px; font-size:.8rem; white-space:pre-wrap; word-break:break-all"></pre>
      </div>
    </div>
  </div>
</div>

<!-- Zona de impresión -->
<div id="zona-impresion" style="display:none"></div>
<span id="badge-ambiente" style="display:none"></span>

<script src="https://cdn.jsdelivr.net/npm/bwip-js"></script>
<script src="jscript.js?v=<?php echo time(); ?>"></script>
