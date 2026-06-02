<?php
/**
 * Módulo: Urgencia POS — dashboard de estado de folios y cola de DTEs locales
 *
 * Muestra:
 *   1. Indicador global de urgencia (ok / bajo / crítico)
 *   2. Tabla de máquinas POS con su stock actual y nivel de alerta
 *   3. Recomendación de cuántos folios pedir al SII por tipo de DTE
 *   4. Estado de la cola de DTEs locales pendientes de envío al SII
 */

use App\Core\Database;

$queueData   = null;
$alertaData  = null;
$pedidoOpt   = null;
$dbError     = null;
$schemaWarnings = [];

$queueData = [
    'resumen' => ['pendiente' => 0, 'enviado' => 0, 'error' => 0, 'dropped' => 0],
    'por_sucursal' => [],
    'ult_errores' => [],
];
$alertaData = [
    'urgencia_global' => 'desconocido',
    'machines_critico' => 0,
    'machines_bajo' => 0,
    'machines_ok' => 0,
    'machines_stale' => 0,
    'alertas' => [],
    'pedido_recomendado' => [],
];
$pedidoOpt = [
    'ok' => true,
    'sugerencias' => [],
    'dias_proyeccion' => 60,
    'factor_seguridad' => 1.3,
];

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
};

if ($dbOk) {
    try {
        $pdo = Database::getInstance();

        $hasLegacyQueue = $tableExists($pdo, 'dte_local_queue');
        $hasSaaSQueue   = $tableExists($pdo, 'folios_consumidos');
        if ($hasLegacyQueue || $hasSaaSQueue) {
            $queueMgr = new \App\Services\DteLocalQueueManager();
            $queueData = $queueMgr->resumenGlobal();
        } else {
            $schemaWarnings[] = 'Falta dte_local_queue: la cola local POS se muestra en cero.';
        }

        $hasLegacyAlerta = $tableExists($pdo, 'folios_alerta');
        $hasSaaSAlerta   = $tableExists($pdo, 'folios_asignaciones');
        if ($hasLegacyAlerta || $hasSaaSAlerta) {
            $alertaMgr = new \App\Services\FoliosAlertaManager();
            $alertaData = $alertaMgr->urgenciaGlobal();
        } else {
            $schemaWarnings[] = 'Falta folios_alerta: no hay reportes de stock POS para mostrar.';
        }

        $hasLegacyCaf = $tableExists($pdo, 'cafs') && $tableExists($pdo, 'caf_consumos');
        $hasSaaSCaf   = $tableExists($pdo, 'caf_archivos');
        if ($hasLegacyCaf || $hasSaaSCaf) {
            $cafMgr = new \App\Services\CafCentralManager();
            $pedidoOpt = $cafMgr->calcularPedidoOptimo(60, 1.3);
        } else {
            $schemaWarnings[] = 'Faltan tablas CAF legacy: la recomendacion automatica de pedido queda desactivada.';
        }
    } catch (\Exception $e) {
        $dbError = $e->getMessage();
    }
}

$urgencia = $alertaData['urgencia_global'] ?? 'desconocido';
$colorMap = ['ok' => '#198754', 'bajo' => '#fd7e14', 'critico' => '#dc3545', 'desconocido' => '#6c757d'];
$iconMap  = ['ok' => 'bi-check-circle-fill', 'bajo' => 'bi-exclamation-triangle-fill',
             'critico' => 'bi-x-octagon-fill', 'desconocido' => 'bi-question-circle'];
$urgColor = $colorMap[$urgencia] ?? '#6c757d';
$urgIcon  = $iconMap[$urgencia]  ?? 'bi-question-circle';

$labelNivel = fn(string $n) => match($n) {
    'critico' => '<span class="badge bg-danger">CRÍTICO</span>',
    'bajo'    => '<span class="badge bg-warning text-dark">BAJO</span>',
    'ok'      => '<span class="badge bg-success">OK</span>',
    default   => '<span class="badge bg-secondary">?</span>',
};
?>

<!-- ═══════════════════════════════════════════════════════════════
     BANNER DE URGENCIA GLOBAL
