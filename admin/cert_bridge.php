<?php
/**
 * Puente de Certificación — enruta todas las acciones del módulo de certificación.
 */
// Suprimir errores PHP para que nunca salga HTML en lugar de JSON
ini_set('display_errors', '0');
error_reporting(0);

ob_start();
define('DTE_API_BOOTSTRAP_ONLY', true);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/autoload.php';
use App\Core\Context;
use App\Services\CertificationManager;
use App\Repositories\EmpresaRepository;

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * Resuelve el ID de empresa para certificación.
 * Usa active_empresa_id de la sesión si existe; si no, busca la primera
 * empresa con ambiente CERTIFICACION en la base de datos.
 */
function resolveCertEmpresaId(): int
{
    // Ruta 1: sesión de admin con empresa activa seleccionada
    if (!empty($_SESSION['admin_id']) && isset($_SESSION['active_empresa_id']) && (int)$_SESSION['active_empresa_id'] > 0) {
        return (int)$_SESSION['active_empresa_id'];
    }
    // Ruta 2: sesión sin admin_id (p.ej. llamada directa a la URL con sesión usuario)
    if (isset($_SESSION['active_empresa_id']) && (int)$_SESSION['active_empresa_id'] > 0) {
        return (int)$_SESSION['active_empresa_id'];
    }
    // Ruta 3: fallback — primera empresa con ambiente CERTIFICACION en la BD
    try {
        $repo = new EmpresaRepository();
        $emp  = $repo->getByAmbiente('CERTIFICACION');
        if ($emp && (int)($emp['id'] ?? 0) > 0) {
            return (int)$emp['id'];
        }
    } catch (\Throwable $ignored) {}
    throw new \Exception('Seleccione explicitamente una empresa antes de iniciar la certificacion.');
}

// ── Las muestras impresas ahora se renderizan en el cliente (jscript.js →
//    DTE.renderMuestras), única fuente de verdad. El servidor solo entrega los
//    XML vía la acción JSON 'cert_muestras_xml' (más abajo). ────────────────────

// ── Resto: JSON ───────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');

