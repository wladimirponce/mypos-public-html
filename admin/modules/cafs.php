<?php
/**
 * Módulo Folios CAF — Dashboard de gestión centralizada
 *
 * Secciones:
 *   1. Resumen ejecutivo (pool central + alertas críticas)
 *   2. Stock por sucursal (visual con barras de días estimados)
 *   3. Asignación manual desde pool (admin elige cuántos folios va a cada sucursal)
 *   4. Subir nuevo CAF (upload XML desde SII)
 *   5. CAFs registrados (tabla completa con distribución proporcional)
 *   6. Historial de consumo (caf_consumos con filtros y paginación)
 */

use App\Services\CafCentralManager;
use App\Core\Database;

$pdo = Database::getInstance();

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

$hasCentralCafSchema = $tableExists($pdo, 'cafs')
    && $tableExists($pdo, 'caf_consumos')
    && $tableExists($pdo, 'caf_consumo_medio');

if (!$hasCentralCafSchema) {
    $hasSiiCafSchema = $tableExists($pdo, 'sii_caf') && $tableExists($pdo, 'sii_folio_consumo');
    $foliosSii = [];

    if ($hasSiiCafSchema) {
        $foliosSii = $pdo->query(
            "SELECT
                c.id,
                c.empresa_id,
                e.rut,
                e.razon_social,
                c.tipo_dte,
                c.folio_desde,
                c.folio_hasta,
                c.ambiente_sii,
                c.fecha_autorizacion,
                c.activo,
                COALESCE(fc.consumidos, 0) AS consumidos,
                GREATEST(c.folio_hasta - c.folio_desde + 1 - COALESCE(fc.consumidos, 0), 0) AS disponibles
             FROM sii_caf c
             LEFT JOIN sii_empresa e ON e.id = c.empresa_id
             LEFT JOIN (
                SELECT caf_id, COUNT(*) AS consumidos
                FROM sii_folio_consumo
                GROUP BY caf_id
             ) fc ON fc.caf_id = c.id
             ORDER BY c.creado_en DESC, c.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $tiposNombre = [
        33 => 'Factura', 34 => 'Factura Exenta', 39 => 'Boleta',
        41 => 'Boleta Exenta', 52 => 'Guia Despacho', 56 => 'Nota Debito', 61 => 'Nota Credito',
    ];

    $totalDisponibles = 0;
    foreach ($foliosSii as $folio) {
        if ((int)$folio['activo'] === 1) $totalDisponibles += (int)$folio['disponibles'];
    }
    ?>
    <div class="d-alert warning">
        <i class="bi bi-exclamation-triangle"></i>
        El panel centralizado por sucursal necesita las tablas legacy <strong>cafs</strong>,
        <strong>caf_consumos</strong> y <strong>caf_consumo_medio</strong>. No se crean tablas automaticamente desde admin.
        <?php if ($hasSiiCafSchema): ?>
            Se muestra una vista de solo lectura usando <strong>sii_caf</strong>.
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="d-card"><div class="d-card-body"><div class="text-muted small">CAFs SII</div><div class="h3 mb-0"><?= count($foliosSii) ?></div></div></div>
        </div>
        <div class="col-md-4">
            <div class="d-card"><div class="d-card-body"><div class="text-muted small">Folios disponibles</div><div class="h3 mb-0"><?= number_format($totalDisponibles, 0, ',', '.') ?></div></div></div>
        </div>
        <div class="col-md-4">
            <div class="d-card"><div class="d-card-body"><div class="text-muted small">Modo</div><div class="h5 mb-0">Solo lectura</div></div></div>
        </div>
    </div>

    <div class="d-card">
        <div class="d-card-header"><i class="bi bi-ticket-perforated"></i> CAFs registrados en SII</div>
        <div class="d-card-body" style="padding:0">
            <?php if (!$hasSiiCafSchema): ?>
                <div style="padding:32px" class="text-muted">No existe el esquema SII de folios en esta base: faltan sii_caf o sii_folio_consumo.</div>
            <?php elseif (!$foliosSii): ?>
                <div style="padding:32px" class="text-muted">No hay CAFs registrados en sii_caf.</div>
            <?php else: ?>
                <table class="d-table">
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>Tipo DTE</th>
                            <th>Rango</th>
                            <th>Consumidos</th>
                            <th>Disponibles</th>
                            <th>Ambiente</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($foliosSii as $folio): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string)($folio['razon_social'] ?? 'Sin razon social')) ?></strong><br>
                                    <span class="text-muted small"><?= htmlspecialchars((string)($folio['rut'] ?? 'Sin RUT')) ?></span>
                                </td>
                                <td><?= htmlspecialchars($tiposNombre[(int)$folio['tipo_dte']] ?? ('DTE ' . $folio['tipo_dte'])) ?></td>
                                <td><?= number_format((int)$folio['folio_desde'], 0, ',', '.') ?> - <?= number_format((int)$folio['folio_hasta'], 0, ',', '.') ?></td>
                                <td><?= number_format((int)$folio['consumidos'], 0, ',', '.') ?></td>
                                <td><?= number_format((int)$folio['disponibles'], 0, ',', '.') ?></td>
                                <td><?= htmlspecialchars((string)$folio['ambiente_sii']) ?></td>
                                <td><?= ((int)$folio['activo'] === 1) ? '<span class="d-badge prod">Activo</span>' : '<span class="d-badge danger">Inactivo</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return;
}