═══════════════════════════════════════════════════════════════ -->
<div class="d-flex align-items-center gap-3 mb-4 p-3 rounded-3"
     style="background:<?= $urgColor ?>22; border:2px solid <?= $urgColor ?>; color:<?= $urgColor ?>">
    <i class="bi <?= $urgIcon ?>" style="font-size:2rem"></i>
    <div>
        <div style="font-size:1.2rem;font-weight:700">
            Urgencia global de folios:
            <strong style="text-transform:uppercase"><?= htmlspecialchars($urgencia) ?></strong>
        </div>
        <?php if (!empty($alertaData)): ?>
        <div style="font-size:.85rem;opacity:.85">
            <?= (int)($alertaData['machines_critico'] ?? 0) ?> crítico ·
            <?= (int)($alertaData['machines_bajo']    ?? 0) ?> bajo ·
            <?= (int)($alertaData['machines_ok']      ?? 0) ?> ok ·
            <?= (int)($alertaData['machines_stale']   ?? 0) ?> sin reporte reciente
            <?php if (!empty($alertaData['min_dias_estimados'])): ?>
            · Mínimo estimado: <strong><?= number_format((float)$alertaData['min_dias_estimados'],1) ?> días</strong>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="ms-auto">
        <button class="btn btn-sm btn-outline-secondary" onclick="recargar()">
            <i class="bi bi-arrow-clockwise"></i> Actualizar
        </button>
    </div>
</div>

<?php if ($dbError): ?>
<div class="d-alert danger"><i class="bi bi-database-x"></i> Error DB: <?= htmlspecialchars($dbError) ?></div>
<?php endif; ?>

<?php if ($schemaWarnings): ?>
<div class="d-alert warning">
    <i class="bi bi-exclamation-triangle"></i>
    <?= htmlspecialchars(implode(' ', $schemaWarnings)) ?>
    No se crean tablas automaticamente desde admin.
</div>
<?php endif; ?>

<div class="row g-4">

<!-- ═══════════════════════════════════════════════════════════════
     COL IZQUIERDA: Alertas por máquina POS
