<?php
/**
 * Modulo: Clientes MyPOS
 * Tablero tecnico DTE/SII leyendo tablas existentes de MyPOS.
 */
if (!defined('DTE_API_BOOTSTRAP_ONLY')) exit;

if (!$dbOk) {
    echo '<div class="d-alert danger"><i class="bi bi-database-x"></i> Sin conexion a base de datos.</div>';
    return;
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

$requiredMyposTables = [
    'empresas',
    'empresa_configuracion_operativa',
    'empresas_suscripcion',
    'suscripciones_ordenes',
    'documentos_emitidos',
];

$useSiiTables = false;
try {
    $stmt = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sii_empresa' LIMIT 1");
    $useSiiTables = (bool)$stmt->fetchColumn();
} catch (Exception $e) {}

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
        ? "LEFT JOIN sii_empresa se ON REPLACE(REPLACE(UPPER(se.rut), '.', ''), ' ', '') = REPLACE(REPLACE(UPPER(e.rut), '.', ''), ' ', '')
         LEFT JOIN (
            SELECT empresa_id, COUNT(*) AS certificados_activos
            FROM sii_certificado
            WHERE activo = 1
            GROUP BY empresa_id
         ) cert ON cert.empresa_id = se.id
         LEFT JOIN (
            SELECT
                c.empresa_id,
                COUNT(*) AS cafs_activos,
                SUM(GREATEST(c.folio_hasta - c.folio_desde + 1 - COALESCE(fc.consumidos, 0), 0)) AS folios_disponibles
            FROM sii_caf c
            LEFT JOIN (
                SELECT caf_id, COUNT(*) AS consumidos
                FROM sii_folio_consumo
                GROUP BY caf_id
            ) fc ON fc.caf_id = c.id
            WHERE c.activo = 1
            GROUP BY c.empresa_id
         ) caf ON caf.empresa_id = se.id"
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
?>

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
                                <?php if (!$hasSii): ?>
                                    <a class="d-btn d-btn-sm d-btn-primary" href="dashboard.php?module=empresas">Crear ficha SII</a>
                                <?php elseif (!$hasCert): ?>
                                    <a class="d-btn d-btn-sm d-btn-primary" href="dashboard.php?module=config&switch_empresa=<?= (int)$cliente['sii_empresa_id'] ?>">Certificado</a>
                                <?php elseif (!$hasFolios): ?>
                                    <a class="d-btn d-btn-sm d-btn-primary" href="dashboard.php?module=cafs&switch_empresa=<?= (int)$cliente['sii_empresa_id'] ?>">Cargar CAF</a>
                                <?php else: ?>
                                    <a class="d-btn d-btn-sm d-btn-secondary" href="dashboard.php?module=emision&switch_empresa=<?= (int)$cliente['sii_empresa_id'] ?>">Prueba DTE</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