$svc = new CafCentralManager();
$msg = $error = null;

// ── POST: Acciones ────────────────────────────────────────────────────────────
if (!empty($_POST['action'])) {
    switch ($_POST['action']) {

        case 'subir_caf':
            if (empty($_FILES['caf_file']['tmp_name'])) {
                $error = 'Selecciona un archivo XML CAF';
                break;
            }
            $content  = file_get_contents($_FILES['caf_file']['tmp_name']);
            $sucursal = $_POST['sucursal_id'] ?: null;
            $origen   = $sucursal ? 'LEGACY' : 'CENTRAL';
            $r = $svc->subirCaf($content, $sucursal, $origen);
            if (!$r['ok'])                $error = $r['error'];
            elseif (!empty($r['duplicado'])) $msg = "CAF ya estaba registrado (ID #{$r['id']})";
            else $msg = "✓ CAF #{$r['id']} subido: tipo {$r['tipo']}, folios {$r['desde']}–{$r['hasta']}";
            break;

        case 'asignacion_manual':
            $sucursalId = trim($_POST['sucursal_id'] ?? '');
            $tipoDte    = (int)($_POST['tipo_dte']   ?? 0);
            $cantidad   = (int)($_POST['cantidad']   ?? 0);
            if (!$sucursalId || !$tipoDte || $cantidad <= 0) {
                $error = 'Completa todos los campos de la asignación manual.';
                break;
            }
            $r = $svc->asignacionManual($sucursalId, $tipoDte, $cantidad);
            if (!$r['ok']) {
                $error = $r['error'];
            } else {
                $msg = "✓ Asignados {$r['asignados']} folios de tipo $tipoDte a sucursal «{$sucursalId}»";
                if ($r['faltaron'] > 0) {
                    $msg .= " (faltan {$r['faltaron']} por falta de pool)";
                }
                $rangosStr = implode(', ', array_map(
                    fn($rng) => "{$rng['desde']}–{$rng['hasta']}",
                    $r['rangos']
                ));
                $msg .= ". Rangos: $rangosStr";
            }
            break;

        case 'distribuir':
            $id   = (int)$_POST['caf_id'];
            $sucs = $_POST['sucursales'] ?? [];
            if (!$id || !$sucs) { $error = 'Faltan parámetros'; break; }
            $r = $svc->distribuirCaf($id, $sucs);
            if (!$r['ok']) $error = $r['error'];
            else $msg = '✓ Distribuido en ' . count($r['distribucion']) . ' sub-CAFs';
            break;

        case 'recalcular_consumo':
            $r = $svc->recalcularConsumoMedio();
            $msg = "✓ Recalculados {$r['actualizados']} registros de consumo medio";
            break;
    }
}

