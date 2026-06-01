<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Context;
use Exception;
use DOMDocument;

/**
 * Gestiona el proceso completo de certificación SII.
 *
 * Estado persistido en tmp/{rut}/cert_state.json para sobrevivir recargas y
 * permitir reintentar solo los casos fallidos sin repetir los exitosos.
 *
 * Etapas:
 *   1. Set de Pruebas   — casos hardcodeados por tipo de DTE
 *   2. Simulación       — 50 docs correlativos por tipo
 *   3. Intercambio      — responder XML enviado por SII
 *   4. Muestras HTML    — HTML imprimible con PDF417 para enviar al SII
 */
class CertificationManager
{
    private Context $context;
    private string  $statePath;

    /** Casos del Set de Pruebas — orden de ejecución */
    private const PRUEBAS_CASES = [
        'B-CASO-1','B-CASO-2','B-CASO-3','B-CASO-4','B-CASO-5',
        'F-4832043-1','F-4832043-2','F-4832043-3','F-4832043-4',
        'F-4832043-5','F-4832043-6','F-4832043-7','F-4832043-8',
    ];

    /** Tipos a simular y cantidad requerida */
    private const SIM_TIPOS    = [33, 39];
    private const SIM_CANTIDAD = 50;

    public function __construct(Context $context)
    {
        if ($context->getAmbiente() !== 'CERTIFICACION') {
            throw new Exception('El módulo de certificación solo está disponible en ambiente CERTIFICACION.');
        }
        $this->context   = $context;
        $this->statePath = $context->getTmpPath() . 'cert_state.json';
    }

    // =========================================================
    // STATE
    // =========================================================

    public function loadState(): array
    {
        if (!file_exists($this->statePath)) {
            return $this->defaultState();
        }
        $s = json_decode(file_get_contents($this->statePath), true);
        return is_array($s) ? $s : $this->defaultState();
    }

