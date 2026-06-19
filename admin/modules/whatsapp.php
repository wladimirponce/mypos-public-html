<?php
/**
 * Módulo WhatsApp — Configuración multi-empresa
 *
 * Permite asignar a cada empresa su propio número WhatsApp Business API:
 *   phone_number_id  → ID del número en Meta (WhatsApp Business API)
 *   access_token     → Token de acceso permanente para enviar mensajes
 *
 * El webhook de MyPOS usa esta tabla para enrutar conversaciones y responder
 * con el número correcto de cada empresa.
 */

$waMsj   = '';
$waError = '';

// ── DDL inline (crea la tabla si aún no se aplicó la migración 053) ──────────
$ddlWa = <<<SQL
CREATE TABLE IF NOT EXISTS empresa_whatsapp_config (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    phone_number_id VARCHAR(50)     NOT NULL COMMENT 'phone_number_id de Meta WhatsApp Business API',
    access_token    TEXT            NOT NULL COMMENT 'Token de acceso para enviar mensajes',
    activo          TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ewc_empresa      (empresa_id),
    UNIQUE KEY uq_ewc_phone_number (phone_number_id),
    KEY idx_ewc_activo             (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tablaOk = false;
if ($dbOk) {
    try {
        $db->query("SELECT 1 FROM empresa_whatsapp_config LIMIT 1");
        $tablaOk = true;
    } catch (Throwable $_) {
        try {
            $db->exec($ddlWa);
            $tablaOk = true;
            $waMsj   = '✓ Tabla empresa_whatsapp_config creada. Ya puedes configurar WhatsApp por empresa.';
        } catch (Throwable $e) {
            $waError = 'Error al crear tabla: ' . $e->getMessage();
        }
    }
}

// ── Procesar formulario ───────────────────────────────────────────────────────
if ($tablaOk && $dbOk && isset($_POST['wa_action'])) {

    $waEmpresaId     = (int)($_POST['wa_empresa_id'] ?? 0);
    $waPhoneNumberId = trim($_POST['wa_phone_number_id'] ?? '');
    $waAccessToken   = trim($_POST['wa_access_token'] ?? '');
    $waActivo        = isset($_POST['wa_activo']) ? 1 : 0;

    if ($_POST['wa_action'] === 'save') {
        if ($waEmpresaId <= 0 || $waPhoneNumberId === '' || $waAccessToken === '') {
            $waError = 'Empresa, Phone Number ID y Access Token son obligatorios.';
        } else {
            try {
                // Verificar que la empresa existe
                $stE = $db->prepare("SELECT id FROM empresas WHERE id = ? AND activo = 1 LIMIT 1");
                $stE->execute([$waEmpresaId]);
                if (!$stE->fetch()) throw new Exception('Empresa no encontrada.');

                // Upsert
                $stmt = $db->prepare("
                    INSERT INTO empresa_whatsapp_config (empresa_id, phone_number_id, access_token, activo)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        phone_number_id = VALUES(phone_number_id),
                        access_token    = VALUES(access_token),
                        activo          = VALUES(activo),
                        updated_at      = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$waEmpresaId, $waPhoneNumberId, $waAccessToken, $waActivo]);
                $waMsj = '✓ Configuración WhatsApp guardada correctamente.';
            } catch (Throwable $e) {
                $waError = 'Error al guardar: ' . $e->getMessage();
            }
        }
    }

    if ($_POST['wa_action'] === 'delete') {
        if ($waEmpresaId > 0) {
            try {
                $db->prepare("DELETE FROM empresa_whatsapp_config WHERE empresa_id = ?")
                   ->execute([$waEmpresaId]);
                $waMsj = '✓ Configuración eliminada.';
            } catch (Throwable $e) {
                $waError = 'Error al eliminar: ' . $e->getMessage();
            }
        }
    }

    if ($_POST['wa_action'] === 'toggle') {
        if ($waEmpresaId > 0) {
            try {
                $db->prepare("UPDATE empresa_whatsapp_config SET activo = IF(activo=1,0,1), updated_at=NOW() WHERE empresa_id = ?")
                   ->execute([$waEmpresaId]);
                $waMsj = '✓ Estado actualizado.';
            } catch (Throwable $e) {
                $waError = 'Error: ' . $e->getMessage();
            }
        }
    }
}

// ── Cargar datos ──────────────────────────────────────────────────────────────
$configs   = [];
$sinConfig = [];

if ($tablaOk && $dbOk) {
    // Empresas con config
    $configs = $db->query("
        SELECT e.id, e.razon_social, e.rut,
               ewc.phone_number_id,
               CONCAT(LEFT(ewc.access_token,12), '…') AS token_preview,
               ewc.activo,
               ewc.updated_at
        FROM empresas e
        INNER JOIN empresa_whatsapp_config ewc ON ewc.empresa_id = e.id
        WHERE e.activo = 1
        ORDER BY e.razon_social
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Empresas sin config
    $sinConfig = $db->query("
        SELECT e.id, e.razon_social, e.rut
        FROM empresas e
        LEFT JOIN empresa_whatsapp_config ewc ON ewc.empresa_id = e.id
        WHERE e.activo = 1 AND ewc.id IS NULL
        ORDER BY e.razon_social
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Empresa seleccionada para editar ─────────────────────────────────────────
$editData = null;
$editEmpId = (int)($_GET['editar'] ?? 0);
if ($editEmpId > 0 && $tablaOk && $dbOk) {
    $stEdit = $db->prepare("
        SELECT e.id, e.razon_social, e.rut,
               COALESCE(ewc.phone_number_id, '') AS phone_number_id,
               COALESCE(ewc.access_token, '')    AS access_token,
               COALESCE(ewc.activo, 1)           AS activo
        FROM empresas e
        LEFT JOIN empresa_whatsapp_config ewc ON ewc.empresa_id = e.id
        WHERE e.id = ? AND e.activo = 1
    ");
    $stEdit->execute([$editEmpId]);
    $editData = $stEdit->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>

<?php if ($waError): ?>
<div class="d-alert danger" style="margin-bottom:1rem;">
    <i class="bi bi-x-circle"></i> <?= htmlspecialchars($waError) ?>
</div>
<?php endif; ?>

<?php if ($waMsj): ?>
<div class="d-alert success" style="margin-bottom:1rem;">
    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($waMsj) ?>
</div>
<?php endif; ?>

<?php if (!$dbOk): ?>
<div class="d-alert danger"><i class="bi bi-database-x"></i> Sin conexión a la base de datos.</div>
<?php return; endif; ?>

<!-- ── Info general ─────────────────────────────────────────────────────────── -->
<div class="d-card" style="margin-bottom:1.5rem; border-left:4px solid #25d366;">
    <div class="d-card-header" style="color:#25d366;">
        <i class="bi bi-whatsapp"></i> WhatsApp Business API — Multi-empresa
    </div>
    <div class="d-card-body" style="font-size:.85rem; color:var(--c-text-muted); line-height:1.7;">
        <p>Cada empresa puede tener su propio número de WhatsApp. El webhook de MyPOS identifica
        el número entrante y enruta la conversación a la empresa correspondiente.</p>
        <p>
            <strong>Dónde obtener los datos:</strong>
            Meta for Developers → tu App → WhatsApp → API Setup.<br>
            <code>Phone Number ID</code>: visible en la sección "From phone number" (solo dígitos, ej: <code>1181492205043206</code>).<br>
            <code>Access Token</code>: generar un token permanente en Business Settings → System Users.
        </p>
        <p style="color:#f59e0b;">
            <i class="bi bi-exclamation-triangle"></i>
            Usa siempre un <strong>token permanente</strong> de System User, no el token temporal de 24 h.
        </p>
    </div>
</div>

<!-- ── Formulario de edición ─────────────────────────────────────────────────── -->
<?php if ($editData): ?>
<div class="d-card" style="margin-bottom:1.5rem; border-left:4px solid var(--c-primary);">
    <div class="d-card-header">
        <i class="bi bi-pencil-square"></i>
        <?= $editData['phone_number_id'] ? 'Editar' : 'Configurar' ?> WhatsApp —
        <?= htmlspecialchars($editData['razon_social']) ?>
    </div>
    <div class="d-card-body">
        <form method="POST" action="dashboard.php?module=whatsapp">
            <input type="hidden" name="wa_action"     value="save">
            <input type="hidden" name="wa_empresa_id" value="<?= $editData['id'] ?>">

            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">
                        Phone Number ID <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="wa_phone_number_id" class="form-control form-control-sm"
                           placeholder="ej: 1181492205043206"
                           value="<?= htmlspecialchars($editData['phone_number_id']) ?>" required>
                    <div class="form-text" style="font-size:.75rem;">
                        Solo dígitos. Se encuentra en Meta → WhatsApp → API Setup.
                    </div>
                </div>
                <div class="col-md-7">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">
                        Access Token <span class="text-danger">*</span>
                    </label>
                    <textarea name="wa_access_token" class="form-control form-control-sm"
                              rows="2" placeholder="EAAxxxxxxxx..." required
                              style="font-family:monospace; font-size:.78rem;"><?= htmlspecialchars($editData['access_token']) ?></textarea>
                    <div class="form-text" style="font-size:.75rem;">
                        Token permanente de System User (Business Manager).
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="wa_activo" id="waActivo"
                               <?= $editData['activo'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="waActivo" style="font-size:.85rem;">
                            Activo — el webhook enruta mensajes a esta empresa
                        </label>
                    </div>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-floppy"></i> Guardar
                </button>
                <a href="dashboard.php?module=whatsapp" class="btn btn-sm btn-outline-secondary">
                    Cancelar
                </a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── Empresas configuradas ─────────────────────────────────────────────────── -->
<div class="d-card" style="margin-bottom:1.5rem;">
    <div class="d-card-header">
        <i class="bi bi-check2-circle" style="color:#22c55e;"></i>
        Empresas con WhatsApp configurado (<?= count($configs) ?>)
    </div>
    <div class="d-card-body" style="padding:0;">
        <?php if (empty($configs)): ?>
            <p style="padding:1.25rem; color:var(--c-text-muted); font-size:.87rem;">
                Ninguna empresa tiene WhatsApp configurado aún.
            </p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.83rem;">
                <thead style="background:var(--c-surface);">
                    <tr>
                        <th>Empresa</th>
                        <th>RUT</th>
                        <th>Phone Number ID</th>
                        <th>Token</th>
                        <th>Estado</th>
                        <th>Actualizado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($configs as $cfg): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($cfg['razon_social']) ?></td>
                        <td><code style="font-size:.78rem;"><?= htmlspecialchars($cfg['rut']) ?></code></td>
                        <td><code style="font-size:.78rem;"><?= htmlspecialchars($cfg['phone_number_id']) ?></code></td>
                        <td><code style="font-size:.78rem; color:var(--c-text-muted);"><?= htmlspecialchars($cfg['token_preview']) ?></code></td>
                        <td>
                            <?php if ($cfg['activo']): ?>
                                <span class="badge bg-success">Activo</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--c-text-muted);">
                            <?= $cfg['updated_at'] ? date('d/m/Y H:i', strtotime($cfg['updated_at'])) : '—' ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="dashboard.php?module=whatsapp&editar=<?= $cfg['id'] ?>"
                                   class="btn btn-xs btn-outline-primary" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" action="dashboard.php?module=whatsapp" style="display:inline;">
                                    <input type="hidden" name="wa_action"     value="toggle">
                                    <input type="hidden" name="wa_empresa_id" value="<?= $cfg['id'] ?>">
                                    <button type="submit" class="btn btn-xs btn-outline-secondary"
                                            title="<?= $cfg['activo'] ? 'Desactivar' : 'Activar' ?>">
                                        <i class="bi bi-<?= $cfg['activo'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                    </button>
                                </form>
                                <form method="POST" action="dashboard.php?module=whatsapp" style="display:inline;"
                                      onsubmit="return confirm('¿Eliminar configuración WhatsApp de esta empresa?');">
                                    <input type="hidden" name="wa_action"     value="delete">
                                    <input type="hidden" name="wa_empresa_id" value="<?= $cfg['id'] ?>">
                                    <button type="submit" class="btn btn-xs btn-outline-danger" title="Eliminar">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Empresas sin WhatsApp ─────────────────────────────────────────────────── -->
<?php if (!empty($sinConfig)): ?>
<div class="d-card">
    <div class="d-card-header">
        <i class="bi bi-dash-circle" style="color:var(--c-text-muted);"></i>
        Empresas sin WhatsApp configurado (<?= count($sinConfig) ?>)
    </div>
    <div class="d-card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.83rem;">
                <thead style="background:var(--c-surface);">
                    <tr>
                        <th>Empresa</th>
                        <th>RUT</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sinConfig as $emp): ?>
                    <tr>
                        <td><?= htmlspecialchars($emp['razon_social']) ?></td>
                        <td><code style="font-size:.78rem;"><?= htmlspecialchars($emp['rut']) ?></code></td>
                        <td>
                            <a href="dashboard.php?module=whatsapp&editar=<?= $emp['id'] ?>"
                               class="btn btn-xs btn-outline-success">
                                <i class="bi bi-plus-circle"></i> Configurar
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.btn-xs { padding: .2rem .45rem; font-size: .75rem; line-height: 1.4; }
</style>