// ── Cargar datos ──────────────────────────────────────────────────────────────
$resumen      = $svc->resumenGlobal(7);
$pedidoOptimo = $svc->calcularPedidoOptimo(60, 1.3);
$stockSucs    = $svc->stockPorSucursal();

// Historial: filtros desde GET
$filtrosHist = [
    'sucursal_id' => trim($_GET['h_suc']   ?? ''),
    'tipo_dte'    => (int)($_GET['h_tipo']  ?? 0),
    'desde'       => trim($_GET['h_desde']  ?? ''),
    'hasta'       => trim($_GET['h_hasta']  ?? date('Y-m-d')),
    'page'        => max(1, (int)($_GET['h_page'] ?? 1)),
];
$historial = $svc->historialConsumo($filtrosHist);

$pdo = Database::getInstance();

$cafsPorTipo = $pdo->query("
    SELECT id, tipo_dte, rut_emisor, sucursal_id, folio_desde, folio_hasta,
           folio_actual, agotado, origen, fecha_subida,
           (folio_hasta - folio_actual + 1) AS restantes
    FROM cafs
    ORDER BY tipo_dte, sucursal_id IS NULL DESC, folio_desde
")->fetchAll(PDO::FETCH_ASSOC);

$sucursales = [];
try {
    $sucursales = $pdo->query(
        "SELECT id_sucursal, nombre FROM dim_sucursal ORDER BY nombre"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_) {
    $sucursales = $pdo->query("
        SELECT DISTINCT sucursal_id AS id_sucursal, sucursal_id AS nombre
        FROM caf_consumos
        UNION
        SELECT DISTINCT sucursal_id AS id_sucursal, sucursal_id AS nombre
        FROM cafs WHERE sucursal_id IS NOT NULL
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Folios en pool central por tipo
$poolPorTipo = [];
foreach ($cafsPorTipo as $c) {
    if ($c['sucursal_id'] === null && !$c['agotado']) {
        $t = $c['tipo_dte'];
        $poolPorTipo[$t] = ($poolPorTipo[$t] ?? 0) + $c['restantes'];
    }
}

$tiposNombre = [
    33 => 'Factura',      34 => 'Factura Exenta', 39 => 'Boleta',
    41 => 'Boleta Exenta', 52 => 'Guía Despacho',  56 => 'Nota Débito', 61 => 'Nota Crédito',
];

// ── Helpers ───────────────────────────────────────────────────────────────────
function urgenciaColor(string $u): string {
    return match($u) {
        'critico'   => '#dc3545',
        'bajo'      => '#fd7e14',
        'ok'        => '#198754',
        default     => '#6c757d',
    };
}
function urgenciaLabel(string $u): string {
    return match($u) {
        'critico'   => 'CRÍTICO',
        'bajo'      => 'BAJO',
        'ok'        => 'OK',
        default     => '—',
    };
}
?>

<style>
/* ── Tarjetas de sucursal ─────────────────────────────────── */
.suc-card          { border:1px solid var(--c-border); border-radius:10px; padding:18px 20px; margin-bottom:14px; }
.suc-card-header   { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
.suc-card-title    { font-weight:700; font-size:.97rem; }
.suc-tipo-row      { display:flex; align-items:center; gap:12px; margin-bottom:8px; font-size:.83rem; }
.suc-tipo-label    { width:140px; flex-shrink:0; }
.suc-tipo-bar-wrap { flex:1; background:#e9ecef; border-radius:100px; height:8px; overflow:hidden; }
.suc-tipo-bar      { height:100%; border-radius:100px; transition:width .4s; }
.suc-tipo-val      { width:90px; text-align:right; flex-shrink:0; font-variant-numeric:tabular-nums; }
.suc-tipo-dias     { width:80px; text-align:right; flex-shrink:0; font-size:.77rem; }

/* ── Tabla historial ─────────────────────────────────────── */
.hist-table th { font-size:.77rem; background:#f8f9fa; }
.hist-table td { font-size:.82rem; }

/* ── Panel asignación manual ─────────────────────────────── */
.assign-panel { background:var(--c-bg); border:1.5px solid var(--c-border); border-radius:10px; padding:20px; }
</style>

<?php if ($msg):   ?><div class="d-alert success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="d-alert danger" ><i class="bi bi-x-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════════════
     1. RESUMEN EJECUTIVO
     ════════════════════════════════════════════════════════════════════════════ -->

<div class="row g-3 mb-3">
    <!-- Pool central -->
    <div class="col-md-4">
        <div class="d-card h-100">
            <div class="d-card-header"><i class="bi bi-archive"></i> Pool central</div>
            <div class="d-card-body">
                <?php if (empty($poolPorTipo)): ?>
                    <div style="color:var(--c-text-muted); font-size:.85rem; text-align:center; padding:12px 0;">
                        Sin folios en pool.<br>Sube un CAF sin asignar sucursal.
                    </div>
                <?php else: ?>
                    <?php foreach ($poolPorTipo as $tipo => $n): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center;
                                margin-bottom:8px; padding:6px 10px;
                                background:var(--c-bg); border-radius:6px;">
                        <span style="font-size:.82rem;">
                            <strong><?= $tipo ?></strong>
                            <span style="color:var(--c-text-muted)"> <?= $tiposNombre[$tipo] ?? '' ?></span>
                        </span>
                        <span class="d-badge info"><?= number_format($n) ?> folios</span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Alertas críticas -->
    <div class="col-md-4">
        <div class="d-card h-100">
            <div class="d-card-header"><i class="bi bi-exclamation-triangle"></i> Alertas de stock</div>
            <div class="d-card-body">
                <?php if (empty($resumen['alertas_stock_bajo'])): ?>
                    <div style="color:#198754; font-size:.85rem; text-align:center; padding:12px 0;">
                        <i class="bi bi-check-circle-fill"></i> Todo en niveles normales
                    </div>
                <?php else: ?>
                    <?php foreach ($resumen['alertas_stock_bajo'] as $a): ?>
                    <div style="background:#fff5f5; border-left:3px solid #dc3545;
                                padding:6px 10px; border-radius:0 6px 6px 0; margin-bottom:8px; font-size:.8rem;">
                        <strong><?= htmlspecialchars($a['sucursal_id']) ?></strong>
                        — tipo <?= $a['tipo_dte'] ?>:
                        <?= number_format($a['folios_disponibles']) ?> folios
                        (~<?= round($a['folios_disponibles'] / max(1, $a['consumo_dia_prom'])) ?> días)
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sugerencia de pedido al SII -->
    <div class="col-md-4">
        <div class="d-card h-100">
            <div class="d-card-header"><i class="bi bi-graph-up-arrow"></i> Pedido sugerido al SII</div>
            <div class="d-card-body">
                <?php
                $hayUrgentes = false;
                foreach ($pedidoOptimo['sugerencias'] as $s) {
                    if ($s['sugerido_pedir'] > 0) { $hayUrgentes = true; break; }
                }
                if (!$hayUrgentes): ?>
                    <div style="color:#198754; font-size:.85rem; text-align:center; padding:12px 0;">
                        <i class="bi bi-check-circle-fill"></i> Stock suficiente por <?= $pedidoOptimo['dias_proyeccion'] ?> días
                    </div>
                <?php else: ?>
                    <?php foreach ($pedidoOptimo['sugerencias'] as $s):
                        if ($s['sugerido_pedir'] == 0) continue; ?>
                    <div style="display:flex; justify-content:space-between; align-items:center;
                                margin-bottom:8px; padding:6px 10px;
                                background:<?= $s['urgente'] ? '#fff5f5' : 'var(--c-bg)' ?>;
                                border-radius:6px;">
                        <span style="font-size:.82rem;">
                            <strong><?= $s['tipo_dte'] ?></strong>
                            <?php if ($s['urgente']): ?>
                                <span style="color:#dc3545; font-size:.72rem; margin-left:4px;">⚠ <?= $s['dias_restantes'] ?> días</span>
                            <?php endif; ?>
                        </span>
                        <span style="font-weight:700; color:#d84315; font-size:.9rem;">
                            <?= number_format($s['sugerido_pedir']) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <div style="font-size:.72rem; color:var(--c-text-muted); margin-top:8px;">
                        Proyección <?= $pedidoOptimo['dias_proyeccion'] ?> días · factor <?= $pedidoOptimo['factor_seguridad'] ?>x<br>
                        Solicitar en <a href="https://palena.sii.cl" target="_blank">palena.sii.cl</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     2. STOCK POR SUCURSAL
     ════════════════════════════════════════════════════════════════════════════ -->

<div class="d-card mb-3">
    <div class="d-card-header">
        <i class="bi bi-bar-chart-steps"></i> Stock por sucursal
        <small style="font-weight:400; color:var(--c-text-muted); margin-left:8px;">
            Barras = días estimados de folios disponibles según consumo histórico
        </small>
    </div>
    <div class="d-card-body">
        <?php if (empty($stockSucs)): ?>
            <div style="text-align:center; color:var(--c-text-muted); padding:24px 0; font-size:.88rem;">
                Sin sucursales con folios asignados. Sube un CAF y asígnalo o usa el pool central.
            </div>
        <?php else: ?>
        <div class="row g-3">
        <?php foreach ($stockSucs as $suc): ?>
            <?php
            // Urgencia global de la sucursal: el peor tipo
            $urgGlobal = 'ok';
            foreach ($suc['tipos'] as $t) {
                if ($t['urgencia'] === 'critico') { $urgGlobal = 'critico'; break; }
                if ($t['urgencia'] === 'bajo')    $urgGlobal = 'bajo';
            }
            $borderColor = urgenciaColor($urgGlobal);
            ?>
            <div class="col-md-6 col-xl-4">
                <div class="suc-card" style="border-color:<?= $borderColor ?>20; border-left:4px solid <?= $borderColor ?>">
                    <div class="suc-card-header">
                        <span class="suc-card-title">
                            <i class="bi bi-geo-alt-fill" style="color:<?= $borderColor ?>"></i>
                            <?= htmlspecialchars($suc['sucursal_nombre']) ?>
                        </span>
                        <span style="font-size:.72rem; background:<?= $borderColor ?>20;
                                     color:<?= $borderColor ?>; padding:2px 8px; border-radius:100px; font-weight:700;">
                            <?= urgenciaLabel($urgGlobal) ?>
                        </span>
                    </div>

                    <?php foreach ($suc['tipos'] as $t): ?>
                    <?php
                    $maxDias = 90; // eje 100% de la barra = 90 días
                    $pct = $t['dias_estimados'] !== null
                        ? min(100, round($t['dias_estimados'] / $maxDias * 100))
                        : 0;
                    $color = urgenciaColor($t['urgencia']);
                    ?>
                    <div class="suc-tipo-row">
                        <div class="suc-tipo-label">
                            <strong><?= $t['tipo_dte'] ?></strong>
                            <span style="color:var(--c-text-muted)"> <?= $tiposNombre[$t['tipo_dte']] ?? '' ?></span>
                        </div>
                        <div class="suc-tipo-bar-wrap">
                            <div class="suc-tipo-bar"
                                 style="width:<?= $pct ?>%; background:<?= $color ?>"></div>
                        </div>
                        <div class="suc-tipo-val"><?= number_format($t['folios_disponibles']) ?></div>
                        <div class="suc-tipo-dias" style="color:<?= $color ?>; font-weight:600;">
                            <?php if ($t['dias_estimados'] !== null): ?>
                                ~<?= $t['dias_estimados'] ?> d
                            <?php else: ?>
                                <span style="color:var(--c-text-muted)">— d</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div style="font-size:.72rem; color:var(--c-text-muted); margin-top:8px; text-align:right;">
                        <?= number_format($suc['total_folios']) ?> folios totales
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     3. ASIGNACIÓN MANUAL + SUBIR CAF
     ════════════════════════════════════════════════════════════════════════════ -->

<div class="row g-3 mb-3">

    <!-- Asignación manual -->
    <div class="col-md-6">
        <div class="d-card h-100">
            <div class="d-card-header"><i class="bi bi-send-check"></i> Asignación manual desde pool</div>
            <div class="d-card-body">
                <?php if (empty($poolPorTipo)): ?>
                    <div class="d-alert warning" style="margin:0;">
                        <i class="bi bi-inbox"></i>
                        Sin folios en pool central. Sube un CAF sin asignar sucursal.
                    </div>
                <?php else: ?>
                <form method="POST" action="?module=cafs">
                    <input type="hidden" name="action" value="asignacion_manual">
                    <div class="assign-panel">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Sucursal destino</label>
                            <select name="sucursal_id" class="form-select" required>
                                <option value="">— Seleccionar —</option>
                                <?php foreach ($sucursales as $s): ?>
                                    <option value="<?= htmlspecialchars($s['id_sucursal']) ?>">
                                        <?= htmlspecialchars($s['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Tipo DTE</label>
                            <select name="tipo_dte" class="form-select" required>
                                <option value="">— Tipo —</option>
                                <?php foreach ($poolPorTipo as $t => $n): ?>
                                    <option value="<?= $t ?>">
                                        <?= $t ?> — <?= $tiposNombre[$t] ?? 'Tipo '.$t ?>
                                        (<?= number_format($n) ?> en pool)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Cantidad de folios</label>
                            <input type="number" name="cantidad" class="form-control"
                                   min="1" step="1" placeholder="Ej: 500" required>
                            <div class="form-text">
                                Se toma del pool central y se asigna directamente. Si el pool
                                no tiene suficiente, se asigna lo que haya.
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-send-check"></i> Asignar folios
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Subir CAF -->
    <div class="col-md-6">
        <div class="d-card h-100">
            <div class="d-card-header"><i class="bi bi-cloud-upload"></i> Subir nuevo CAF (desde SII)</div>
            <div class="d-card-body">
                <form method="POST" enctype="multipart/form-data" action="?module=cafs">
                    <input type="hidden" name="action" value="subir_caf">
                    <div class="assign-panel">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Archivo XML del CAF</label>
                            <input type="file" name="caf_file" accept=".xml" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Asignar a (opcional)</label>
                            <select name="sucursal_id" class="form-select">
                                <option value="">— Pool central (distribuir o asignar después) —</option>
                                <?php foreach ($sucursales as $s): ?>
                                    <option value="<?= htmlspecialchars($s['id_sucursal']) ?>">
                                        <?= htmlspecialchars($s['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn btn-success w-100">
                            <i class="bi bi-upload"></i> Subir al servidor
                        </button>
                        <div class="form-text mt-2">
                            Sin sucursal → queda en pool central. Con sucursal → asignación directa.
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     4. CAFs REGISTRADOS (tabla completa)
     ════════════════════════════════════════════════════════════════════════════ -->

<div class="d-card mb-3">
    <div class="d-card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul"></i> CAFs registrados</span>
        <form method="POST" action="?module=cafs" class="d-inline">
            <input type="hidden" name="action" value="recalcular_consumo">
            <button class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-clockwise"></i> Recalcular consumo medio
            </button>
        </form>
    </div>
    <div class="d-card-body p-0" style="overflow-x:auto;">
        <table class="d-table">
            <thead>
                <tr>
                    <th>ID</th><th>Tipo</th><th>Sucursal</th><th>Rango</th>
                    <th>Actual</th><th>Restantes</th><th>Origen</th><th>Subido</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cafsPorTipo as $c): ?>
                <tr style="<?= $c['agotado'] ? 'opacity:.4' : ($c['sucursal_id'] === null ? 'background:#fff8e1' : '') ?>">
                    <td><small>#<?= $c['id'] ?></small></td>
                    <td>
                        <strong><?= $c['tipo_dte'] ?></strong>
                        <small style="color:var(--c-text-muted)"> <?= $tiposNombre[$c['tipo_dte']] ?? '' ?></small>
                    </td>
                    <td>
                        <?php if ($c['sucursal_id'] === null): ?>
                            <span style="color:#856404; font-weight:700; font-size:.8rem;">
                                <i class="bi bi-archive"></i> POOL
                            </span>
                        <?php else: ?>
                            <?= htmlspecialchars($c['sucursal_id']) ?>
                        <?php endif; ?>
                    </td>
                    <td style="font-variant-numeric:tabular-nums; font-size:.82rem;">
                        <?= number_format($c['folio_desde']) ?> — <?= number_format($c['folio_hasta']) ?>
                    </td>
                    <td style="font-variant-numeric:tabular-nums;"><?= number_format($c['folio_actual']) ?></td>
                    <td>
                        <?php if ($c['agotado']): ?>
                            <span class="d-badge" style="background:#6c757d; color:#fff;">AGOTADO</span>
                        <?php else: ?>
                            <strong><?= number_format($c['restantes']) ?></strong>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="d-badge <?= $c['origen'] === 'LEGACY' ? 'info' : 'prod' ?>">
                            <?= $c['origen'] ?>
                        </span>
                    </td>
                    <td><small style="color:var(--c-text-muted)"><?= date('d/m/Y', strtotime($c['fecha_subida'])) ?></small></td>
                    <td>
                        <?php if ($c['sucursal_id'] === null && !$c['agotado'] && !empty($sucursales)): ?>
                            <button class="btn btn-sm btn-warning"
                                    onclick="abrirDistribucion(<?= $c['id'] ?>, <?= $c['restantes'] ?>)">
                                Distribuir %
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($cafsPorTipo)): ?>
                <tr><td colspan="9" style="text-align:center; color:var(--c-text-muted); padding:24px;">
                    Sin CAFs registrados. Sube el primero con el formulario de arriba.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     5. HISTORIAL DE CONSUMO
     ════════════════════════════════════════════════════════════════════════════ -->

<div class="d-card mb-3">
    <div class="d-card-header"><i class="bi bi-clock-history"></i> Historial de consumo</div>
    <div class="d-card-body">

        <!-- Filtros -->
        <form method="GET" action="?module=cafs" class="row g-2 mb-3 align-items-end">
            <input type="hidden" name="module" value="cafs">
            <div class="col-sm-3">
                <label class="form-label small fw-semibold">Sucursal</label>
                <select name="h_suc" class="form-select form-select-sm">
                    <option value="">Todas</option>
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?= htmlspecialchars($s['id_sucursal']) ?>"
                            <?= $filtrosHist['sucursal_id'] === $s['id_sucursal'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small fw-semibold">Tipo DTE</label>
                <select name="h_tipo" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($tiposNombre as $t => $n): ?>
                        <option value="<?= $t ?>" <?= $filtrosHist['tipo_dte'] == $t ? 'selected' : '' ?>>
                            <?= $t ?> - <?= $n ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small fw-semibold">Desde</label>
                <input type="date" name="h_desde" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($filtrosHist['desde']) ?>">
            </div>
            <div class="col-sm-2">
                <label class="form-label small fw-semibold">Hasta</label>
                <input type="date" name="h_hasta" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($filtrosHist['hasta']) ?>">
            </div>
            <div class="col-sm-3">
                <button type="submit" class="btn btn-sm btn-primary me-1">
                    <i class="bi bi-search"></i> Filtrar
                </button>
                <a href="?module=cafs" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x"></i> Limpiar
                </a>
            </div>
        </form>

        <!-- Resultado -->
        <div style="font-size:.78rem; color:var(--c-text-muted); margin-bottom:8px;">
            <?= number_format($historial['total']) ?> registros
            (página <?= $historial['page'] ?> de <?= max(1, $historial['pages']) ?>)
        </div>

        <?php if (empty($historial['rows'])): ?>
            <div style="text-align:center; color:var(--c-text-muted); padding:20px 0; font-size:.88rem;">
                Sin registros de consumo para los filtros seleccionados.
            </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="d-table hist-table">
                <thead>
                    <tr>
                        <th>ID</th><th>Fecha</th><th>Sucursal</th>
                        <th>Tipo</th><th>Folio</th><th>Máquina</th><th>CAF</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($historial['rows'] as $h): ?>
                    <tr>
                        <td><small style="color:var(--c-text-muted)">#<?= $h['id'] ?></small></td>
                        <td><?= date('d/m/Y H:i', strtotime($h['fecha_consumo'])) ?></td>
                        <td><?= htmlspecialchars($h['sucursal_nombre']) ?></td>
                        <td>
                            <strong><?= $h['tipo_dte'] ?></strong>
                            <small style="color:var(--c-text-muted)"> <?= $tiposNombre[$h['tipo_dte']] ?? '' ?></small>
                        </td>
                        <td style="font-variant-numeric:tabular-nums; font-weight:700;">
                            <?= number_format($h['folio']) ?>
                        </td>
                        <td style="font-size:.75rem; color:var(--c-text-muted);">
                            <?= $h['maquina_id'] ? htmlspecialchars($h['maquina_id']) : '—' ?>
                        </td>
                        <td><small style="color:var(--c-text-muted)">#<?= $h['caf_id'] ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <?php if ($historial['pages'] > 1): ?>
        <nav style="margin-top:12px;">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php
                $baseUrl = '?module=cafs&h_suc=' . urlencode($filtrosHist['sucursal_id'])
                    . '&h_tipo=' . $filtrosHist['tipo_dte']
                    . '&h_desde=' . urlencode($filtrosHist['desde'])
                    . '&h_hasta=' . urlencode($filtrosHist['hasta'])
                    . '&h_page=';
                $cur = $historial['page'];
                $tot = $historial['pages'];
                $pages = array_unique(array_filter(array_merge(
                    [1, 2],
                    [$cur - 1, $cur, $cur + 1],
                    [$tot - 1, $tot]
                ), fn($p) => $p >= 1 && $p <= $tot));
                sort($pages);
                $prev = null;
                foreach ($pages as $p) {
                    if ($prev !== null && $p - $prev > 1) {
                        echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }
                    $active = $p === $cur ? 'active' : '';
                    echo "<li class=\"page-item $active\"><a class=\"page-link\" href=\"{$baseUrl}{$p}\">$p</a></li>";
                    $prev = $p;
                }
                ?>
            </ul>
        </nav>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<!-- Modal distribución proporcional -->
<div class="modal fade" id="modalDistribuir" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" action="?module=cafs" class="modal-content">
      <input type="hidden" name="action" value="distribuir">
      <input type="hidden" name="caf_id" id="distCafId">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-distribute-vertical"></i> Distribución proporcional</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:.88rem;">
            Se dividirán los <strong id="distRestantes"></strong> folios disponibles entre las
            sucursales seleccionadas, <em>proporcional a su consumo medio de los últimos 30 días</em>.
            Para una cantidad exacta usa el panel "Asignación manual".
        </p>
        <div style="max-height:280px; overflow-y:auto; border:1px solid var(--c-border); border-radius:8px; padding:12px;">
            <?php foreach ($sucursales as $s): ?>
            <div class="form-check mb-1">
              <input type="checkbox" name="sucursales[]" value="<?= htmlspecialchars($s['id_sucursal']) ?>"
                     id="suc_<?= htmlspecialchars($s['id_sucursal']) ?>" checked class="form-check-input">
              <label for="suc_<?= htmlspecialchars($s['id_sucursal']) ?>" class="form-check-label" style="font-size:.85rem;">
                <?= htmlspecialchars($s['nombre']) ?>
              </label>
            </div>
            <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-warning">
            <i class="bi bi-distribute-vertical"></i> Distribuir proporcionalmente
        </button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function abrirDistribucion(id, restantes) {
    document.getElementById('distCafId').value = id;
    document.getElementById('distRestantes').textContent = restantes.toLocaleString('es-CL');
    new bootstrap.Modal(document.getElementById('modalDistribuir')).show();
}
</script>
