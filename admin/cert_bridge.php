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
    if (isset($_SESSION['active_empresa_id']) && (int)$_SESSION['active_empresa_id'] > 0) {
        return (int)$_SESSION['active_empresa_id'];
    }
    $repo = new EmpresaRepository();
    $emp  = $repo->getByAmbiente('CERTIFICACION');
    if (!$emp) {
        throw new \Exception('No hay empresa configurada en ambiente CERTIFICACIÓN. Configure una empresa con ambiente CERTIFICACION en la sección Empresas.');
    }
    return (int)$emp['id'];
}

// ── Las muestras impresas ahora se renderizan en el cliente (jscript.js →
//    DTE.renderMuestras), única fuente de verdad. El servidor solo entrega los
//    XML vía la acción JSON 'cert_muestras_xml' (más abajo). ────────────────────

// ── Resto: JSON ───────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');

try {
    $empresaId = resolveCertEmpresaId();
    // Asegurar que api.php no sobreescriba $globalContext con null
    $_SESSION['active_empresa_id'] = $empresaId;
    $globalContext = new Context($empresaId);

    require_once __DIR__ . '/api.php';
    // api.php re-habilita display_errors; lo suprimimos de nuevo para evitar HTML en la respuesta
    ini_set('display_errors', '0');

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
            $state = [];
            $caseIds = [
                'F-4832043-1','F-4832043-2','F-4832043-3','F-4832043-4',
                'F-4832043-5','F-4832043-6','F-4832043-7','F-4832043-8',
            ];
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
            $state = [];
            $caseIds = [];
            if (isset($_GET['skip_boletas']) && $_GET['skip_boletas'] == '1') {
                $caseIds = [
                    'F-4832043-1','F-4832043-2','F-4832043-3','F-4832043-4',
                    'F-4832043-5','F-4832043-6','F-4832043-7','F-4832043-8',
                ];
            }
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

        // ── Limpieza de folios no recibidos por SII ─────────────
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
                $db->prepare("DELETE FROM sii_folio_consumo WHERE empresa_id=? AND tipo_dte=? AND folio=?")
                   ->execute([$empId, $tipo, $folio]);
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

        default:
            ob_clean();
            echo json_encode(['ok' => false, 'error' => "Acción '$action' no soportada en cert_bridge."]);
    }

} catch (\Throwable $e) {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