    private function saveState(array &$state): void
    {
        $state['updated'] = date('Y-m-d\TH:i:s');
        file_put_contents($this->statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function defaultState(): array
    {
        return [
            'rut'         => $this->context->getRut(),
            'started'     => date('Y-m-d\TH:i:s'),
            'updated'     => date('Y-m-d\TH:i:s'),
            'pruebas'     => [],
            'simulacion'  => [],
            'intercambio' => ['status' => 'pending'],
            'muestras'    => ['status' => 'pending'],
        ];
    }

    public function resetState(): array
    {
        $s = $this->defaultState();
        $this->saveState($s);
        return ['ok' => true, 'mensaje' => 'Estado de certificación reiniciado.'];
    }

    // =========================================================
    // RUN ALL
    // =========================================================

    /**
     * Ejecuta el pool completo: pruebas + simulación.
     * Solo ejecuta lo que está pending o failed; no repite ok.
     */
    public function runAll(): array
    {
        $state = $this->loadState();
        $log   = [];

        $log['pruebas']    = $this->runPruebas($state);

        foreach (self::SIM_TIPOS as $tipo) {
            $log["sim_$tipo"] = $this->runSimulacion($tipo, self::SIM_CANTIDAD, $state);
        }

        return ['ok' => true, 'resultados' => $log, 'estado' => $this->loadState()];
    }

    // =========================================================
    // SET DE PRUEBAS
    // =========================================================

    /**
     * Ejecuta casos del set de pruebas.
     * $caseIds vacío → todos los que no sean 'ok'.
     */
    public function runPruebas(array &$state = [], array $caseIds = []): array
    {
        if (empty($state)) $state = $this->loadState();

        $toRun = empty($caseIds)
            ? array_filter(self::PRUEBAS_CASES, fn($id) => ($state['pruebas'][$id]['status'] ?? 'pending') !== 'ok')
            : $caseIds;

        $results = [];
        foreach ($toRun as $caseId) {
            $results[$caseId] = $this->runOneCase($caseId, $state);
            $this->saveState($state);
        }

        return $results;
    }

    private function runOneCase(string $caseId, array &$state): array
    {
        $dte = ['ok' => false, 'tipo' => null, 'folio' => null]; // inicializar para preservar en catch
        try {
            $caseData = getCertCaseData($caseId);
            $dte      = generateDTE($caseData);

            if (empty($dte['ok'])) {
                throw new Exception($dte['error'] ?? 'Error generando DTE');
            }

            $send = sendDTE([
                'xml'   => $dte['xml'],
                'tipo'  => $dte['tipo'],
                'folio' => $dte['folio'],
            ]);

            $result = [
                'status'  => $send['ok'] ? 'ok' : 'failed',
                'tipo'    => $dte['tipo'],
                'folio'   => $dte['folio'],
                'trackId' => $send['trackId'] ?? null,
                'error'   => $send['ok'] ? null : ($send['error'] ?? 'Error en envío SII'),
                'ts'      => date('Y-m-d\TH:i:s'),
            ];
        } catch (\Throwable $e) {
            $result = [
                'status' => 'failed',
                'tipo'   => $dte['tipo'] ?? null,   // preservar folio/tipo si generateDTE ya corrió
                'folio'  => $dte['folio'] ?? null,
                'error'  => $e->getMessage(),
                'ts'     => date('Y-m-d\TH:i:s'),
            ];
        }

        $state['pruebas'][$caseId] = $result;
        return $result;
    }

    // =========================================================
    // SET DE SIMULACIÓN
    // =========================================================

    /**
     * Emite y envía los N docs de simulación para un tipo.
     * Si ya está 'ok', omite. Si está 'partial', continúa desde donde quedó.
     */
    public function runSimulacion(int $tipo, int $cantidad = self::SIM_CANTIDAD, array &$state = []): array
    {
        if (empty($state)) $state = $this->loadState();

        $key = "t$tipo";

        if (($state['simulacion'][$key]['status'] ?? '') === 'ok') {
            return ['skipped' => true, 'mensaje' => "Simulación tipo $tipo ya completada."];
        }

        $foliosOk     = $state['simulacion'][$key]['folios_ok']    ?? [];
        $foliosFailed = $state['simulacion'][$key]['folios_failed'] ?? [];
        $done         = count($foliosOk);
        $remaining    = $cantidad - $done;

        if ($remaining <= 0) {
            $state['simulacion'][$key]['status'] = 'ok';
            $this->saveState($state);
            return ['skipped' => true, 'mensaje' => "Simulación tipo $tipo ya tiene $done docs."];
        }

        $rutRec = in_array($tipo, [39, 41]) ? '66666666-6' : '55555555-5';

        for ($i = 0; $i < $remaining; $i++) {
            try {
                $dte = generateDTE([
                    'tipoDTE' => $tipo,
                    'receptor' => [
                        'rut'    => $rutRec,
                        'nombre' => 'Receptor Simulacion',
                        'giro'   => 'Giro Pruebas',
                    ],
                    'items' => [[
                        'nombre'   => 'Producto Simulacion ' . ($done + $i + 1),
                        'cantidad' => 1,
                        'precio'   => 1000 + $i,
                    ]],
                ]);

                if (empty($dte['ok'])) throw new Exception($dte['error'] ?? 'Error DTE');

                $send = sendDTE(['xml' => $dte['xml'], 'tipo' => $dte['tipo'], 'folio' => $dte['folio']]);

                if ($send['ok']) {
                    $foliosOk[] = $dte['folio'];
                } else {
                    $foliosFailed[] = ['folio' => $dte['folio'], 'error' => $send['error'] ?? '?'];
                }
            } catch (\Throwable $e) {
                $foliosFailed[] = ['folio' => null, 'error' => $e->getMessage()];
            }

            // Persistir progreso cada 10 documentos
            if (($i + 1) % 10 === 0) {
                $state['simulacion'][$key] = [
                    'status'       => 'running',
                    'tipo'         => $tipo,
                    'folios_ok'    => $foliosOk,
                    'folios_failed'=> $foliosFailed,
                    'ts'           => date('Y-m-d\TH:i:s'),
                ];
                $this->saveState($state);
            }
        }

        $allDone = count($foliosOk) >= $cantidad;
        $state['simulacion'][$key] = [
            'status'       => $allDone ? 'ok' : 'partial',
            'tipo'         => $tipo,
            'folios_ok'    => $foliosOk,
            'folios_failed'=> $foliosFailed,
            'ts'           => date('Y-m-d\TH:i:s'),
        ];
        $this->saveState($state);

        return [
            'ok'      => $allDone,
            'tipo'    => $tipo,
            'enviados'=> count($foliosOk),
            'fallidos'=> count($foliosFailed),
        ];
    }

    // =========================================================
    // RETRY
    // =========================================================

    public function retryFailed(): array
    {
        $state   = $this->loadState();
        $results = [];

        $failedCases = array_keys(array_filter(
            $state['pruebas'],
            fn($c) => ($c['status'] ?? '') === 'failed'
        ));

        if (!empty($failedCases)) {
            $results['pruebas'] = $this->runPruebas($state, $failedCases);
        }

        foreach (self::SIM_TIPOS as $tipo) {
            $key = "t$tipo";
            $st  = $state['simulacion'][$key]['status'] ?? 'pending';
            if (in_array($st, ['partial', 'failed'])) {
                $results["sim_$tipo"] = $this->runSimulacion($tipo, self::SIM_CANTIDAD, $state);
            }
        }

        return ['ok' => true, 'resultados' => $results, 'estado' => $this->loadState()];
    }

    // =========================================================
    // SET DE INTERCAMBIO
    // =========================================================

    /**
     * Recibe el XML de intercambio del SII, genera y envía la RespuestaDTE.
     * El XML puede pegarse en el formulario o subirse como archivo.
     */
    public function responderIntercambio(string $xmlIntercambio): array
    {
        $state = $this->loadState();

        // Guardar XML recibido para auditoría
        file_put_contents($this->context->getTmpPath() . 'intercambio_recibido.xml', $xmlIntercambio);

        // Parsear DTE recibido
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xmlIntercambio)) {
            throw new Exception('XML de intercambio inválido o malformado.');
        }

        $get = fn(string $tag) => $dom->getElementsByTagName($tag)->item(0)?->textContent ?? '';

        $tipoDte  = (int)$get('TipoDTE');
        $folio    = (int)$get('Folio');
        $fchEmis  = $get('FchEmis');
        $rutEmis  = $get('RUTEmisor');
        $rutRecep = $get('RUTRecep');
        $mntTotal = (int)$get('MntTotal');

        if (!$tipoDte || !$folio) {
            throw new Exception('No se pudo leer TipoDTE o Folio del XML de intercambio.');
        }

        [$cert, $privKey] = loadCertificate();
        $rutResponde = $this->context->getRut();
        $idResp      = 'Reslt1';
        $ts          = date('Y-m-d\TH:i:s');

        $xmlResp = <<<XML
<?xml version="1.0" encoding="ISO-8859-1"?>
<RespuestaDTE version="1.0" xmlns="http://www.sii.cl/SiiDte">
<Resultado ID="$idResp">
  <Caratula>
    <RutResponde>$rutResponde</RutResponde>
    <RutRecibe>$rutEmis</RutRecibe>
    <IdRespuesta>1</IdRespuesta>
    <NroDtesRecibidos>1</NroDtesRecibidos>
    <NmbEnvio>Respuesta Intercambio Certificacion</NmbEnvio>
    <FchRecepEnv>$ts</FchRecepEnv>
  </Caratula>
  <ResultadoDTE>
    <TipoDTE>$tipoDte</TipoDTE>
    <Folio>$folio</Folio>
    <FchEmis>$fchEmis</FchEmis>
    <RUTEmisor>$rutEmis</RUTEmisor>
    <RUTRecep>$rutRecep</RUTRecep>
    <MntTotal>$mntTotal</MntTotal>
    <CodEnvio>1</CodEnvio>
    <EstadoRecepDTE>0</EstadoRecepDTE>
    <RecepDTEGlosa>DTE Recibido OK</RecepDTEGlosa>
    <EstadoDTE>0</EstadoDTE>
    <GlosaDTE>Documento Aceptado</GlosaDTE>
  </ResultadoDTE>
</Resultado>
</RespuestaDTE>
XML;

        $xmlFirmado = signDTE($xmlResp, $cert, $privKey, $idResp);
        file_put_contents($this->context->getTmpPath() . 'intercambio_respuesta.xml', $xmlFirmado);

        $semilla = getSemilla();
        $token   = getToken($semilla, $cert, $privKey);
        $upload  = uploadDTE($xmlFirmado, $token);

        $state['intercambio'] = [
            'status'   => $upload['ok'] ? 'responded' : 'failed',
            'trackId'  => $upload['trackId'] ?? null,
            'dteInfo'  => "T{$tipoDte}F{$folio} de RUT $rutEmis",
            'error'    => $upload['ok'] ? null : ($upload['error'] ?? ''),
            'ts'       => $ts,
        ];
        $this->saveState($state);

        return [
            'ok'      => $upload['ok'],
            'trackId' => $upload['trackId'] ?? null,
            'mensaje' => $upload['mensaje'] ?? ($upload['ok'] ? 'Respuesta enviada al SII.' : 'Error enviando respuesta.'),
        ];
    }

