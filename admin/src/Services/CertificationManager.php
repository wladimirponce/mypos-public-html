<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Context;
use App\Repositories\EmpresaRepository;
use Exception;
use DOMDocument;
use DOMXPath;

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
    /** Casos del Set de Pruebas – generados dinámicamente */
    public function getPruebasCases(): array
    {
        $setMgr = new \App\Services\CertSetManager($this->context);
        $cases = [];
        foreach ($setMgr->getBoletas() as $b) {
            $cases[] = 'B-' . $b['caso'];
        }
        foreach ($setMgr->getFacturas() as $f) {
            $prefix = 'F-';
            if ($f['tipoDTE'] == 52) $prefix = 'G-';
            elseif ($f['tipoDTE'] == 43) $prefix = 'L-';
            elseif ($f['tipoDTE'] == 46) $prefix = 'C-';
            $cases[] = $prefix . $f['caso'];
        }
        return $cases;
    }

    /** Tipos a simular y cantidad requerida */
    private const SIM_TIPOS        = [33, 52, 56, 61];
    private const SIM_CANTIDADES   = [33 => 50, 52 => 1, 56 => 1, 61 => 1];
    private const SIM_CANTIDAD     = 50;   // Cantidad objetivo a intentar enviar
    /** Mínimo para considerar la simulación completa (exigencia SII para bajo volumen) */
    private const SIM_MIN_CANTIDAD = 10;
    /** Muestras exigidas por el upload SII para la etapa de simulación. */
    private const MUESTRAS_SIMULACION = [33 => 3, 52 => 1, 56 => 1, 61 => 1];
    /** DTE únicos exigidos por el set de prueba mostrado en el portal SII. */
    private const MUESTRAS_PRUEBA = [33 => 4, 52 => 3, 56 => 1, 61 => 3];
    private const TOTAL_PDFS_EXIGIDOS = 26; // 17 prueba + 9 simulación

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

    /** Versión pública de saveState para uso desde cert_bridge en casos de restauración. */
    public function saveStatePublic(array &$state): void
    {
        $this->saveState($state);
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

    /**
     * Genera el XML del caso caseId con el folio dado SIN enviarlo al SII.
     * Útil para diagnóstico del error cvc-id.2: ver exactamente qué XML se firma.
     *
     * @return array{ok:bool, folio_usado:int, dte_ids:array, envio_raw_ids:array,
     *               envio_firmado_ids:array, dte_xml:string, envio_raw_xml:string,
     *               envio_firmado_primeras_80:string, envio_firmado_linea_73:string,
     *               envio_firmado_contexto_60_80:string}
     */
    public function buildCaseDTEForDiag(string $caseId, int $folio = 12): array
    {
        $state    = $this->loadState();
        $caseData = $this->getUploadedCertCaseData($caseId, $state) ?? getCertCaseData($caseId);
        $caseData['certNoConsume'] = true;
        $caseData['folio']         = $folio;

        $dte = generateDTE($caseData);
        if (empty($dte['ok'])) {
            return ['ok' => false, 'error' => $dte['error'] ?? 'Error generando DTE'];
        }

        [$cert, $privKey] = loadCertificate((int)$dte['tipo']);
        $envioRaw     = buildEnvioDTE($dte['xml'], (int)$dte['tipo'], (int)$dte['folio'], $cert);
        $envioFirmado = signDTE($envioRaw, $cert, $privKey, 'SetDoc');

        // Los XML salen en ISO-8859-1 de signDTE. Convertir a UTF-8 para json_encode.
        $u8 = static function(string $s): string {
            return preg_match('//u', $s) ? $s : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        };

        $dteXmlU8  = $u8($dte['xml']);
        $envRawU8  = $u8($envioRaw);
        $envFirmU8 = $u8($envioFirmado);

        preg_match_all('/ ID="([^"]+)"/', $dteXmlU8,  $mDte);
        preg_match_all('/ ID="([^"]+)"/', $envRawU8,  $mRaw);
        preg_match_all('/ ID="([^"]+)"/', $envFirmU8, $mFirm);
        $lines = explode("\n", $envFirmU8);

        return [
            'ok'                            => true,
            'folio_usado'                   => $dte['folio'],
            'dte_ids'                       => $mDte[1],
            'envio_raw_ids'                 => $mRaw[1],
            'envio_firmado_ids'             => $mFirm[1],
            'dte_xml_primeras_80'           => implode("\n", array_slice(explode("\n", $dteXmlU8), 0, 80)),
            'envio_raw_primeras_80'         => implode("\n", array_slice(explode("\n", $envRawU8), 0, 80)),
            'envio_firmado_primeras_80'     => implode("\n", array_slice($lines, 0, 80)),
            'envio_firmado_linea_73'        => $lines[72] ?? '(no existe línea 73)',
            'envio_firmado_contexto_60_80'  => implode("\n", array_slice($lines, 59, 21)),
        ];
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
            $log["sim_$tipo"] = $this->runSimulacion($tipo, self::SIM_CANTIDADES[$tipo], $state);
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
            ? array_filter($this->getPruebasCases(), fn($id) => ($state['pruebas'][$id]['status'] ?? 'pending') !== 'ok')
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
            if (!preg_match('/^([FGLC])-\d+-\d+$/', (string)$caseId)) {
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

        // Folios NUEVOS en cada corrida. El SII deja "quemado" todo (Tipo, Folio)
        // que ya recibió: reenviar el mismo folio devuelve "DTE Repetido" (3-100)
        // o "Folio ya recibido" (3-101). Por eso ya NO se reutiliza: el folio base
        // por tipo = primer folio LIBRE (más alto ya usado + 1, tomado del state de
        // pruebas y de la BD) y AVANZA en cada corrida vía el state. NO se registra
        // en BD (certNoConsume=true): en cert el XML va en Latin-1 y json_encode del
        // payload falla (rompería el CHECK json_valid de documentos_emitidos), y los
        // DTE de prueba no deben ensuciar la tabla de documentos reales.
        $offsetMap  = $this->certFolioOffsetMap(); // caseId => offset dentro de su tipo
        $baseByTipo = [];

        foreach ($caseIds as $caseId) {
            try {
                $caseData = $this->getUploadedCertCaseData($caseId, $state) ?? getCertCaseData($caseId);
                $tipoCaso = (int)($caseData['tipoDTE'] ?? $caseData['tipo'] ?? 33);
                if (!isset($baseByTipo[$tipoCaso])) {
                    $baseByTipo[$tipoCaso] = $this->certFolioBaseLibre($tipoCaso, $state);
                }
                $offset = $offsetMap[$caseId]['offset'] ?? 0;
                $caseData['folio']         = $baseByTipo[$tipoCaso] + $offset;
                $caseData['certNoConsume'] = true; // no escribir en BD; el folio nuevo avanza vía el state

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
                $this->preservarFolioAnterior($state, $caseId, (int)$dte['folio']);
                $state['pruebas'][$caseId] = $results[$caseId];
            } catch (\Throwable $e) {
                $results[$caseId] = [
                    'status' => 'failed',
                    'tipo'   => null,
                    'folio'  => null,
                    'error'  => $e->getMessage(),
                    'ts'     => date('Y-m-d\TH:i:s'),
                ];
                $this->preservarFolioAnterior($state, $caseId, null);
                $state['pruebas'][$caseId] = $results[$caseId];
                $this->saveState($state);
                return $results;
            }
        }

        try {
            $GLOBALS['SII_CERT_TIPO'] = 33;
            [$cert, $privKey] = loadCertificate(33);
            $sobre = buildEnvioDTESet($dtes, $cert);
            $sobreFirmado = signDTE($sobre, $cert, $privKey, 'FENV010');
            file_put_contents($tmpDir . 'envio_set_basico.xml', $sobreFirmado);

            $val = validateXmlAgainstXSD($sobreFirmado);
            if (empty($val['valid']) && empty($val['skipped'])) {
                throw new Exception('El sobre no paso XSD local: ' . implode('; ', array_slice($val['errors'] ?? [], 0, 5)));
            }

            $semilla = getSemilla();
            $token = getToken($semilla, $cert, $privKey);
            $send = uploadDTE($sobreFirmado, $token, $cert);

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
     * Primer folio LIBRE para un tipo de DTE en certificación: el más alto ya
     * usado + 1. "Ya usado" combina MAX(folio) en sii_dte (durable: sobrevive a
     * "Reiniciar") con el folio más alto registrado en el state de pruebas (cubre
     * la primera corrida tras activar el consumo, cuando sii_dte todavía no tiene
     * los folios viejos). Así nunca se repite un (Tipo, Folio) que el SII ya recibió.
     */
    private function certFolioBaseLibre(int $tipoDte, array $state): int
    {
        $repo = new EmpresaRepository();
        $cafs = $repo->getCAFsActivos($this->context->getEmpresaId(), $tipoDte, $this->context->getAmbiente());
        if (empty($cafs)) {
            throw new Exception(
                "No hay CAF activo para tipo $tipoDte en ambiente "
                . $this->context->getAmbiente() . '. Cargue el CAF correspondiente en Configuracion.'
            );
        }

        // Iterar todos los CAFs (ordenados por folio_desde ASC) hasta encontrar uno
        // con folios disponibles — considera tanto la DB como los ya asignados en state.
        foreach ($cafs as $caf) {
            $desde = (int)$caf['folio_desde'];
            $hasta = (int)$caf['folio_hasta'];

            // Folio más alto ya emitido (durable) dentro del rango del CAF.
            $hw = $repo->getUltimoFolioUsadoEnRango(
                $this->context->getEmpresaId(), $tipoDte, $this->context->getAmbiente(), $desde, $hasta
            );

            // Folio más alto que este flujo ya asignó en corridas previas (incluye los
            // reutilizados antes de activar el consumo real, que aún no están en sii_dte).
            foreach (($state['pruebas'] ?? []) as $prueba) {
                if ((int)($prueba['tipo'] ?? 0) === $tipoDte) {
                    $f = (int)($prueba['folio'] ?? 0);
                    if ($f >= $desde && $f <= $hasta) {
                        $hw = max($hw, $f);
                    }
                }
            }

            // Folios quemados por la simulación: en CERTIFICACION generateDTE no
            // registra en sii_dte, así que solo el state los conoce. Los failed
            // también cuentan — un corte de red al leer la respuesta no garantiza
            // que el SII no haya recibido el DTE.
            $sim = $state['simulacion']['t' . $tipoDte] ?? [];
            $foliosSim = array_merge(
                $sim['folios_ok'] ?? [],
                array_column($sim['folios_failed'] ?? [], 'folio')
            );
            foreach ($foliosSim as $f) {
                $f = (int)$f;
                if ($f >= $desde && $f <= $hasta) {
                    $hw = max($hw, $f);
                }
            }

            $base = max($desde, $hw + 1);
            if ($base <= $hasta) {
                return $base;   // Este CAF tiene folios disponibles — usarlo
            }
            // CAF agotado: probar el siguiente en la lista
        }

        // Todos los CAFs agotados — reportar el último
        $last  = end($cafs);
        $desde = (int)$last['folio_desde'];
        $hasta = (int)$last['folio_hasta'];
        $hw    = $repo->getUltimoFolioUsadoEnRango(
            $this->context->getEmpresaId(), $tipoDte, $this->context->getAmbiente(), $desde, $hasta
        );
        throw new Exception(
            "CAF de certificación tipo $tipoDte agotado (todos los rangos usados, último rango $desde-$hasta, último folio $hw). "
            . 'Cargue un nuevo CAF de certificación para continuar.'
        );
    }

    /**
     * Mapa determinista de offsets para el set básico: caseId => offset dentro de
     * su tipo de DTE, calculado por la posición FIJA del caso en el set completo
     * (no por el lote reintentado). El folio final = certFolioBaseLibre(tipo) +
     * offset, recalculado en cada corrida para tomar folios nuevos sin repetir.
     *
     * @return array<string,array{tipo:int,offset:int}>
     */
    public function certFolioOffsetMap(): array
    {
        // BUG FIX: El array $orden NO debe usar el sufijo numérico ($n) como clave.
        // Cuando hay dos sets con casos del mismo número (ej: 4884260-1 y 4884263-1,
        // ambos con sufijo n=1), el segundo sobrescribía al primero → casos perdidos
        // del mapa → todos caían a offset=0 → folios duplicados → cvc-id.2.
        // Solución: construir el mapa directamente con caseId como clave, ordenando
        // los casos por sufijo numérico ANTES de asignar offsets.
        $setMgr = new CertSetManager($this->context);
        $casos  = $setMgr->getFacturas();

        $map          = [];
        $offsetByTipo = [];

        if (!empty($casos)) {
            // Ordenar por sufijo numérico para folios consecutivos dentro de cada tipo
            usort($casos, static function ($a, $b) {
                preg_match('/-(\d+)$/', (string)($a['caso'] ?? ''), $ma);
                preg_match('/-(\d+)$/', (string)($b['caso'] ?? ''), $mb);
                return (int)($ma[1] ?? 0) <=> (int)($mb[1] ?? 0);
            });

            foreach ($casos as $c) {
                $tipo = (int)($c['tipoDTE'] ?? 33);
                $pfx  = match(true) {
                    $tipo === 52 => 'G',
                    $tipo === 43 => 'L',
                    $tipo === 46 => 'C',
                    default      => 'F',
                };
                $caseId = $pfx . '-' . $c['caso'];
                $offsetByTipo[$tipo] ??= 0;
                $map[$caseId] = ['tipo' => $tipo, 'offset' => $offsetByTipo[$tipo]++];
            }
        } else {
            // Fallback al set 4832043 cuando no hay set subido
            foreach (range(1, 8) as $n) {
                try {
                    $cid  = 'F-4832043-' . $n;
                    $data = getCertCaseData($cid);
                    $tipo = (int)($data['tipoDTE'] ?? 33);
                    $offsetByTipo[$tipo] ??= 0;
                    $map[$cid] = ['tipo' => $tipo, 'offset' => $offsetByTipo[$tipo]++];
                } catch (\Throwable $e) { /* caso inexistente: omitir */ }
            }
        }

        return $map;
    }

    private function tipoDteForSetBasicoCase(int $caseNumber, int $detected): int
    {
        // Usar el tipo real del set si está disponible (ya no sobreescribir con posición)
        if ($detected > 0) return $detected;
        // Fallback posicional solo cuando no hay tipo detectado (set 4832043)
        if ($caseNumber >= 1 && $caseNumber <= 4) return 33;
        if ($caseNumber >= 5 && $caseNumber <= 7) return 61;
        if ($caseNumber === 8) return 56;
        return 33;
    }

    /**
     * Preserva el folio anterior de un caso como phantom antes de sobreescribirlo.
     * El state guarda solo el ÚLTIMO folio por caseId: sin esto, los folios de
     * corridas previas desaparecen del historial y certFolioBaseLibre puede
     * reasignarlos (pasó con T61: el folio 10 quemado fue reelegido después de
     * que la corrida siguiente sobreescribiera el caso con folio 14).
     */
    private function preservarFolioAnterior(array &$state, string $caseId, $nuevoFolio): void
    {
        $prev = $state['pruebas'][$caseId] ?? null;
        if (!$prev || empty($prev['folio']) || empty($prev['tipo'])) return;
        if ($nuevoFolio !== null && (int)$prev['folio'] === (int)$nuevoFolio) return;
        $phKey = "__hw_t{$prev['tipo']}_f{$prev['folio']}__";
        if (isset($state['pruebas'][$phKey])) return;
        $state['pruebas'][$phKey] = [
            'tipo'    => (int)$prev['tipo'],
            'folio'   => (int)$prev['folio'],
            'status'  => 'ok',
            'trackId' => $prev['trackId'] ?? null,
            'error'   => null,
            'ts'      => date('Y-m-d\TH:i:s'),
            'note'    => "phantom: folio de corrida anterior preservado al reasignar {$caseId}",
        ];
    }

    private function runOneCase(string $caseId, array &$state): array
    {
        $dte = ['ok' => false, 'tipo' => null, 'folio' => null]; // inicializar para preservar en catch
        try {
            $caseData = $this->getUploadedCertCaseData($caseId, $state) ?? getCertCaseData($caseId);
            // Nunca registrar en BD durante certificación: el XML va en Latin-1 y
            // json_encode del payload rompería el CHECK json_valid de documentos_emitidos.
            // Consistente con runSetBasico() que ya lo hace.
            $caseData['certNoConsume'] = true;
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

        $this->preservarFolioAnterior($state, $caseId, $result['folio'] ?? null);
        $state['pruebas'][$caseId] = $result;
        return $result;
    }

    private function getUploadedCertCaseData(string $caseId, array $state): ?array
    {
        if (!preg_match('/^([FGLC])-\d+-(\d+)$/', $caseId, $m)) {
            return null;
        }

        $setMgr = new CertSetManager($this->context);
        $casos  = $setMgr->getFacturas();
        if (empty($casos)) {
            return null;
        }

        $num = (int)$m[2];
        $prefix = $m[1];
        
        $caso = null;
        foreach ($casos as $candidate) {
            $candidatePrefix = 'F';
            if ($candidate['tipoDTE'] == 52) $candidatePrefix = 'G';
            elseif ($candidate['tipoDTE'] == 43) $candidatePrefix = 'L';
            elseif ($candidate['tipoDTE'] == 46) $candidatePrefix = 'C';

            if ($candidatePrefix === $prefix && preg_match('/-(\d+)$/', (string)($candidate['caso'] ?? ''), $cm) && (int)$cm[1] === $num) {
                $caso = $candidate;
                break;
            }
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
            // Referencia al SET de pruebas: el revisor del SII identifica cada caso
            // por <TpoDocRef>SET</TpoDocRef> + <RazonRef>CASO {AT}-{N}</RazonRef>.
            // Sin ella el set completo se rechaza con "El Documento No Esta en el
            // Envio" aunque los DTE estén aceptados. FolioRef (= folio del propio
            // documento) se completa en el builder XML, donde el folio ya existe.
            'referencias' => [[
                'tipo'  => 'SET',
                'razon' => 'CASO ' . (string)($caso['caso'] ?? ''),
            ]],
        ];

        if (isset($caso['descuentoGlobal'])) {
            $data['descuentoGlobal'] = $caso['descuentoGlobal'];
        }

        // Guías de Despacho: IndTraslado y TipoDespacho según motivo del caso
        if ($tipoDte === 52 && !empty($caso['motivo'])) {
            $motivo      = strtolower($caso['motivo']);
            $trasladoPor = strtolower($caso['trasladoPor'] ?? '');
            if (strpos($motivo, 'interno') !== false || strpos($motivo, 'bodega') !== false) {
                // Traslado interno: receptor = propio emisor (RUT igual al emisor)
                $data['indTraslado'] = 5;
                $data['receptor'] = [
                    'rut'       => $this->context->getRut(),
                    'nombre'    => 'EMPRESA DE PRUEBAS SII',
                    'giro'      => 'GIRO DE PRUEBAS',
                    'direccion' => 'CALLE PRUEBA 123',
                    'comuna'    => 'SANTIAGO',
                    'ciudad'    => 'SANTIAGO',
                ];
                // La tabla del traslado interno del set SII solo trae CANTIDAD
                // (la guía va SIN valores). Si el parser confundió columnas
                // (cantidades correlativas 1,2,3.. y la cantidad real quedó como
                // precio), reinterpretar: cantidad = "precio" leído, precio = 0.
                // Reparo SII que esto corrige: "Los Valores ... No Cuadran".
                $itemsInt   = array_values($data['items'] ?? []);
                $confundido = !empty($itemsInt);
                foreach ($itemsInt as $i => $it) {
                    if ((int)($it['cantidad'] ?? 0) !== $i + 1 || (int)($it['precio'] ?? 0) <= 0) {
                        $confundido = false;
                        break;
                    }
                }
                if ($confundido) {
                    foreach ($itemsInt as $i => $it) {
                        $itemsInt[$i]['nombre']   = 'ITEM ' . ($i + 1);
                        $itemsInt[$i]['cantidad'] = (int)$it['precio'];
                        $itemsInt[$i]['precio']   = 0;
                    }
                    $data['items'] = $itemsInt;
                }
            } else {
                // Venta. TipoDespacho según quién traslada (reparo SII "Los
                // Indicadores (Despacho/Traslado) No Corresponden" con el mapeo
                // anterior): 1=por cuenta del receptor (cliente), 2=por cuenta
                // del emisor a instalaciones del cliente, 3=por cuenta del
                // emisor a otras instalaciones. OJO: evaluar 'emisor' primero,
                // porque "EMISOR ... AL LOCAL DEL CLIENTE" también dice cliente.
                $data['indTraslado'] = 1;
                if (strpos($trasladoPor, 'emisor') !== false) {
                    $data['tipoDespacho'] = 2;
                } else {
                    $data['tipoDespacho'] = 1; // traslada el cliente
                }
            }
        }

        if (!empty($caso['referencia']) && in_array($tipoDte, [56, 61], true)) {
            $ref = $this->buildUploadedReference($caso, $casos, $state);
            $data['referencias'][] = $ref;

            if (empty($data['items']) || $this->itemsRequireReferencePrices($data['items'], $ref)) {
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
        $razon   = trim((string)($refInfo['razon'] ?? 'REFERENCIA SET DE PRUEBAS'));
        // Fallback posicional solo cuando el set no trae caso_ref explícito
        if (empty($casoRef) && preg_match('/-(\d+)$/', (string)($caso['caso'] ?? ''), $mCaso)) {
            $numCaso = (int)$mCaso[1];
            if (in_array($numCaso, [5, 6, 7], true)) {
                $casoRef = preg_replace('/-\d+$/', '-' . ($numCaso - 4), (string)($caso['caso'] ?? '')) ?: '';
            } elseif ($numCaso === 8) {
                $casoRef = preg_replace('/-\d+$/', '-5', (string)($caso['caso'] ?? '')) ?: '';
            }
        }
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
        if (empty($casoRef)) return 'F-' . $casoRef;
        // Buscar en el set subido para obtener el prefijo correcto según tipoDTE
        $setMgr = new CertSetManager($this->context);
        foreach ($setMgr->getFacturas() as $c) {
            if ((string)($c['caso'] ?? '') === $casoRef) {
                $tipo = (int)($c['tipoDTE'] ?? 33);
                $pfx  = match(true) {
                    $tipo === 52 => 'G',
                    $tipo === 43 => 'L',
                    $tipo === 46 => 'C',
                    default      => 'F',
                };
                return $pfx . '-' . $casoRef;
            }
        }
        // Fallback: prefijo F- (T33/T61/T56 del set 4832043)
        return 'F-' . $casoRef;
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
        if (str_contains($r, 'DEVOLUCION') || str_contains($r, 'DEVOLUCIÓN') || str_contains($r, 'MERCADERIA') || str_contains($r, 'MERCADERÍA')) {
            return 3;
        }
        return 2;
    }

    private function itemsForUploadedReference(array $ref, array $caso, array $casos): array
    {
        // NC de texto (CodRef=2, ej: corrige giro): UNA línea descriptiva AFECTA
        // con monto 0. El revisor SII rechaza la línea si va marcada exenta
        // ("Los Valores de la Linea 1 No Cuadran"); SimpleAPI usa Afecto=true.
        if ((int)($ref['codigo'] ?? 0) === 2) {
            return [[
                'nombre'   => mb_substr((string)($ref['razon'] ?? 'CORRIGE TEXTO'), 0, 80, 'UTF-8'),
                'cantidad' => 0, // sin QtyItem: solo NmbItem + MontoItem 0
                'precio'   => 0,
                'exento'   => false,
            ]];
        }

        $casoRef = (string)(($caso['referencia'] ?? [])['caso_ref'] ?? '');
        foreach ($casos as $candidate) {
            if ((string)($candidate['caso'] ?? '') !== $casoRef) {
                continue;
            }
            // El caso referenciado es una NC de texto sin ítems (ej: ND caso 8
            // que anula la NC caso 5): la ND replica la línea descriptiva de la
            // NC (su razón de referencia), afecta y con monto 0.
            if (empty($candidate['items'])) {
                $razonCand = (string)((($candidate['referencia'] ?? [])['razon'])
                    ?? ($ref['razon'] ?? 'REFERENCIA SET DE PRUEBAS'));
                return [[
                    'nombre'   => mb_substr($razonCand, 0, 80, 'UTF-8'),
                    'cantidad' => 0, // sin QtyItem: solo NmbItem + MontoItem 0
                    'precio'   => 0,
                    'exento'   => false,
                ]];
            }
            $currentItems = $caso['items'] ?? [];
            if (!empty($currentItems)) {
                $priced = [];
                foreach (array_values($currentItems) as $idx => $item) {
                    $refItem = $candidate['items'][$idx] ?? [];
                    $priced[] = array_merge($item, [
                        'precio' => (int)($item['precio'] ?? 0) > 0
                            ? (int)$item['precio']
                            : (int)($refItem['precio'] ?? 0),
                        // Heredar el descuento de la línea original: una NC de
                        // devolución debe replicar precio Y descuento de la
                        // factura referenciada (reparo "Valores No Cuadran").
                        'descuento' => (int)($item['descuento'] ?? 0) > 0
                            ? (int)$item['descuento']
                            : (int)($refItem['descuento'] ?? 0),
                        'exento' => $item['exento'] ?? ($refItem['exento'] ?? false),
                    ]);
                }
                return $priced;
            }
            return $candidate['items'];
        }

        return [[
            'nombre'   => mb_substr((string)($ref['razon'] ?? 'AJUSTE REFERENCIA'), 0, 80, 'UTF-8'),
            'cantidad' => 0, // sin QtyItem: solo NmbItem + MontoItem 0
            'precio'   => 0,
            'exento'   => false,
        ]];
    }

    private function itemsRequireReferencePrices(array $items, array $ref): bool
    {
        if ((int)($ref['codigo'] ?? 0) === 2) {
            return false;
        }
        foreach ($items as $item) {
            if ((float)($item['precio'] ?? 0) <= 0) {
                return true;
            }
        }
        return false;
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

        $foliosOk     = $state['simulacion'][$key]['folios_ok']    ?? [];
        $foliosFailed = $state['simulacion'][$key]['folios_failed'] ?? [];
        $muestrasRequeridas = self::MUESTRAS_SIMULACION[$tipo] ?? 1;
        $foliosConXml = $this->simulationFoliosWithXml($tipo, $foliosOk);

        // El estado puede sobrevivir a una limpieza/migración de tmp.
        if (($state['simulacion'][$key]['status'] ?? '') === 'ok'
            && count($foliosConXml) >= $muestrasRequeridas
        ) {
            return ['skipped' => true, 'mensaje' => "Simulación tipo $tipo ya completada."];
        }

        $done         = count($foliosOk);
        $faltanMuestras = max(0, $muestrasRequeridas - count($foliosConXml));
        $objetivo     = max($cantidad, $done + $faltanMuestras);
        $remaining    = $objetivo - $done;

        if ($remaining <= 0) {
            $state['simulacion'][$key]['status'] = 'ok';
            $this->saveState($state);
            return ['skipped' => true, 'mensaje' => "Simulación tipo $tipo ya tiene $done docs."];
        }

        $rutRec = in_array($tipo, [39, 41]) ? '66666666-6' : '55555555-5';

        // ── Calcular folio de inicio ─────────────────────────────────────────────
        // En ambiente CERTIFICACION generateDTE no registra en sii_dte, por lo que
        // loadCAF siempre ve lastUsed=0 y elegiría folio desde=1 → DTE-3-101.
        // Calculamos el punto de inicio con la misma lógica de certFolioBaseLibre:
        // max(sii_dte, state['pruebas'], folios_ok anteriores) + 1.
        $repo       = new EmpresaRepository();
        $cafRow     = $repo->getCAFNextAvailable(
            $this->context->getEmpresaId(), $tipo, $this->context->getAmbiente()
        );
        if (!$cafRow) {
            return [
                'ok'    => false,
                'error' => "No hay CAF disponible para tipo $tipo en ambiente "
                         . $this->context->getAmbiente()
                         . '. Cargue el CAF en Configuración.',
            ];
        }
        $cafDesde = (int)$cafRow['folio_desde'];
        $cafHasta = (int)$cafRow['folio_hasta'];

        $hw = $repo->getUltimoFolioUsadoEnRango(
            $this->context->getEmpresaId(), $tipo, $this->context->getAmbiente(),
            $cafDesde, $cafHasta
        );
        // Folios del set básico (no están en sii_dte en cert env)
        foreach (($state['pruebas'] ?? []) as $prueba) {
            if ((int)($prueba['tipo'] ?? 0) === $tipo) {
                $f = (int)($prueba['folio'] ?? 0);
                if ($f >= $cafDesde && $f <= $cafHasta) $hw = max($hw, $f);
            }
        }
        // Folios ya enviados con éxito en lotes anteriores de esta simulación
        foreach ($foliosOk as $fOk) {
            $fOk = (int)$fOk;
            if ($fOk >= $cafDesde && $fOk <= $cafHasta) $hw = max($hw, $fOk);
        }
        $nextFolio = max($cafDesde, $hw + 1);
        // ────────────────────────────────────────────────────────────────────────

        for ($i = 0; $i < $remaining; $i++) {
            $folioToUse = $nextFolio + $i;
            if ($folioToUse > $cafHasta) {
                $foliosFailed[] = [
                    'folio' => $folioToUse,
                    'error' => "Folio $folioToUse supera el rango del CAF ($cafDesde–$cafHasta). Cargue un nuevo CAF tipo $tipo.",
                ];
                break;
            }
            try {
                $recSimul = [
                    'rut'    => $rutRec,
                    'nombre' => 'Receptor Simulacion',
                    'giro'   => 'Giro Pruebas',
                ];
                if (!in_array($tipo, [39, 41])) {
                    $recSimul['direccion'] = 'Direccion Simulacion 123';
                    $recSimul['comuna']    = 'Santiago';
                }
                $payload = [
                    'tipoDTE' => $tipo,
                    'folio'   => $folioToUse,
                    'receptor' => $recSimul,
                    'items' => [[
                        'nombre'   => 'Producto Simulacion ' . ($done + $i + 1),
                        'cantidad' => 1,
                        'precio'   => 1000 + $i,
                    ]],
                ];
                if ($tipo === 52) {
                    $payload['indTraslado'] = 1;
                    $payload['tipoDespacho'] = 2;
                }
                if (in_array($tipo, [56, 61], true)) {
                    $folioRef = $this->simulationFacturaReference($state);
                    if ($folioRef <= 0) {
                        throw new Exception("Para simular T{$tipo} primero debe existir una factura T33 de prueba o simulación.");
                    }
                    $payload['referencias'] = [[
                        'tipo' => 33,
                        'folio' => $folioRef,
                        'fecha' => date('Y-m-d'),
                        'codigo' => 3,
                        'razon' => $tipo === 56 ? 'AUMENTA VALOR FACTURA SIMULACION' : 'DISMINUYE VALOR FACTURA SIMULACION',
                    ]];
                }
                $dte = generateDTE($payload);

                if (empty($dte['ok'])) throw new Exception($dte['error'] ?? 'Error DTE');

                $send = sendDTE(['xml' => $dte['xml'], 'tipo' => $dte['tipo'], 'folio' => $dte['folio']]);

                if ($send['ok']) {
                    $foliosOk[] = $dte['folio'];
                } else {
                    $foliosFailed[] = ['folio' => $dte['folio'], 'error' => $send['error'] ?? '?'];
                }
            } catch (\Throwable $e) {
                $foliosFailed[] = ['folio' => $folioToUse, 'error' => $e->getMessage()];
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

        // Completo si se alcanzó el mínimo SII (10 docs). El sistema intenta
        // hasta $cantidad (50) pero acepta menos si el CAF se agota antes.
        $minimo = $tipo === 33 ? self::SIM_MIN_CANTIDAD : 1;
        $allDone = count($foliosOk) >= $minimo
            && count($this->simulationFoliosWithXml($tipo, $foliosOk)) >= $muestrasRequeridas;
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
    // SIMULACIÓN — SOBRE ÚNICO (requisito SII: 20-100 docs, 1 TrackID)
    // =========================================================

    /**
     * Construye UN solo EnvioDTE con $cantPorTipo documentos de cada tipo
     * ($tipos, default [33,39]) y lo sube a maullin.sii.cl via SOAP.
     *
     * El SII exige para la etapa de Simulación:
     *   - 1 único sobre (EnvioDTE)
     *   - 20 a 100 documentos dentro del mismo envío
     *   - Todos los tipos que se están certificando
     *   - 1 solo N° de Envío (TrackID) para declarar en el portal
     *
     * @param array $tipos       Tipos de DTE a incluir, default [33, 39]
     * @param int   $cantPorTipo Documentos por tipo, default 15 (15+15=30 total)
     */
    public function runSimulacionSobre(array $tipos = [33, 39], int $cantPorTipo = 15): array
    {
        if (empty($tipos) || $cantPorTipo < 1) {
            return ['ok' => false, 'error' => 'Parámetros inválidos: tipos vacíos o cantidad < 1.'];
        }

        // El requisito 20-100 docs aplica al sobre EnvioDTE (facturas/NC/ND/guías).
        // Las boletas (39/41) van por canal REST separado y no cuentan para ese rango.
        $tiposFactura = array_values(array_filter($tipos, fn($t) => !in_array((int)$t, [39, 41], true)));
        $docsFactura  = count($tiposFactura) * $cantPorTipo;
        if ($docsFactura > 0 && ($docsFactura < 20 || $docsFactura > 100)) {
            return [
                'ok'    => false,
                'error' => "El sobre EnvioDTE llevaría $docsFactura documento(s); el SII exige entre 20 y 100 "
                         . "en el envío de simulación. Ajuste cantPorTipo (actual: $cantPorTipo) o los tipos (actual: "
                         . implode(',', $tipos) . ').',
            ];
        }

        $state  = $this->loadState();
        $repo   = new EmpresaRepository();
        $xmlDtes = [];          // XML de cada DTE individual para armar el sobre
        $foliosPorTipo = [];    // [tipo => [folios usados con éxito]]

        // ── Generar DTEs por tipo ────────────────────────────────────────────
        foreach ($tipos as $tipo) {
            $tipo = (int)$tipo;
            $rutRec = in_array($tipo, [39, 41]) ? '66666666-6' : '55555555-5';

            $cafRow = $repo->getCAFNextAvailable(
                $this->context->getEmpresaId(), $tipo, $this->context->getAmbiente()
            );
            if (!$cafRow) {
                return [
                    'ok'    => false,
                    'error' => "No hay CAF disponible para tipo $tipo en ambiente "
                             . $this->context->getAmbiente()
                             . '. Cargue el CAF en Configuración.',
                ];
            }
            $cafDesde = (int)$cafRow['folio_desde'];
            $cafHasta = (int)$cafRow['folio_hasta'];

            // Calcular primer folio libre (igual que runSimulacion)
            $hw = $repo->getUltimoFolioUsadoEnRango(
                $this->context->getEmpresaId(), $tipo, $this->context->getAmbiente(),
                $cafDesde, $cafHasta
            );
            foreach (($state['pruebas'] ?? []) as $prueba) {
                if ((int)($prueba['tipo'] ?? 0) === $tipo) {
                    $f = (int)($prueba['folio'] ?? 0);
                    if ($f >= $cafDesde && $f <= $cafHasta) $hw = max($hw, $f);
                }
            }
            // Respetar también folios usados en simulaciones previas (individuales)
            foreach (($state['simulacion']["t$tipo"]['folios_ok'] ?? []) as $fOk) {
                $fOk = (int)$fOk;
                if ($fOk >= $cafDesde && $fOk <= $cafHasta) $hw = max($hw, $fOk);
            }
            $nextFolio = max($cafDesde, $hw + 1);

            $foliosPorTipo[$tipo] = [];
            for ($i = 0; $i < $cantPorTipo; $i++) {
                $folioToUse = $nextFolio + $i;
                if ($folioToUse > $cafHasta) {
                    return [
                        'ok'    => false,
                        'error' => "CAF tipo $tipo insuficiente: se necesita folio $folioToUse "
                                 . "pero el rango llega hasta $cafHasta. "
                                 . "Cargue un CAF con más folios para tipo $tipo.",
                    ];
                }

                $recSimul = ['rut' => $rutRec, 'nombre' => 'Receptor Simulacion', 'giro' => 'Giro Pruebas'];
                if (!in_array($tipo, [39, 41])) {
                    $recSimul['direccion'] = 'Direccion Simulacion 123';
                    $recSimul['comuna']    = 'Santiago';
                }

                $dte = generateDTE([
                    'tipoDTE'  => $tipo,
                    'folio'    => $folioToUse,
                    'receptor' => $recSimul,
                    'items'    => [[
                        'nombre'   => 'Servicio Simulacion ' . ($i + 1),
                        'cantidad' => 1,
                        'precio'   => 10000 + ($i * 100),
                    ]],
                ]);

                if (empty($dte['ok'])) {
                    return ['ok' => false, 'error' => "Error generando DTE T{$tipo} folio $folioToUse: " . ($dte['error'] ?? '?')];
                }

                $xmlDtes[] = ['xml' => $dte['xml'], 'tipo' => (int)$dte['tipo'], 'folio' => (int)$dte['folio']];
                $foliosPorTipo[$tipo][] = $folioToUse;
            }
        }

        // ── Armar y enviar sobres ────────────────────────────────────────────
        // Las boletas (39/41) NO pueden ir dentro de un EnvioDTE vía SOAP: usan
        // su propio sobre EnvioBOLETA y el canal REST. Se separan los canales y
        // cada uno entrega su propio TrackID (mismo patrón que runSetBasico y
        // certificarSetBoletas).
        if (empty($xmlDtes)) {
            return ['ok' => false, 'error' => 'No se generaron DTEs para el sobre.'];
        }

        $dtesFact = array_values(array_filter($xmlDtes, fn($d) => !in_array($d['tipo'], [39, 41], true)));
        $dtesBol  = array_values(array_filter($xmlDtes, fn($d) => in_array($d['tipo'], [39, 41], true)));

        $tmpDir = $this->context->getTmpPath() . 'cert_simulacion/';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);

        $trackIdFact   = null;
        $trackIdBol    = null;
        $tiposEnviados = []; // tipos cuyos folios YA llegaron al SII (persistir aunque falle el resto)

        // Merge de folios: nunca pisar el historial de folios_ok previos (los
        // folios aceptados por el SII quedan quemados y no deben reutilizarse).
        $persistFolios = function (int $tipo) use (&$state, $foliosPorTipo) {
            $prev = $state['simulacion']["t$tipo"]['folios_ok'] ?? [];
            $state['simulacion']["t$tipo"] = [
                'status'        => 'ok',
                'tipo'          => $tipo,
                'folios_ok'     => array_values(array_unique(array_merge($prev, $foliosPorTipo[$tipo] ?? []))),
                'folios_failed' => $state['simulacion']["t$tipo"]['folios_failed'] ?? [],
                'ts'            => date('Y-m-d\TH:i:s'),
            ];
        };

        try {
            if (!empty($dtesFact)) {
                $tipoFirma = (int)$dtesFact[0]['tipo'];
                $GLOBALS['SII_CERT_TIPO'] = $tipoFirma;
                [$cert, $privKey] = loadCertificate($tipoFirma);

                $sobre        = buildEnvioDTESet($dtesFact, $cert);
                $sobreFirmado = signDTE($sobre, $cert, $privKey, 'FENV010');
                file_put_contents($tmpDir . 'envio_simulacion.xml', $sobreFirmado);

                $val = validateXmlAgainstXSD($sobreFirmado);
                if (empty($val['valid']) && empty($val['skipped'])) {
                    throw new Exception('El sobre EnvioDTE no pasó XSD local: ' . implode('; ', array_slice($val['errors'] ?? [], 0, 5)));
                }

                $semilla = getSemilla();
                $token   = getToken($semilla, $cert, $privKey);
                $send    = uploadDTE($sobreFirmado, $token, $cert);
                if (empty($send['ok'])) {
                    throw new Exception('Error enviando el sobre al SII: ' . ($send['error'] ?? $send['mensaje'] ?? '?'));
                }
                $trackIdFact = $send['trackId'] ?? null;
                foreach ($dtesFact as $d) $tiposEnviados[(int)$d['tipo']] = true;
            }

            if (!empty($dtesBol)) {
                $GLOBALS['SII_CERT_TIPO'] = 39;
                [$certB, $privKeyB] = loadCertificate(39);

                $sobreBol    = buildEnvioBoletaSet(array_column($dtesBol, 'xml'), $certB);
                $sobreBolFdo = signDTE($sobreBol, $certB, $privKeyB, 'SetDoc');
                file_put_contents($tmpDir . 'envio_simulacion_boletas.xml', $sobreBolFdo);

                $val = validateXmlAgainstXSD($sobreBolFdo);
                if (empty($val['valid']) && empty($val['skipped'])) {
                    throw new Exception('El sobre EnvioBOLETA no pasó XSD local: ' . implode('; ', array_slice($val['errors'] ?? [], 0, 5)));
                }

                $envioBol = sendBoletaREST($sobreBolFdo, (int)$dtesBol[0]['tipo'], (int)$dtesBol[0]['folio'], '');
                if (empty($envioBol['ok'])) {
                    throw new Exception('Error enviando el sobre de boletas al SII: ' . ($envioBol['error'] ?? '?'));
                }
                $trackIdBol = $envioBol['trackId'] ?? null;
                foreach ($dtesBol as $d) $tiposEnviados[(int)$d['tipo']] = true;
            }
        } catch (\Throwable $e) {
            foreach (array_keys($tiposEnviados) as $tipo) {
                $persistFolios($tipo);
            }
            if (!empty($tiposEnviados)) $this->saveState($state);
            return [
                'ok'              => false,
                'error'           => $e->getMessage(),
                'trackId'         => $trackIdFact,
                'trackId_boletas' => $trackIdBol,
            ];
        }

        // ── Persistir resultado en el estado ─────────────────────────────────
        $state['simulacion']['sobre'] = [
            'status'          => 'ok',
            'trackId'         => $trackIdFact ?? $trackIdBol,
            'trackId_boletas' => $trackIdBol,
            'total_docs'      => count($xmlDtes),
            'tipos'           => $tipos,
            'cant_por_tipo'   => $cantPorTipo,
            'ts'              => date('Y-m-d\TH:i:s'),
        ];
        foreach ($tipos as $tipo) {
            $persistFolios((int)$tipo);
        }
        $this->saveState($state);

        return [
            'ok'              => true,
            'trackId'         => $trackIdFact ?? $trackIdBol,
            'trackId_boletas' => $trackIdBol,
            'total_docs'      => count($xmlDtes),
            'tipos'           => $tipos,
            'folios'          => $foliosPorTipo,
            'cant_por_tipo'   => $cantPorTipo,
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
                $results["sim_$tipo"] = $this->runSimulacion($tipo, self::SIM_CANTIDADES[$tipo], $state);
            }
        }

        return ['ok' => true, 'resultados' => $results, 'estado' => $this->loadState()];
    }

    // =========================================================
    // SET DE INTERCAMBIO
    // =========================================================

    /**
     * Recibe el XML de intercambio del SII y genera las TRES respuestas que el
     * examen de intercambio exige, evaluando CADA DTE del sobre (el set trae
     * documentos con problemas a propósito — p.ej. receptor ajeno — que deben
     * marcarse como no recibidos/rechazados):
     *   1. Acuse de Recibo del envío   (RespuestaDTE/RecepcionEnvio)
     *   2. Recibo de mercaderías       (EnvioRecibos, Ley 19.983) — solo DTEs propios
     *   3. Resultado de validación     (RespuestaDTE/ResultadoDTE)
     * Los archivos firmados quedan en tmp/{RUT}/intercambio/ y se verifican en
     * la página "Verificación de respuestas" del menú postulantes en maullin.
     */
    public function responderIntercambio(string $xmlIntercambio): array
    {
        $state = $this->loadState();

        // El XML llega del navegador como UTF-8 aunque declare ISO-8859-1:
        // reconvertir para que el parser respete los bytes declarados.
        if (stripos($xmlIntercambio, 'ISO-8859-1') !== false
            && preg_match('//u', $xmlIntercambio)
            && strlen($xmlIntercambio) !== mb_strlen($xmlIntercambio, 'UTF-8')) {
            $xmlIntercambio = mb_convert_encoding($xmlIntercambio, 'ISO-8859-1', 'UTF-8');
        }

        $tmpDir = $this->context->getTmpPath() . 'intercambio/';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir . 'intercambio_recibido.xml', $xmlIntercambio);

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!@$dom->loadXML($xmlIntercambio)) {
            throw new Exception('XML de intercambio inválido o malformado.');
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('s', 'http://www.sii.cl/SiiDte');
        $txt = fn(\DOMNode $ctx, string $q) => trim((string)$xp->evaluate("string($q)", $ctx));

        $rutEmisorEnv = $txt($dom->documentElement, './/s:Caratula/s:RutEmisor');
        $setNode      = $xp->query('//s:SetDTE')->item(0);
        $envioId      = $setNode instanceof \DOMElement ? ($setNode->getAttribute('ID') ?: 'SetDoc') : 'SetDoc';
        $miRut        = $this->context->getRut();

        $docNodes = $xp->query('//s:DTE/s:Documento | //s:DTE/s:Exportaciones | //s:DTE/s:Liquidacion');
        if (!$docNodes->length) {
            throw new Exception('El XML de intercambio no contiene documentos DTE.');
        }

        $docsRecep = $docsResult = $docsRecibo = $resumen = [];
        foreach ($docNodes as $i => $doc) {
            $tipo  = (int)$txt($doc, './/s:IdDoc/s:TipoDTE');
            $folio = (int)$txt($doc, './/s:IdDoc/s:Folio');
            $fch   = $txt($doc, './/s:IdDoc/s:FchEmis');
            $rutE  = $txt($doc, './/s:Emisor/s:RUTEmisor');
            $rutR  = $txt($doc, './/s:Receptor/s:RUTRecep');
            $mnt   = (int)$txt($doc, './/s:Totales/s:MntTotal');
            if (!$tipo || !$folio) {
                throw new Exception('No se pudo leer TipoDTE/Folio de un documento del intercambio.');
            }

            $esMio = strcasecmp($rutR, $miRut) === 0;
            $base  = [
                'tipoDTE'     => $tipo,
                'folio'       => $folio,
                'fecha'       => $fch,
                'rutEmisor'   => $rutE,
                'rutReceptor' => $rutR,
                'montoTotal'  => $mnt,
            ];
            // Acuse: 0 = recibido OK · 3 = no recibido, error RUT receptor
            $docsRecep[]  = $base + ['estadoRecepDTE' => $esMio ? 0 : 3];
            // Resultado comercial: 0 = aceptado · 2 = rechazado
            $docsResult[] = $base + [
                'estadoDTE' => $esMio ? 0 : 2,
                'glosa'     => $esMio ? '' : 'RUT receptor no corresponde a este contribuyente.',
                'codEnvio'  => $i + 1,
            ];
            if ($esMio) $docsRecibo[] = $base;
            $resumen[] = "T{$tipo} F{$folio} → " . ($esMio ? 'ACEPTADO' : "RECHAZADO (receptor $rutR no es $miRut)");
        }

        // 1) Acuse de Recibo del envío (RecepcionEnvio + RecepcionDTE por doc)
        $acuse = generateRespuestaEnvioDTE([
            'rutRecibe'      => $rutEmisorEnv,
            'id'             => 'AcuseEnv1',
            'idRespuesta'    => 1,
            'recepcionEnvio' => [
                'estadoRecepEnv' => 0,
                'envioDTEId'     => $envioId,
                'nombreArchivo'  => 'intercambio_recibido.xml',
                'rutEmisor'      => $rutEmisorEnv,
                'rutReceptor'    => $miRut,
            ],
            'recepcionDTE'   => $docsRecep,
        ]);
        if (empty($acuse['ok'])) {
            throw new Exception('Error generando el acuse de recibo: ' . ($acuse['error'] ?? '?'));
        }

        // 2) Recibo de mercaderías (solo DTEs dirigidos a esta empresa)
        $recibos = null;
        if (!empty($docsRecibo)) {
            $recibos = generateEnvioRecibos(['rutRecibe' => $rutEmisorEnv, 'documentos' => $docsRecibo]);
            if (empty($recibos['ok'])) {
                throw new Exception('Error generando el recibo de mercaderías: ' . ($recibos['error'] ?? '?'));
            }
        }

        // 3) Resultado de validación comercial (todos los docs)
        $resultado = generateRespuestaEnvioDTE([
            'rutRecibe'    => $rutEmisorEnv,
            'id'           => 'ResultEnv1',
            'idRespuesta'  => 2,
            'resultadoDTE' => $docsResult,
        ]);
        if (empty($resultado['ok'])) {
            throw new Exception('Error generando el resultado de validación: ' . ($resultado['error'] ?? '?'));
        }

        file_put_contents($tmpDir . '1_acuse_recibo.xml', $acuse['xml']);
        if ($recibos) file_put_contents($tmpDir . '2_recibo_mercaderias.xml', $recibos['xml']);
        file_put_contents($tmpDir . '3_resultado_validacion.xml', $resultado['xml']);

        $ts = date('Y-m-d\TH:i:s');
        $state['intercambio'] = [
            'status' => 'responded',
            'docs'   => $resumen,
            'ts'     => $ts,
        ];
        $this->saveState($state);

        $archivos = ['acuse' => '1_acuse_recibo.xml'];
        if ($recibos) $archivos['recibo'] = '2_recibo_mercaderias.xml';
        $archivos['resultado'] = '3_resultado_validacion.xml';

        return [
            'ok'       => true,
            'docs'     => $resumen,
            'archivos' => $archivos,
            'mensaje'  => 'Respuestas de intercambio generadas y firmadas. Descárguelas y súbalas en '
                        . '"Verificación de respuestas de intercambio" del menú postulantes (maullin).',
        ];
    }

    // =========================================================
    // LIBROS DE CERTIFICACIÓN
    // =========================================================

    /**
     * 4820751 — Libro de Ventas.
     * Construido con los DTEs del Set Básico ya emitidos (F-4820750-*).
     */
    public function runLibroVentas(?string $periodo = null): array
    {
        $state  = $this->loadState();
        $tmpDir = $this->context->getTmpPath();

        // Construir lista desde el set subido (sin T52 guías, que van en libro guías)
        $setMgr     = new CertSetManager($this->context);
        $casesVenta = [];
        foreach ($setMgr->getFacturas() as $f) {
            $tipo = (int)($f['tipoDTE'] ?? 33);
            if ($tipo === 52) continue;
            $pfx = match(true) {
                $tipo === 43 => 'L',
                $tipo === 46 => 'C',
                default      => 'F',
            };
            $casesVenta[$pfx . '-' . $f['caso']] = $tipo;
        }
        // Fallback si no hay set subido
        if (empty($casesVenta)) {
            $casesVenta = [
                'F-4832043-1' => 33, 'F-4832043-2' => 33,
                'F-4832043-3' => 33, 'F-4832043-4' => 33,
                'F-4832043-5' => 61, 'F-4832043-6' => 61,
                'F-4832043-7' => 61, 'F-4832043-8' => 56,
            ];
        }

        $detalles  = [];
        $faltantes = [];

        foreach ($casesVenta as $caseId => $tipoDefault) {
            $info = $state['pruebas'][$caseId] ?? null;
            if (!$info || $info['status'] !== 'ok' || !$info['folio']) {
                $faltantes[] = $caseId;
                continue;
            }
            $tipo    = (int)($info['tipo'] ?? $tipoDefault);
            $folio   = (int)$info['folio'];
            // Buscar XML: primero en cert_basico/ (generado por runSetBasico), luego en raíz
            $xmlFile = $tmpDir . "cert_basico/dte_T{$tipo}F{$folio}.xml";
            if (!file_exists($xmlFile)) {
                $xmlFile = $tmpDir . "dte_T{$tipo}F{$folio}.xml";
            }

            if (!file_exists($xmlFile)) { $faltantes[] = $caseId; continue; }

            $dom = new DOMDocument();
            @$dom->loadXML(file_get_contents($xmlFile));
            $g = fn(string $t) => $dom->getElementsByTagName($t)->item(0)?->textContent ?? '';

            $neto  = (int)$g('MntNeto');
            $iva   = (int)$g('IVA');
            $exe   = (int)$g('MntExe');
            $total = (int)$g('MntTotal');

            // NC/ND llevan montos POSITIVOS en el libro, el tipo de documento indica la resta
            /* if (in_array($tipo, [61, 56])) {
                $neto  = -abs($neto);
                $iva   = -abs($iva);
                $exe   = -abs($exe);
                $total = -abs($total);
            } */

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

        $set        = $setMgr->load() ?? [];
        $folioNotif = (int)($set['atencion_ventas'] ?? 0);

        $result = sendLibro([
            'tipoLibro'         => 'VENTA',
            'tipoEnvio'         => 'TOTAL',
            'periodo'           => $periodo ?: date('Y-m'),
            'folioDsde'         => 1,
            'folioNotificacion' => $folioNotif,
            'detalles'          => $detalles,
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
    public function runLibroGuias(?string $periodo = null): array
    {
        $state  = $this->loadState();
        $tmpDir = $this->context->getTmpPath();

        $setMgr = new \App\Services\CertSetManager($this->context);
        $casesConfig = [];
        foreach ($setMgr->getFacturas() as $f) {
            if ($f['tipoDTE'] == 52) {
                $caseId = 'G-' . $f['caso'];
                $motivo = strtolower($f['motivo'] ?? '');
                $tpoOper = 1; // venta
                if (strpos($motivo, 'interno') !== false || strpos($motivo, 'bodega') !== false) {
                    $tpoOper = 5; // traslado interno
                }
                $anulado = ($f['num'] == 3) ? 2 : 0; // Por regla SII general, la 3 es la anulada
                $casesConfig[$caseId] = ['tpoOper' => $tpoOper, 'anulado' => $anulado];
            }
        }

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

        $set = $setMgr->load() ?? [];
        $folioNotif = (int)($set['atencion_libro_guias'] ?? $set['atencion_guias'] ?? 0);

        if ($folioNotif <= 0) {
            return [
                'ok'    => false,
                'error' => 'No se encontró el Nº de Atención del Libro de Guías en el set cargado '
                         . '(campo atencion_libro_guias). Asegúrese de haber subido el TXT del set '
                         . 'que incluye la sección "SET LIBRO DE GUIAS".',
            ];
        }

        $libroParams = [
            'tipoLibro'          => 'GUIA',
            'tipoEnvio'          => 'TOTAL',
            'periodo'            => $periodo ?: date('Y-m'),
            'folioNotificacion'  => $folioNotif,
            'detalles'           => $detalles,
        ];

        $result = sendLibro($libroParams);

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
    public function runLibroCompras(?string $periodo = null): array
    {
        $state   = $this->loadState();
        $periodo = $periodo ?: date('Y-m');
        // FchDoc debe caer DENTRO del período del libro: con período pasado y
        // fecha de hoy el SII rechaza "[FchDoc] es fecha futura" (LBR-3).
        $fecha = ($periodo === date('Y-m')) ? date('Y-m-d') : $periodo . '-15';

        // RUT del SII como proveedor de los docs en papel y FE de prueba
        $rutSII  = '60803000-K';
        $nomSII  = 'SII DE PRUEBAS';
        $setMgr = new \App\Services\CertSetManager($this->context);
        $set = $setMgr->load() ?? [];
        $factorIvaUsoComun = isset($set['factor_iva_uso_comun']) ? (float)$set['factor_iva_uso_comun'] : null;
        $detalles = [];
        foreach ($setMgr->getLibroCompras() as $c) {
            // Montos SIEMPRE positivos: el SII solo acepta negativos en liquidaciones
            // (40/43) — reparo LBR-2 "[TpoDoc] debe ser [40,43]". Las NC (60/61)
            // rebajan por su TpoDoc, no por signo. Tolera tipo 55 legado en set.json
            // (mapeo viejo NC papel=55; el correcto es 60) corrigiéndolo al vuelo.
            $tipoDoc = (int)$c['tipo'];
            if ($tipoDoc === 55 && stripos($c['obs'] ?? '', 'NOTA DE CREDITO') !== false) {
                $tipoDoc = 60;
            }
            $neto  = (int)$c['afecto'];
            $exe   = (int)$c['exento'];
            $iva   = (int)round($neto * 0.19);
            $total = $neto + $iva + $exe;

            $d = [
                'tipo'  => $tipoDoc,
                'folio' => $c['folio'],
                'fecha' => $fecha,
                'rut'   => $rutSII,
                'razon' => $nomSII,
                'neto'  => $neto,
                'iva'   => $iva,
                'exe'   => $exe,
                'total' => $total,
            ];

            $obs = strtolower($c['obs']);
            if (strpos($obs, 'uso comun') !== false || strpos($obs, 'uso común') !== false) {
                if ($factorIvaUsoComun === null || $factorIvaUsoComun <= 0) {
                    return [
                        'ok' => false,
                        'error' => 'El set importado contiene IVA de uso común, pero no informa su factor de proporcionalidad. Reimporte el archivo original del set.',
                    ];
                }
                $d['mntIvaUsoComun'] = $iva;
                $d['fctProp'] = $factorIvaUsoComun;
                $d['iva'] = 0;
            }
            if (strpos($obs, 'gratuita') !== false || strpos($obs, 'no recuperable') !== false) {
                $d['codIvaNoRec'] = 4; // cód. 4 = Entrega Gratuita (según LibroCV_v10.xsd)
                $d['mntIvaNoRec'] = $iva;
                $d['iva'] = 0;
            }
            if (strpos($obs, 'retencion') !== false || strpos($obs, 'retención') !== false) {
                // Retención total: MntIVA SÍ se informa (el SII valida MntIVA =
                // MntNeto*TasaImp y rechaza "Falta [MntIVA]" si se omite). La
                // retención se expresa en IVARetTotal y descuenta del total:
                // MntTotal = neto + iva - retención = neto.
                $d['ivaRetTotal'] = $iva;
                $d['total'] = $neto + $exe;
                // Instructivo de llenado de compras (campos 15-17): la retención
                // de una factura de compra se informa ADEMÁS en la tabla "Otros
                // Impuestos/Retenciones" (código 15 = IVA retenido total, tasa 19)
                // y se resta del Monto Total. Sin esto el revisor del set reparó
                // "No Informa Adecuadamente IVA Retenido Total".
                $d['otrosImp'] = [['codigo' => 15, 'tasa' => 19, 'monto' => $iva]];
            }

            $detalles[] = $d;
        }

        $folioNotif = (int)($set['atencion_compras'] ?? 0);

        $result = sendLibro([
            'tipoLibro'         => 'COMPRA',
            'tipoEnvio'         => 'TOTAL',
            'periodo'           => $periodo,
            'folioDsde'         => 1,
            'folioNotificacion' => $folioNotif,
            'detalles'          => $detalles,
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
     * Selecciona únicamente los DTE exigidos por el upload de muestras:
     * set de pruebas sin boletas + muestras exactas de simulación.
     * Elimina duplicados por tipo y folio antes de renderizar.
     * @return array<int,array{label:string,tipo:int,folio:int,xml:string}>
     */
    public function getMuestrasXmls(bool $withRaw = false): array
    {
        $state  = $this->loadState();
        $tmpDir = $this->context->getTmpPath();
        $dtes   = [];
        $seen   = [];
        $pruebasPorTipo = [];
        // 'raw' = bytes ISO-8859-1 originales del archivo: el PDF417 de las
        // muestras PDF debe contener el TED EXACTO firmado (re-codificarlo a
        // UTF-8 invalida la firma). NO incluir en respuestas JSON.
        $add = function (string $label, int $tipo, int $folio, string $xmlContent) use (&$dtes, &$seen, $withRaw) {
            $key = "{$tipo}:{$folio}";
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            // Algunos XML firmados ya llegan como UTF-8 aunque declaren Latin-1.
            // Convertirlos siempre genera mojibake (CajÃ³n/Pañuelo) en el PDF.
            $xmlUtf8 = preg_match('//u', $xmlContent)
                ? $xmlContent
                : mb_convert_encoding($xmlContent, 'UTF-8', 'ISO-8859-1');
            $row = [
                'label' => $label,
                'tipo'  => $tipo,
                'folio' => $folio,
                'xml'   => $xmlUtf8,
            ];
            if ($withRaw) $row['raw'] = $xmlContent;
            $dtes[] = $row;
            return true;
        };

        // Set de Pruebas
        foreach ($this->getPruebasCases() as $caseId) {
            $info = $state['pruebas'][$caseId] ?? null;
            if (!$info || $info['status'] !== 'ok' || !$info['folio']) continue;
            $tipo    = (int)($info['tipo'] ?? $this->detectTipo($caseId));
            if (in_array($tipo, [39, 41], true)) continue;
            if (!isset(self::MUESTRAS_PRUEBA[$tipo])) continue;
            if (($pruebasPorTipo[$tipo] ?? 0) >= self::MUESTRAS_PRUEBA[$tipo]) continue;
            $xmlFile = $tmpDir . "dte_T{$tipo}F{$info['folio']}.xml";
            if (file_exists($xmlFile)) {
                if ($add($caseId, $tipo, (int)$info['folio'], file_get_contents($xmlFile))) {
                    $pruebasPorTipo[$tipo] = ($pruebasPorTipo[$tipo] ?? 0) + 1;
                }
            }
        }

        // Simulación: el portal pide 3 facturas y una muestra por cada otro
        // tipo certificado (guía, nota de débito y nota de crédito).
        foreach (self::MUESTRAS_SIMULACION as $tipo => $cantidad) {
            $key     = "t$tipo";
            $simInfo = $state['simulacion'][$key] ?? null;
            $agregadas = 0;
            foreach (($simInfo['folios_ok'] ?? []) as $folio) {
                $xmlFile = $tmpDir . "dte_T{$tipo}F{$folio}.xml";
                if (file_exists($xmlFile)) {
                    if ($add("SIM-T{$tipo}", (int)$tipo, (int)$folio, file_get_contents($xmlFile))) {
                        $agregadas++;
                    }
                    if ($agregadas >= $cantidad) break;
                }
            }
        }

        return $dtes;
    }

    /**
     * Genera un PDF individual por cada muestra (y copia CEDIBLE donde aplica)
     * en tmp/{RUT}/muestras_pdf/. Devuelve la lista de archivos generados,
     * listos para subirse uno a uno en "Upload de muestras impresas" del SII.
     */
    public function generarMuestrasPdf(array $opts): array
    {
        $gen    = new MuestraPdfGenerator();
        $outDir = $this->context->getTmpPath() . 'muestras_pdf/';
        if (!is_dir($outDir)) @mkdir($outDir, 0755, true);
        foreach (glob($outDir . '*.pdf') ?: [] as $old) @unlink($old);

        $files    = [];
        $errors   = [];
        $muestras = $this->getMuestrasXmls(true);
        foreach (self::MUESTRAS_PRUEBA as $tipo => $cantidad) {
            $disponibles = count(array_filter(
                $muestras,
                fn(array $dte): bool => (int)$dte['tipo'] === $tipo
                    && !str_starts_with((string)$dte['label'], 'SIM-')
            ));
            if ($disponibles < $cantidad) {
                $errors[] = "Faltan muestras de prueba T{$tipo}: se requieren {$cantidad} y hay {$disponibles}.";
            }
        }
        foreach (self::MUESTRAS_SIMULACION as $tipo => $cantidad) {
            $disponibles = count(array_filter(
                $muestras,
                fn(array $dte): bool => (int)$dte['tipo'] === $tipo
                    && str_starts_with((string)$dte['label'], 'SIM-')
            ));
            if ($disponibles < $cantidad) {
                $errors[] = "Faltan muestras de simulación T{$tipo}: se requieren {$cantidad} y hay {$disponibles}.";
            }
        }

        $generated = [];
        foreach ($muestras as $dte) {
            // Las boletas (39/41) NO van en el upload de muestras del proceso de
            // factura de mercado: su certificación es un trámite aparte y el
            // validador las rechaza ("Validación" roja) por no pertenecer a los
            // envíos de este proceso.
            if (in_array((int)$dte['tipo'], [39, 41], true)) continue;
            $copias = ['TRIBUTARIA'];
            // Guía de traslado interno (sin venta) no requiere cedible
            $esGuiaInterna = false;
            if ((int)$dte['tipo'] === 52 && preg_match('/<IndTraslado>\s*5\s*<\/IndTraslado>/', $dte['raw'])) {
                $esGuiaInterna = true;
            }
            $esGuiaSimulacion = (int)$dte['tipo'] === 52
                && str_starts_with((string)$dte['label'], 'SIM-');
            if (in_array((int)$dte['tipo'], MuestraPdfGenerator::TIPOS_CEDIBLE, true)
                && !$esGuiaInterna
                && !$esGuiaSimulacion
            ) {
                $copias[] = 'CEDIBLE';
            }
            foreach ($copias as $copia) {
                $pdfKey = "{$dte['tipo']}:{$dte['folio']}:{$copia}";
                if (isset($generated[$pdfKey])) {
                    continue;
                }
                $generated[$pdfKey] = true;

                try {
                    $pdfBytes = $gen->render($dte, $opts, $copia);
                    $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $dte['label']);
                    $name = sprintf('T%d_F%d_%s_%s.pdf', $dte['tipo'], $dte['folio'],
                        $copia === 'CEDIBLE' ? 'CED' : 'TRIB', $slug);
                    file_put_contents($outDir . $name, $pdfBytes);
                    $files[] = [
                        'file'  => $name,
                        'label' => $dte['label'],
                        'tipo'  => $dte['tipo'],
                        'folio' => $dte['folio'],
                        'copia' => $copia,
                        'kb'    => (int)round(strlen($pdfBytes) / 1024),
                    ];
                } catch (\Throwable $e) {
                    $errors[] = "T{$dte['tipo']}F{$dte['folio']} $copia: " . $e->getMessage();
                }
            }
        }
        if (count($files) !== self::TOTAL_PDFS_EXIGIDOS) {
            $errors[] = 'El portal SII exige exactamente ' . self::TOTAL_PDFS_EXIGIDOS
                . ' PDF (17 de prueba y 9 de simulación); se generaron ' . count($files) . '.';
        }

        $state = $this->loadState();
        $state['muestras'] = [
            'status' => empty($errors) ? 'generated' : 'partial',
            'total'  => count($files),
            'ts'     => date('Y-m-d\TH:i:s'),
        ];
        $this->saveState($state);

        return [
            'ok' => count($files) === self::TOTAL_PDFS_EXIGIDOS && empty($errors),
            'archivos' => $files,
            'errores' => $errors,
        ];
    }

    private function simulationFacturaReference(array $state): int
    {
        $foliosSim = $state['simulacion']['t33']['folios_ok'] ?? [];
        if (!empty($foliosSim)) {
            return (int)end($foliosSim);
        }
        foreach (array_reverse($state['pruebas'] ?? []) as $prueba) {
            if ((int)($prueba['tipo'] ?? 0) === 33 && (int)($prueba['folio'] ?? 0) > 0) {
                return (int)$prueba['folio'];
            }
        }

        return 0;
    }

    /**
     * @return int[]
     */
    private function simulationFoliosWithXml(int $tipo, array $folios): array
    {
        $tmpDir = $this->context->getTmpPath();

        return array_values(array_filter(
            array_map('intval', $folios),
            fn(int $folio): bool => $folio > 0 && file_exists($tmpDir . "dte_T{$tipo}F{$folio}.xml")
        ));
    }

    private function detectTipo(string $caseId): int
    {
        // Prefijo del caseId indica tipo directo
        if (str_starts_with($caseId, 'B')) return 39;
        if (str_starts_with($caseId, 'G')) return 52;
        if (str_starts_with($caseId, 'L')) return 43;
        if (str_starts_with($caseId, 'C')) return 46;
        // Para F-: buscar en el set subido (fuente de verdad)
        if (preg_match('/^F-(.+)$/', $caseId, $m)) {
            $casoId = $m[1];
            $setMgr = new CertSetManager($this->context);
            foreach ($setMgr->getFacturas() as $f) {
                if ((string)($f['caso'] ?? '') === $casoId) {
                    return (int)($f['tipoDTE'] ?? 33);
                }
            }
        }
        // Heurística posicional como último recurso (set 4832043)
        if (str_ends_with($caseId, '-5') || str_ends_with($caseId, '-6') || str_ends_with($caseId, '-7')) return 61;
        if (str_ends_with($caseId, '-8')) return 56;
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
