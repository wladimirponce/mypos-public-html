<?php
/**
 * Módulo Empresas — Gestión Multi-Cliente
 */
$empMsg = '';
$empError = '';
$empNewKey = '';

$useSiiTables = false;
try {
    $stmt = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sii_empresa' LIMIT 1");
    $useSiiTables = (bool)$stmt->fetchColumn();
} catch (Exception $e) {}

// Procesar creación
if (isset($_POST['action']) && $_POST['action'] === 'create_empresa' && $dbOk) {
    try {
        $db->beginTransaction();
        $rut = trim($_POST['rut']);
        $rs  = trim($_POST['razon_social']);
        
        if ($useSiiTables) {
            $stmt = $db->prepare("INSERT INTO sii_empresa (rut, razon_social, giro, acteco, direccion_origen, comuna_origen, ciudad_origen, fecha_resolucion, numero_resolucion, ambiente_default) VALUES (?, ?, ?, '[]', ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$rut, $rs, $_POST['giro'], $_POST['direccion'], $_POST['comuna'], $_POST['ciudad'] ?? '', $_POST['fecha_resol'] ?? '2014-01-01', $_POST['num_resol'] ?? '0', $_POST['ambiente']]);
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

            // Insertar configuraciones necesarias de MyPOS
            $stmtConf = $db->prepare("INSERT INTO empresa_configuracion (empresa_id, rut_empresa, razon_social, nombre_fantasia, giro, direccion, comuna, ciudad) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtConf->execute([$empresaId, $rut, $rs, $rs, $_POST['giro'], $_POST['direccion'], $_POST['comuna'], $_POST['ciudad'] ?? '']);

            $db->prepare("INSERT INTO empresa_configuracion_operativa (empresa_id) VALUES (?)")->execute([$empresaId]);

            // Configuración DTE
            $stmtDte = $db->prepare("INSERT INTO dte_configuracion (empresa_id, modo, ambiente, activo) VALUES (?, 'REAL', ?, 1)");
            $stmtDte->execute([$empresaId, strtoupper($_POST['ambiente'])]);

            $empNewKey = ''; // No se requiere API key en la tabla sii_api_key para MyPOS SaaS
        }
        
        $db->commit();
        $empMsg = "Empresa '$rs' creada con éxito.";
        
        // Refrescar lista
        if ($useSiiTables) {
            $empresas = $db->query("SELECT id, rut, razon_social, ambiente_default, activo FROM sii_empresa WHERE activo = 1 ORDER BY razon_social")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $empresas = $db->query("
                SELECT e.id, e.rut, e.razon_social,
                       COALESCE(dc.ambiente, 'CERTIFICACION') AS ambiente_default,
                       e.activo
                FROM empresas e
                LEFT JOIN dte_configuracion dc ON e.id = dc.empresa_id
                WHERE e.activo = 1
                ORDER BY e.razon_social
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $db->rollBack();
        $empError = $e->getMessage();
    }
}
?>

<?php if ($empMsg): ?>
    <div class="d-alert success"><i class="bi bi-check-circle"></i> <?= $empMsg ?></div>
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
            <div class="d-card-header"><i class="bi bi-plus-circle"></i> Nueva Empresa</div>
            <div class="d-card-body">
                <?php if (!$dbOk): ?>
                    <div class="d-alert danger"><i class="bi bi-database-x"></i> Sin conexión a base de datos.</div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="create_empresa">
                    <div class="mb-3">
                        <label class="d-label">RUT Empresa</label>
                        <input type="text" name="rut" class="d-input" placeholder="12345678-9" required>
                    </div>
                    <div class="mb-3">
                        <label class="d-label">Razón Social</label>
                        <input type="text" name="razon_social" class="d-input" required>
                    </div>
                    <div class="mb-3">
                        <label class="d-label">Giro</label>
                        <input type="text" name="giro" class="d-input" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-8">
                            <label class="d-label">Dirección</label>
                            <input type="text" name="direccion" class="d-input" required>
                        </div>
                        <div class="col-4">
                            <label class="d-label">Comuna</label>
                            <input type="text" name="comuna" class="d-input" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="d-label">N° Resolución SII</label>
                            <input type="text" name="num_resol" class="d-input" value="0">
                        </div>
                        <div class="col-6">
                            <label class="d-label">Fecha Resolución</label>
                            <input type="date" name="fecha_resol" class="d-input">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="d-label">Ambiente Inicial</label>
                        <select name="ambiente" class="d-input d-select">
                            <option value="certificacion">🧪 Certificación (Pruebas)</option>
                            <option value="produccion">🟢 Producción (Real)</option>
                        </select>
                    </div>
                    <button type="submit" class="d-btn d-btn-primary w-100">
                        <i class="bi bi-building-add"></i> Crear Empresa
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($empresas as $emp): ?>
                        <tr>
                            <td style="font-weight:700; font-family:monospace"><?= htmlspecialchars($emp['rut']) ?></td>
                            <td><?= htmlspecialchars($emp['razon_social']) ?></td>
                            <td>
                                <span class="d-badge <?= $emp['ambiente_default'] === 'produccion' ? 'prod' : 'cert' ?>">
                                    <?= $emp['ambiente_default'] === 'produccion' ? '🟢 Producción' : '🧪 Certificación' ?>
                                </span>
                            </td>
                            <td><?= $emp['activo'] ? '<span class="d-badge prod">Activa</span>' : '<span class="d-badge danger">Inactiva</span>' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
