<?php
/**
 * Módulo Ventas Reset — Limpieza de ventas de prueba por empresa
 * Permite seleccionar y eliminar ventas sin tocar folios ni CAFs.
 */

$vrMsg   = '';
$vrError = '';
$vrCount = 0;

$empresaId = isset($_SESSION['active_empresa_id']) ? (int)$_SESSION['active_empresa_id'] : 0;

// ——— Acción: eliminar ventas seleccionadas ————————————————————————————
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!$empresaId) {
        $vrError = 'Debes seleccionar una empresa antes de eliminar ventas.';
    } else {
        $accion = $_POST['accion'];

        // Obtener IDs a eliminar
        $idsRaw = [];
        if ($accion === 'eliminar_seleccionadas' && !empty($_POST['ids'])) {
            foreach ((array)$_POST['ids'] as $id) {
                $idsRaw[] = (int)$id;
            }
        } elseif ($accion === 'eliminar_todas') {
            $stmt = $db->prepare("SELECT id FROM ventas WHERE empresa_id = ?");
            $stmt->execute([$empresaId]);
            $idsRaw = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
        }

        $ids = array_filter($idsRaw, fn($id) => $id > 0);

        if (empty($ids)) {
            $vrError = 'No se seleccionó ninguna venta para eliminar.';
        } else {
            try {
                $db->beginTransaction();

                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                // Verificar que todas las ventas pertenecen a esta empresa
                $stmtCheck = $db->prepare(
                    "SELECT COUNT(*) FROM ventas WHERE id IN ($placeholders) AND empresa_id = ?"
                );
                $stmtCheck->execute([...$ids, $empresaId]);
                $count = (int)$stmtCheck->fetchColumn();

                if ($count !== count($ids)) {
                    throw new RuntimeException('Algunas ventas no pertenecen a la empresa seleccionada.');
                }

                // 1. Impuestos de detalle
                $db->prepare("DELETE FROM venta_detalle_impuestos WHERE venta_id IN ($placeholders) AND empresa_id = ?")
                   ->execute([...$ids, $empresaId]);

                // 2. Detalles de venta
                $db->prepare("DELETE FROM venta_detalles WHERE venta_id IN ($placeholders) AND empresa_id = ?")
                   ->execute([...$ids, $empresaId]);

                // 3. Pagos de venta
                $db->prepare("DELETE FROM venta_pagos WHERE venta_id IN ($placeholders) AND empresa_id = ?")
                   ->execute([...$ids, $empresaId]);

                // 4. Documentos emitidos vinculados (DTE de prueba — folios intactos en admin)
                $db->prepare("DELETE FROM documentos_emitidos WHERE venta_id IN ($placeholders) AND empresa_id = ?")
                   ->execute([...$ids, $empresaId]);

                // 5. Cabecera de ventas
                $db->prepare("DELETE FROM ventas WHERE id IN ($placeholders) AND empresa_id = ?")
                   ->execute([...$ids, $empresaId]);

                $db->commit();
                $vrCount = count($ids);
                $vrMsg   = "Se eliminaron $vrCount venta(s) correctamente. Los folios CAF no fueron modificados.";

            } catch (Exception $e) {
                $db->rollBack();
                $vrError = 'Error al eliminar: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// ——— Cargar ventas de la empresa activa ———————————————————————————————
$ventas      = [];
$totalVentas = 0;
$totalMonto  = 0;

if ($empresaId && $dbOk) {
    try {
        // Paginación simple
        $page     = max(1, (int)($_GET['pag'] ?? 1));
        $perPage  = 100;
        $offset   = ($page - 1) * $perPage;

        $filtroEstado = $_GET['estado'] ?? '';
        $filtroFecha  = $_GET['fecha']  ?? '';

        $where   = 'v.empresa_id = ?';
        $params  = [$empresaId];

        if ($filtroEstado !== '') {
            $where  .= ' AND v.estado = ?';
            $params[] = $filtroEstado;
        }
        if ($filtroFecha !== '') {
            $where  .= ' AND DATE(v.fecha_venta) = ?';
            $params[] = $filtroFecha;
        }

        $stmtCount = $db->prepare("SELECT COUNT(*), SUM(v.total) FROM ventas v WHERE $where");
        $stmtCount->execute($params);
        [$totalVentas, $totalMonto] = $stmtCount->fetch(PDO::FETCH_NUM);
        $totalMonto = (int)($totalMonto ?? 0);

        $stmtV = $db->prepare("
            SELECT v.id, v.folio, v.estado, v.tipo_venta,
                   v.total, v.fecha_venta,
                   s.nombre AS sucursal,
                   u.nombre AS cajero,
                   COUNT(vd.id) AS n_items
            FROM ventas v
            LEFT JOIN sucursales s ON s.id = v.sucursal_id
            LEFT JOIN usuarios   u ON u.id = v.usuario_id
            LEFT JOIN venta_detalles vd ON vd.venta_id = v.id
            WHERE $where
            GROUP BY v.id
            ORDER BY v.fecha_venta DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmtV->execute($params);
        $ventas = $stmtV->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $vrError = 'Error al cargar ventas: ' . htmlspecialchars($e->getMessage());
    }
}

$totalPages = $totalVentas > 0 ? (int)ceil($totalVentas / 100) : 1;

function vrFmt(int $n): string {
    return '$' . number_format($n, 0, ',', '.');
}
?>

<style>
.vr-stats { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
.vr-stat  { flex:1; min-width:160px; background:var(--c-surface); border:1px solid var(--c-border);
            border-radius:var(--radius); padding:16px 20px; }
.vr-stat-label { font-size:.7rem; text-transform:uppercase; letter-spacing:.06em;
                 color:var(--c-text-muted); font-weight:600; margin-bottom:4px; }
.vr-stat-value { font-size:1.4rem; font-weight:700; color:var(--c-text); }
.vr-stat-value.danger { color:#ef4444; }

#vr-seleccionadas-info { display:none; }
.vr-toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.vr-cb-all  { cursor:pointer; width:18px; height:18px; accent-color:#ef4444; }
.vr-cb      { cursor:pointer; width:16px; height:16px; accent-color:#ef4444; }
</style>

<?php if (!$empresaId): ?>
<div class="alert alert-warning" style="margin:20px 0;">
    <i class="bi bi-exclamation-triangle"></i>
    <strong>Sin empresa seleccionada.</strong> Usa el selector del menú lateral para elegir una empresa.
</div>
<?php else: ?>

<?php if ($vrMsg): ?>
<div class="alert alert-success" style="margin-bottom:16px;">
    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($vrMsg) ?>
</div>
<?php endif; ?>

<?php if ($vrError): ?>
<div class="alert alert-danger" style="margin-bottom:16px;">
    <i class="bi bi-x-circle"></i> <?= htmlspecialchars($vrError) ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="vr-stats">
    <div class="vr-stat">
        <div class="vr-stat-label">Total ventas</div>
        <div class="vr-stat-value <?= $totalVentas > 0 ? 'danger' : '' ?>"><?= number_format((int)$totalVentas, 0, ',', '.') ?></div>
    </div>
    <div class="vr-stat">
        <div class="vr-stat-label">Monto total</div>
        <div class="vr-stat-value"><?= vrFmt($totalMonto) ?></div>
    </div>
    <div class="vr-stat">
        <div class="vr-stat-label">Empresa activa</div>
        <div class="vr-stat-value" style="font-size:1rem">
            <?php
            $empActiva = array_filter($empresas, fn($e) => (int)$e['id'] === $empresaId);
            $empActiva = reset($empActiva);
            echo htmlspecialchars($empActiva['razon_social'] ?? "ID $empresaId");
            ?>
        </div>
    </div>
</div>

<!-- Filtros -->
<form method="GET" action="dashboard.php" style="margin-bottom:16px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
    <input type="hidden" name="module" value="ventas_reset">
    <div>
        <label style="font-size:.75rem; font-weight:600; display:block; margin-bottom:4px;">Estado</label>
        <select name="estado" class="form-select form-select-sm" style="min-width:150px;">
            <option value="">Todos</option>
            <?php foreach (['REGISTRADA','COMPLETADA','ANULADA','CANCELADA'] as $e): ?>
            <option value="<?= $e ?>" <?= $filtroEstado === $e ? 'selected' : '' ?>><?= $e ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label style="font-size:.75rem; font-weight:600; display:block; margin-bottom:4px;">Fecha</label>
        <input type="date" name="fecha" class="form-control form-control-sm" value="<?= htmlspecialchars($filtroFecha) ?>">
    </div>
    <button type="submit" class="d-btn d-btn-sm d-btn-outline" style="height:31px;">
        <i class="bi bi-funnel"></i> Filtrar
    </button>
    <a href="dashboard.php?module=ventas_reset" class="d-btn d-btn-sm" style="height:31px; background:var(--c-surface); border:1px solid var(--c-border);">
        <i class="bi bi-x"></i> Limpiar
    </a>
</form>

<!-- Tabla con selección -->
<div class="d-card">
    <div class="d-card-header" style="flex-wrap:wrap; gap:10px;">
        <i class="bi bi-trash3"></i> Ventas de la empresa
        <div id="vr-seleccionadas-info" style="margin-left:8px; background:#fef2f2; border:1px solid #fca5a5; border-radius:6px; padding:4px 10px; font-size:.8rem; color:#b91c1c; font-weight:600;">
            <span id="vr-sel-count">0</span> venta(s) seleccionada(s)
        </div>
        <div class="vr-toolbar" style="margin-left:auto;">
            <button type="button" class="d-btn d-btn-sm d-btn-outline" id="btn-eliminar-sel"
                    onclick="confirmarEliminar('seleccionadas')"
                    style="border-color:#f87171; color:#dc2626; display:none;">
                <i class="bi bi-trash"></i> Eliminar seleccionadas
            </button>
            <?php if ($totalVentas > 0): ?>
            <button type="button" class="d-btn d-btn-sm"
                    onclick="confirmarEliminar('todas')"
                    style="background:#dc2626; color:#fff; border:none;">
                <i class="bi bi-trash3-fill"></i> Eliminar TODAS (<?= number_format((int)$totalVentas, 0, ',', '.') ?>)
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($ventas)): ?>
    <div class="d-card-body" style="text-align:center; padding:60px 20px; color:var(--c-text-muted);">
        <i class="bi bi-check-circle" style="font-size:2.5rem; color:#22c55e;"></i>
        <p class="mt-3" style="font-size:1rem;">Esta empresa no tiene ventas registradas.</p>
        <p style="font-size:.85rem;">Base limpia y lista para el cliente.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="d-table" style="font-size:.8rem;">
            <thead>
                <tr>
                    <th style="width:40px; text-align:center;">
                        <input type="checkbox" class="vr-cb-all" id="cb-all" title="Seleccionar todos en esta página">
                    </th>
                    <th>ID</th>
                    <th>Fecha</th>
                    <th>Sucursal</th>
                    <th>Cajero</th>
                    <th>Tipo</th>
                    <th>Folio</th>
                    <th>Estado</th>
                    <th>Items</th>
                    <th style="text-align:right;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ventas as $v): ?>
                <tr>
                    <td style="text-align:center;">
                        <input type="checkbox" class="vr-cb" data-id="<?= $v['id'] ?>">
                    </td>
                    <td style="color:var(--c-text-muted);">#<?= $v['id'] ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($v['fecha_venta'])) ?></td>
                    <td><?= htmlspecialchars($v['sucursal'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($v['cajero'] ?? '—') ?></td>
                    <td>
                        <span style="font-size:.7rem; background:var(--c-surface-alt,#f1f5f9); padding:2px 6px; border-radius:4px;">
                            <?= htmlspecialchars($v['tipo_venta']) ?>
                        </span>
                    </td>
                    <td><?= $v['folio'] ? htmlspecialchars($v['folio']) : '<span style="color:var(--c-text-muted);">—</span>' ?></td>
                    <td>
                        <?php
                        $estadoColor = match($v['estado']) {
                            'COMPLETADA' => '#22c55e',
                            'ANULADA','CANCELADA' => '#ef4444',
                            default => '#f59e0b',
                        };
                        ?>
                        <span style="font-size:.7rem; color:<?= $estadoColor ?>; font-weight:600;"><?= $v['estado'] ?></span>
                    </td>
                    <td style="text-align:center;"><?= (int)$v['n_items'] ?></td>
                    <td style="text-align:right; font-weight:600;"><?= vrFmt((int)$v['total']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($totalPages > 1): ?>
    <div style="padding:12px 16px; display:flex; gap:6px; align-items:center; flex-wrap:wrap; border-top:1px solid var(--c-border);">
        <span style="font-size:.75rem; color:var(--c-text-muted);">Página <?= $page ?> de <?= $totalPages ?> · <?= number_format((int)$totalVentas, 0, ',', '.') ?> ventas totales</span>
        <div style="margin-left:auto; display:flex; gap:4px;">
            <?php if ($page > 1): ?>
            <a href="dashboard.php?module=ventas_reset&pag=<?= $page - 1 ?>&estado=<?= urlencode($filtroEstado) ?>&fecha=<?= urlencode($filtroFecha) ?>"
               class="d-btn d-btn-sm d-btn-outline"><i class="bi bi-chevron-left"></i></a>
            <?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
            <a href="dashboard.php?module=ventas_reset&pag=<?= $p ?>&estado=<?= urlencode($filtroEstado) ?>&fecha=<?= urlencode($filtroFecha) ?>"
               class="d-btn d-btn-sm <?= $p === $page ? '' : 'd-btn-outline' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="dashboard.php?module=ventas_reset&pag=<?= $page + 1 ?>&estado=<?= urlencode($filtroEstado) ?>&fecha=<?= urlencode($filtroFecha) ?>"
               class="d-btn d-btn-sm d-btn-outline"><i class="bi bi-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Formulario oculto para submit -->
<form id="vr-form-eliminar" method="POST" action="dashboard.php?module=ventas_reset" style="display:none;">
    <input type="hidden" name="accion" id="vr-accion">
    <div id="vr-ids-container"></div>
</form>

<!-- Aviso folio -->
<div style="margin-top:20px; background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:14px 18px; font-size:.8rem; color:#166534;">
    <i class="bi bi-shield-check" style="margin-right:6px;"></i>
    <strong>Los folios CAF no se modifican.</strong>
    Esta operación elimina solo los registros de venta de la base de datos SaaS.
    Los folios, CAFs y contadores del sistema SII permanecen intactos.
</div>

<script>
(function () {
    const cbAll  = document.getElementById('cb-all');
    const cbList = () => document.querySelectorAll('.vr-cb');
    const info   = document.getElementById('vr-seleccionadas-info');
    const cntEl  = document.getElementById('vr-sel-count');
    const btnSel = document.getElementById('btn-eliminar-sel');

    function updateUI() {
        const sel = [...cbList()].filter(c => c.checked);
        const n   = sel.length;
        if (n > 0) {
            info.style.display  = 'inline-flex';
            btnSel.style.display = 'inline-flex';
            cntEl.textContent   = n;
        } else {
            info.style.display  = 'none';
            btnSel.style.display = 'none';
        }
        cbAll.indeterminate = n > 0 && n < cbList().length;
        cbAll.checked       = n > 0 && n === cbList().length;
    }

    cbAll.addEventListener('change', () => {
        cbList().forEach(c => c.checked = cbAll.checked);
        updateUI();
    });

    document.addEventListener('change', e => {
        if (e.target.matches('.vr-cb')) updateUI();
    });

    window.confirmarEliminar = function (tipo) {
        const form    = document.getElementById('vr-form-eliminar');
        const accion  = document.getElementById('vr-accion');
        const cont    = document.getElementById('vr-ids-container');
        cont.innerHTML = '';

        if (tipo === 'seleccionadas') {
            const sel = [...cbList()].filter(c => c.checked);
            if (sel.length === 0) { alert('Selecciona al menos una venta.'); return; }
            if (!confirm(`¿Confirmas que deseas eliminar ${sel.length} venta(s)?\n\nLos folios NO se tocan.`)) return;
            accion.value = 'eliminar_seleccionadas';
            sel.forEach(c => {
                const inp = document.createElement('input');
                inp.type  = 'hidden';
                inp.name  = 'ids[]';
                inp.value = c.dataset.id;
                cont.appendChild(inp);
            });
        } else {
            const total = <?= (int)$totalVentas ?>;
            if (!confirm(`⚠️ ELIMINAR TODAS LAS VENTAS (${total.toLocaleString('es-CL')})\n\nEsta acción no se puede deshacer.\n¿Continuar?\n\nLos folios NO se tocan.`)) return;
            accion.value = 'eliminar_todas';
        }

        form.submit();
    };
})();
</script>
<?php endif; ?>
