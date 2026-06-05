<?php
/**
 * Módulo Empresas — Gestión Multi-Cliente
 * CRUD completo: crear / editar / eliminar (soft-delete) empresas.
 */
$empMsg = '';
$empError = '';
$empNewKey = '';

$useSiiTables = false;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sii_empresa'");
    if ((int)$stmt->fetchColumn() > 0) {
        $stmt = $db->query("SELECT COUNT(*) FROM sii_empresa WHERE activo = 1");
        $useSiiTables = ((int)$stmt->fetchColumn()) > 0;
    }
} catch (Exception $e) {}

/** Normaliza el campo acteco (texto "107300, 463020") a JSON array. */
$actecoToJson = function (?string $raw): string {
    $codes = array_values(array_filter(array_map('trim', explode(',', (string)$raw))));
    return json_encode($codes ?: []);
};

$metadataJson = function () use ($actecoToJson): string {
    return json_encode([
        'acteco' => json_decode($actecoToJson($_POST['acteco'] ?? ''), true) ?: [],
        'unidad_sii' => trim((string)($_POST['unidad_sii'] ?? '')),
        'email_sii' => trim((string)($_POST['email_sii'] ?? '')),
        'numero_resolucion' => trim((string)($_POST['num_resol'] ?? '80')) ?: '80',
        'fecha_resolucion' => trim((string)($_POST['fecha_resol'] ?? '2026-05-17')) ?: '2026-05-17',
    ], JSON_UNESCAPED_UNICODE);
};

// ── Procesar CREACIÓN ────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'create_empresa' && $dbOk) {
    try {
        $db->beginTransaction();
        $rut = trim($_POST['rut']);
        $rs  = trim($_POST['razon_social']);

        if ($useSiiTables) {
            $stmt = $db->prepare("INSERT INTO sii_empresa
                (rut, razon_social, giro, acteco, direccion_origen, comuna_origen, ciudad_origen,
                 unidad_sii, email_sii, fecha_resolucion, numero_resolucion, ambiente_default)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $rut, $rs, $_POST['giro'], $actecoToJson($_POST['acteco'] ?? ''),
                $_POST['direccion'], $_POST['comuna'], $_POST['ciudad'] ?? '',
                $_POST['unidad_sii'] ?? '', $_POST['email_sii'] ?? '',
                $_POST['fecha_resol'] ?: '2014-01-01', $_POST['num_resol'] ?: '0', $_POST['ambiente']
            ]);
            $empresaId = $db->lastInsertId();

            $rawKey = 'sk_' . bin2hex(random_bytes(16));
            $hash = hash('sha256', $rawKey);
            $db->prepare("INSERT INTO sii_api_key (empresa_id, nombre, clave_hash) VALUES (?, 'Default', ?)")->execute([$empresaId, $hash]);
            $empNewKey = $rawKey;
        } else {
            // Para MyPOS SaaS:
            $stmt = $db->prepare("INSERT INTO empresas (rut, razon_social, nombre_fantasia, giro, direccion, comuna, ciudad, activo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$rut, $rs, $rs, $_POST['giro'], $_POST['direccion'], $_POST['comuna'], $_POST['ciudad'] ?? '']);
            $empresaId = $db->lastInsertId();

            $stmtConf = $db->prepare("INSERT INTO empresa_configuracion (empresa_id, rut_empresa, razon_social, nombre_fantasia, giro, direccion, comuna, ciudad, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtConf->execute([$empresaId, $rut, $rs, $rs, $_POST['giro'], $_POST['direccion'], $_POST['comuna'], $_POST['ciudad'] ?? '', $metadataJson()]);

            $db->prepare("INSERT INTO empresa_configuracion_operativa (empresa_id) VALUES (?)")->execute([$empresaId]);

            $stmtDte = $db->prepare("INSERT INTO dte_configuracion (empresa_id, modo, ambiente, activo) VALUES (?, 'REAL', ?, 1)");
            $stmtDte->execute([$empresaId, strtoupper($_POST['ambiente'])]);

            $empNewKey = '';
        }

        $db->commit();
        $empMsg = "Empresa '$rs' creada con éxito.";
    } catch (Exception $e) {
        $db->rollBack();
        $empError = $e->getMessage();
    }
}

