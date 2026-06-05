/**
 * DTE — Emisor de Documentos Tributarios Electrónicos Chile
 * Lógica de frontend: formulario, cálculo de totales, llamadas a api.php
 */

const DTE = {

    // Estado de sesión actual
    state: {
        xml: null,
        folio: null,
        tipo: null,
        trackId: null,
        ambiente: 'CERTIFICACION',
        emisor: {},
        ted: null,
    },

    // ─── Inicialización ───────────────────────────────────────
    init() {
        const elFecha = document.getElementById('fechaEmision');
        if (elFecha) elFecha.value = todayISO();

        if (document.getElementById('tipoDTE')) {
            this.addItem();
            this.onTipoDTEChange();
            this.updateFolioHelp();
        }
        this.loadEmisorInfo();
    },

    loadEmisorInfo() {
        const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        fetch('api.php?action=info')
            .then(r => r.json())
            .then(d => {
                if (!d.ok) return;
                this.state.ambiente = d.ambiente;
                this.state.emisor = d;

                set('info-rut', d.rut);
                set('info-rs', d.razonSocial);
                set('info-giro', d.giro);
                set('info-dir', d.direccion);

                const badge = document.getElementById('badge-ambiente');
                if (badge) {
                    badge.textContent = d.ambiente;
                    badge.className = d.ambiente === 'PRODUCCION'
                        ? 'ms-auto badge fs-6 px-3 bg-success'
                        : 'ms-auto badge fs-6 px-3 bg-warning text-dark';
                }
            })
            .catch(() => {
                set('badge-ambiente', 'CONFIGURAR api.php');
            });
    },

    // ─── Cambio de tipo DTE ───────────────────────────────────
    onTipoDTEChange() {
        const tipo = parseInt(document.getElementById('tipoDTE').value);
        const esBoleta = [39, 41].includes(tipo);
        const esExportacion = [110, 111, 112].includes(tipo);
        const esExento = [34, 41].includes(tipo) || esExportacion;

        // Mostrar/ocultar campos de receptor según tipo
        document.querySelectorAll('.campo-factura').forEach(el => {
            el.style.display = esBoleta ? 'none' : '';
        });

        const dRowIva = document.getElementById('row-iva');
        const dRowExento = document.getElementById('row-exento');
        const dRowNeto = document.getElementById('row-neto');
        const dSecGuia = document.getElementById('seccion-guia');
        const dSecExportacion = document.getElementById('seccion-exportacion');

        if (dRowIva) dRowIva.style.display = esExento ? 'none' : '';
        if (dRowExento) dRowExento.style.display = esExento ? '' : 'none';
        if (dRowNeto) dRowNeto.style.display = esExento ? 'none' : '';
        if (dSecGuia) dSecGuia.style.display = (tipo === 52) ? '' : 'none';
        if (dSecExportacion) dSecExportacion.style.display = esExportacion ? '' : 'none';

        this.calculateTotals();
        this.resetState();
        this.updateFolioHelp();
    },

    async updateFolioHelp() {
        const tipo = document.getElementById('tipoDTE').value;
        const help = document.getElementById('folio-help');
        if (!help) return;

        try {
            const resp = await fetch(`api.php?action=next_folio&tipo=${tipo}`);
            const res = await resp.json();
            if (res.ok) {
                help.innerHTML = `Último: <strong>${res.last}</strong> | Sugerido: <strong>${res.next}</strong>`;
                help.className = "small mt-1 text-success opacity-75";
            } else {
                help.textContent = "Sin CAF cargado para este tipo.";
                help.className = "small mt-1 text-danger opacity-75";
            }
        } catch (e) {
            help.textContent = "";
        }
    },

    toggleMismoReceptor() {
        const switchMismo = document.getElementById('mismoReceptor');
        const isMismo = switchMismo.checked;
        const emisor = this.state.emisor;

        const fields = {
            rRut: emisor.rut || '',
            rNombre: emisor.razonSocial || '',
            rGiro: emisor.giro || '',
            rDireccion: emisor.direccion || '',
            rComuna: emisor.comuna || '',
            rCiudad: emisor.ciudad || '',
        };

        for (const [id, val] of Object.entries(fields)) {
            const el = document.getElementById(id);
            if (isMismo) {
                el.value = val;
                el.readOnly = true;
                el.classList.add('bg-light');
            } else {
                el.readOnly = false;
                el.classList.remove('bg-light');
            }
        }
    },

    // ─── Gestión de ítems ─────────────────────────────────────
    addItem() {
        const tbody = document.getElementById('items-body');
        const idx = tbody.rows.length + 1;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="text-center small pt-2 text-muted">${idx}</td>
            <td><input type="text"   class="form-control form-control-sm" placeholder="Nombre producto" oninput="DTE.resetState()"></td>
            <td><input type="text"   class="form-control form-control-sm" placeholder="Descripción" oninput="DTE.resetState()"></td>
            <td><input type="number" class="form-control form-control-sm item-qty"  value="1"   min="0.001" step="0.001" oninput="DTE.calculateTotals()"></td>
            <td><input type="number" class="form-control form-control-sm item-prc"  value="0"   min="0"               oninput="DTE.calculateTotals()"></td>
            <td><input type="number" class="form-control form-control-sm item-desc" value="0"   min="0" max="100"     oninput="DTE.calculateTotals()"></td>
            <td class="text-end"><span class="item-total fw-semibold small">$0</span></td>
            <td><button class="btn btn-outline-danger btn-sm px-1 py-0" onclick="DTE.removeItem(this)" title="Eliminar">×</button></td>`;

        tbody.appendChild(tr);
        this.calculateTotals();
    },

    removeItem(btn) {
        const tbody = document.getElementById('items-body');
        if (tbody.rows.length <= 1) return;
        btn.closest('tr').remove();
        // Renumerar
        [...tbody.rows].forEach((row, i) => {
            row.cells[0].textContent = i + 1;
        });
        this.calculateTotals();
        this.resetState();
    },

    // ─── Cálculo de totales ───────────────────────────────────
    calculateTotals() {
        const tipo = parseInt(document.getElementById('tipoDTE').value);
        const esExento = [34, 41, 110, 111, 112].includes(tipo);
        const esBoleta = (tipo === 39);

        let sumaItems = 0;

        document.querySelectorAll('#items-body tr').forEach(tr => {
            const qty = parseFloat(tr.querySelector('.item-qty')?.value || 1);
            const prc = parseFloat(tr.querySelector('.item-prc')?.value || 0);
            const desc = parseFloat(tr.querySelector('.item-desc')?.value || 0);
            const monto = Math.round(qty * prc * (1 - desc / 100));
            sumaItems += monto;
            const span = tr.querySelector('.item-total');
            if (span) span.textContent = fmt(monto);
        });

        let neto = 0, iva = 0, total = 0, exento = 0;

        if (esExento) {
            exento = sumaItems;
            total = sumaItems;
        } else if (esBoleta) {
            // En boleta afecta el precio ya incluye IVA
            neto = Math.round(sumaItems / 1.19);
            iva = sumaItems - neto;
            total = sumaItems;
        } else {
            neto = sumaItems;
            iva = Math.round(sumaItems * 0.19);
            total = neto + iva;
        }

        // ── Aplicar Descuento/Recargo Global (opcional) ──
        const dscTipo = document.getElementById('dscGlbTipo')?.value || '';
        const dscVal = parseFloat(document.getElementById('dscGlbValor')?.value || 0);
        const rowDsc = document.getElementById('row-dscglb');
        const dscLabel = document.getElementById('row-dscglb-label');
        const dscOut = document.getElementById('total-dscglb');
        let ajuste = 0;
        if (dscTipo && dscVal > 0) {
            // Calcular monto del ajuste sobre el bruto correspondiente
            const base = esExento ? exento : (esBoleta ? total : (neto + iva));
            const esPct = dscTipo.includes('%');
            const esDsc = dscTipo.startsWith('D');
            ajuste = esPct ? Math.round(base * dscVal / 100) : Math.round(dscVal);
            const signo = esDsc ? -1 : 1;
            ajuste = signo * ajuste;
            if (rowDsc) rowDsc.style.display = '';
            if (dscLabel) dscLabel.textContent = (esDsc ? 'Descuento' : 'Recargo') + ' Global' + (esPct ? ` (${dscVal}%)` : '');
            if (dscOut) dscOut.textContent = (ajuste < 0 ? '-' : '+') + '$' + Math.abs(ajuste).toLocaleString('es-CL');
            // Recomputar totales finales según afecto/exento/boleta
            if (esExento) {
                exento += ajuste; total += ajuste;
            } else if (esBoleta) {
                // El ajuste sale del bruto; ajustamos neto e IVA proporcional
                total += ajuste;
                neto = Math.round(total / 1.19);
                iva = total - neto;
            } else {
                neto += ajuste;
                iva = Math.round(neto * 0.19);
                total = neto + iva;
            }
        } else {
            if (rowDsc) rowDsc.style.display = 'none';
        }

        const dNeto = document.getElementById('total-neto');
        const dIva = document.getElementById('total-iva');
        const dExento = document.getElementById('total-exento');
        const dFinal = document.getElementById('total-final');

        if (dNeto) dNeto.textContent = fmt(neto);
        if (dIva) dIva.textContent = fmt(iva);
        if (dExento) dExento.textContent = fmt(exento);
        if (dFinal) dFinal.textContent = fmt(total);
    },

    getFormData() {
        const tipo = parseInt(document.getElementById('tipoDTE').value);
        const folio = parseInt(document.getElementById('folio').value) || 0;
        const fecha = document.getElementById('fechaEmision').value || todayISO();

        const esExportacion = [110, 111, 112].includes(tipo);
        const receptor = {
            rut: document.getElementById('rRut').value.trim() || ([39, 41].includes(tipo) ? '66666666-6' : (esExportacion ? '55555555-5' : '')),
            nombre: document.getElementById('rNombre').value.trim() || ([39, 41].includes(tipo) ? 'Consumidor Final' : (esExportacion ? 'RECEPTOR EXTRANJERO' : '')),
            giro: document.getElementById('rGiro').value.trim(),
            direccion: document.getElementById('rDireccion').value.trim(),
            comuna: document.getElementById('rComuna').value.trim(),
            ciudad: document.getElementById('rCiudad').value.trim(),
        };
        if (esExportacion) {
            receptor.extranjero = {
                numId: document.getElementById('exp_num_id')?.value.trim() || '',
                nacionalidad: document.getElementById('exp_nacionalidad')?.value.trim() || '',
            };
        }

        const items = [];
        document.querySelectorAll('#items-body tr').forEach(tr => {
            const inputs = tr.querySelectorAll('input');
            items.push({
                nombre: inputs[0]?.value.trim() || 'Producto',
                descripcion: inputs[1]?.value.trim() || '',
                cantidad: parseFloat(inputs[2]?.value || 1),
                precio: parseFloat(inputs[3]?.value || 0),
                descuento: parseFloat(inputs[4]?.value || 0),
            });
        });

        const indTraslado = (tipo === 52 && document.getElementById('indTraslado')) ? parseInt(document.getElementById('indTraslado').value) : 0;
        const tipoDespacho = ((tipo === 52 || esExportacion) && document.getElementById('tipoDespacho')) ? parseInt(document.getElementById('tipoDespacho').value) : 0;
        const patente = document.getElementById('patente')?.value.trim() || '';
        const rutTranspor = document.getElementById('rutTranspor')?.value.trim() || '';
        const observaciones = document.getElementById('observaciones')?.value.trim() || '';

        // ── Descuento / Recargo Global ──
        const dscTipo = document.getElementById('dscGlbTipo')?.value || '';
        const dscVal  = parseFloat(document.getElementById('dscGlbValor')?.value || 0);
        const dscGlosa = document.getElementById('dscGlbGlosa')?.value.trim() || '';
        let descuentoGlobal = null;
        if (dscTipo && dscVal > 0) {
            descuentoGlobal = {
                tipoMov:  dscTipo.startsWith('D') ? 'D' : 'R',
                tipoVal:  dscTipo.includes('%')   ? '%' : '$',
                valor:    dscVal,
                glosa:    dscGlosa || (dscTipo.startsWith('D') ? 'Descuento global' : 'Recargo global'),
            };
        }

        const payload = { tipoDTE: tipo, folio, fecha, receptor, items, indTraslado, tipoDespacho, patente, rutTranspor, observaciones, descuentoGlobal };
        if (esExportacion) {
            const tipoCambio = parseFloat(document.getElementById('exp_tipo_cambio')?.value || 0);
            payload.moneda = document.getElementById('exp_moneda')?.value || 'DOLAR USA';
            if (tipoCambio > 0) payload.tipoCambio = tipoCambio;
            payload.exportacion = {
                moneda: payload.moneda,
                formaPagoExportacion: parseInt(document.getElementById('exp_forma_pago')?.value || 0) || null,
                extranjero: receptor.extranjero
            };
            payload.aduana = {
                codModVenta: parseInt(document.getElementById('exp_mod_venta')?.value || 0) || null,
                codClauVenta: parseInt(document.getElementById('exp_clau_venta')?.value || 0) || null,
                codViaTransp: parseInt(document.getElementById('exp_via_transp')?.value || 0) || null,
                codPtoEmbarque: parseInt(document.getElementById('exp_pto_emb')?.value || 0) || null,
                codPaisDestin: parseInt(document.getElementById('exp_pais_destino')?.value || 0) || null
            };
        }
        return payload;
    },

    // ─── Proceso Completo Automatizado ────────────────────────
    async processAll() {
        const btn = document.getElementById('btn-procesar-todo');
        setLoading(btn, true);
        hideResult();

        try {
            // 1. Generar
            await this.generate();

            // 2. Enviar
            await this.send();

            // 3. Validar (esperar 2 seg para que el SII procese)
            await new Promise(r => setTimeout(r, 2000));
            await this.validate();

            // 4. Imprimir (Formato Carta por defecto)
            this.print('letter');

            showResult('success', `<i class="bi bi-check-all fs-4 me-2"></i> Documento <strong>${this.state.folio}</strong> emitido, verificado e impreso correctamente.`);

        } catch (e) {
            // El error ya es manejado por los métodos individuales (showErrorModal)
            console.error("Falla en proceso automatizado:", e);
        } finally {
            setLoading(btn, false);
        }
    },

    // ─── Generar DTE ──────────────────────────────────────────
    async generate() {
        const btn = document.getElementById('btn-generar');
        setLoading(btn, true);
        hideResult();

        try {
            const data = this.getFormData();
            const res = await apiCall('generate', data);

            this.state.xml = res.xml;
            this.state.folio = res.folio;
            this.state.tipo = res.tipo;

            // Extraer el TED para el código de barras PDF417
            const tedMatch = res.xml.match(/<TED.*?>[\s\S]*?<\/TED>/);
            this.state.ted = tedMatch ? tedMatch[0] : null;

            document.getElementById('seccion-xml').style.display = '';
            document.getElementById('seccion-estado').style.display = 'none';
            document.getElementById('xml-preview').textContent = res.xml;

            showResult('success',
                `<i class="bi bi-check-circle-fill me-1"></i>${res.mensaje} ` +
                `— Folio: <strong>${res.folio}</strong> | ` +
                `Total: <strong>${fmt(res.montos.mntTotal)}</strong>`
            );

            enableBtn('btn-enviar');
            enableBtn('btn-imprimir-carta');
            enableBtn('btn-imprimir-ticket');

        } catch (e) {
            this.showErrorModal(e);
            showResult('danger', `<i class="bi bi-x-circle-fill me-1"></i>${e.message}`);
        } finally {
            setLoading(btn, false);
        }
    },

    // ─── Enviar al SII ────────────────────────────────────────
    async send() {
        if (!this.state.xml) return;
        const btn = document.getElementById('btn-enviar');
        setLoading(btn, true);

        try {
            const res = await apiCall('send', {
                xml: this.state.xml,
                tipo: this.state.tipo,
                folio: this.state.folio,
            });

            this.state.trackId = res.trackId;

            showResult('success',
                `<i class="bi bi-cloud-check-fill me-1"></i>Enviado al SII.<br>` +
                `<strong>TrackID:</strong> ${res.trackId} &nbsp;|&nbsp; ` +
                `<strong>Estado:</strong> ${res.estado}`
            );
            enableBtn('btn-validar');

            // Verificación automática inmediata tras envío exitoso
            setTimeout(() => {
                this.validate();
            }, 1000);

        } catch (e) {
            showResult('danger', `<i class="bi bi-x-circle-fill me-1"></i>${e.message}`);
        } finally {
            setLoading(btn, false);
        }
    },

    // ─── Verificar estado SII ─────────────────────────────────
    async validate() {
        const btn = document.getElementById('btn-validar');
        setLoading(btn, true);

        try {
            const res = await apiCall('validate', {
                tipo: this.state.tipo,
                folio: this.state.folio,
                trackId: this.state.trackId,
            });

            const iconos = { DOK: '✅', EPR: '🔄', RCH: '❌', RPR: '⚠️', SOK: '✅' };
            const icono = iconos[res.estado] ?? 'ℹ️';
            const glosa = res.glosa || glosarioEstado(res.estado);

            document.getElementById('seccion-estado').style.display = '';
            document.getElementById('estado-sii').innerHTML =
                `${icono} <strong>${res.estado}</strong>: ${glosa}`;

            showResult('info',
                `<i class="bi bi-shield-check me-1"></i>Estado SII: ` +
                `<strong>${res.estado}</strong> — ${glosa}`
            );

        } catch (e) {
            showResult('danger', `<i class="bi bi-x-circle-fill me-1"></i>${e.message}`);
        } finally {
            setLoading(btn, false);
        }
    },

    // ─── Imprimir documento ───────────────────────────────────
    // Imprime desde el XML firmado generado (única fuente de verdad).
    // format: 'letter' | 'ticket' ; opts: { cedible: bool }
    print(format = 'letter', opts = {}) {
        if (!this.state.xml) {
            alert('Primero genere el documento.');
            return;
        }
        this.printFromXML(this.state.xml, format, opts);
    },

    async printXML(id, folio, tipo) {
        try {
            const res = await apiCall('get_xml', { tipo, folio });
            if (res.ok && res.xml) {
                this.printFromXML(res.xml);
            }
        } catch (e) {
            console.error('Error printXML:', e);
            alert('Error al obtener XML para impresión: ' + e.message);
        }
    },

    /**
     * Imprime un DTE en el formato pedido, según requisitos del Manual de
     * Muestras Impresas SII v4.0.
     *
     * @param {string} xmlText  XML firmado del DTE
     * @param {string} format   'letter' (hoja carta/oficio) | 'ticket' (POS 80mm)
     * @param {object} opts
     *   - cedible: bool — imprime la copia cedible con acuse de recibo
     *   - resolNum / resolFch / unidadSII: override de info de la resolución
     *                                       (si no se pasan, intenta leer de cache)
     */
    async printFromXML(xmlText, format = 'letter', opts = {}) {
        try {
            // Cargar info del emisor (resolución, unidad SII) si no vino en opts
            if (!opts.resolNum || !opts.unidadSII) {
                if (!this.state.emisorInfo) {
                    try {
                        const r = await fetch('api.php?action=info');
                        this.state.emisorInfo = await r.json();
                    } catch (e) { this.state.emisorInfo = {}; }
                }
                opts.resolNum  = opts.resolNum  || this.state.emisorInfo.resolNum  || 0;
                opts.resolFch  = opts.resolFch  || this.state.emisorInfo.resolFch  || '';
                opts.unidadSII = opts.unidadSII || this.state.emisorInfo.unidadSII || 'SII';
                opts.ambiente  = opts.ambiente  || this.state.emisorInfo.ambiente  || '';
            }

            const built = this.buildDocHtml(xmlText, format, { ...opts, canvasId: 'barcode-canvas' });
            if (!built) return;
            this.state.ted = built.ted;
            this.doPrint(built.html);
        } catch (e) {
            console.error('Error parseando XML para impresión:', e);
            alert('Error parseando documento para impresión: ' + e.message);
        }
    },

    /**
     * Parsea un DTE firmado a un contexto de render. ÚNICA fuente de verdad del
     * parseo, compartida por la impresión real y las muestras de certificación.
     * Sin efectos colaterales (no toca this.state).
     * @returns {{ctx:object, ted:?string, folio:string, tipo:number}}
     */
    parseDteCtx(xmlText, opts = {}) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(xmlText, "text/xml");

        const getText = (tagName, context = doc) => {
            let els = context.getElementsByTagName(tagName);
            if (els.length === 0) {
                els = context.getElementsByTagNameNS('*', tagName);
            }
            return els.length > 0 ? els[0].textContent : '';
        };

        const tipo = parseInt(getText('TipoDTE')) || 33;
        const folio = getText('Folio') || '—';
        let fecha = getText('FchEmis');
        if (fecha && fecha.includes('-')) {
            const p = fecha.split('-');
            if (p[0].length === 4) fecha = `${p[2]}-${p[1]}-${p[0]}`;
        }

        const emisor = {
            rut: getText('RUTEmisor'),
            razonSocial: getText('RznSoc'),
            giro: getText('GiroEmis'),
            acteco: getText('Acteco'),
            direccion: getText('DirOrigen'),
            comuna: getText('CmnaOrigen') || getText('CmnOrigen'),
            ciudad: getText('CiudadOrigen'),
            sucursal: getText('Sucursal')
        };

        const receptor = {
            rut: getText('RUTRecep'),
            nombre: getText('RznSocRecep'),
            direccion: getText('DirRecep'),
            comuna: getText('CmnaRecep') || getText('CmnRecep'),
            ciudad: getText('CiudadRecep'),
            giro: getText('GiroRecep')
        };

        const items = [];
        const detalles = doc.getElementsByTagName('Detalle');
        const detallesNS = doc.getElementsByTagNameNS('*', 'Detalle');
        const listaDetalles = detalles.length > 0 ? detalles : detallesNS;

        for (let i = 0; i < listaDetalles.length; i++) {
            const det = listaDetalles[i];
            items.push({
                nombre: getText('NmbItem', det),
                descripcion: getText('DscItem', det),
                cantidad: parseFloat(getText('QtyItem', det)) || 1,
                precio: parseFloat(getText('PrcItem', det)) || 0,
                descuento: parseFloat(getText('DescuentoPct', det)) || 0,
                total: parseFloat(getText('MontoItem', det)) || 0,
                exento: getText('IndExe', det) === '1'
            });
        }

        const num  = (tagName) => parseInt(getText(tagName)) || 0;

        const totales = {
            neto:     num('MntNeto'),
            exento:   num('MntExe'),
            tasaIVA:  parseFloat(getText('TasaIVA')) || 0,
            iva:      num('IVA'),
            total:    num('MntTotal'),
        };

        // Descuento/Recargo global
        const dscNodes = doc.getElementsByTagName('DscRcgGlobal');
        const dscGlobal = dscNodes.length > 0 ? {
            tipoMov:  getText('TpoMov',   dscNodes[0]),
            glosa:    getText('GlosaDR',  dscNodes[0]),
            tipoValor:getText('TpoValor', dscNodes[0]),
            valor:    parseFloat(getText('ValorDR', dscNodes[0])) || 0,
        } : null;

        // Referencias (para NC/ND)
        const refNodes = doc.getElementsByTagName('Referencia');
        const referencias = [];
        for (let i = 0; i < refNodes.length; i++) {
            const r = refNodes[i];
            referencias.push({
                tipo:    getText('TpoDocRef', r),
                folio:   getText('FolioRef',  r),
                fecha:   getText('FchRef',    r),
                codigo:  getText('CodRef',    r),
                razon:   getText('RazonRef',  r),
            });
        }

        const totalPalabras = this.numberToWords(totales.total);

        // Catálogo SII completo de nombres de documento (Manual de Muestras §1.1.4)
        const tipoNombre = {
            33: 'FACTURA ELECTRÓNICA',
            34: 'FACTURA NO AFECTA O EXENTA ELECTRÓNICA',
            39: 'BOLETA ELECTRÓNICA',
            41: 'BOLETA EXENTA ELECTRÓNICA',
            43: 'LIQUIDACIÓN FACTURA ELECTRÓNICA',
            46: 'FACTURA DE COMPRA ELECTRÓNICA',
            52: 'GUÍA DE DESPACHO ELECTRÓNICA',
            56: 'NOTA DE DÉBITO ELECTRÓNICA',
            61: 'NOTA DE CRÉDITO ELECTRÓNICA',
            110:'FACTURA DE EXPORTACIÓN ELECTRÓNICA',
            111:'NOTA DE DÉBITO DE EXPORTACIÓN ELECTRÓNICA',
            112:'NOTA DE CRÉDITO DE EXPORTACIÓN ELECTRÓNICA',
        }[tipo] || 'DTE';

        // Documentos que llevan acuse de recibo / cedible (Manual §1.4)
        //   Factura, Factura Exenta, Guía, Factura de Compra, Liq. Factura.
        //   NC/ND NO llevan acuse ni cedible. Boletas (39/41) tampoco.
        const tiposConCedible = [33, 34, 46, 43, 52];
        const llevaAcuse  = tiposConCedible.includes(tipo);
        const cedibleReal = llevaAcuse && opts.cedible;
        const cedibleLabel = (tipo === 52) ? 'CEDIBLE CON SU FACTURA' : 'CEDIBLE';

        // Extraer el TED para el código de barras
        const tedMatch = xmlText.match(/<TED[\s\S]*?<\/TED>/i);
        const ted = tedMatch ? tedMatch[0] : null;

        // Tabla completa de tipos de traslado SII (Manual §1.4)
        const trasladoGlosa = {
            1: 'Operación constituye venta',
            2: 'Ventas por efectuar',
            3: 'Consignaciones',
            4: 'Entrega gratuita',
            5: 'Traslados internos',
            6: 'Otros traslados no venta',
            7: 'Guía de devolución',
            8: 'Traslado para exportación (no venta)',
            9: 'Venta para exportación'
        };
        const despachoGlosa = {
            1: 'Por cuenta del receptor',
            2: 'Por cuenta del emisor',
            3: 'Por cuenta de un tercero'
        };

        const extra = {
            indTraslado:  parseInt(getText('IndTraslado')) || 0,
            tipoDespacho: parseInt(getText('TipoDespacho')) || 0,
            patente:      getText('Patente'),
            rutTranspor:  getText('RUTTrans'),
            rutChofer:    getText('RUTChofer'),
            nombreChofer: getText('NombreChofer'),
            trasladoGlosa,
            despachoGlosa,
        };

        const ctx = {
            tipo, folio, fecha, emisor, receptor, items,
            totales, dscGlobal, referencias,
            totalPalabras, tipoNombre, extra,
            opts, llevaAcuse, cedibleReal, cedibleLabel,
            canvasId: opts.canvasId || 'barcode-canvas',
        };

        return { ctx, ted, folio, tipo };
    },

    /**
     * Construye el HTML imprimible de UN documento (sin efectos colaterales).
     * Usa el MISMO renderer (renderLetter/renderTicket) que la impresión real.
     * @returns {{html:string, ted:?string, folio:string, tipo:number}|null}
     */
    buildDocHtml(xmlText, format = 'letter', opts = {}) {
        try {
            const parsed = this.parseDteCtx(xmlText, opts);
            const html = (format === 'ticket') ? this.renderTicket(parsed.ctx) : this.renderLetter(parsed.ctx);
            return { html, ted: parsed.ted, folio: parsed.folio, tipo: parsed.tipo };
        } catch (e) {
            console.error('Error construyendo HTML del documento:', e);
            return null;
        }
    },

    /**
     * Renderiza un LOTE de documentos (muestras de certificación) reutilizando
     * el mismo renderer de la impresión real — única fuente de verdad. Cada
     * documento lleva su propio timbre PDF417.
     * @param {Array<{xml:string,label?:string}>} list
     * @param {object} opts  { format, cedible, unidadSII, resolNum, resolFch, ambiente }
     */
    renderMuestras(list, opts = {}) {
        if (!Array.isArray(list) || !list.length) {
            alert('No hay documentos para generar muestras.');
            return;
        }
        const format = opts.format || 'letter';
        const zona = this.getPrintZone();
        let allHtml = '';
        const barcodes = [];
        list.forEach((it, i) => {
            const cid = 'ted-cv-' + i;
            const built = this.buildDocHtml(it.xml, format, { ...opts, canvasId: cid });
            if (!built) return;
            allHtml += `<div style="page-break-after:always">${built.html}</div>`;
            if (built.ted) barcodes.push({ cid, ted: built.ted });
        });
        if (!allHtml) { alert('No se pudo construir ninguna muestra.'); return; }
        zona.innerHTML = allHtml;
        zona.style.display = 'block';
        barcodes.forEach(b => this.renderBarcode(b.cid, b.ted));
        setTimeout(() => {
            window.print();
            setTimeout(() => { zona.style.display = 'none'; }, 1500);
        }, 700);
    },

    renderLetter(ctx) {
        const { tipo, folio, fecha, emisor, receptor, items, totales, dscGlobal,
                referencias, totalPalabras, tipoNombre, extra, opts,
                llevaAcuse, cedibleReal, cedibleLabel } = ctx;

        const fmtCLP = v => (v > 0 || v < 0) ? '$' + Math.round(v).toLocaleString('es-CL') : '$0';

        // ── Filas de detalle ──
        const filas = items.map((it, i) => `
            <tr>
                <td style="padding:4px 6px; border-bottom:1px solid #eee">${i + 1}</td>
                <td style="padding:4px 6px; border-bottom:1px solid #eee">${this._esc(it.nombre)}${it.descripcion ? ' — ' + this._esc(it.descripcion) : ''}${it.exento ? ' <small>(EXENTO)</small>' : ''}</td>
                <td style="padding:4px 6px; border-bottom:1px solid #eee; text-align:center">${it.cantidad}</td>
                <td style="padding:4px 6px; border-bottom:1px solid #eee; text-align:right">${fmtCLP(it.precio)}</td>
                <td style="padding:4px 6px; border-bottom:1px solid #eee; text-align:right">${it.descuento > 0 ? it.descuento + '%' : ''}</td>
                <td style="padding:4px 6px; border-bottom:1px solid #eee; text-align:right; font-weight:600">${fmtCLP(it.total)}</td>
            </tr>`).join('');

        // ── Referencias (NC/ND, Guía referenciada en factura, etc.) ──
        const tiposRef = {30:'Factura', 32:'Fact. Exenta', 33:'Factura Electrónica',
            34:'Factura Exenta Elec.', 35:'Boleta', 38:'Boleta Exenta', 39:'Boleta Electrónica',
            41:'Boleta Exenta Elec.', 43:'Liq. Factura Elec.', 46:'Factura Compra Elec.',
            50:'Guía Despacho', 52:'Guía Despacho Elec.', 56:'Nota Débito Elec.',
            61:'Nota Crédito Elec.', 110:'Factura Exportación', 111:'NotaDébitoExp', 112:'NotaCréditoExp'};
        const codRef = {1:'Anula documento', 2:'Corrige texto', 3:'Corrige montos'};
        const refsHtml = referencias.length === 0 ? '' : `
            <div style="margin-top:8px; border:1px solid #000; padding:6px; font-size:10px">
              <strong>Referencias:</strong>
              <table style="width:100%; margin-top:4px; font-size:10px">
                ${referencias.map(r => `<tr>
                  <td>${this._esc(tiposRef[parseInt(r.tipo)] || r.tipo || '—')}</td>
                  <td>Folio ${this._esc(r.folio)}</td>
                  <td>${this._esc(r.fecha)}</td>
                  <td>${r.codigo ? this._esc(codRef[parseInt(r.codigo)] || r.codigo) : ''}</td>
                  <td>${this._esc(r.razon || '')}</td>
                </tr>`).join('')}
              </table>
            </div>`;

        // ── Información de transporte (Guía Despacho 52) ──
        const transportInfo = (tipo === 52) ? `
            <div style="display:flex; gap:8px; margin-top:12px; font-size:10px; border:1px solid #000; padding:8px">
              <div style="flex:1">
                <strong>TIPO DE TRASLADO:</strong> ${this._esc(extra.trasladoGlosa[extra.indTraslado] || 'No especificado')}<br>
                <strong>TIPO DE DESPACHO:</strong> ${this._esc(extra.despachoGlosa[extra.tipoDespacho] || 'No especificado')}<br>
                <strong>DIRECCIÓN DESTINO:</strong> ${this._esc(receptor.direccion || '—')}, ${this._esc(receptor.comuna || '—')}
              </div>
              <div style="flex:1">
                <strong>PATENTE:</strong> ${this._esc(extra.patente || '—')}<br>
                <strong>RUT TRANSPORTISTA:</strong> ${this._esc(extra.rutTranspor || '—')}<br>
                <strong>CHOFER:</strong> ${this._esc(extra.nombreChofer || '—')} ${extra.rutChofer ? ' (' + this._esc(extra.rutChofer) + ')' : ''}
              </div>
            </div>` : '';

        // ── Tabla de totales (incluye Exento, Descuento Global, Tasa IVA dinámica) ──
        const filasTotales = [];
        if (totales.neto > 0)   filasTotales.push(['Monto Neto',   fmtCLP(totales.neto)]);
        if (totales.exento > 0) filasTotales.push(['Monto Exento', fmtCLP(totales.exento)]);
        if (totales.iva > 0)    filasTotales.push([`IVA (${totales.tasaIVA || 19}%)`, fmtCLP(totales.iva)]);
        if (dscGlobal && dscGlobal.valor > 0) {
            const sign = dscGlobal.tipoMov === 'D' ? '-' : '+';
            const lbl = (dscGlobal.glosa || (dscGlobal.tipoMov === 'D' ? 'Descuento Global' : 'Recargo Global'));
            const v = dscGlobal.tipoValor === '%' ? `${dscGlobal.valor}%` : fmtCLP(dscGlobal.valor);
            filasTotales.push([this._esc(lbl), `${sign}${v}`]);
        }
        const tablaTotales = `
            <table style="width:100%; font-size:12px; border-collapse:collapse">
              ${filasTotales.map(([l, v]) => `
                <tr>
                  <td style="padding:5px; border:1px solid #000">${l}</td>
                  <td style="padding:5px; border:1px solid #000; text-align:right">${v}</td>
                </tr>`).join('')}
              <tr>
                <td style="padding:6px; border:1px solid #000; background:#eaeaea"><strong style="font-size:14px">TOTAL</strong></td>
                <td style="padding:6px; border:1px solid #000; text-align:right; background:#eaeaea"><strong style="font-size:14px">${fmtCLP(totales.total)}</strong></td>
              </tr>
            </table>`;

        // ── Bloque acuse de recibo (solo cedible y solo en doc. con derecho) ──
        const acuseBlock = (cedibleReal && llevaAcuse) ? `
            <div style="margin-top:14px; border:1px solid #000; padding:8px; font-size:10px">
              <div style="font-weight:bold; margin-bottom:6px">Acuse de Recibo</div>
              <table style="width:100%; font-size:10px">
                <tr>
                  <td style="width:50%; padding:4px 0">Nombre: ____________________________________</td>
                  <td style="width:50%; padding:4px 0">R.U.T.: __________________</td>
                </tr>
                <tr>
                  <td style="padding:4px 0">Fecha: ____ / ____ / ________</td>
                  <td style="padding:4px 0">Recinto: __________________</td>
                </tr>
                <tr>
                  <td colspan="2" style="padding:8px 0">Firma: ___________________________________________</td>
                </tr>
              </table>
              <p style="font-size:9px; margin:4px 0 0 0; text-align:justify; line-height:1.3">
                El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la
                letra b) del Art. 4°, y la letra c) del Art. 5° de la Ley 19.983, acredita que la
                entrega de mercaderías o servicio (s) prestado (s) ha (n) sido recibido (s).
              </p>
            </div>` : '';

        // ── Etiqueta CEDIBLE / leyenda de ambiente certificación ──
        const cedibleTag = cedibleReal ? `
            <div style="position:absolute; bottom:8mm; right:10mm; font-weight:900; font-size:16px; color:#000; border:2px solid #000; padding:4px 14px">
              ${cedibleLabel}
            </div>` : '';

        const ambienteTag = (opts.ambiente === 'CERTIFICACION') ? `
            <div style="position:absolute; top:4mm; left:50%; transform:translateX(-50%); background:#fef3c7; border:1px solid #d97706; color:#92400e; font-weight:bold; padding:2px 12px; font-size:11px; letter-spacing:1px">
              DOCUMENTO DE CERTIFICACIÓN — SIN VALIDEZ TRIBUTARIA
            </div>` : '';

        // ── Resolución dinámica ──
        const resolStr = (opts.resolNum || opts.resolNum === 0 || opts.ambiente === 'CERTIFICACION')
            ? `Res. Ex. SII N° ${opts.resolNum || 0}` + (opts.resolFch ? ` del ${this._fechaAnio(opts.resolFch)}` : '')
            : '';

        const html = `