═══════════════════════════════════════════════════════════════ -->
<div class="col-lg-7">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-phone"></i> Stock por Máquina POS</span>
            <small class="text-muted">Reportado por los APKs</small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($alertaData['alertas'])): ?>
            <div class="p-3 text-muted text-center">
                <i class="bi bi-inbox" style="font-size:2rem;opacity:.3"></i><br>
                Sin reportes de stock recibidos aún.<br>
                <small>Los APKs envían su stock al detectar nivel bajo o crítico.</small>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Sucursal / Máquina</th>
                            <th>Tipo</th>
                            <th class="text-end">Folios</th>
                            <th class="text-end">Días est.</th>
                            <th>Nivel</th>
                            <th>Actualizado</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($alertaData['alertas'] as $a): ?>
                    <tr class="<?= $a['nivel'] === 'critico' ? 'table-danger' : ($a['nivel'] === 'bajo' ? 'table-warning' : '') ?>
                                <?= (int)$a['obsoleto'] ? 'opacity-50' : '' ?>">
                        <td>
                            <strong><?= htmlspecialchars($a['sucursal_id']) ?></strong>
                            <?php if ($a['maquina_id'] !== ''): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($a['maquina_id']) ?></small>
                            <?php endif; ?>
                            <?php if ((int)$a['obsoleto']): ?>
                            <span class="badge bg-secondary ms-1" title="Sin reporte en >6h">stale</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-secondary">T<?= (int)$a['tipo_dte'] ?></span></td>
                        <td class="text-end fw-bold"><?= number_format((int)$a['folios_locales']) ?></td>
                        <td class="text-end">
                            <?= $a['dias_estimados'] !== null
                                ? number_format((float)$a['dias_estimados'], 1)
                                : '—' ?>
                        </td>
                        <td><?= $labelNivel($a['nivel']) ?></td>
                        <td><small class="text-muted"><?= htmlspecialchars(substr($a['actualizado'], 0, 16)) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recomendación de pedido por tipo -->
    <?php if (!empty($alertaData['pedido_recomendado'])): ?>
    <div class="card mt-3">
        <div class="card-header">
            <i class="bi bi-cart-plus"></i> Folios a Pedir al SII (basado en alertas POS)
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr>
                        <th>Tipo DTE</th>
                        <th class="text-end">Máquinas urgentes</th>
                        <th class="text-end">Folios actuales</th>
                        <th class="text-end fw-bold">Sugerido pedir</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($alertaData['pedido_recomendado'] as $r): ?>
                    <tr>
                        <td><span class="badge bg-primary">Tipo <?= (int)$r['tipo_dte'] ?></span></td>
                        <td class="text-end"><?= (int)$r['maquinas_urgentes'] ?> / <?= (int)$r['maquinas_total'] ?></td>
                        <td class="text-end"><?= number_format((int)$r['folios_actuales']) ?></td>
                        <td class="text-end fw-bold text-danger"><?= number_format((int)$r['sugerido_pedir']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <small class="text-muted">200 folios por máquina urgente como base mínima.</small>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     COL DERECHA: Cola de DTEs + pedido óptimo global
═══════════════════════════════════════════════════════════════ -->
<div class="col-lg-5">

    <!-- Cola de DTEs locales -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-stack"></i> Cola de Envío al SII</span>
            <?php $pend = (int)($queueData['resumen']['pendiente'] ?? 0);
                  $errs = (int)($queueData['resumen']['error']     ?? 0);
                  $drop = (int)($queueData['resumen']['dropped']   ?? 0);
                  if ($pend > 0 || $errs > 0): ?>
            <button class="btn btn-sm btn-primary"
                    onclick="procesarCola()"
                    title="Procesar los DTEs pendientes y enviarlos al SII">
                <i class="bi bi-send"></i> Procesar ahora
            </button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!$dbOk): ?>
            <div class="text-muted">DB no disponible</div>
            <?php else: ?>
            <div class="row g-2 text-center mb-3">
                <?php
                $estados = [
                    'pendiente'  => ['label'=>'Pendiente',   'color'=>'warning'],
                    'enviado'    => ['label'=>'Enviado SII',  'color'=>'success'],
                    'error'      => ['label'=>'Error',        'color'=>'danger'],
                    'dropped'    => ['label'=>'Abandonado',   'color'=>'secondary'],
                ];
                foreach ($estados as $k => $e): ?>
                <div class="col-6">
                    <div class="p-2 rounded-2" style="background:var(--bs-<?= $e['color'] ?>-bg,#f8f9fa)">
                        <div class="fw-bold fs-4 text-<?= $e['color'] ?>"><?= (int)($queueData['resumen'][$k] ?? 0) ?></div>
                        <div class="text-muted" style="font-size:.75rem"><?= $e['label'] ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($queueData['por_sucursal'])): ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Sucursal</th><th class="text-center">Pend.</th><th class="text-center">Err.</th><th class="text-center">OK</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($queueData['por_sucursal'] as $s): ?>
                    <tr>
                        <td><small><?= htmlspecialchars($s['sucursal_id']) ?></small></td>
                        <td class="text-center"><small class="text-warning fw-bold"><?= (int)$s['pendiente'] ?></small></td>
                        <td class="text-center"><small class="text-danger"><?= (int)$s['con_error'] ?></small></td>
                        <td class="text-center"><small class="text-success"><?= (int)$s['enviado'] ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($queueData['ult_errores'])): ?>
            <hr>
            <div class="text-danger mb-1" style="font-size:.8rem"><strong>Últimos errores:</strong></div>
            <?php foreach ($queueData['ult_errores'] as $e): ?>
            <div style="border-left:3px solid #dc3545; padding:6px 8px; margin-bottom:6px;
                        background:#fff5f5; border-radius:3px; font-size:.78rem">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-danger">
                        T<?= (int)$e['tipo_dte'] ?>F<?= (int)$e['folio'] ?>
                    </strong>
                    <span>
                        <small class="text-muted me-2">
                            <?= htmlspecialchars($e['estado'] ?? '') ?> · intentos:
                            <?= (int)($e['intentos'] ?? 0) ?>
                        </small>
                        <button class="btn btn-sm btn-outline-primary py-0 px-2"
                                style="font-size:.7rem"
                                onclick="reenviarUno(<?= (int)$e['id'] ?>, this)"
                                title="Reenviar este documento al SII">
                            Reenviar
                        </button>
                    </span>
                </div>
                <div class="text-danger" style="word-break:break-word; font-family:monospace;
                                                font-size:.7rem; max-height:6em; overflow:auto;
                                                padding:2px 0;">
                    <?= htmlspecialchars($e['ultimo_error'] ?? '') ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pedido óptimo global (desde CafCentralManager) -->
    <?php if (!empty($pedidoOpt['sugerencias'])): ?>
    <div class="card">
        <div class="card-header"><i class="bi bi-graph-up-arrow"></i> Pedido Óptimo al SII (consumo 90 días)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Tipo</th>
                            <th class="text-end">Disponibles</th>
                            <th class="text-end">Días rest.</th>
                            <th class="text-end fw-bold">Pedir</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pedidoOpt['sugerencias'] as $s): ?>
                    <?php $dias = $s['dias_restantes']; ?>
                    <tr class="<?= (!empty($s['urgente'])) ? 'table-warning' : '' ?>">
                        <td><span class="badge bg-primary">T<?= (int)$s['tipo_dte'] ?></span></td>
                        <td class="text-end"><?= number_format((int)$s['disponibles']) ?></td>
                        <td class="text-end <?= $dias !== null && $dias < 7 ? 'text-danger fw-bold' : '' ?>">
                            <?= $dias !== null ? number_format($dias, 1) : '∞' ?>
                        </td>
                        <td class="text-end fw-bold <?= (int)$s['sugerido_pedir'] > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= $s['sugerido_pedir'] > 0 ? number_format((int)$s['sugerido_pedir']) : '—' ?>
                        </td>
                        <td><?= !empty($s['urgente']) ? '<i class="bi bi-exclamation-triangle-fill text-danger"></i>' : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-2">
                <small class="text-muted">Proyección <?= (int)$pedidoOpt['dias_proyeccion'] ?> días · factor <?= $pedidoOpt['factor_seguridad'] ?>x</small>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /col derecha -->
