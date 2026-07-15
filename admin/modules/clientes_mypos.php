<?php
/**
 * Modulo: Clientes MyPOS
 * Tablero tecnico DTE/SII leyendo tablas existentes de MyPOS.
 */
if (!defined('DTE_API_BOOTSTRAP_ONLY')) exit;

use App\Services\MyposSubscriptionService;

if (!$dbOk) {
    echo '<div class="d-alert danger"><i class="bi bi-database-x"></i> Sin conexion a base de datos.</div>';
    return;
}

// Interceptor AJAX para obtener sucursales
if (isset($_GET['action']) && $_GET['action'] === 'get_sucursales') {
    header('Content-Type: application/json; charset=UTF-8');
    ob_clean();
    try {
        $empresaId = (int)($_GET['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new Exception('Empresa inválida.');
        }
        $stmt = $db->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = ? AND activo = 1 ORDER BY nombre");
        $stmt->execute([$empresaId]);
        $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['ok' => true, 'sucursales' => $sucursales]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$tableExists = static function (PDO $db, string $table): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
};

$columnExists = static function (PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
};

$requiredMyposTables = [
    'empresas',
    'empresa_configuracion_operativa',
    'empresas_suscripcion',
    'suscripciones_ordenes',
    'documentos_emitidos',
];

$useSiiTables = false;

$requiredSiiTables = $useSiiTables ? [
    'sii_empresa',
    'sii_certificado',
    'sii_caf',
    'sii_folio_consumo',
] : [
    'empresas',
    'archivos_subidos',
    'caf_archivos',
    'folios_consumidos',
];

$missingMyposTables = [];
foreach ($requiredMyposTables as $table) {
    if (!$tableExists($db, $table)) $missingMyposTables[] = $table;
}

$missingSiiTables = [];
foreach ($requiredSiiTables as $table) {
    if (!$tableExists($db, $table)) $missingSiiTables[] = $table;
}
$hasSiiSchema = $missingSiiTables === [];

if ($missingMyposTables) {
    echo '<div class="d-alert warning"><i class="bi bi-exclamation-triangle"></i> Faltan tablas para este tablero: '
        . htmlspecialchars(implode(', ', $missingMyposTables))
        . '. El tablero necesita estas tablas de MyPOS para listar clientes.</div>';
    return;
}

$hasRecurringPriceColumn = $columnExists($db, 'empresas_suscripcion', 'precio_especial_clp');
$precioEspecialSelect = $hasRecurringPriceColumn
    ? 'es.precio_especial_clp'
    : 'NULL AS precio_especial_clp';

if (empty($_SESSION['clientes_mypos_csrf'])) {
    $_SESSION['clientes_mypos_csrf'] = bin2hex(random_bytes(32));
}

if ($useSiiTables) {
    $siiSelect = $hasSiiSchema
        ? "se.id AS sii_empresa_id,
            se.ambiente_default,
            COALESCE(cert.certificados_activos, 0) AS certificados_activos,
            COALESCE(caf.cafs_activos, 0) AS cafs_activos,
            COALESCE(caf.folios_disponibles, 0) AS folios_disponibles,"
        : "NULL AS sii_empresa_id,
            NULL AS ambiente_default,
            0 AS certificados_activos,
            0 AS cafs_activos,
            0 AS folios_disponibles,";

    $siiJoins = $hasSiiSchema
        ? "LEFT JOIN sii_empresa se ON REPLACE(REPLACE(UPPER(se.rut), '.', ''), ' ', '') COLLATE utf8mb4_unicode_ci = REPLACE(REPLACE(UPPER(e.rut), '.', ''), ' ', '') COLLATE utf8mb4_unicode_ci
         LEFT JOIN (
            SELECT empresa_id, ambiente_sii, COUNT(*) AS certificados_activos
            FROM sii_certificado
            WHERE activo = 1
            GROUP BY empresa_id, ambiente_sii
         ) cert ON cert.empresa_id = se.id AND cert.ambiente_sii = se.ambiente_default
         LEFT JOIN (
            SELECT
                c.empresa_id, c.ambiente_sii,
                COUNT(*) AS cafs_activos,
                SUM(GREATEST(c.folio_hasta - c.folio_desde + 1 - COALESCE(fc.consumidos, 0), 0)) AS folios_disponibles
            FROM sii_caf c
            LEFT JOIN (
                SELECT caf_id, COUNT(*) AS consumidos
                FROM sii_folio_consumo
                GROUP BY caf_id
            ) fc ON fc.caf_id = c.id
            WHERE c.activo = 1
            GROUP BY c.empresa_id, c.ambiente_sii
         ) caf ON caf.empresa_id = se.id AND caf.ambiente_sii = se.ambiente_default"
        : "";
} else {
    // Para MyPOS SaaS usando tablas nativas
    $siiSelect = $hasSiiSchema
        ? "e.id AS sii_empresa_id,
            COALESCE(dc.ambiente, 'CERTIFICACION') AS ambiente_default,
            COALESCE(cert.certificados_activos, 0) AS certificados_activos,
            COALESCE(caf.cafs_activos, 0) AS cafs_activos,
            COALESCE(caf.folios_disponibles, 0) AS folios_disponibles,"
        : "e.id AS sii_empresa_id,
            'CERTIFICACION' AS ambiente_default,
            0 AS certificados_activos,
            0 AS cafs_activos,
            0 AS folios_disponibles,";

    $siiJoins = $hasSiiSchema
        ? "LEFT JOIN dte_configuracion dc ON dc.empresa_id = e.id
         LEFT JOIN (
            SELECT empresa_id, COUNT(*) AS certificados_activos
            FROM archivos_subidos
            WHERE entidad = 'certificado_sii' AND estado = 'ACTIVO'
            GROUP BY empresa_id
         ) cert ON cert.empresa_id = e.id
         LEFT JOIN (
            SELECT
                c.empresa_id,
                COUNT(*) AS cafs_activos,
                SUM(GREATEST(c.folio_hasta - c.folio_desde + 1 - COALESCE(fc.consumidos, 0), 0)) AS folios_disponibles
            FROM caf_archivos c
            LEFT JOIN (
                SELECT caf_archivo_id, COUNT(*) AS consumidos
                FROM folios_consumidos
                GROUP BY caf_archivo_id
            ) fc ON fc.caf_archivo_id = c.id
            WHERE c.estado = 'ACTIVO'
            GROUP BY c.empresa_id
         ) caf ON caf.empresa_id = e.id"
        : "";
}

// ── Acción: Forzar Pago Manual ───────────────────────────────────────────────
$pagoMsg = null;
$pagoError = null;
if (isset($_POST['action']) && $_POST['action'] === 'actualizar_monto_plan' && $dbOk) {
    try {
        if (!hash_equals((string)($_SESSION['clientes_mypos_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
            throw new Exception('La sesion del formulario expiro. Recarga la pagina e intenta nuevamente.');
        }
        if (!$hasRecurringPriceColumn) {
            throw new Exception('Falta aplicar la migracion 075_promo_links en la base de datos.');
        }
        $empresaId = (int)($_POST['empresa_id'] ?? 0);
        $montoClp = filter_var($_POST['monto_plan_clp'] ?? null, FILTER_VALIDATE_INT);
        if ($montoClp === false) {
            throw new Exception('Ingresa un monto mensual valido, sin puntos ni decimales.');
        }
        (new MyposSubscriptionService())->actualizarMontoRecurrente($empresaId, (int)$montoClp);
        $pagoMsg = 'Monto mensual actualizado a $' . number_format((int)$montoClp, 0, ',', '.')
            . '. Los proximos cobros usaran este valor.';
    } catch (Throwable $e) {
        $pagoError = $e->getMessage();
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'forzar_pago_manual' && $dbOk) {
    try {
        $empresaId = (int)($_POST['empresa_id'] ?? 0);
        $dias = max(1, min(365, (int)($_POST['dias_vigencia'] ?? 30)));
        if ($empresaId <= 0) throw new Exception('Empresa inválida.');

        $stmtSub = $db->prepare('SELECT plan_id FROM empresas_suscripcion WHERE empresa_id = ?');
        $stmtSub->execute([$empresaId]);
        $subActual = $stmtSub->fetch(PDO::FETCH_ASSOC);
        $planId = $subActual['plan_id'] ?? 'basico';

        $stmtUsr = $db->prepare('SELECT usuario_id FROM empresa_usuarios WHERE empresa_id = ? AND activo = 1 ORDER BY id ASC LIMIT 1');
        $stmtUsr->execute([$empresaId]);
        $usrRow = $stmtUsr->fetch(PDO::FETCH_ASSOC);
        if (!$usrRow) throw new Exception('La empresa no tiene usuarios activos.');
        $usuarioId = (int)$usrRow['usuario_id'];

        $db->beginTransaction();

        // 1. Marcar onboarding como completado (desbloquea el acceso al app)
        $db->prepare('UPDATE empresas SET onboarding_completado = 1 WHERE id = ?')
           ->execute([$empresaId]);

        // 2. Activar/extender suscripción
        $db->prepare(
            'INSERT INTO empresas_suscripcion (empresa_id, plan_id, fecha_inicio, fecha_fin, estado)
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), "activa")
             ON DUPLICATE KEY UPDATE
               plan_id    = VALUES(plan_id),
               fecha_inicio = IF(estado != "activa", NOW(), fecha_inicio),
               fecha_fin  = DATE_ADD(NOW(), INTERVAL ? DAY),
               estado     = "activa"'
        )->execute([$empresaId, $planId, $dias, $dias]);

        // 3. Registrar orden manual para que pagos_completados > 0
        $ordenNum = 'ADMIN-MANUAL-' . $empresaId . '-' . time();
        $db->prepare(
            'INSERT INTO suscripciones_ordenes
               (orden_numero, empresa_id, usuario_id, gateway, plan_id, monto, moneda, estado)
             VALUES (?, ?, ?, "flow", ?, 0, "CLP", "completado")'
        )->execute([$ordenNum, $empresaId, $usuarioId, $planId]);

        $db->commit();
        $pagoMsg = 'Pago manual registrado. Suscripción activa por ' . $dias . ' días.';
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $pagoError = $e->getMessage();
    }
}
// ─────────────────────────────────────────────────────────────────────────────

$clientes = $db->query(
    "SELECT
        e.id,
        e.rut,
        e.razon_social,
        e.nombre_fantasia,
        e.email,
        e.telefono,
        e.activo,
        COALESCE(eco.documentos_tributarios_habilitados, 0) AS dte_habilitado,
        es.plan_id,
        {$precioEspecialSelect},
        es.estado AS suscripcion_estado,
        es.fecha_fin AS suscripcion_fin,
        COALESCE(p.pagos_completados, 0) AS pagos_completados,
        {$siiSelect}
        COALESCE(doc.documentos_30d, 0) AS documentos_30d,
        doc.ultimo_documento_at
     FROM empresas e
     LEFT JOIN empresa_configuracion_operativa eco ON eco.empresa_id = e.id
     LEFT JOIN empresas_suscripcion es ON es.empresa_id = e.id
     LEFT JOIN (
        SELECT empresa_id, COUNT(*) AS pagos_completados
        FROM suscripciones_ordenes
        WHERE estado = 'completado'
        GROUP BY empresa_id
     ) p ON p.empresa_id = e.id
     {$siiJoins}
     LEFT JOIN (
        SELECT
            empresa_id,
            COUNT(*) AS documentos_30d,
            MAX(fecha_emision) AS ultimo_documento_at
        FROM documentos_emitidos
        WHERE fecha_emision >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY empresa_id
     ) doc ON doc.empresa_id = e.id
     ORDER BY e.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$total = count($clientes);
$sinSii = 0;
$sinCertificado = 0;
$sinFolios = 0;
$enPrueba = 0;

foreach ($clientes as $cliente) {
    if (empty($cliente['sii_empresa_id'])) $sinSii++;
    if (!empty($cliente['sii_empresa_id']) && (int)$cliente['certificados_activos'] === 0) $sinCertificado++;
    if (!empty($cliente['sii_empresa_id']) && (int)$cliente['folios_disponibles'] <= 0) $sinFolios++;
    if (isTrialSubscription($cliente)) $enPrueba++;
}

function clienteBadge(string $label, string $tone): string
{
    return '<span class="d-badge ' . htmlspecialchars($tone) . '">' . htmlspecialchars($label) . '</span>';
}

function isTrialSubscription(array $cliente): bool
{
    if (($cliente['suscripcion_estado'] ?? '') !== 'activa') return false;
    if ((int)($cliente['pagos_completados'] ?? 0) > 0) return false;

    $fin = strtotime((string)($cliente['suscripcion_fin'] ?? ''));
    if (!$fin) return false;

    return $fin > time() && $fin <= strtotime('+8 days');
}

function montoMensualCliente(array $cliente): int
{
    $especial = (int)($cliente['precio_especial_clp'] ?? 0);
    if ($especial > 0) return $especial;

    return match ((string)($cliente['plan_id'] ?? '')) {
        'mypos-pro', 'multisucursal' => 30928,
        'mypos-cadena' => 35688,
        'mypos-escala' => 47588,
        default => 23788,
    };
}
?>

<?php if ($pagoMsg): ?>
    <div class="d-alert success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($pagoMsg) ?></div>
<?php endif; ?>
<?php if (!$hasRecurringPriceColumn): ?>
    <div class="d-alert warning">
        <i class="bi bi-exclamation-triangle"></i>
        La edicion del monto mensual requiere aplicar la migracion <strong>075_promo_links</strong>.
    </div>
<?php endif; ?>
<?php if ($pagoError): ?>
    <div class="d-alert danger"><i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($pagoError) ?></div>
<?php endif; ?>
<?php if (!$hasSiiSchema): ?>
    <div class="d-alert warning">
        <i class="bi bi-exclamation-triangle"></i>
        Faltan tablas SII para el control tecnico DTE: <?= htmlspecialchars(implode(', ', $missingSiiTables)) ?>.
        Se muestran los clientes MyPOS en modo lectura; no se crean tablas automaticamente desde admin.
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="d-card"><div class="d-card-body"><div class="text-muted small">Clientes MyPOS</div><div class="h3 mb-0"><?= $total ?></div></div></div>
    </div>
    <div class="col-md-3">
        <div class="d-card"><div class="d-card-body"><div class="text-muted small">Sin ficha SII</div><div class="h3 mb-0"><?= $sinSii ?></div></div></div>
    </div>
    <div class="col-md-3">
        <div class="d-card"><div class="d-card-body"><div class="text-muted small">Sin certificado</div><div class="h3 mb-0"><?= $sinCertificado ?></div></div></div>
    </div>
    <div class="col-md-3">
        <div class="d-card"><div class="d-card-body"><div class="text-muted small">Sin folios CAF</div><div class="h3 mb-0"><?= $sinFolios ?></div></div></div>
    </div>
</div>

<div class="d-card">
    <div class="d-card-header">
        <i class="bi bi-buildings"></i> Clientes MyPOS y estado DTE
        <span class="d-badge info" style="margin-left:auto"><?= $enPrueba ?> en prueba</span>
    </div>
    <div class="d-card-body" style="padding:0">
        <?php if (!$clientes): ?>
            <div style="padding:60px; text-align:center; color:var(--c-text-muted)">
                <i class="bi bi-building-x" style="font-size:2.5rem"></i>
                <p class="mt-2">No hay clientes registrados en MyPOS.</p>
            </div>
        <?php else: ?>
            <table class="d-table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Suscripcion</th>
                        <th>Monto mensual</th>
                        <th>DTE MyPOS</th>
                        <th>Ficha SII</th>
                        <th>Certificado</th>
                        <th>Folios</th>
                        <th>Docs 30d</th>
                        <th>Accion tecnica</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $cliente): ?>
                        <?php
                            $hasSii = !empty($cliente['sii_empresa_id']);
                            $hasCert = (int)$cliente['certificados_activos'] > 0;
                            $hasFolios = (int)$cliente['folios_disponibles'] > 0;
                            $subscription = isTrialSubscription($cliente)
                                ? clienteBadge('Prueba 7 dias', 'warning')
                                : (($cliente['suscripcion_estado'] ?? '') === 'activa'
                                    ? clienteBadge('Activa', 'prod')
                                    : clienteBadge('Impaga/vencida', 'danger'));
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($cliente['razon_social'] ?: $cliente['nombre_fantasia'] ?: 'Sin razon social') ?></strong><br>
                                <span class="text-muted small"><?= htmlspecialchars($cliente['rut'] ?: 'Sin RUT') ?></span>
                            </td>
                            <td><?= $subscription ?></td>
                            <td style="min-width:170px">
                                <?php if (!empty($cliente['plan_id']) && $hasRecurringPriceColumn): ?>
                                    <form method="POST" style="display:flex;gap:5px;align-items:center"
                                          onsubmit="return confirm('¿Guardar este monto como cobro mensual recurrente?')">
                                        <input type="hidden" name="action" value="actualizar_monto_plan">
                                        <input type="hidden" name="empresa_id" value="<?= (int)$cliente['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['clientes_mypos_csrf']) ?>">
                                        <span style="font-weight:600">$</span>
                                        <input type="number" name="monto_plan_clp" min="1" max="100000000" step="1" required
                                               value="<?= montoMensualCliente($cliente) ?>"
                                               placeholder="Monto CLP"
                                               style="width:105px;padding:4px 6px;font-size:12px;border:1px solid var(--c-border,#ccc);border-radius:4px">
                                        <button type="submit" class="d-btn d-btn-sm d-btn-primary" title="Guardar monto mensual">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </form>
                                    <span class="text-muted small">
                                        Plan: <?= htmlspecialchars((string)$cliente['plan_id']) ?>
                                        <?= !empty($cliente['precio_especial_clp']) ? '· monto personalizado' : '· precio de catálogo' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">Sin suscripcion</span>
                                <?php endif; ?>
                            </td>
                            <td><?= ((int)$cliente['dte_habilitado'] === 1) ? clienteBadge('Habilitado', 'prod') : clienteBadge('Deshabilitado', 'warning') ?></td>
                            <td>
                                <?= $hasSii ? clienteBadge((string)$cliente['ambiente_default'], $cliente['ambiente_default'] === 'produccion' ? 'prod' : 'cert') : clienteBadge('Falta ficha', 'danger') ?>
                            </td>
                            <td><?= $hasCert ? clienteBadge('OK', 'prod') : clienteBadge('Falta', 'danger') ?></td>
                            <td><?= $hasFolios ? clienteBadge(number_format((int)$cliente['folios_disponibles'], 0, ',', '.') . ' disponibles', 'prod') : clienteBadge('Faltan CAF', 'danger') ?></td>
                            <td>
                                <?= (int)$cliente['documentos_30d'] ?><br>
                                <span class="text-muted small"><?= $cliente['ultimo_documento_at'] ? htmlspecialchars((string)$cliente['ultimo_documento_at']) : 'Sin emision reciente' ?></span>
                            </td>
                            <td>
                                <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start">
                                    <?php if ($hasSii): ?>
                                        <button type="button" class="d-btn d-btn-sm d-btn-info w-100 mb-1" style="background:#0dcaf0;color:#000;border:none;text-align:left;display:flex;align-items:center;gap:6px" onclick="abrirModalUsuarios(<?= (int)$cliente['sii_empresa_id'] ?>, '<?= htmlspecialchars(addslashes($cliente['razon_social'] ?: $cliente['nombre_fantasia'])) ?>')">
                                            <i class="bi bi-people"></i> Usuarios
                                        </button>
                                    <?php endif; ?>
                                    <?php if (!$hasSii): ?>
                                        <a class="d-btn d-btn-sm d-btn-primary" href="dashboard.php?module=empresas">Crear ficha SII</a>
                                    <?php elseif (!$hasCert): ?>
                                        <a class="d-btn d-btn-sm d-btn-primary" href="dashboard.php?module=config&switch_empresa=<?= (int)$cliente['sii_empresa_id'] ?>">Certificado</a>
                                    <?php elseif (!$hasFolios): ?>
                                        <a class="d-btn d-btn-sm d-btn-primary" href="dashboard.php?module=cafs&switch_empresa=<?= (int)$cliente['sii_empresa_id'] ?>">Cargar CAF</a>
                                    <?php else: ?>
                                        <a class="d-btn d-btn-sm d-btn-secondary" href="dashboard.php?module=emision&switch_empresa=<?= (int)$cliente['sii_empresa_id'] ?>">Prueba DTE</a>
                                    <?php endif; ?>
                                    <form method="POST" style="display:flex;gap:4px;align-items:center"
                                          onsubmit="return confirm('¿Registrar pago manual para <?= htmlspecialchars(addslashes((string)($cliente['razon_social'] ?: $cliente['nombre_fantasia']))) ?>?')">
                                        <input type="hidden" name="action" value="forzar_pago_manual">
                                        <input type="hidden" name="empresa_id" value="<?= (int)$cliente['id'] ?>">
                                        <select name="dias_vigencia" style="padding:2px 4px;font-size:11px;border:1px solid var(--c-border,#ccc);border-radius:4px;background:var(--c-bg,#fff);color:var(--c-text,#333)">
                                            <option value="30">30d</option>
                                            <option value="60">60d</option>
                                            <option value="90">90d</option>
                                            <option value="365">365d</option>
                                        </select>
                                        <button type="submit" class="d-btn d-btn-sm" style="background:#198754;color:#fff;border:none" title="Registrar pago manual">
                                            <i class="bi bi-cash-coin"></i> Pago
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Gestión de Usuarios -->
<div class="modal fade" id="modalUsuarios" tabindex="-1" aria-labelledby="modalUsuariosLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalUsuariosLabel"><i class="bi bi-people-fill"></i> Usuarios - <span id="nombre-empresa-modal"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        
        <!-- Alertas dinámicas -->
        <div id="modal-alert" class="alert d-none" role="alert"></div>

        <!-- Vista 1: Tabla de Usuarios -->
        <div id="vista-lista">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Listado de Operadores/Usuarios POS</h6>
            <div class="d-flex gap-2">
              <button class="btn btn-sm btn-outline-secondary" onclick="mostrarPermisos()"><i class="bi bi-shield-lock"></i> Permisos</button>
              <button class="btn btn-sm btn-primary" onclick="mostrarFormularioCrear()"><i class="bi bi-person-plus"></i> Nuevo Usuario</button>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-hover table-striped align-middle" style="font-size: 13px;">
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th>Email</th>
                  <th>Rol</th>
                  <th>Sucursal</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody id="tabla-usuarios-body">
                <!-- AJAX -->
              </tbody>
            </table>
          </div>
        </div>

        <!-- Vista 3: Permisos por Rol -->
        <div id="vista-permisos" class="d-none">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0"><i class="bi bi-shield-lock"></i> Permisos por Rol</h6>
            <button class="btn btn-sm btn-secondary" onclick="mostrarLista()"><i class="bi bi-arrow-left"></i> Volver</button>
          </div>
          <p class="text-muted small mb-3">Define qué funciones puede ejecutar cada rol en el POS de esta empresa.</p>
          <form id="form-permisos" onsubmit="guardarMatriz(event)">
            <table class="table table-bordered text-center align-middle" style="font-size: 13px;">
              <thead class="table-light">
                <tr>
                  <th class="text-start">Permiso / Función</th>
                  <th>Cajero</th>
                  <th>Supervisor</th>
                  <th>Administrador</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td class="text-start">Anular documentos (Ventas/Boletas)</td>
                  <td><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" id="cajero_puede_anular"></div></td>
                  <td><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" id="supervisor_puede_anular"></div></td>
                  <td><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" id="admin_puede_anular" checked></div></td>
                </tr>
                <tr>
                  <td class="text-start">Aplicar descuentos &gt; 10%</td>
                  <td><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" id="cajero_puede_descuento_mayor"></div></td>
                  <td><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" id="supervisor_puede_descuento_mayor"></div></td>
                  <td><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" id="admin_puede_descuento_mayor" checked></div></td>
                </tr>
                <tr>
                  <td class="text-start">Ver reportes X y Z</td>
                  <td><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" id="cajero_puede_ver_reportes"></div></td>
                  <td><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" id="supervisor_puede_ver_reportes"></div></td>
                  <td><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" id="admin_puede_ver_reportes" checked></div></td>
                </tr>
                <tr>
                  <td class="text-start">Configuración de Caja (Impresoras, etc.)</td>
                  <td><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" id="cajero_puede_configurar_pos"></div></td>
                  <td><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" id="supervisor_puede_configurar_pos"></div></td>
                  <td><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" id="admin_puede_configurar_pos" checked></div></td>
                </tr>
              </tbody>
            </table>
            <div class="d-flex justify-content-end gap-2 mt-2">
              <button type="button" class="btn btn-sm btn-secondary" onclick="mostrarLista()"><i class="bi bi-x-circle"></i> Cancelar</button>
              <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-save"></i> Guardar Permisos</button>
            </div>
          </form>
        </div>

        <!-- Vista 2: Formulario (Crear / Editar) -->
        <div id="vista-formulario" class="d-none">
          <h6 id="titulo-formulario" class="mb-3">Nuevo Usuario</h6>
          <form id="form-usuario" onsubmit="guardarUsuario(event)">
            <input type="hidden" id="usr-id" name="id" value="">
            <input type="hidden" id="usr-empresa-id" name="empresa_id" value="">
            
            <div class="row g-3">
              <div class="col-md-6">
                <label for="usr-nombre" class="form-label">Nombre Completo</label>
                <input type="text" class="form-control form-control-sm" id="usr-nombre" required>
              </div>
              <div class="col-md-6">
                <label for="usr-email" class="form-label">Correo Electrónico (Email)</label>
                <input type="email" class="form-control form-control-sm" id="usr-email" required>
              </div>
              <div class="col-md-6">
                <label for="usr-password" class="form-label">Contraseña <span class="text-muted small" id="lbl-password-req">(Obligatoria)</span></label>
                <input type="password" class="form-control form-control-sm" id="usr-password">
                <div class="form-text small" id="help-password" style="display:none">Dejar en blanco para conservar la actual.</div>
              </div>
              <div class="col-md-6">
                <label for="usr-rol" class="form-label">Rol / Permiso</label>
                <select class="form-select form-select-sm" id="usr-rol" required>
                  <option value="CAJERO">Cajero</option>
                  <option value="VENDEDOR">Vendedor</option>
                  <option value="ADMIN_EMPRESA">Administrador de Empresa</option>
                </select>
              </div>
              <div class="col-md-6">
                <label for="usr-sucursal" class="form-label">Sucursal Asignada</label>
                <select class="form-select form-select-sm" id="usr-sucursal">
                  <!-- AJAX -->
                </select>
              </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
              <button type="button" class="btn btn-sm btn-secondary" onclick="mostrarLista()"><i class="bi bi-x-circle"></i> Cancelar</button>
              <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-circle"></i> Guardar</button>
            </div>
          </form>
        </div>

      </div>
    </div>
  </div>
</div>

<script>
let modalInstancia = null;
let sucursalesCache = [];

function abrirModalUsuarios(empresaId, razonSocial) {
    document.getElementById('nombre-empresa-modal').textContent = razonSocial;
    document.getElementById('usr-empresa-id').value = empresaId;
    
    mostrarLista();
    limpiarAlerta();
    
    const modalEl = document.getElementById('modalUsuarios');
    if (!modalInstancia) {
        modalInstancia = new bootstrap.Modal(modalEl);
    }
    modalInstancia.show();
    
    cargarUsuarios(empresaId);
}

async function cargarUsuarios(empresaId) {
    const tbody = document.getElementById('tabla-usuarios-body');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2" role="status"></div> Cargando usuarios...</td></tr>';
    
    try {
        const resp = await fetch(`api.php?action=listar_usuarios&empresa_id=${empresaId}`);
        const data = await resp.json();
        
        if (!data.ok) {
            mostrarAlerta(data.error || 'Error al obtener usuarios', 'danger');
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Error al cargar datos</td></tr>';
            return;
        }
        
        const usuarios = data.data || [];
        
        await cargarSucursalesSelect(empresaId);
        
        if (usuarios.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No hay usuarios registrados en esta empresa.</td></tr>';
            return;
        }
        
        tbody.innerHTML = usuarios.map(u => {
            let rolLabel = '';
            if (u.rol === 'ADMIN_EMPRESA') {
                rolLabel = '<span class="badge bg-primary">Admin Empresa</span>';
            } else if (u.rol === 'VENDEDOR') {
                rolLabel = '<span class="badge bg-warning text-dark">Vendedor</span>';
            } else if (u.rol === 'SUPER_ADMIN') {
                rolLabel = '<span class="badge bg-danger">Super Admin</span>';
            } else {
                rolLabel = '<span class="badge bg-secondary">Cajero</span>';
            }
            const sucursalLabel = u.sucursal_nombre ? u.sucursal_nombre : '<em class="text-muted">Casa Matriz/Ninguna</em>';
            
            const uEscaped = JSON.stringify(u).replace(/"/g, '&quot;');
            
            return `
                <tr>
                    <td><strong>${esc(u.nombre)}</strong></td>
                    <td>${esc(u.email)}</td>
                    <td>${rolLabel}</td>
                    <td>${esc(sucursalLabel)}</td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="editarUsuario(${uEscaped})" title="Editar usuario"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-outline-danger" onclick="desactivarUsuario(${u.id}, ${u.empresa_id})" title="Desactivar usuario"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
        
    } catch (e) {
        mostrarAlerta(e.message, 'danger');
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Error en la conexión</td></tr>';
    }
}

async function cargarSucursalesSelect(empresaId) {
    const select = document.getElementById('usr-sucursal');
    select.innerHTML = '<option value="">Casa Matriz / Sin Sucursal</option>';
    
    try {
        const resp = await fetch(`dashboard.php?module=clientes_mypos&action=get_sucursales&empresa_id=${empresaId}`);
        const data = await resp.json();
        
        if (data.ok && data.sucursales) {
            sucursalesCache = data.sucursales;
            data.sucursales.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.nombre;
                select.appendChild(opt);
            });
        }
    } catch (e) {
        console.error('Error cargando sucursales:', e);
    }
}

function mostrarLista() {
    document.getElementById('vista-lista').classList.remove('d-none');
    document.getElementById('vista-formulario').classList.add('d-none');
    document.getElementById('vista-permisos').classList.add('d-none');
}

function mostrarFormularioCrear() {
    document.getElementById('titulo-formulario').innerHTML = '<i class="bi bi-person-plus"></i> Nuevo Usuario';
    document.getElementById('form-usuario').reset();
    document.getElementById('usr-id').value = '';
    document.getElementById('lbl-password-req').style.display = '';
    document.getElementById('help-password').style.display = 'none';
    document.getElementById('usr-password').required = true;
    
    document.getElementById('vista-lista').classList.add('d-none');
    document.getElementById('vista-formulario').classList.remove('d-none');
}

function editarUsuario(u) {
    document.getElementById('titulo-formulario').innerHTML = '<i class="bi bi-pencil-square"></i> Editar Usuario';
    document.getElementById('form-usuario').reset();
    
    document.getElementById('usr-id').value = u.id;
    document.getElementById('usr-nombre').value = u.nombre;
    document.getElementById('usr-email').value = u.email;
    document.getElementById('usr-rol').value = u.rol;
    document.getElementById('usr-sucursal').value = u.sucursal_id || '';
    
    document.getElementById('lbl-password-req').style.display = 'none';
    document.getElementById('help-password').style.display = 'block';
    document.getElementById('usr-password').required = false;
    
    document.getElementById('vista-lista').classList.add('d-none');
    document.getElementById('vista-formulario').classList.remove('d-none');
}

async function guardarUsuario(event) {
    event.preventDefault();
    limpiarAlerta();
    
    const id = document.getElementById('usr-id').value;
    const empresaId = document.getElementById('usr-empresa-id').value;
    const action = id ? 'actualizar_usuario' : 'crear_usuario';
    
    const payload = {
        id: id ? parseInt(id) : undefined,
        empresa_id: parseInt(empresaId),
        nombre: document.getElementById('usr-nombre').value.trim(),
        email: document.getElementById('usr-email').value.trim(),
        password: document.getElementById('usr-password').value || undefined,
        rol: document.getElementById('usr-rol').value,
        sucursal_id: document.getElementById('usr-sucursal').value ? parseInt(document.getElementById('usr-sucursal').value) : null
    };
    
    try {
        const resp = await fetch('api.php?action=' + action, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        const data = await resp.json();
        
        if (!data.ok) {
            mostrarAlerta(data.error || 'Error al guardar el usuario', 'danger');
            return;
        }
        
        mostrarAlerta(id ? 'Usuario actualizado con éxito' : 'Usuario creado con éxito', 'success');
        mostrarLista();
        cargarUsuarios(empresaId);
        
    } catch (e) {
        mostrarAlerta('Error de red al guardar: ' + e.message, 'danger');
    }
}

async function desactivarUsuario(id, empresaId) {
    if (!confirm('¿Seguro que deseas desactivar este usuario? Ya no aparecerá en el listado activo.')) {
        return;
    }
    
    limpiarAlerta();
    try {
        const resp = await fetch('api.php?action=desactivar_usuario', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ id, empresa_id: empresaId })
        });
        const data = await resp.json();
        
        if (!data.ok) {
            mostrarAlerta(data.error || 'Error al desactivar el usuario', 'danger');
            return;
        }
        
        mostrarAlerta('Usuario desactivado con éxito', 'success');
        cargarUsuarios(empresaId);
        
    } catch (e) {
        mostrarAlerta('Error de red al desactivar: ' + e.message, 'danger');
    }
}

function mostrarAlerta(msg, type) {
    const alertEl = document.getElementById('modal-alert');
    alertEl.className = `alert alert-${type} mb-3 py-2 px-3 small`;
    alertEl.textContent = msg;
    alertEl.classList.remove('d-none');
}

function limpiarAlerta() {
    const alertEl = document.getElementById('modal-alert');
    alertEl.classList.add('d-none');
    alertEl.textContent = '';
}

async function mostrarPermisos() {
    document.getElementById('vista-lista').classList.add('d-none');
    document.getElementById('vista-formulario').classList.add('d-none');
    document.getElementById('vista-permisos').classList.remove('d-none');
    limpiarAlerta();

    const empresaId = document.getElementById('usr-empresa-id').value;
    await cargarMatriz(empresaId);
}

async function cargarMatriz(empresaId) {
    const roles = ['cajero', 'supervisor', 'admin'];
    const permisos = ['puede_anular', 'puede_descuento_mayor', 'puede_ver_reportes', 'puede_configurar_pos'];

    try {
        const resp = await fetch(`api.php?action=get_matriz_permisos&empresa_id=${empresaId}`);
        const data = await resp.json();
        if (!data.ok) return;

        const matriz = data.matriz || {};
        roles.forEach(rol => {
            permisos.forEach(perm => {
                const el = document.getElementById(`${rol}_${perm}`);
                if (el) el.checked = !!(matriz[rol] && matriz[rol][perm]);
            });
        });
    } catch (e) {
        mostrarAlerta('Error cargando permisos: ' + e.message, 'danger');
    }
}

async function guardarMatriz(event) {
    event.preventDefault();
    limpiarAlerta();

    const empresaId = parseInt(document.getElementById('usr-empresa-id').value);
    const roles = ['cajero', 'supervisor', 'admin'];
    const permisos = ['puede_anular', 'puede_descuento_mayor', 'puede_ver_reportes', 'puede_configurar_pos'];

    const matriz = {};
    roles.forEach(rol => {
        matriz[rol] = {};
        permisos.forEach(perm => {
            const el = document.getElementById(`${rol}_${perm}`);
            matriz[rol][perm] = el && el.checked ? 1 : 0;
        });
    });

    try {
        const resp = await fetch('api.php?action=guardar_matriz_permisos', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ empresa_id: empresaId, matriz })
        });
        const data = await resp.json();

        if (!data.ok) {
            mostrarAlerta(data.error || 'Error al guardar permisos', 'danger');
            return;
        }
        mostrarAlerta('Permisos guardados con éxito', 'success');
    } catch (e) {
        mostrarAlerta('Error de red: ' + e.message, 'danger');
    }
}

function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>