    // =========================================================
    // LIBROS DE CERTIFICACIÓN
    // =========================================================

    /**
     * 4820751 — Libro de Ventas.
     * Construido con los DTEs del Set Básico ya emitidos (F-4820750-*).
     */
    public function runLibroVentas(): array
    {
        $state  = $this->loadState();
        $tmpDir = $this->context->getTmpPath();

        $casesVenta = [
            'F-4832043-1' => 33, 'F-4832043-2' => 33,
            'F-4832043-3' => 33, 'F-4832043-4' => 33,
            'F-4832043-5' => 61, 'F-4832043-6' => 61,
            'F-4832043-7' => 61, 'F-4832043-8' => 56,
        ];

        $detalles = [];
        $faltantes = [];

        foreach ($casesVenta as $caseId => $tipoDefault) {
            $info = $state['pruebas'][$caseId] ?? null;
            if (!$info || $info['status'] !== 'ok' || !$info['folio']) {
                $faltantes[] = $caseId;
                continue;
            }
            $tipo    = (int)($info['tipo'] ?? $tipoDefault);
            $folio   = (int)$info['folio'];
            $xmlFile = $tmpDir . "dte_T{$tipo}F{$folio}.xml";

            if (!file_exists($xmlFile)) { $faltantes[] = $caseId; continue; }

            $dom = new DOMDocument();
            @$dom->loadXML(file_get_contents($xmlFile));
            $g = fn(string $t) => $dom->getElementsByTagName($t)->item(0)?->textContent ?? '';

            $neto  = (int)$g('MntNeto');
            $iva   = (int)$g('IVA');
            $exe   = (int)$g('MntExe');
            $total = (int)$g('MntTotal');

            // NC/ND llevan montos negativos en el libro de ventas
            if (in_array($tipo, [61, 56])) {
                $neto  = -abs($neto);
                $iva   = -abs($iva);
                $exe   = -abs($exe);
                $total = -abs($total);
            }

            $detalles[] = [
                'tipo'  => $tipo,
                'folio' => $folio,
                'fecha' => $g('FchEmis'),
                'rut'   => $g('RUTRecep'),
                'razon' => $g('RznSocRecep'),
                'neto'  => $neto,
                'iva'   => $iva,
                'exe'   => $exe,
                'total' => $total,
            ];
        }

        if (!empty($faltantes)) {
            return ['ok' => false, 'error' => 'Faltan casos por generar antes de construir el libro: ' . implode(', ', $faltantes)];
        }

        $result = sendLibro([
            'tipoLibro' => 'VENTA',
            'tipoEnvio' => 'TOTAL',
            'periodo'   => date('Y-m'),
            'folioDsde' => 1,
            'detalles'  => $detalles,
        ]);

        $state['libros']['ventas'] = [
            'status'  => $result['ok'] ? 'ok' : 'failed',
            'trackId' => $result['trackId'] ?? null,
            'error'   => $result['ok'] ? null : ($result['error'] ?? ''),
            'ts'      => date('Y-m-d\TH:i:s'),
        ];
        $this->saveState($state);

        return $result;
    }