</div><!-- /row -->

<script>
function recargar() { location.reload(); }

function procesarCola() {
    if (!confirm('¿Procesar ahora los DTEs pendientes y enviar al SII?')) return;
    const btn = document.querySelector('[onclick="procesarCola()"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Procesando…'; }

    fetch('api.php?action=dte_cola_procesar&max=20', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            const res = d.resultados || [];
            const ok  = res.filter(r => r.resultado === 'OK');
            const err = res.filter(r => r.resultado !== 'OK');
            let msg = `Procesados: ${d.procesados ?? 0}\n✓ Enviados: ${ok.length}\n✗ Errores: ${err.length}`;
            if (err.length) {
                msg += '\n\n──── Detalle de errores ────';
                err.forEach(r => {
                    msg += `\n\nFolio ${r.folio}  [${r.resultado}]\n${r.error || '(sin detalle)'}`;
                });
            }
            alert(msg);
            location.reload();
        })
        .catch(e => { alert('Error: ' + e); if (btn) btn.disabled = false; });
}

function reenviarUno(id, btn) {
    if (!confirm('¿Reenviar este documento al SII?\nSe reinicia el contador de intentos.')) return;
    btn.disabled = true;
    const txt = btn.textContent;
    btn.textContent = '…';
    fetch('api.php?action=dte_cola_procesar_uno&id=' + id, { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            if (d.resultado === 'OK') {
                alert('✓ Enviado al SII\nFolio: ' + d.folio + '\nTrackID: ' + d.trackId);
                location.reload();
            } else if (d.ok) {
                alert('Reintento terminado pero falló:\n[' + d.resultado + ']\n' + (d.error || ''));
                location.reload();
            } else {
                alert('Error: ' + (d.error || 'desconocido'));
                btn.disabled = false;
                btn.textContent = txt;
            }
        })
        .catch(e => {
            alert('Error de red: ' + e);
            btn.disabled = false;
            btn.textContent = txt;
        });
}
</script>