// ── Procesar EDICIÓN ─────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'update_empresa' && $dbOk) {
    try {
        $id  = (int)$_POST['empresa_id'];
        $rut = trim($_POST['rut']);
        $rs  = trim($_POST['razon_social']);

        if ($useSiiTables) {
            $stmt = $db->prepare("UPDATE sii_empresa SET
                rut=?, razon_social=?, giro=?, acteco=?, direccion_origen=?, comuna_origen=?,
                ciudad_origen=?, unidad_sii=?, email_sii=?, fecha_resolucion=?, numero_resolucion=?, ambiente_default=?
                WHERE id=?");
            $stmt->execute([
                $rut, $rs, $_POST['giro'], $actecoToJson($_POST['acteco'] ?? ''),
                $_POST['direccion'], $_POST['comuna'], $_POST['ciudad'] ?? '',
                $_POST['unidad_sii'] ?? '', $_POST['email_sii'] ?? '',
                $_POST['fecha_resol'] ?: '2014-01-01', $_POST['num_resol'] ?: '0', $_POST['ambiente'], $id
            ]);
        } else {
            $db->prepare("UPDATE empresas SET rut=?, razon_social=?, nombre_fantasia=?, giro=?, direccion=?, comuna=?, ciudad=? WHERE id=?")
               ->execute([$rut, $rs, $rs, $_POST['giro'], $_POST['direccion'], $_POST['comuna'], $_POST['ciudad'] ?? '', $id]);
            $db->prepare("UPDATE empresa_configuracion SET rut_empresa=?, razon_social=?, nombre_fantasia=?, giro=?, direccion=?, comuna=?, ciudad=?, metadata_json=? WHERE empresa_id=?")
               ->execute([$rut, $rs, $rs, $_POST['giro'], $_POST['direccion'], $_POST['comuna'], $_POST['ciudad'] ?? '', $metadataJson(), $id]);
        }
        $empMsg = "Empresa '$rs' actualizada con éxito.";
    } catch (Exception $e) {
        $empError = $e->getMessage();
    }
}

// ── Procesar ELIMINACIÓN (soft-delete: activo=0 para preservar DTEs históricos) ──
if (isset($_POST['action']) && $_POST['action'] === 'delete_empresa' && $dbOk) {
    try {
        $id = (int)$_POST['empresa_id'];
        if ($useSiiTables) {
            $db->prepare("UPDATE sii_empresa SET activo=0 WHERE id=?")->execute([$id]);
        } else {
            $db->prepare("UPDATE empresas SET activo=0 WHERE id=?")->execute([$id]);
        }
        $empMsg = "Empresa desactivada. Sus documentos históricos se conservan.";
    } catch (Exception $e) {
        $empError = $e->getMessage();
    }
}