    /**
     * 4820754 — Libro de Guías.
     * Caso 1: traslado interno. Caso 2: facturada. Caso 3: anulada.
     */
    public function runLibroGuias(): array
    {
        $state  = $this->loadState();
        $tmpDir = $this->context->getTmpPath();

        // Definición de comportamiento especial de cada caso en el libro
        $casesConfig = [
            'G-4820753-1' => ['tpoOper' => 5, 'anulado' => 0],  // traslado interno
            'G-4820753-2' => ['tpoOper' => 1, 'anulado' => 0],  // venta, facturada en período
            'G-4820753-3' => ['tpoOper' => 1, 'anulado' => 2],  // venta, anulada
        ];

        $detalles  = [];
        $faltantes = [];

        foreach ($casesConfig as $caseId => $cfg) {
            $info = $state['pruebas'][$caseId] ?? null;
            if (!$info || $info['status'] !== 'ok' || !$info['folio']) {
                $faltantes[] = $caseId;
                continue;
            }
            $folio   = (int)$info['folio'];
            $xmlFile = $tmpDir . "dte_T52F{$folio}.xml";

            if (!file_exists($xmlFile)) { $faltantes[] = $caseId; continue; }

            $dom = new DOMDocument();
            @$dom->loadXML(file_get_contents($xmlFile));
            $g = fn(string $t) => $dom->getElementsByTagName($t)->item(0)?->textContent ?? '';

            $detalles[] = [
                'tipo'     => 52,
                'folio'    => $folio,
                'fecha'    => $g('FchEmis'),
                'rut'      => $g('RUTRecep'),
                'razon'    => $g('RznSocRecep'),
                'total'    => (int)$g('MntTotal'),
                'tpoOper'  => $cfg['tpoOper'],
                'anulado'  => $cfg['anulado'],
            ];
        }

        if (!empty($faltantes)) {
            return ['ok' => false, 'error' => 'Faltan casos por generar: ' . implode(', ', $faltantes)];
        }

        $result = sendLibro([
            'tipoLibro'         => 'GUIA',
            'tipoEnvio'         => 'TOTAL',
            'periodo'           => date('Y-m'),
            'folioDsde'         => 1,
            'folioNotificacion' => 4820754,
            'detalles'          => $detalles,
        ]);

        $state['libros']['guias'] = [
            'status'  => $result['ok'] ? 'ok' : 'failed',
            'trackId' => $result['trackId'] ?? null,
            'error'   => $result['ok'] ? null : ($result['error'] ?? ''),
            'ts'      => date('Y-m-d\TH:i:s'),
        ];
        $this->saveState($state);

        return $result;
    }

