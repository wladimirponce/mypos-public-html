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
        'G-4820753-1','G-4820753-2','G-4820753-3',
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

        if ($this->isSetBasicoRun($toRun)) {
            return $this->runSetBasico($state, $toRun);
        }

        $results = [];
        foreach ($toRun as $caseId) {
            $results[$caseId] = $this->runOneCase($caseId, $state);
            $this->saveState($state);
        }

        return $results;
    }

    private function isSetBasicoRun(array $caseIds): bool
    {
        if (count($caseIds) < 2) {
            return false;
        }
        foreach ($caseIds as $caseId) {
            if (!preg_match('/^F-\d+-\d+$/', (string)$caseId)) {
                return false;
            }
        }
        return true;
    }

    private function runSetBasico(array &$state, array $caseIds): array
    {
        usort($caseIds, function ($a, $b) {
            preg_match('/-(\d+)$/', (string)$a, $ma);
            preg_match('/-(\d+)$/', (string)$b, $mb);
            return ((int)($ma[1] ?? 0)) <=> ((int)($mb[1] ?? 0));
        });

        $results = [];
        $dtes = [];
        $tmpDir = $this->context->getTmpPath() . 'cert_basico/';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);

        // Folios FIJOS y REUTILIZABLES: cada caso usa SIEMPRE el mismo folio,
        // determinado por su posición FIJA dentro del set completo (no por el
        // lote que se reintente). Así un reintento parcial reutiliza exactamente
        // los mismos folios, sin consumirlos ni perderlos.
        $offsetMap   = $this->certFolioOffsetMap(); // caseId => offset dentro de su tipo
        $desdeByTipo = [];

        foreach ($caseIds as $caseId) {
            try {
                $caseData = $this->getUploadedCertCaseData($caseId, $state) ?? getCertCaseData($caseId);
                $tipoCaso = (int)($caseData['tipoDTE'] ?? $caseData['tipo'] ?? 33);
                if (!isset($desdeByTipo[$tipoCaso])) {
                    $cafInfo = loadCAF($tipoCaso, 0); // lanza si no hay CAF de ese tipo
                    $desdeByTipo[$tipoCaso] = (int)$cafInfo['desde'];
                }
                $offset = $offsetMap[$caseId]['offset'] ?? 0;
                $caseData['folio']         = $desdeByTipo[$tipoCaso] + $offset;
                $caseData['certNoConsume'] = true; // certificación: no consumir folios

                $dte = generateDTE($caseData);
                if (empty($dte['ok'])) {
                    throw new Exception($dte['error'] ?? 'Error generando DTE');
                }

                $dtes[] = ['xml' => $dte['xml'], 'tipo' => (int)$dte['tipo'], 'folio' => (int)$dte['folio']];
                file_put_contents($tmpDir . "dte_T{$dte['tipo']}F{$dte['folio']}.xml", $dte['xml']);

                $results[$caseId] = [
                    'status' => 'generated',
                    'tipo'   => (int)$dte['tipo'],
                    'folio'  => (int)$dte['folio'],
                    'ts'     => date('Y-m-d\TH:i:s'),
                ];
                $state['pruebas'][$caseId] = $results[$caseId];
            } catch (\Throwable $e) {
                $results[$caseId] = [
                    'status' => 'failed',
                    'tipo'   => null,
                    'folio'  => null,
                    'error'  => $e->getMessage(),
                    'ts'     => date('Y-m-d\TH:i:s'),
                ];
                $state['pruebas'][$caseId] = $results[$caseId];
                $this->saveState($state);
                return $results;
            }
        }

        try {
            $GLOBALS['SII_CERT_TIPO'] = 33;
            [$cert, $privKey] = loadCertificate(33);
            $sobre = buildEnvioDTESet($dtes, $cert);
            $sobreFirmado = signDTE($sobre, $cert, $privKey, 'SetDoc');
            file_put_contents($tmpDir . 'envio_set_basico.xml', $sobreFirmado);

            $val = validateXmlAgainstXSD($sobreFirmado);
            if (empty($val['valid']) && empty($val['skipped'])) {
                throw new Exception('El sobre no paso XSD local: ' . implode('; ', array_slice($val['errors'] ?? [], 0, 5)));
            }

            $semilla = getSemilla();
            $token = getToken($semilla, $cert, $privKey);
            $send = uploadDTE($sobreFirmado, $token);

            foreach ($caseIds as $i => $caseId) {
                $dte = $dtes[$i] ?? ['tipo' => null, 'folio' => null];
                if (!empty($dte['tipo']) && !empty($dte['folio'])) {
                    saveTrackingId((int)$dte['tipo'], (int)$dte['folio'], $send['trackId'] ?? null, $send, [
                        'certificacion_set' => 'basico',
                    ]);
                }
                $results[$caseId] = [
                    'status'  => !empty($send['ok']) ? 'ok' : 'failed',
                    'tipo'    => $dte['tipo'],
                    'folio'   => $dte['folio'],
                    'trackId' => $send['trackId'] ?? null,
                    'error'   => !empty($send['ok']) ? null : ($send['error'] ?? $send['mensaje'] ?? 'Error en envio SII'),
                    'ts'      => date('Y-m-d\TH:i:s'),
                ];
                $state['pruebas'][$caseId] = $results[$caseId];
            }
        } catch (\Throwable $e) {
            foreach ($caseIds as $i => $caseId) {
                $dte = $dtes[$i] ?? ['tipo' => null, 'folio' => null];
                $results[$caseId] = [
                    'status' => 'failed',
                    'tipo'   => $dte['tipo'] ?? null,
                    'folio'  => $dte['folio'] ?? null,
                    'error'  => $e->getMessage(),
                    'ts'     => date('Y-m-d\TH:i:s'),
                ];
                $state['pruebas'][$caseId] = $results[$caseId];
            }
        }

        $this->saveState($state);
        return $results;
    }

    /**
     * Mapa determinista de folios para el set básico: caseId => offset dentro de
     * su tipo de DTE, calculado por la posición FIJA del caso en el set completo
     * (no por el lote reintentado). Garantiza que cada caso reutilice siempre el
     * mismo folio (desde[tipo] + offset), incluso en reintentos parciales.
     *
     * @return array<string,array{tipo:int,offset:int}>
     */
    private function certFolioOffsetMap(): array
    {
        // Orden completo de casos: num => tipoDTE. Del set subido si existe.
        $orden  = [];
        $setMgr = new CertSetManager($this->context);
        $casos  = $setMgr->getFacturas();
        if (!empty($casos)) {
            foreach ($casos as $c) {
                if (preg_match('/-(\d+)$/', (string)($c['caso'] ?? ''), $m)) {
                    $orden[(int)$m[1]] = (int)($c['tipoDTE'] ?? 33);
                }
            }
        } else {
            // Fallback al set hardcodeado (4832043): 1-4 factura(33), 5-7 NC(61), 8 ND(56)
            foreach (range(1, 8) as $n) {
                try {
                    $data = getCertCaseData('F-4832043-' . $n);
                    $orden[$n] = (int)($data['tipoDTE'] ?? 33);
                } catch (\Throwable $e) { /* caso inexistente: omitir */ }
            }
        }
        ksort($orden);

        $map = [];
        $offsetByTipo = [];
        foreach ($orden as $num => $tipo) {
            $offsetByTipo[$tipo] = $offsetByTipo[$tipo] ?? 0;
            $map['F-4832043-' . $num] = ['tipo' => $tipo, 'offset' => $offsetByTipo[$tipo]];
            $offsetByTipo[$tipo]++;
        }
        return $map;
    }

    private function runOneCase(string $caseId, array &$state): array
    {
        $dte = ['ok' => false, 'tipo' => null, 'folio' => null]; // inicializar para preservar en catch
        try {
            $caseData = $this->getUploadedCertCaseData($caseId, $state) ?? getCertCaseData($caseId);
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

    private function getUploadedCertCaseData(string $caseId, array $state): ?array
    {
        if (!preg_match('/^F-\d+-(\d+)$/', $caseId, $m)) {
            return null;
        }

        $setMgr = new CertSetManager($this->context);
        $casos  = $setMgr->getFacturas();
        if (empty($casos)) {
            return null;
        }

        $num = (int)$m[1];
        $caso = null;
        foreach ($casos as $candidate) {
            if (preg_match('/-(\d+)$/', (string)($candidate['caso'] ?? ''), $cm) && (int)$cm[1] === $num) {
                $caso = $candidate;
                break;
            }
        }
        if (!$caso && isset($casos[$num - 1])) {
            $caso = $casos[$num - 1];
        }
        if (!$caso) {
            return null;
        }

        $tipoDte = (int)($caso['tipoDTE'] ?? 33);
        $items   = $caso['items'] ?? [];
        $data = [
            'tipoDTE'  => $tipoDte,
            'receptor' => $this->certReceptor($tipoDte),
            'items'    => $items,
        ];

        if (isset($caso['descuentoGlobal'])) {
            $data['descuentoGlobal'] = $caso['descuentoGlobal'];
        }

        if (!empty($caso['referencia']) && in_array($tipoDte, [56, 61], true)) {
            $ref = $this->buildUploadedReference($caso, $casos, $state);
            $data['referencias'] = [$ref];

            if (empty($data['items'])) {
                $data['items'] = $this->itemsForUploadedReference($ref, $caso, $casos);
            }
        }

        return $data;
    }

    private function certReceptor(int $tipoDte): array
    {
        return [
            'rut'       => in_array($tipoDte, [39, 41], true) ? '66666666-6' : '55555555-5',
            'nombre'    => 'EMPRESA DE PRUEBAS SII',
            'giro'      => 'GIRO DE PRUEBAS',
            'direccion' => 'CALLE PRUEBA 123',
            'comuna'    => 'SANTIAGO',
            'ciudad'    => 'SANTIAGO',
        ];
    }

    private function buildUploadedReference(array $caso, array $casos, array $state): array
    {
        $refInfo = $caso['referencia'] ?? [];
        $casoRef = (string)($refInfo['caso_ref'] ?? '');
        $razon = trim((string)($refInfo['razon'] ?? 'REFERENCIA SET DE PRUEBAS'));
        $tipoRef = $this->tipoForUploadedCase($casoRef, $casos);
        $folioRef = $this->folioForUploadedCase($casoRef, $state);

        return [
            'tipo'   => $tipoRef,
            'folio'  => $folioRef,
            'fecha'  => date('Y-m-d'),
            'codigo' => $this->codigoRefForRazon($razon),
            'razon'  => $razon !== '' ? $razon : 'REFERENCIA SET DE PRUEBAS',
        ];
    }

    private function tipoForUploadedCase(string $casoRef, array $casos): int
    {
        foreach ($casos as $candidate) {
            if ((string)($candidate['caso'] ?? '') === $casoRef) {
                return (int)($candidate['tipoDTE'] ?? 33);
            }
        }
        return 33;
    }

    private function folioForUploadedCase(string $casoRef, array $state): int
    {
        $key = $this->legacyKeyForUploadedCase($casoRef);
        $folio = (int)($state['pruebas'][$key]['folio'] ?? 0);
        return $folio > 0 ? $folio : 1;
    }

    private function legacyKeyForUploadedCase(string $casoRef): string
    {
        if (preg_match('/-(\d+)$/', $casoRef, $m)) {
            return 'F-4832043-' . (int)$m[1];
        }
        return 'F-4832043-1';
    }

    private function codigoRefForRazon(string $razon): int
    {
        $r = mb_strtoupper($razon, 'UTF-8');
        if (str_contains($r, 'ANULA')) {
            return 1;
        }
        if (str_contains($r, 'CORRIGE') || str_contains($r, 'GIRO') || str_contains($r, 'TEXTO')) {
            return 2;
        }
        return 3;
    }

    private function itemsForUploadedReference(array $ref, array $caso, array $casos): array
    {
        if ((int)($ref['codigo'] ?? 0) === 2) {
            return [[
                'nombre'   => mb_substr((string)($ref['razon'] ?? 'CORRIGE TEXTO'), 0, 80, 'UTF-8'),
                'cantidad' => 1,
                'precio'   => 0,
                'exento'   => true,
            ]];
        }

        $casoRef = (string)(($caso['referencia'] ?? [])['caso_ref'] ?? '');
        foreach ($casos as $candidate) {
            if ((string)($candidate['caso'] ?? '') === $casoRef && !empty($candidate['items'])) {
                return $candidate['items'];
            }
        }

        return [[
            'nombre'   => mb_substr((string)($ref['razon'] ?? 'AJUSTE REFERENCIA'), 0, 80, 'UTF-8'),
            'cantidad' => 1,
            'precio'   => 0,
            'exento'   => true,
        ]];
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

            // Factura de Compra Electrónica 9 — retención total IVA (cód. 6)
            ['tipo'=>46,'folio'=>9,'fecha'=>$fecha,'rut'=>$rutSII,'razon'=>$nomSII,
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
     * Selecciona los DTEs para las muestras impresas (set de pruebas + hasta 10
     * de simulación + set de boletas). Devuelve solo los XML; el render lo hace
     * el ÚNICO renderer (jscript.js → DTE.renderMuestras) — misma fuente de
     * verdad que la impresión real.
     * @return array<int,array{label:string,tipo:int,folio:int,xml:string}>
     */
    public function getMuestrasXmls(): array
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

        // Set de boletas de certificación: cert_boletas/dte_T39F*.xml
        $boletasDir = $tmpDir . 'cert_boletas/';
        if (is_dir($boletasDir)) {
            foreach (glob($boletasDir . 'dte_T*F*.xml') ?: [] as $bxml) {
                if (preg_match('/dte_T(\d+)F(\d+)\.xml$/', $bxml, $mm)) {
                    $dtes[] = [
                        'label' => 'BOLETA SET',
                        'tipo'  => (int)$mm[1],
                        'folio' => (int)$mm[2],
                        'xml'   => file_get_contents($bxml),
                    ];
                }
            }
        }

        return $dtes;
    }

    private function detectTipo(string $caseId): int
    {
        if (str_starts_with($caseId, 'B'))                      return 39;
        if (str_starts_with($caseId, 'G'))                      return 52;
        if (str_ends_with($caseId, '-5') || str_ends_with($caseId, '-6') || str_ends_with($caseId, '-7')) return 61;
        if (str_ends_with($caseId, '-8'))                       return 56;
        return 33;
    }


    // =========================================================
    //  CERTIFICACIÓN DE BOLETAS — Set en un sobre SII
    // =========================================================

    /**
     * Ejecuta TODO el flujo de certificación de boletas exigido por el SII:
     *   1. Toma los casos del Set de Pruebas vinculado a la empresa.
     *   2. Genera N boletas usando los folios del CAF en orden FIJO (desde..),
     *      SIN consumir folios ni avanzar el contador → reintentable indefinidamente
     *      sobre los mismos folios hasta que el SII apruebe.
     *   3. Arma UN solo sobre EnvioBOLETA y lo firma.
     *   4. Genera y firma el RCOF (ConsumoFolios) del set como respaldo local.
     *   5. Envía el sobre al SII (ambiente certificación) y devuelve su Track ID.
     *
     * Reintentar simplemente vuelve a llamar este método: regenera las mismas
     * boletas con los mismos folios (no pide folios nuevos).
     */
    public function certificarSetBoletas(): array
    {
        $setMgr  = new CertSetManager($this->context);
        $boletas = $setMgr->getBoletas();
        if (empty($boletas)) {
            throw new Exception('No hay un Set de Pruebas de boletas vinculado a la empresa. Súbalo primero en "Set de Certificación".');
        }

        // CAF de boletas (tipo 39). Folios fijos: desde .. desde+N-1 (no se consumen).
        $caf        = loadCAF(39, 0);
        $folioDesde = (int)$caf['desde'];
        $folioHasta = (int)$caf['hasta'];
        $disponibles = $folioHasta - $folioDesde + 1;
        $necesarios  = count($boletas);
        if ($disponibles < $necesarios) {
            throw new Exception("El CAF de boletas tiene $disponibles folio(s) pero el set requiere $necesarios. Cargue un CAF con al menos $necesarios folios.");
        }

        $GLOBALS['SII_CERT_TIPO'] = 39;
        [$cert, $privKey] = loadCertificate(39);

        $fecha  = date('Y-m-d');
        $tmpDir = $this->context->getTmpPath() . 'cert_boletas/';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);

        $dtesFirmados = [];
        $foliosUsados = [];
        $detalleCasos = [];
        $sumNeto = $sumIva = $sumExe = $sumTotal = 0;

        foreach (array_values($boletas) as $i => $caso) {
            $folio  = $folioDesde + $i;
            $items  = $caso['items'] ?? [];
            $montos = calcularMontos($items, 39);
            $idDte  = "T39F{$folio}";
            $ref    = !empty($caso['referencia']) ? [$caso['referencia']] : [];

            $xmlDoc = buildDocumentoXML(
                39, $folio, $fecha,
                ['rut' => '66666666-6', 'nombre' => 'Consumidor Final'],
                $items, $montos, $caf, $idDte, $privKey,
                0, 0, '', '', $ref, $caso['descuentoGlobal'] ?? null, null, null, 3
            );
            $xmlFirmado = signDTE($xmlDoc, $cert, $privKey, $idDte);
            file_put_contents($tmpDir . "dte_{$idDte}.xml", $xmlFirmado);

            $dtesFirmados[] = $xmlFirmado;
            $foliosUsados[] = $folio;
            $sumNeto  += $montos['mntNeto'];
            $sumIva   += $montos['iva'];
            $sumExe   += $montos['mntExe'];
            $sumTotal += $montos['mntTotal'];
            $detalleCasos[] = ['caso' => $caso['caso'] ?? "#$i", 'folio' => $folio, 'total' => $montos['mntTotal']];
        }

        // Sobre único + firma
        $sobre        = buildEnvioBoletaSet($dtesFirmados, $cert);
        $sobreFirmado = signDTE($sobre, $cert, $privKey, 'SetDoc');
        file_put_contents($tmpDir . 'envio_set_boletas.xml', $sobreFirmado);

        // Validación XSD local del sobre
        $val = validateXmlAgainstXSD($sobreFirmado);
        if (empty($val['valid']) && empty($val['skipped'])) {
            return [
                'ok'         => false,
                'error'      => 'El sobre no pasó la validación XSD local: ' . implode('; ', array_slice($val['errors'] ?? [], 0, 5)),
                'xsd_errors' => $val['errors'] ?? [],
            ];
        }

        // Enviar el sobre de boletas
        $envio = sendBoletaREST($sobreFirmado, 39, $folioDesde, '');

        // RCOF del set
        $rcofGen = generateRCOF([
            'fecha'     => $fecha,
            'secuencia' => 1,
            'resumenes' => [[
                'tipo' => 39, 'total' => $sumTotal, 'neto' => $sumNeto, 'iva' => $sumIva, 'exe' => $sumExe,
                'emitidos' => $necesarios, 'utilizados' => $necesarios, 'anulados' => 0,
                'rango_desde' => $folioDesde, 'rango_hasta' => $folioDesde + $necesarios - 1,
            ]],
        ]);
        file_put_contents($tmpDir . 'rcof_set_boletas.xml', $rcofGen['xml'] ?? '');
        $enviarRcof = getenv('SII_CERT_SEND_RCOF') === '1';
        $rcofEnvio = !empty($rcofGen['ok'])
            ? (
                $enviarRcof
                    ? sendRCOFToSII($rcofGen['xml'], $fecha, 1)
                    : [
                        'ok'      => true,
                        'skipped' => true,
                        'via'     => 'omitido',
                        'mensaje' => 'RCOF generado y guardado, no enviado: el SII informa que el RVD/RCOF no es obligatorio desde 2022-08-01.',
                    ]
            )
            : ['ok' => false, 'error' => $rcofGen['error'] ?? 'No se pudo generar el RCOF'];

        // Persistir estado para auditoría / reintento
        $state = $this->loadState();
        $state['boletas'] = [
            'ts'            => date('Y-m-d\TH:i:s'),
            'folios'        => $foliosUsados,
            'casos'         => $detalleCasos,
            'sobre_trackId' => $envio['trackId'] ?? null,
            'sobre_estado'  => $envio['estado'] ?? null,
            'sobre_ok'      => (bool)($envio['ok'] ?? false),
            'rcof_trackId'  => $rcofEnvio['trackId'] ?? null,
            'rcof_ok'       => (bool)($rcofEnvio['ok'] ?? false),
            'rcof_via'      => $rcofEnvio['via'] ?? null,
        ];
        $this->saveState($state);

        return [
            'ok'     => (bool)($envio['ok'] ?? false),
            'folios' => $foliosUsados,
            'sobre'  => [
                'trackId' => $envio['trackId'] ?? null,
                'estado'  => $envio['estado'] ?? null,
                'ok'      => (bool)($envio['ok'] ?? false),
                'mensaje' => $envio['mensaje'] ?? ($envio['error'] ?? ''),
            ],
            'rcof'   => [
                'trackId' => $rcofEnvio['trackId'] ?? null,
                'ok'      => (bool)($rcofEnvio['ok'] ?? false),
                'via'     => $rcofEnvio['via'] ?? null,
                'mensaje' => $rcofEnvio['mensaje'] ?? ($rcofEnvio['error'] ?? ''),
            ],
            'montos' => ['neto' => $sumNeto, 'iva' => $sumIva, 'exento' => $sumExe, 'total' => $sumTotal],
            'mensaje'=> 'Set de boletas enviado. Informe el Track ID del sobre en el portal SII. RCOF generado como respaldo local.',
        ];
    }
}