// ── Cargar empresa para edición (modo formulario) ────────────────────────────
$editEmp = null;
if ($dbOk && isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    if ($useSiiTables) {
        $stmt = $db->prepare("SELECT * FROM sii_empresa WHERE id=? LIMIT 1");
        $stmt->execute([$eid]);
        $editEmp = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $stmt = $db->prepare("
            SELECT e.*,
                   CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.acteco')), '[]') ELSE '[]' END AS acteco,
                   CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.unidad_sii')), '') ELSE '' END AS unidad_sii,
                   CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.email_sii')), e.email) ELSE e.email END AS email_sii,
                   CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.numero_resolucion')), '80') ELSE '80' END AS numero_resolucion,
                   CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.fecha_resolucion')), '2026-05-17') ELSE '2026-05-17' END AS fecha_resolucion
            FROM empresas e
            LEFT JOIN empresa_configuracion ec ON ec.empresa_id = e.id
            WHERE e.id=? LIMIT 1
        ");
        $stmt->execute([$eid]);
        $editEmp = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// ── Refrescar listado ────────────────────────────────────────────────────────
$empresas = [];
if ($dbOk) {
    if ($useSiiTables) {
        $empresas = $db->query("SELECT id, rut, razon_social, giro, unidad_sii, ambiente_default, activo
                                FROM sii_empresa ORDER BY activo DESC, razon_social")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $empresas = $db->query("
            SELECT e.id, e.rut, e.razon_social, e.giro,
                   '' AS unidad_sii,
                   COALESCE(dc.ambiente, 'CERTIFICACION') AS ambiente_default,
                   e.activo
            FROM empresas e
            LEFT JOIN dte_configuracion dc ON e.id = dc.empresa_id
            ORDER BY e.activo DESC, e.razon_social
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

/** Helper: valor del formulario en modo edición. */
$ev = function (string $key, string $default = '') use ($editEmp) {
    return htmlspecialchars((string)($editEmp[$key] ?? $default), ENT_QUOTES);
};
$isEdit       = $editEmp !== null;
$formAction   = $isEdit ? 'update_empresa' : 'create_empresa';
$actecoPretty = '';
if ($isEdit) {
    $arr = json_decode($editEmp['acteco'] ?? '[]', true);
    $actecoPretty = is_array($arr) ? implode(', ', $arr) : (string)($editEmp['acteco'] ?? '');
}
$ambActual = strtolower($editEmp['ambiente_default'] ?? 'certificacion');
?>

<?php if ($empMsg): ?>
    <div class="d-alert success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($empMsg) ?></div>
<?php endif; ?>
<?php if ($empNewKey): ?>
    <div class="d-alert warning" style="flex-direction:column; align-items:flex-start">
        <div><i class="bi bi-key"></i> <strong>¡IMPORTANTE!</strong> Guarde esta API Key ahora. No se volverá a mostrar:</div>
        <div style="background:rgba(0,0,0,.05); border:2px dashed var(--c-warning); padding:12px 20px; border-radius:8px; font-family:monospace; font-size:1.1rem; font-weight:700; margin-top:8px; letter-spacing:.02em; user-select:all">
            <?= $empNewKey ?>
        </div>
    </div>
<?php endif; ?>
<?php if ($empError): ?>
    <div class="d-alert danger"><i class="bi bi-x-circle"></i> <?= htmlspecialchars($empError) ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Formulario -->
    <div class="col-lg-4">
        <div class="d-card">
            <div class="d-card-header">
                <i class="bi bi-<?= $isEdit ? 'pencil-square' : 'plus-circle' ?>"></i>
                <?= $isEdit ? 'Editar Empresa' : 'Nueva Empresa' ?>
                <?php if ($isEdit): ?>
                    <a href="?module=empresas" class="d-btn d-btn-sm d-btn-outline" style="margin-left:auto"><i class="bi bi-x"></i> Cancelar</a>
                <?php endif; ?>
            </div>
            <div class="d-card-body">
                <?php if (!$dbOk): ?>
                    <div class="d-alert danger"><i class="bi bi-database-x"></i> Sin conexión a base de datos.</div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="<?= $formAction ?>">
                    <?php if ($isEdit): ?><input type="hidden" name="empresa_id" value="<?= (int)$editEmp['id'] ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label class="d-label">RUT Empresa</label>
                        <input type="text" name="rut" class="d-input" placeholder="12345678-9" value="<?= $ev('rut') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="d-label">Razón Social</label>
                        <input type="text" name="razon_social" class="d-input" value="<?= $ev('razon_social') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="d-label">Giro <small style="color:var(--c-text-muted)">(sin abreviar — el SII lo exige)</small></label>
                        <input type="text" name="giro" class="d-input" value="<?= $ev('giro') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="d-label">Acteco <small style="color:var(--c-text-muted)">(códigos separados por coma)</small></label>
                        <input type="text" name="acteco" class="d-input" placeholder="620200, 631000" value="<?= htmlspecialchars($actecoPretty, ENT_QUOTES) ?>">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-8">
                            <label class="d-label">Dirección</label>
                            <input type="text" name="direccion" class="d-input" value="<?= $ev($useSiiTables ? 'direccion_origen' : 'direccion') ?>" required>
                        </div>
                        <div class="col-4">
                            <label class="d-label">Comuna</label>
                            <input type="text" name="comuna" class="d-input" value="<?= $ev($useSiiTables ? 'comuna_origen' : 'comuna') ?>" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="d-label">Ciudad</label>
                            <input type="text" name="ciudad" class="d-input" value="<?= $ev($useSiiTables ? 'ciudad_origen' : 'ciudad') ?>">
                        </div>
                        <div class="col-6">
                            <label class="d-label">Unidad SII</label>
                            <input type="text" name="unidad_sii" class="d-input" placeholder="SII - SANTIAGO NORTE" value="<?= $ev('unidad_sii') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="d-label">Email registrado en SII</label>
                        <input type="email" name="email_sii" class="d-input" value="<?= $ev('email_sii') ?>">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="d-label">N° Resolución SII</label>
                            <input type="text" name="num_resol" class="d-input" value="<?= $ev('numero_resolucion', '0') ?>">
                        </div>
                        <div class="col-6">
                            <label class="d-label">Fecha Resolución</label>
                            <input type="date" name="fecha_resol" class="d-input" value="<?= $ev('fecha_resolucion') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="d-label">Ambiente</label>
                        <select name="ambiente" class="d-input d-select">
                            <option value="certificacion" <?= $ambActual === 'certificacion' ? 'selected' : '' ?>>🧪 Certificación (Pruebas)</option>
                            <option value="produccion" <?= $ambActual === 'produccion' ? 'selected' : '' ?>>🟢 Producción (Real)</option>
                        </select>
                    </div>
                    <button type="submit" class="d-btn d-btn-primary w-100">
                        <i class="bi bi-<?= $isEdit ? 'save' : 'building-add' ?>"></i>
                        <?= $isEdit ? 'Guardar Cambios' : 'Crear Empresa' ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Listado -->
    <div class="col-lg-8">
        <div class="d-card">
            <div class="d-card-header">
                <i class="bi bi-buildings"></i> Empresas Registradas
                <span class="d-badge info" style="margin-left:auto"><?= count($empresas) ?> empresa(s)</span>
            </div>
            <div class="d-card-body" style="padding:0">
                <?php if (empty($empresas)): ?>
                    <div style="padding:60px; text-align:center; color:var(--c-text-muted)">
                        <i class="bi bi-building-x" style="font-size:2.5rem"></i>
                        <p class="mt-2">No hay empresas registradas aún.<br>Cree la primera usando el formulario.</p>
                    </div>
                <?php else: ?>
                <table class="d-table">
                    <thead>
                        <tr>
                            <th>RUT</th>
                            <th>Razón Social</th>
                            <th>Ambiente</th>
                            <th>Estado</th>
                            <th style="text-align:right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($empresas as $emp): ?>
                        <tr style="<?= $emp['activo'] ? '' : 'opacity:.5' ?>">
                            <td style="font-weight:700; font-family:monospace"><?= htmlspecialchars($emp['rut']) ?></td>
                            <td><?= htmlspecialchars($emp['razon_social']) ?></td>
                            <td>
                                <span class="d-badge <?= $emp['ambiente_default'] === 'produccion' ? 'prod' : 'cert' ?>">
                                    <?= $emp['ambiente_default'] === 'produccion' ? '🟢 Producción' : '🧪 Certificación' ?>
                                </span>
                            </td>
                            <td><?= $emp['activo'] ? '<span class="d-badge prod">Activa</span>' : '<span class="d-badge danger">Inactiva</span>' ?></td>
                            <td style="text-align:right; white-space:nowrap">
                                <a href="?module=empresas&edit=<?= (int)$emp['id'] ?>" class="d-btn d-btn-sm d-btn-outline" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($emp['activo']): ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('¿Desactivar la empresa <?= htmlspecialchars(addslashes($emp['razon_social'])) ?>? Sus documentos históricos se conservan.');">
                                    <input type="hidden" name="action" value="delete_empresa">
                                    <input type="hidden" name="empresa_id" value="<?= (int)$emp['id'] ?>">
                                    <button type="submit" class="d-btn d-btn-sm d-btn-outline" style="color:var(--c-danger)" title="Eliminar">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