    /**
     * 4832045 — Libro de Compras.
     * Combina documentos en papel (hardcodeados del set oficial) con los
     * electrónicos recibidos. Factor proporcionalidad IVA uso común = 0.60.
     *
     * Datos exactos según archivo SIISetDePruebas777763210.txt:
     *   Factura papel  234  — neto 11.563 — crédito pleno
     *   FE             32   — neto 5.021, exento 8.299 — crédito pleno
     *   Factura papel  781  — neto 29.668 — IVA uso común (fct 0.60)
     *   NC papel       451  — neto -2.655 — descuento sobre f.234
     *   FE             67   — neto 9.383 — entrega gratuita (IVA no rec. cód. 5)
     *   FCA electrónica 9   — neto 9.253 — retención total IVA (cód. 6)
     *   NC papel       211  — neto -3.068 — descuento sobre FE 32
     */
    public function runLibroCompras(): array
    {
        $state  = $this->loadState();
        $fecha  = date('Y-m-d');

        // RUT del SII como proveedor de los docs en papel y FE de prueba
        $rutSII  = '60803000-K';
        $nomSII  = 'SII DE PRUEBAS';

        $detalles = [
            // Factura papel 234 — afecto crédito pleno
            ['tipo'=>33,'folio'=>234,'fecha'=>$fecha,'rut'=>$rutSII,'razon'=>$nomSII,
             'neto'=>11563,'iva'=>2197,'exe'=>0,'total'=>13760],

            // FE 32 — afecto + exento, crédito pleno
            ['tipo'=>33,'folio'=>32,'fecha'=>$fecha,'rut'=>$rutSII,'razon'=>$nomSII,
             'neto'=>5021,'iva'=>954,'exe'=>8299,'total'=>14274],

            // Factura papel 781 — IVA uso común, factor prop = 0.60
            ['tipo'=>33,'folio'=>781,'fecha'=>$fecha,'rut'=>$rutSII,'razon'=>$nomSII,
             'neto'=>29668,'iva'=>0,'exe'=>0,'total'=>35305,
             'codIvaNoRec'=>2,'mntIvaUsoComun'=>5637,'fctProp'=>0.60],

            // NC papel 451 — descuento sobre factura 234
            ['tipo'=>61,'folio'=>451,'fecha'=>$fecha,'rut'=>$rutSII,'razon'=>$nomSII,
             'neto'=>-2655,'iva'=>-504,'exe'=>0,'total'=>-3159],

            // FE 67 — entrega gratuita, IVA no recuperable (cód. 5)
            ['tipo'=>33,'folio'=>67,'fecha'=>$fecha,'rut'=>$rutSII,'razon'=>$nomSII,
             'neto'=>9383,'iva'=>0,'exe'=>0,'total'=>11166,
             'codIvaNoRec'=>5,'mntIvaNoRec'=>1783],

            // FCA electrónica 9 — retención total IVA (cód. 6)
            ['tipo'=>45,'folio'=>9,'fecha'=>$fecha,'rut'=>$rutSII,'razon'=>$nomSII,
             'neto'=>9253,'iva'=>0,'exe'=>0,'total'=>11011,
             'codIvaNoRec'=>6,'mntIvaNoRec'=>1758],

            // NC papel 211 — descuento sobre FE 32
            ['tipo'=>61,'folio'=>211,'fecha'=>$fecha,'rut'=>$rutSII,'razon'=>$nomSII,
             'neto'=>-3068,'iva'=>-583,'exe'=>0,'total'=>-3651],
        ];

        $result = sendLibro([
            'tipoLibro' => 'COMPRA',
            'tipoEnvio' => 'TOTAL',
            'periodo'   => date('Y-m'),
            'folioDsde' => 1,
            'detalles'  => $detalles,
        ]);

        $state['libros']['compras'] = [
            'status'  => $result['ok'] ? 'ok' : 'failed',
            'trackId' => $result['trackId'] ?? null,
            'error'   => $result['ok'] ? null : ($result['error'] ?? ''),
            'ts'      => date('Y-m-d\TH:i:s'),
        ];
        $this->saveState($state);

        return $result;
    }