try {
    $empresaId = resolveCertEmpresaId();
    // Poner active_empresa_id en sesión ANTES de requerir api.php.
    $_SESSION['active_empresa_id'] = $empresaId;

    // api.php inicializa $globalContext y define las constantes (ACTECO, RUT_EMISOR…)
    // SOLO cuando hay sesión admin activa (isAdminRequest=true). Sin ella entra al
    // bloque else y fija ACTECO=0, RUT_EMISOR='', etc. — valores inutilizables.
    // Solución: si no hay admin_id, ponemos uno centinela temporal para que api.php
    // entre al if-isAdminRequest y defina todo con los datos reales de la empresa.
    // Lo quitamos inmediatamente después para no contaminar la sesión real.
    $_certBridgeNeedsFakeAdmin = empty($_SESSION['admin_id']);
    if ($_certBridgeNeedsFakeAdmin) {
        $_SESSION['admin_id'] = '__cert_bridge_init__';
    }

    $globalContext = new Context($empresaId);
    if ($globalContext->getAmbiente() !== 'CERTIFICACION') {
        throw new \Exception('La empresa seleccionada no esta en ambiente CERTIFICACION.');
    }

    require_once __DIR__ . '/api.php';
    // api.php re-habilita display_errors; lo suprimimos de nuevo para evitar HTML en la respuesta
    ini_set('display_errors', '0');

    // Limpiar el admin_id centinela si lo pusimos nosotros
    if (!empty($_certBridgeNeedsFakeAdmin)) {
        unset($_SESSION['admin_id']);
    }

    // Seguridad: si api.php igualmente dejó $globalContext null (no debería pasar
    // ahora), lo re-creamos junto con los globals de rutas.
    if (!($globalContext instanceof Context)) {
        $globalContext   = new Context($empresaId);
        global $actualTmpDir, $actualCafDir, $actualCertPfx;
        $actualTmpDir    = $globalContext->getTmpPath();
        $actualCafDir    = dirname($globalContext->getCafPath(0)) . '/';
        $actualCertPfx   = $globalContext->getCertPath();
        if (!is_dir($actualTmpDir)) @mkdir($actualTmpDir, 0755, true);
    }

    $mgr = new CertificationManager($globalContext);

    switch ($action) {

        // ── Estado ──────────────────────────────────────────────
        case 'cert_state':
            ob_clean();
            echo json_encode(['ok' => true, 'estado' => $mgr->loadState()]);
            break;

        case 'cert_empresa_info':
            ob_clean();
            $empData = $globalContext->getEmpresa();
            echo json_encode([
                'ok'        => true,
                'rut'       => $globalContext->getRut(),
                'ambiente'  => $globalContext->getAmbiente(),
                'nro_resol' => $empData['nro_resol'] ?? 'NO DEFINIDO',
                'fch_resol' => $empData['fch_resol'] ?? 'NO DEFINIDO',
                'empresa'   => $empData,
            ]);
            break;

        case 'cert_reset':
            ob_clean();
            echo json_encode($mgr->resetState());
            break;

        // ── Ejecución ────────────────────────────────────────────
        case 'cert_run_all':
            set_time_limit(600);
            ob_clean();
            $state   = [];
            // Todos los casos no-boleta del set actual (dinámico)
            $caseIds = array_values(array_filter(
                $mgr->getPruebasCases(),
                fn($id) => !str_starts_with($id, 'B-')
            ));
            $log = [
                'pruebas' => $mgr->runPruebas($state, $caseIds),
                'sim_33'  => $mgr->runSimulacion(33, 50, $state),
                'sim_39'  => $mgr->runSimulacion(39, 50, $state),
                'boletas' => [
                    'skipped' => true,
                    'mensaje' => 'Las boletas del set SII se ejecutan solo con cert_boletas (sobre + RCOF).',
                ],
            ];
            echo json_encode(['ok' => true, 'resultados' => $log, 'estado' => $mgr->loadState()]);
            break;

        case 'cert_run_pruebas':
            set_time_limit(300);
            ob_clean();
            // Con force=1 se reinicia el estado antes de ejecutar
            $forceRun = isset($_GET['force']) && $_GET['force'] == '1';
            if ($forceRun) $mgr->resetState();
            $state   = [];
            // skip_boletas=1 → solo facturas/NC/ND/guías del set (dinámico, sin hardcodear)
            $caseIds = isset($_GET['skip_boletas']) && $_GET['skip_boletas'] == '1'
                ? array_values(array_filter(
                    $mgr->getPruebasCases(),
                    fn($id) => !str_starts_with($id, 'B-')
                  ))
                : [];   // vacío = todos (incluyendo boletas, aunque se maneja aparte)
            echo json_encode(['ok' => true, 'resultados' => $mgr->runPruebas($state, $caseIds), 'estado' => $mgr->loadState()]);
            break;

        case 'cert_run_sim':
            set_time_limit(600);
            $tipo = (int)($_GET['tipo'] ?? $_POST['tipo'] ?? 33);
            $cant = (int)($_GET['cantidad'] ?? $_POST['cantidad'] ?? 50);
            $state = [];
            ob_clean();
            echo json_encode($mgr->runSimulacion($tipo, $cant, $state));
            break;

        case 'cert_sim_all':
            set_time_limit(600);
            ob_clean();
            $state = [];
            $results = [
                'sim_33' => $mgr->runSimulacion(33, 50, $state),
                'sim_39' => $mgr->runSimulacion(39, 50, $state),
            ];
            echo json_encode(['ok' => true, 'resultados' => $results, 'estado' => $mgr->loadState()]);
            break;

        case 'cert_retry':
            set_time_limit(600);
            ob_clean();
            echo json_encode($mgr->retryFailed());
            break;

        // ── Caso individual ──────────────────────────────────────
        case 'cc':
        case 'cert_case':
            $cid  = $_GET['cid'] ?? $_POST['cid'] ?? '';
            if (str_starts_with($cid, 'B-CASO-')) {
                ob_clean();
                echo json_encode([
                    'ok' => false,
                    'error' => 'Las boletas del set SII no se envian individualmente. Use Certificar Boletas (sobre + RCOF).',
                ]);
                break;
            }
            ob_clean();
            $state = [];
            $resultados = $mgr->runPruebas($state, [$cid]);
            $resCaso = $resultados[$cid] ?? ['status' => 'failed', 'error' => 'Sin resultado'];
            echo json_encode([
                'ok'    => ($resCaso['status'] ?? '') === 'ok',
                'tipo'  => $resCaso['tipo'] ?? null,
                'folio' => $resCaso['folio'] ?? null,
                'envio' => [
                    'ok'      => ($resCaso['status'] ?? '') === 'ok',
                    'trackId' => $resCaso['trackId'] ?? null,
                    'error'   => $resCaso['error'] ?? null,
                ],
            ]);
            break;

        // ── Simulación legacy ────────────────────────────────────
        case 'cs50':
        case 'cert_simulation':
            set_time_limit(600);
            $tipo = (int)($_GET['tipo'] ?? $_POST['tipo'] ?? 33);
            $cant = (int)($_GET['cantidad'] ?? $_POST['cantidad'] ?? 50);
            $state = [];
            ob_clean();
            echo json_encode($mgr->runSimulacion($tipo, $cant, $state));
            break;

        // ── Libros de certificación ──────────────────────────────
        case 'cert_libro_ventas':
            set_time_limit(120);
            ob_clean();
            echo json_encode($mgr->runLibroVentas());
            break;

        case 'cert_libro_guias':
            set_time_limit(120);
            ob_clean();
            echo json_encode($mgr->runLibroGuias());
            break;

        case 'cert_libro_compras':
            set_time_limit(120);
            ob_clean();
            echo json_encode($mgr->runLibroCompras());
            break;

        // ── Restaurar estado desde XMLs existentes en tmp/ ───────
        // Útil cuando el state fue reiniciado pero los XMLs y TrackIDs
        // ya existen (no hay más folios para regenerar).
        // action via GET/POST: ?action=cert_restore_state
        // body JSON: { "casos": { "F-4832043-1": { "folio": 11, "tipo": 33, "trackId": "..." }, ... } }
        case 'cert_restore_state':
            ob_clean();
            $body  = (string) file_get_contents('php://input');
            $bodyData = json_decode($body, true);
            $casos = $bodyData['casos'] ?? ($_POST['casos'] ?? []);
            if (empty($casos) || !is_array($casos)) {
                echo json_encode(['ok' => false, 'error' => 'Debe enviar casos con folio, tipo y trackId.']);
                break;
            }
            $state   = $mgr->loadState();
            $tmpDir  = $globalContext->getTmpPath();
            $restored = [];
            $missing  = [];

            foreach ($casos as $caseId => $info) {
                $tipo  = (int)($info['tipo']    ?? 0);
                $folio = (int)($info['folio']   ?? 0);
                $trk   = trim((string)($info['trackId'] ?? ''));

                if (!$tipo || !$folio) {
                    $missing[] = $caseId . ' (tipo o folio inválido)';
                    continue;
                }

                $xmlFile = $tmpDir . "dte_T{$tipo}F{$folio}.xml";
                if (!file_exists($xmlFile)) {
                    $missing[] = $caseId . " (XML no encontrado: dte_T{$tipo}F{$folio}.xml)";
                    continue;
                }

                $state['pruebas'][$caseId] = [
                    'status'  => 'ok',
                    'tipo'    => $tipo,
                    'folio'   => $folio,
                    'trackId' => $trk ?: null,
                    'error'   => null,
                    'ts'      => date('Y-m-d\TH:i:s'),
                ];
                $restored[] = $caseId;
            }

            $mgr->saveStatePublic($state);
            echo json_encode([
                'ok'       => true,
                'restored' => $restored,
                'missing'  => $missing,
                'message'  => count($restored) . ' caso(s) restaurados en el state. Ya puede enviar los libros.',
            ]);
            break;


        // ── Avanzar HW de folio para un tipo (folios quemados en SII) ───────────
        // Inserta una entrada phantom en state['pruebas'] para que certFolioBaseLibre
        // omita folios ya enviados al SII que el state no conoce (p.ej. tras reset).
        case 'cert_advance_hw':
            ob_clean();
            $tipo  = (int)($_GET['tipo']  ?? $_POST['tipo']  ?? 0);
            $folio = (int)($_GET['folio'] ?? $_POST['folio'] ?? 0);
            if (!$tipo || !$folio) {
                echo json_encode(['ok' => false, 'error' => 'Parámetros tipo y folio son requeridos.']);
                break;
            }
            $state = $mgr->loadState();
            $phKey = "__hw_t{$tipo}_f{$folio}__";
            $state['pruebas'][$phKey] = [
                'tipo'    => $tipo,
                'folio'   => $folio,
                'status'  => 'ok',
                'trackId' => null,
                'error'   => null,
                'ts'      => date('Y-m-d\TH:i:s'),
                'note'    => 'phantom: folio quemado en SII, solo avanza HW',
            ];
            $mgr->saveStatePublic($state);
            echo json_encode([
                'ok'      => true,
                'mensaje' => "HW avanzado: T{$tipo} folio {$folio} registrado en state.",
            ]);
            break;

        // Elimina registros de sii_folio_consumo y sii_dte cuyo estado sea
        // 'emitido'/'firmado' (nunca confirmados por el SII). Permite reintentar
        // el mismo caso reutilizando los mismos folios sin desperdiciarlos.
        case 'cert_fix_orphaned':
            ob_clean();
            $db    = \App\Core\Database::getInstance();
            $empId = $globalContext->getEmpresaId();
            $amb   = strtolower($globalContext->getAmbiente());

            // 1. Identificar folios emitidos pero sin trackId confirmado en sii_dte
            $stmtSel = $db->prepare(
                "SELECT tipo_dte, folio FROM sii_dte
                 WHERE empresa_id = ? AND ambiente = ?
                   AND estado_local = 'firmado'
                   AND (track_id IS NULL OR track_id = '')
                 ORDER BY tipo_dte, folio"
            );
            $stmtSel->execute([$empId, $amb]);
            $orphaned = $stmtSel->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($orphaned)) {
                echo json_encode(['ok' => true, 'mensaje' => 'No hay folios huérfanos.', 'limpiados' => 0]);
                break;
            }

            $limpiados = [];
            foreach ($orphaned as $row) {
                $tipo  = (int)$row['tipo_dte'];
                $folio = (int)$row['folio'];
                // Eliminar de sii_folio_consumo primero (FK apunta a sii_dte)
                $db->prepare("DELETE FROM sii_folio_consumo WHERE empresa_id=? AND tipo_dte=? AND folio=? AND ambiente=?")
                   ->execute([$empId, $tipo, $folio, $amb]);
                // Eliminar de sii_dte
                $db->prepare("DELETE FROM sii_dte WHERE empresa_id=? AND tipo_dte=? AND folio=? AND ambiente=?")
                   ->execute([$empId, $tipo, $folio, $amb]);
                $limpiados[] = "T{$tipo}F{$folio}";
            }

            echo json_encode([
                'ok'       => true,
                'mensaje'  => 'Folios huérfanos eliminados. Puede reintentar los casos.',
                'limpiados'=> count($limpiados),
                'folios'   => $limpiados,
            ]);
            break;

        // ── Set de Certificación: subir el .txt del SII y parsearlo ──
        case 'cert_set_upload':
            ob_clean();
            if (empty($_FILES['set_file']['tmp_name'])) {
                echo json_encode(['ok' => false, 'error' => 'Adjunte el archivo .txt del Set de Pruebas del SII.']);
                break;
            }
            $setMgr = new \App\Services\CertSetManager($globalContext);
            $raw    = file_get_contents($_FILES['set_file']['tmp_name']);
            echo json_encode($setMgr->importarTxt($raw, $_FILES['set_file']['name'] ?? 'set.txt'));
            break;

        // ── Set de Certificación: ver el set vinculado ───────────
        case 'cert_set_get':
            ob_clean();
            $setMgr = new \App\Services\CertSetManager($globalContext);
            echo json_encode(['ok' => true, 'set' => $setMgr->load()]);
            break;

        // ── Set de Certificación: eliminar vínculo ───────────────
        case 'cert_set_delete':
            ob_clean();
            $setMgr = new \App\Services\CertSetManager($globalContext);
            echo json_encode(['ok' => $setMgr->delete()]);
            break;

        // ── Certificación de BOLETAS: set en un sobre + RCOF ──────
        case 'cert_boletas':
            set_time_limit(600);
            ob_clean();
            echo json_encode($mgr->certificarSetBoletas());
            break;

        // ── Muestras impresas: entrega los XML + opts para el renderer cliente ──
        case 'cert_muestras_xml':
            ob_clean();
            $empM   = $globalContext->getEmpresa();
            $esCert = $globalContext->getAmbiente() === 'CERTIFICACION';
            echo json_encode([
                'ok'   => true,
                'dtes' => $mgr->getMuestrasXmls(),
                'opts' => [
                    'format'    => 'letter',
                    'unidadSII' => $empM['unidad_sii'] ?? 'S.I.I.',
                    'resolNum'  => $esCert ? 0 : (int)($empM['numero_resolucion'] ?? 0),
                    'resolFch'  => $esCert ? '2021-01-04' : ($empM['fecha_resolucion'] ?? ''),
                    'ambiente'  => $globalContext->getAmbiente(),
                ],
            ]);
            break;

        // ── Intercambio ──────────────────────────────────────────
        case 'cert_intercambio':
            $xml = '';
            if (!empty($_POST['xml'])) {
                $xml = $_POST['xml'];
            } elseif (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
                $xml = file_get_contents($_FILES['archivo']['tmp_name']);
            }
            if (empty(trim($xml))) {
                throw new \Exception('Debe proporcionar el XML de intercambio (pegar o subir archivo).');
            }
            ob_clean();
            echo json_encode($mgr->responderIntercambio($xml));
            break;

        // ── Diagnóstico: genera XML del caso 1 sin enviar al SII ────
        case 'cert_diag_xml':
            ob_clean();
            $folio = (int)($_GET['folio'] ?? 12);
            // Encontrar el primer caso del set (sufijo -1)
            $setMgr2 = new \App\Services\CertSetManager($globalContext);
            $casos2  = $setMgr2->getFacturas();
            $caso1   = null;
            foreach ($casos2 as $c) {
                if (preg_match('/-1$/', (string)($c['caso'] ?? ''))) { $caso1 = $c; break; }
            }
            if (!$caso1) {
                echo json_encode(['ok' => false, 'error' => 'No se encontró caso -1 en el set. Asegúrese de haber subido el set de pruebas.']);
                break;
            }
            $caseId2   = 'F-' . $caso1['caso'];
            $diagData  = $mgr->buildCaseDTEForDiag($caseId2, $folio);
            $diagJson  = json_encode(
                $diagData,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($diagJson === false) {
                echo json_encode(['ok' => false, 'error' => 'json_encode falló: ' . json_last_error_msg(),
                                  'dte_ids' => $diagData['dte_ids'] ?? [],
                                  'envio_raw_ids' => $diagData['envio_raw_ids'] ?? [],
                                  'envio_firmado_ids' => $diagData['envio_firmado_ids'] ?? []]);
            } else {
                echo $diagJson;
            }
            break;

        // ── Diagnóstico cvc-id.2 ─────────────────────────────────
        case 'cert_diag_files':
            ob_clean();
            $errorFile = __DIR__ . '/dte_error_upload.xml';

            // Buscar TODOS los sobre_T*.xml en tmp/ y elegir el más reciente
            $sobreFile  = '';
            $sobreFiles = [];
            $tmpBase    = __DIR__ . '/tmp/';
            if (is_dir($tmpBase)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tmpBase, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $f) {
                    if (preg_match('/^sobre_T\d+F\d+\.xml$/', $f->getFilename())) {
                        $sobreFiles[$f->getPathname()] = $f->getMTime();
                    }
                }
                arsort($sobreFiles); // más reciente primero
                if (!empty($sobreFiles)) {
                    $sobreFile = (string) array_key_first($sobreFiles);
                }
            }

            $out = [
                'ok'               => true,
                'sobres_lista'     => array_keys($sobreFiles),
                'sobre_seleccionado' => $sobreFile ? basename($sobreFile) : null,
            ];

            // ── Respuesta SII (dte_error_upload.xml) ─────────────────────────
            if (file_exists($errorFile)) {
                $raw = file_get_contents($errorFile);
                preg_match('/<DETAIL>([\s\S]*?)<\/DETAIL>/i', $raw, $mD);
                $out['sii_detail_completo'] = $mD[1] ?? '(DETAIL no encontrado)';
                $out['sii_response_raw']    = substr($raw, 0, 4000);
            } else {
                $out['sii_detail_completo'] = '(dte_error_upload.xml no existe aún)';
            }

            // ── Sobre firmado (sobre_T*.xml) ──────────────────────────────────
            if ($sobreFile && file_exists($sobreFile)) {
                $sobre = file_get_contents($sobreFile);
                preg_match_all('/ ID="([^"]+)"/', $sobre, $mIds);
                $lines = explode("\n", $sobre);
                $out['sobre_ids_encontrados']    = $mIds[1] ?? [];
                $out['sobre_total_lineas']       = count($lines);
                $out['sobre_linea_73']           = $lines[72] ?? '(no existe línea 73)';
                $out['sobre_contexto_l68_78']    = implode("\n", array_slice($lines, 67, 11));
                $out['sobre_primeras_100_lineas']= implode("\n", array_slice($lines, 0, 100));
            } else {
                $out['sobre_ids_encontrados']    = [];
                $out['sobre_primeras_100_lineas']= "(no se encontró sobre_T*.xml en $tmpBase)";
            }

            // ── Sobre RAW pre-firma (envio_raw_T*.xml) si existe ─────────────
            $rawFile = '';
            if (!empty($sobreFile)) {
                $rawFile = str_replace('sobre_T', 'envio_raw_T', $sobreFile);
            }
            if ($rawFile && file_exists($rawFile)) {
                $rawSobre = file_get_contents($rawFile);
                preg_match_all('/ ID="([^"]+)"/', $rawSobre, $mRawIds);
                $rawLines = explode("\n", $rawSobre);
                $out['raw_ids_encontrados']    = $mRawIds[1] ?? [];
                $out['raw_total_lineas']       = count($rawLines);
                $out['raw_primeras_100_lineas']= implode("\n", array_slice($rawLines, 0, 100));
            } else {
                $out['raw_ids_encontrados']    = [];
                $out['raw_primeras_100_lineas']= "(envio_raw_T*.xml no existe — si quieres este diagnóstico activa el guardado en sendDTE)";
            }

            echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        default:
            ob_clean();
            echo json_encode(['ok' => false, 'error' => "Acción '$action' no soportada en cert_bridge."]);
    }

} catch (\Throwable $e) {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