<div style="font-family:Arial,Helvetica,sans-serif; width:100%; max-width:215.9mm; margin:0 auto; color:#000; background:#fff; padding:10mm 12mm; box-sizing:border-box; position:relative; min-height:280mm">

  ${ambienteTag}

  <!-- Encabezado: Emisor (izq) + Recuadro tipo doc (der) -->
  <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px">
    <div style="width:55%">
      <div style="font-weight:900; font-size:16px; text-transform:uppercase; line-height:1.15">${this._esc(emisor.razonSocial)}</div>
      <div style="font-size:11px; margin-top:3px"><strong>Giro:</strong> ${this._esc(emisor.giro)}</div>
      <div style="font-size:11px"><strong>Casa Matriz:</strong> ${this._esc(emisor.direccion)}${emisor.comuna ? ', ' + this._esc(emisor.comuna) : ''}${emisor.ciudad ? ' — ' + this._esc(emisor.ciudad) : ''}</div>
      ${emisor.sucursal ? `<div style="font-size:11px"><strong>Sucursal:</strong> ${this._esc(emisor.sucursal)}</div>` : ''}
    </div>
    <!-- Recuadro tipo doc (rojo, 1.5×5.5cm mín / 4×8cm máx) -->
    <div style="width:75mm; border:0.7mm solid #f00; padding:8px 10px; text-align:center; color:#f00; align-self:flex-start; box-sizing:border-box">
      <div style="font-weight:bold; font-size:13px">R.U.T.: ${this._formatRut(emisor.rut)}</div>
      <div style="font-weight:900; font-size:14px; margin:4px 0; text-transform:uppercase; line-height:1.1">${this._esc(tipoNombre)}</div>
      <div style="font-weight:bold; font-size:18px">N° ${folio}</div>
    </div>
  </div>
  <!-- Unidad SII bajo el recuadro (alineada al recuadro) -->
  <div style="text-align:right; font-weight:bold; font-size:11px; margin-top:-6px; margin-bottom:10px; color:#000">
    ${this._esc(opts.unidadSII || 'S.I.I.')}
  </div>

  <!-- Datos receptor + fecha -->
  <div style="border:1px solid #000; padding:8px 10px; font-size:11px; margin-bottom:12px">
    <div style="display:flex">
      <div style="width:65%"><strong>Señor(es):</strong> ${this._esc(receptor.nombre)}</div>
      <div style="width:35%"><strong>R.U.T.:</strong> ${this._formatRut(receptor.rut)}</div>
    </div>
    <div style="display:flex; margin-top:3px">
      <div style="width:65%"><strong>Dirección:</strong> ${this._esc(receptor.direccion || '—')}</div>
      <div style="width:35%"><strong>Fecha emisión:</strong> ${this._esc(fecha)}</div>
    </div>
    <div style="display:flex; margin-top:3px">
      <div style="width:32%"><strong>Comuna:</strong> ${this._esc(receptor.comuna || '—')}</div>
      <div style="width:32%"><strong>Ciudad:</strong> ${this._esc(receptor.ciudad || '—')}</div>
      <div style="width:36%"><strong>Giro:</strong> ${this._esc(receptor.giro || '—')}</div>
    </div>
  </div>

  ${refsHtml}

  <!-- Detalle items -->
  <table style="width:100%; border-collapse:collapse; font-size:11px; margin-top:10px; margin-bottom:14px">
    <thead>
      <tr style="border-bottom:1.5px solid #000; border-top:1.5px solid #000">
        <th style="padding:6px; text-align:center; width:5%">#</th>
        <th style="padding:6px; text-align:left">Descripción</th>
        <th style="padding:6px; text-align:center; width:9%">Cant.</th>
        <th style="padding:6px; text-align:right; width:14%">P. Unitario</th>
        <th style="padding:6px; text-align:right; width:8%">Dcto.%</th>
        <th style="padding:6px; text-align:right; width:14%">Total</th>
      </tr>
    </thead>
    <tbody>${filas}</tbody>
  </table>

  ${transportInfo}

  <!-- Totales + Son: -->
  <div style="display:flex; justify-content:space-between; margin-top:14px">
    <div style="width:58%">
      <div style="text-transform:uppercase; font-weight:bold; font-size:10px; line-height:1.4">SON: ${this._esc(totalPalabras)}</div>
    </div>
    <div style="width:40%">
      ${tablaTotales}
    </div>
  </div>

  ${acuseBlock}

  <!-- TED a 2cm del borde izquierdo, abajo -->
  <div style="margin-top:18mm; display:flex; align-items:flex-end">
    <div style="margin-left:8mm; text-align:center">
      <canvas id="${ctx.canvasId || 'barcode-canvas'}" style="width:75mm; height:22mm; display:block"></canvas>
      <div style="font-size:8pt; font-weight:bold; margin-top:3px; text-align:center">Timbre Electrónico SII</div>
      <div style="font-size:7pt; margin-top:1px; text-align:center">${this._esc(resolStr)}</div>
      <div style="font-size:7pt; margin-top:1px; text-align:center">Verifique documento: www.sii.cl</div>
    </div>
  </div>

  ${cedibleTag}