    // =========================================================
    // MUESTRAS IMPRESAS
    // =========================================================

    /**
     * Genera HTML listo para imprimir a PDF desde el navegador.
     * Incluye todos los DTEs del set de pruebas + hasta 10 de simulación.
     * El código PDF417 del TED se renderiza con bwip-js en el cliente.
     */
    public function getMuestrasHtml(): string
    {
        $state  = $this->loadState();
        $tmpDir = $this->context->getTmpPath();
        $dtes   = [];

        // Set de Pruebas
        foreach (self::PRUEBAS_CASES as $caseId) {
            $info = $state['pruebas'][$caseId] ?? null;
            if (!$info || $info['status'] !== 'ok' || !$info['folio']) continue;
            $tipo    = (int)($info['tipo'] ?? $this->detectTipo($caseId));
            $xmlFile = $tmpDir . "dte_T{$tipo}F{$info['folio']}.xml";
            if (file_exists($xmlFile)) {
                $dtes[] = ['label' => $caseId, 'tipo' => $tipo, 'folio' => $info['folio'], 'xml' => file_get_contents($xmlFile)];
            }
        }

        // Simulación: hasta 10 docs representativos
        $simCount = 0;
        foreach (self::SIM_TIPOS as $tipo) {
            $key     = "t$tipo";
            $simInfo = $state['simulacion'][$key] ?? null;
            foreach (array_slice($simInfo['folios_ok'] ?? [], 0, 3) as $folio) {
                if ($simCount >= 10) break;
                $xmlFile = $tmpDir . "dte_T{$tipo}F{$folio}.xml";
                if (file_exists($xmlFile)) {
                    $dtes[] = ['label' => "SIM-T{$tipo}", 'tipo' => $tipo, 'folio' => $folio, 'xml' => file_get_contents($xmlFile)];
                    $simCount++;
                }
            }
        }

        if (empty($dtes)) {
            return '<html><body><p style="padding:40px;font-family:sans-serif">No hay DTEs generados aún. Ejecute primero el Set de Pruebas.</p></body></html>';
        }

        return $this->buildMuestrasHtml($dtes);
    }