</div>`;
        return html;
    },

    // ─── Helpers compartidos ───────────────────────────────
    _esc(s) {
        return String(s ?? '').replace(/[&<>"]/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    },
    _formatRut(rut) {
        if (!rut) return '—';
        const m = String(rut).trim().match(/^(\d+)-?([0-9kK])$/);
        if (!m) return this._esc(rut);
        const cuerpo = m[1].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return `${cuerpo}-${m[2].toUpperCase()}`;
    },
    _fechaAnio(f) {
        if (!f) return '';
        const m = String(f).match(/(\d{4})/);
        return m ? m[1] : f;
    },

    renderTicket(ctx) {
        const { tipo, folio, fecha, emisor, receptor, items, totales, dscGlobal,
                referencias, totalPalabras, tipoNombre, extra, opts,
                cedibleReal, cedibleLabel } = ctx;

        const fmtCLP = v => (v > 0 || v < 0) ? '$' + Math.round(v).toLocaleString('es-CL') : '$0';

        const itemsHtml = items.map(it => `
            <div style="margin-bottom:3px">
                <div style="display:flex; justify-content:space-between">
                    <div style="flex:1">${this._esc(it.nombre)}${it.exento ? ' (EX)' : ''}</div>
                    <div style="text-align:right; min-width:22mm">${fmtCLP(it.total)}</div>
                </div>
                <div style="font-size:9px; color:#000">${it.cantidad} x ${fmtCLP(it.precio)}${it.descuento > 0 ? '  Dcto ' + it.descuento + '%' : ''}</div>
            </div>`).join('');

        // Totales (Exento, IVA con tasa, Descuento global)
        const tLines = [];
        if (totales.neto > 0)   tLines.push(['NETO', fmtCLP(totales.neto)]);
        if (totales.exento > 0) tLines.push(['EXENTO', fmtCLP(totales.exento)]);
        if (totales.iva > 0)    tLines.push([`IVA ${totales.tasaIVA || 19}%`, fmtCLP(totales.iva)]);
        if (dscGlobal && dscGlobal.valor > 0) {
            const sign = dscGlobal.tipoMov === 'D' ? '-' : '+';
            const v = dscGlobal.tipoValor === '%' ? `${dscGlobal.valor}%` : fmtCLP(dscGlobal.valor);
            tLines.push([(dscGlobal.tipoMov === 'D' ? 'DCTO GLOBAL' : 'RECARGO GLOBAL'), sign + v]);
        }
        const totalesHtml = tLines.map(([l, v]) =>
            `<div style="display:flex; justify-content:space-between"><span>${l}</span><span>${v}</span></div>`).join('');

        // Referencias (NC/ND)
        const tiposRef = {33:'Factura Elec.', 39:'Boleta Elec.', 41:'Boleta Exenta',
            52:'Guía Desp. Elec.', 56:'Nota Débito', 61:'Nota Crédito'};
        const codRef = {1:'Anula', 2:'Corrige texto', 3:'Corrige montos'};
        const refsHtml = referencias.length === 0 ? '' : `
            <div style="border-top:1px dashed #000; padding-top:4px; margin-bottom:6px; font-size:9px">
              <strong>REFERENCIA:</strong><br>
              ${referencias.map(r =>
                `${this._esc(tiposRef[parseInt(r.tipo)] || r.tipo)} N°${this._esc(r.folio)} ${this._esc(r.fecha)}` +
                `${r.codigo ? ' — ' + this._esc(codRef[parseInt(r.codigo)] || r.codigo) : ''}` +
                `${r.razon ? '<br>' + this._esc(r.razon) : ''}`).join('<br>')}
            </div>`;

        // Traslado (guía)
        const trasladoHtml = (tipo === 52) ? `
            <div style="border-top:1px dashed #000; padding-top:4px; margin-bottom:6px; font-size:9px">
              <strong>TRASLADO:</strong> ${this._esc(extra.trasladoGlosa[extra.indTraslado] || '—')}<br>
              <strong>DESPACHO:</strong> ${this._esc(extra.despachoGlosa[extra.tipoDespacho] || '—')}<br>
              ${extra.patente ? '<strong>PATENTE:</strong> ' + this._esc(extra.patente) + '<br>' : ''}
              ${extra.rutTranspor ? '<strong>TRANSP:</strong> ' + this._esc(extra.rutTranspor) : ''}
            </div>` : '';

        const resolStr = (opts.resolNum || opts.resolNum === 0 || opts.ambiente === 'CERTIFICACION')
            ? `Res. Ex. SII N° ${opts.resolNum || 0}` + (opts.resolFch ? ` del ${this._fechaAnio(opts.resolFch)}` : '')
            : '';

        const ambienteTag = (opts.ambiente === 'CERTIFICACION') ?
            `<div style="text-align:center; border:1px solid #000; padding:2px; margin-bottom:4px; font-size:9px; font-weight:bold">DOCUMENTO DE CERTIFICACIÓN<br>SIN VALIDEZ TRIBUTARIA</div>` : '';

        // Ancho 80mm POS — contenido 72mm, TED con margen mínimo 2cm desde borde izq.
        const html = `
<div style="font-family:'Courier New',Courier,monospace; width:80mm; margin:0 auto; color:#000; background:#fff; padding:3mm; font-size:11px; box-sizing:border-box">
    ${ambienteTag}

    <!-- Recuadro tipo doc centrado con borde (Manual §1.1.7 papel continuo) -->
    <div style="border:0.7mm solid #000; padding:5px 6px; text-align:center; margin-bottom:5px">
        <div style="font-weight:bold; font-size:11px">R.U.T.: ${this._formatRut(emisor.rut)}</div>
        <div style="font-weight:900; font-size:12px; margin:2px 0; text-transform:uppercase; line-height:1.1">${this._esc(tipoNombre)}</div>
        <div style="font-weight:bold; font-size:15px">N° ${folio}</div>
    </div>
    <div style="text-align:center; font-weight:bold; font-size:10px; margin-bottom:6px">${this._esc(opts.unidadSII || 'S.I.I.')}</div>

    <!-- Datos emisor alineados izquierda -->
    <div style="font-size:10px; margin-bottom:5px; line-height:1.3">
        <div style="font-weight:bold; text-transform:uppercase">${this._esc(emisor.razonSocial)}</div>
        <div>Giro: ${this._esc(emisor.giro)}</div>
        <div>Casa Matriz: ${this._esc(emisor.direccion)}${emisor.comuna ? ', ' + this._esc(emisor.comuna) : ''}</div>
        ${emisor.sucursal ? `<div>Sucursal: ${this._esc(emisor.sucursal)}</div>` : ''}
    </div>

    <!-- Datos receptor alineados izquierda -->
    <div style="border-top:1px dashed #000; padding-top:4px; font-size:10px; margin-bottom:5px; line-height:1.3">
        <div>Señor(es): ${this._esc(receptor.nombre)}</div>
        <div>R.U.T.: ${this._formatRut(receptor.rut)}</div>
        ${receptor.giro ? `<div>Giro: ${this._esc(receptor.giro)}</div>` : ''}
        ${receptor.direccion ? `<div>Dirección: ${this._esc(receptor.direccion)}${receptor.comuna ? ', ' + this._esc(receptor.comuna) : ''}</div>` : ''}
        <div>Fecha emisión: ${this._esc(fecha)}</div>
    </div>

    ${refsHtml}
    ${trasladoHtml}

    <div style="border-top:1px dashed #000; padding-top:5px; margin-bottom:6px">
        ${itemsHtml}
    </div>

    <div style="border-top:1px solid #000; padding-top:5px; font-size:11px">
        ${totalesHtml}
        <div style="display:flex; justify-content:space-between; font-weight:bold; font-size:14px; margin-top:3px; border-top:1px solid #000; padding-top:3px">
            <span>TOTAL</span><span>${fmtCLP(totales.total)}</span>
        </div>
    </div>

    <div style="margin-top:6px; font-size:9px; text-transform:uppercase">SON: ${this._esc(totalPalabras)}</div>

    <!-- TED: a >=2cm del borde izq (margin-left 20mm sobre 80mm de papel) -->
    <div style="margin-top:8px; margin-left:8mm; text-align:center">
        <canvas id="${ctx.canvasId || 'barcode-canvas'}" style="width:60mm; height:20mm; display:block; margin:0 auto"></canvas>
        <div style="font-size:8px; font-weight:bold; margin-top:2px">Timbre Electrónico SII</div>
        <div style="font-size:7px">${this._esc(resolStr)}</div>
        <div style="font-size:7px">Verifique documento: www.sii.cl</div>
    </div>

    ${cedibleReal ? `<div style="text-align:right; font-weight:900; font-size:13px; margin-top:8px; border:1.5px solid #000; padding:3px 8px; display:inline-block; float:right">${cedibleLabel}</div><div style="clear:both"></div>` : ''}