    private function detectTipo(string $caseId): int
    {
        if (str_starts_with($caseId, 'B'))                      return 39;
        if (str_starts_with($caseId, 'G'))                      return 52;
        if (str_ends_with($caseId, '-5') || str_ends_with($caseId, '-6') || str_ends_with($caseId, '-7')) return 61;
        if (str_ends_with($caseId, '-8'))                       return 56;
        return 33;
    }

    private function buildMuestrasHtml(array $dtes): string
    {
        $tipoNombres = [
            33 => 'FACTURA ELECTRÓNICA',
            34 => 'FACTURA NO AFECTA O EXENTA',
            39 => 'BOLETA ELECTRÓNICA',
            41 => 'BOLETA NO AFECTA O EXENTA',
            52 => 'GUÍA DE DESPACHO ELECTRÓNICA',
            56 => 'NOTA DE DÉBITO ELECTRÓNICA',
            61 => 'NOTA DE CRÉDITO ELECTRÓNICA',
        ];

        $pages = '';
        foreach ($dtes as $item) {
            $dom = new DOMDocument();
            @$dom->loadXML($item['xml']);
            $g = fn(string $tag) => htmlspecialchars($dom->getElementsByTagName($tag)->item(0)?->textContent ?? '', ENT_QUOTES, 'UTF-8');

            // Construir filas de detalle
            $rows = '';
            foreach ($dom->getElementsByTagName('Detalle') as $det) {
                $gd  = fn(string $t) => htmlspecialchars($det->getElementsByTagName($t)->item(0)?->textContent ?? '', ENT_QUOTES, 'UTF-8');
                $rows .= "<tr><td>{$gd('NroLinDet')}</td><td>{$gd('NmbItem')}</td>"
                       . "<td style='text-align:right'>{$gd('QtyItem')}</td>"
                       . "<td style='text-align:right'>$ {$gd('PrcItem')}</td>"
                       . "<td style='text-align:right'>$ {$gd('MontoItem')}</td></tr>";
            }

            // TED para PDF417
            $tedNode = $dom->getElementsByTagName('TED')->item(0);
            $tedXml  = $tedNode ? $dom->saveXML($tedNode) : '';
            $tedJs   = json_encode($tedXml);
            $folio   = $item['folio'];

            $tipoNombre = $tipoNombres[$item['tipo']] ?? "DTE TIPO {$item['tipo']}";
            $label      = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');

            $neto = $g('MntNeto');
            $iva  = $g('IVA');
            $exe  = $g('MntExe');
            $tot  = $g('MntTotal');

            $totalesRows = '';
            if ($neto) $totalesRows .= "<tr><td>Neto</td><td style='text-align:right'>$ $neto</td></tr>";
            if ($iva)  $totalesRows .= "<tr><td>IVA 19%</td><td style='text-align:right'>$ $iva</td></tr>";
            if ($exe)  $totalesRows .= "<tr><td>Exento</td><td style='text-align:right'>$ $exe</td></tr>";
            $totalesRows .= "<tr class='tot'><td><strong>Total</strong></td><td style='text-align:right'><strong>$ $tot</strong></td></tr>";

            $pages .= <<<HTML
<div class="page">
  <div class="header">
    <div class="emisor">
      <strong>{$g('RznSoc')}</strong><br>
      RUT: {$g('RUTEmisor')}<br>
      {$g('GiroEmis')}<br>
      {$g('DirOrigen')}, {$g('CmnaOrigen')}, {$g('CiudadOrigen')}
    </div>
    <div class="tipo-box">
      <div class="tipo-nombre">$tipoNombre</div>
      <div class="folio-num">N° $folio</div>
      <div class="caso-label">CASO: $label</div>
      <div class="ambiente-badge">SII — CERTIFICACIÓN</div>
    </div>
  </div>
  <div class="recep">
    <strong>Señor(es):</strong> {$g('RznSocRecep')}&nbsp;&nbsp;|&nbsp;&nbsp;
    <strong>RUT:</strong> {$g('RUTRecep')}<br>
    <strong>Giro:</strong> {$g('GiroRecep')}&nbsp;&nbsp;|&nbsp;&nbsp;
    <strong>Dir:</strong> {$g('DirRecep')}, {$g('CmnaRecep')}, {$g('CiudadRecep')}<br>
    <strong>F. Emisión:</strong> {$g('FchEmis')}&nbsp;&nbsp;|&nbsp;&nbsp;
    <strong>F. Pago:</strong> {$g('FmaPago')}
  </div>
  <table class="items">
    <thead><tr><th>#</th><th>Descripción</th><th>Cant.</th><th>Precio Unit.</th><th>Total</th></tr></thead>
    <tbody>$rows</tbody>
  </table>
  <div class="footer-area">
    <div class="ted-area">
      <canvas id="ted-$folio"></canvas>
      <div class="ted-label">Timbre Electrónico SII (PDF417)</div>
      <script>
        (function(){
          var xml = $tedJs;
          if (xml) {
            bwipjs.toCanvas('ted-$folio', {bcid:'pdf417', text:xml, scale:2, height:14, includetext:false});
          }
        })();
      </script>
    </div>
    <div class="totales-area">
      <table class="totales">$totalesRows</table>
    </div>
  </div>
  <div class="resol">
    Resolución SII N° {$g('NroResol')} del {$g('FchResol')} |
    Emisión: {$g('FchEmis')} |
    Verifique en <em>www.sii.cl</em>
  </div>
</div>
HTML;
        }

        $css = <<<CSS
* { margin:0; padding:0; box-sizing:border-box; }
body { background:#ddd; font-family:Arial, sans-serif; font-size:8.5pt; color:#111; }
.topbar { position:sticky; top:0; z-index:100; background:#1a1a2e; color:#fff;
  padding:8px 20px; display:flex; align-items:center; gap:16px; }
.topbar strong { font-size:11pt; }
.topbar .count { background:#27ae60; padding:2px 10px; border-radius:12px; font-size:9pt; }
.topbar button { background:#e74c3c; color:#fff; border:none; padding:7px 20px;
  border-radius:4px; cursor:pointer; font-size:10pt; font-weight:bold; }
.page { background:#fff; width:21cm; margin:14px auto; padding:1.4cm 1.5cm;
  page-break-after:always; box-shadow:0 2px 8px rgba(0,0,0,.25); }
.header { display:flex; justify-content:space-between; gap:16px;
  border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:10px; }
.emisor { flex:1; line-height:1.6; }
.tipo-box { border:2px solid #000; padding:10px 14px; text-align:center; min-width:200px; }
.tipo-nombre { font-weight:bold; font-size:9pt; text-transform:uppercase; }
.folio-num { font-size:18pt; font-weight:bold; color:#c0392b; margin:4px 0; }
.caso-label { font-size:7.5pt; color:#555; background:#f8f8f8; padding:1px 6px; border-radius:3px; }
.ambiente-badge { font-size:7pt; color:#999; margin-top:4px; }
.recep { border:1px solid #bbb; padding:6px 10px; background:#fafafa;
  margin-bottom:10px; line-height:1.7; }
.items { width:100%; border-collapse:collapse; margin-bottom:12px; }
.items th, .items td { border:1px solid #ccc; padding:3px 6px; }
.items th { background:#f0f0f0; text-align:center; }
.footer-area { display:flex; gap:16px; margin-bottom:10px; align-items:flex-start; }
.ted-area { flex:0 0 auto; text-align:center; }
.ted-label { font-size:6.5pt; color:#888; margin-top:3px; }
.totales-area { flex:1; display:flex; justify-content:flex-end; }
.totales { border-collapse:collapse; min-width:180px; }
.totales td { padding:3px 10px; border:1px solid #ccc; }
.totales .tot { background:#f5f5f5; font-size:10pt; }
.resol { font-size:6.5pt; color:#888; text-align:center;
  border-top:1px dashed #ccc; padding-top:6px; }
@media print {
  body { background:#fff; }
  .topbar { display:none !important; }
  .page { box-shadow:none; margin:0; width:100%; }
}
CSS;

        $total = count($dtes);
        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Muestras Impresas DTE — Certificación SII</title>
<script src="https://cdn.jsdelivr.net/npm/bwip-js@latest/dist/bwip-js-min.js"></script>
<style>$css</style>
</head>
<body>
<div class="topbar">
  <strong>Muestras Impresas — Certificación SII</strong>
  <span class="count">$total documentos</span>
  <button onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
</div>
$pages
</body>
</html>
HTML;
    }
}