</div>`;
        return html;
    },

    getPrintZone() {
        const zones = [...document.querySelectorAll('#zona-impresion')];
        let zona = zones.find(el => !el.closest('.dash-content')) || zones[zones.length - 1];

        if (!zona) {
            zona = document.createElement('div');
            zona.id = 'zona-impresion';
            zona.style.display = 'none';
            document.body.appendChild(zona);
        }

        zones.forEach(el => {
            if (el !== zona) {
                el.innerHTML = '';
                el.style.display = 'none';
            }
        });

        return zona;
    },

    doPrint(html) {
        const zona = this.getPrintZone();
        zona.innerHTML = html;
        zona.style.display = 'block';

        // Renderizar el código de barras si hay TED
        if (this.state.ted) {
            const rendered = this.renderBarcode('barcode-canvas', this.state.ted);
            if (!rendered) {
                zona.style.display = 'none';
                alert('No se pudo generar el timbre PDF417. Verifique que bwip-js esté cargado antes de imprimir.');
                return;
            }
        } else {
            zona.style.display = 'none';
            alert('El XML no contiene TED; no se puede imprimir una representación tributaria válida.');
            return;
        }

        // Esperar un breve momento para que el canvas se renderice antes de imprimir
        setTimeout(() => {
            window.print();
            setTimeout(() => { zona.style.display = 'none'; }, 1500);
        }, 500);
    },

    renderBarcode(canvasId, text) {
        try {
            if (typeof bwipjs === 'undefined' || !bwipjs.toCanvas) {
                throw new Error('bwip-js no está disponible.');
            }

            // El SII requiere que el contenido del PDF417 esté en ISO-8859-1.
            // Para asegurar que bwip-js procese los bytes correctos, usamos el truco de 
            // re-codificar el string UTF-8 a una representación binaria de un solo byte por caracter.
            const binaryText = unescape(encodeURIComponent(text));

            bwipjs.toCanvas(canvasId, {
                bcid: 'pdf417',    // Tipo de código
                text: binaryText,  // Datos binarizados
                scale: 3,           // Escala para nitidez
                rows: 5,            // Filas usadas por SimpleAPI/iTextSharp
                columns: 18,        // Columnas usadas por SimpleAPI/iTextSharp
                eclevel: 5,         // Nivel de corrección de error
                incltext: false,
            });
            return true;
        } catch (e) {
            console.error('Error renderBarcode:', e);
            return false;
        }
    },

    showErrorModal(err) {
        const details = document.getElementById('error-details');
        details.textContent = `${err.message}\n\nStack:\n${err.stack || 'No disponible'}`;
        const modal = new bootstrap.Modal(document.getElementById('modalError'));
        modal.show();
    },

    copyErrorToClipboard() {
        const text = document.getElementById('error-details').textContent;
        navigator.clipboard.writeText(text).then(() => {
            alert('Error copiado al cortapapeles');
        });
    },

    // Convierte un entero a palabras (sin sufijo). Uso interno.
    _numToWords(n) {
        const units    = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve'];
        const tens     = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
        const teens    = ['diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve'];
        const veinti    = ['veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];
        const hundreds = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

        if (n === 0) return '';
        if (n === 100) return 'cien';

        let w = '';
        if (n >= 1e6) {
            const mill = Math.floor(n / 1e6);
            w += (mill === 1 ? 'un millón ' : this._numToWords(mill) + ' millones ');
            n %= 1e6;
        }
        if (n >= 1000) {
            const mil = Math.floor(n / 1000);
            w += (mil === 1 ? 'mil ' : this._numToWords(mil) + ' mil ');
            n %= 1000;
        }
        if (n >= 100) {
            w += hundreds[Math.floor(n / 100)] + ' ';
            n %= 100;
        }
        if (n >= 30) {
            w += tens[Math.floor(n / 10)];
            if (n % 10 > 0) w += ' y ' + units[n % 10];
        } else if (n >= 20) {
            w += veinti[n - 20];
        } else if (n >= 10) {
            w += teens[n - 10];
        } else if (n > 0) {
            w += units[n];
        }
        return w.trim();
    },

    // Monto a palabras para representación impresa: "<NÚMERO> PESOS"
    numberToWords(n) {
        n = Math.round(Math.abs(parseInt(n) || 0));
        if (n === 0) return 'CERO PESOS';
        return (this._numToWords(n).toUpperCase() + ' PESOS').replace(/\s+/g, ' ').trim();
    },

    // ─── Resetear estado (cuando cambia el formulario) ────────
    resetState() {
        this.state.xml = null;
        this.state.trackId = null;
        document.getElementById('btn-enviar').disabled = true;
        document.getElementById('btn-validar').disabled = true;
        document.getElementById('btn-imprimir-carta').disabled = true;
        document.getElementById('btn-imprimir-ticket').disabled = true;
    },
};

// ────────────────────────────────────────────────────────────
// Utilidades
// ────────────────────────────────────────────────────────────

async function apiCall(action, data) {
    // Si action es un objeto, extraemos el nombre de la acción y lo movemos a data
    let actionName = action;
    if (typeof action === 'object' && action.action) {
        actionName = action.action;
        data = action;
    }

    const resp = await fetch(`api.php?action=${actionName}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });

    const text = await resp.text();
    let res;
    try {
        res = JSON.parse(text);
    } catch (e) {
        throw new Error(`Respuesta no válida del servidor (Posible error 500):\n\n${text.slice(0, 1000)}...`);
    }

    if (!res.ok) throw new Error(res.error || `Error en acción: ${action}`);
    return res;
}

function fmt(n) {
    return '$' + Math.round(n).toLocaleString('es-CL');
}

function todayISO() {
    return new Date().toISOString().slice(0, 10);
}

function setLoading(btn, loading) {
    if (!btn._origHTML) btn._origHTML = btn.innerHTML;
    btn.disabled = loading;
    btn.innerHTML = loading
        ? '<span class="spinner-border spinner-border-sm me-1"></span>Procesando…'
        : btn._origHTML;
}

function enableBtn(id) {
    document.getElementById(id).disabled = false;
}

function showResult(type, html) {
    document.getElementById('alerta-resultado').innerHTML =
        `<div class="alert alert-${type} mb-2 py-2">${html}</div>`;
    const panel = document.getElementById('panel-resultado');
    panel.style.display = '';
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function hideResult() {
    document.getElementById('alerta-resultado').innerHTML = '';
}

function glosarioEstado(estado) {
    return {
        DOK: 'Aceptado OK por el SII',
        EPR: 'Enviado al SII — en proceso',
        RCH: 'Rechazado por el SII',
        RPR: 'Aceptado con reparos',
        SOK: 'Sobre aceptado',
        FAU: 'Falla de autenticación',
    }[estado] || 'Estado desconocido';
}

// ─── Arranque ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => DTE.init());
