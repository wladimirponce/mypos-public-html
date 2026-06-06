<?php
/**
 * Módulo Dispositivos — Enrolamiento de máquinas POS
 *
 * Permite al admin:
 *   1. Ver todos los dispositivos enrolados o pendientes de enrolar
 *   2. Generar un nuevo código de activación (token de 6 letras, válido 24 h)
 *   3. Desactivar un dispositivo (revoca su API key)
 */

$dispMsg   = '';
$dispError = '';
$dispToken = null;  // resultado del último token generado

// ── DDL de la tabla (se aplica inline si no existe) ──────────────────────────
$ddlDispositivo = <<<SQL
CREATE TABLE IF NOT EXISTS `sii_dispositivo` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id`         INT UNSIGNED NOT NULL,
    `sucursal_id`        VARCHAR(40) NOT NULL,
    `nombre`             VARCHAR(120) NOT NULL DEFAULT '',
    `tipo`               ENUM('POS_ANDROID','POS_WEB','APK_MOVIL','OTRO') NOT NULL DEFAULT 'POS_ANDROID',
    `token_activacion`   VARCHAR(20) NOT NULL,
    `token_hash`         VARCHAR(64) NOT NULL,
    `token_expira`       DATETIME NOT NULL,
    `token_usado`        TINYINT(1) NOT NULL DEFAULT 0,
    `api_key_hash`       VARCHAR(64) NULL,
    `device_android_id`  VARCHAR(64) NULL,
    `device_modelo`      VARCHAR(100) NULL,
    `device_version_app` VARCHAR(20) NULL,
    `enrolado_en`        DATETIME NULL,
    `ultimo_contacto`    DATETIME NULL,
    `activo`             TINYINT(1) NOT NULL DEFAULT 1,
    `notas`              VARCHAR(255) NULL,
    `creado_en`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token_hash` (`token_hash`),
    KEY `idx_empresa`      (`empresa_id`),
    KEY `idx_sucursal`     (`sucursal_id`),
    KEY `idx_activo`       (`activo`),
    KEY `idx_api_key_hash` (`api_key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

// ── Verificar / crear tabla automáticamente ──────────────────────────────────
$tablaOk = false;
if ($dbOk) {
    try {
        $db->query("SELECT 1 FROM sii_dispositivo LIMIT 1");
        $tablaOk = true;
    } catch (Throwable $_) {
        // La tabla no existe — intentar crearla
        if (isset($_POST['action']) && $_POST['action'] === 'aplicar_migracion') {
            try {
                $db->exec($ddlDispositivo);
                $tablaOk  = true;
                $dispMsg  = '✓ Tabla sii_dispositivo creada correctamente. Ya puedes enrolar dispositivos.';
            } catch (Throwable $e) {
                $dispError = 'Error al crear la tabla: ' . $e->getMessage();
            }
        }
    }
}

// ── Si la tabla no existe → mostrar panel de migración y salir ───────────────
if ($dbOk && !$tablaOk): ?>
<div class="d-card" style="border-left: 4px solid var(--c-warning);">
    <div class="d-card-header" style="color:var(--c-warning);">
        <i class="bi bi-database-exclamation"></i> Migración pendiente
    </div>
    <div class="d-card-body">
        <?php if ($dispError): ?>
            <div class="d-alert danger"><i class="bi bi-x-circle"></i> <?= htmlspecialchars($dispError) ?></div>
        <?php endif; ?>
        <p style="font-size:.9rem;">
            La tabla <code>sii_dispositivo</code> no existe en la base de datos.
            Haz clic en el botón para crearla ahora.
        </p>
        <p style="font-size:.82rem; color:var(--c-text-muted);">
            DDL que se ejecutará:<br>
            <code style="white-space:pre-wrap; font-size:.75rem;"><?= htmlspecialchars($ddlDispositivo) ?></code>
        </p>
        <form method="POST" action="?module=dispositivos">
            <input type="hidden" name="action" value="aplicar_migracion">
            <button type="submit" class="btn btn-warning">
                <i class="bi bi-play-circle"></i> Crear tabla sii_dispositivo
            </button>
        </form>
    </div>
</div>
<?php
    // No mostramos nada más hasta que exista la tabla
    return;
endif;

// ── Acción: generar token ────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'generar_token' && $dbOk) {
    try {
        $sucursalId = trim($_POST['sucursal_id'] ?? '');
        $nombre     = trim($_POST['nombre']      ?? 'POS sin nombre');
        $tipo       = trim($_POST['tipo']        ?? 'POS_ANDROID');

        if (empty($sucursalId)) throw new Exception('Selecciona una sucursal.');
        if (empty($nombre))     $nombre = 'POS sin nombre';

        $tiposValidos = ['POS_ANDROID', 'POS_WEB', 'APK_MOVIL', 'OTRO'];
        if (!in_array($tipo, $tiposValidos, true)) $tipo = 'POS_ANDROID';

        // Empresa activa en sesión
        $empresaId = $_SESSION['active_empresa_id'] ?? null;
        if (!$empresaId) throw new Exception('No hay empresa activa en la sesión.');

        // Código legible: 6 chars sin ambiguos (sin 0/O/I/1/L)
        $chars  = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $codigo = '';
        for ($i = 0; $i < 6; $i++) {
            $codigo .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $hash   = hash('sha256', $codigo);
        $expira = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $db->prepare(
            "INSERT INTO sii_dispositivo
                (empresa_id, sucursal_id, nombre, tipo, token_activacion, token_hash, token_expira)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$empresaId, $sucursalId, $nombre, $tipo, $codigo, $hash, $expira]);

        $dispMsg  = "Token generado para «{$nombre}».";
        $dispToken = [
            'codigo'      => $codigo,
            'expira'      => $expira,
            'nombre'      => $nombre,
            'sucursal_id' => $sucursalId,
        ];
    } catch (Exception $e) {
        $dispError = $e->getMessage();
    }
}

// ── Acción: desactivar dispositivo ──────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'desactivar' && $dbOk) {
    $id        = (int) ($_POST['dispositivo_id'] ?? 0);
    $empresaId = $_SESSION['active_empresa_id'] ?? 0;
    if ($id && $empresaId) {
        // Revocar API key antes de desactivar
        $stHash = $db->prepare("SELECT api_key_hash FROM sii_dispositivo WHERE id = ?");
        $stHash->execute([$id]);
        $keyHash = $stHash->fetchColumn();
        if ($keyHash) {
            $db->prepare("UPDATE sii_api_key SET activa = 0 WHERE clave_hash = ?")->execute([$keyHash]);
        }

        $db->prepare(
            "UPDATE sii_dispositivo SET activo = 0 WHERE id = ? AND empresa_id = ?"
        )->execute([$id, $empresaId]);
        $dispMsg = 'Dispositivo desactivado correctamente.';
    }
}

// ── Cargar lista de dispositivos y sucursales ────────────────────────────────
$dispositivos = [];
$sucursales   = [];
if ($dbOk) {
    $hasDimSucursal = false;
    try {
        $stCheck = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dim_sucursal' LIMIT 1");
        $hasDimSucursal = (bool)$stCheck->fetchColumn();
    } catch (Throwable $_) {}

    $empresaId = $_SESSION['active_empresa_id'] ?? null;
    if ($empresaId) {
        $sucTable = $hasDimSucursal ? 'dim_sucursal' : 'sucursales';
        $sucJoinCol = $hasDimSucursal ? 'id_sucursal' : 'id';

        $stDisp = $db->prepare(
            "SELECT d.id, d.sucursal_id, d.nombre, d.tipo,
                    d.token_activacion, d.token_usado, d.token_expira,
                    d.device_modelo, d.device_version_app,
                    d.enrolado_en, d.ultimo_contacto, d.activo, d.creado_en,
                    COALESCE(s.nombre, CONCAT('Sucursal ', d.sucursal_id)) AS sucursal_nombre
               FROM sii_dispositivo d
          LEFT JOIN {$sucTable} s ON s.{$sucJoinCol} = d.sucursal_id
              WHERE d.empresa_id = ?
              ORDER BY d.creado_en DESC"
        );
        $stDisp->execute([$empresaId]);
        $dispositivos = $stDisp->fetchAll(PDO::FETCH_ASSOC);
    }

    try {
        if ($hasDimSucursal && $empresaId) {
            $stSuc = $db->prepare(
                "SELECT id_sucursal AS id, nombre FROM dim_sucursal WHERE empresa_id = ? ORDER BY nombre ASC"
            );
            $stSuc->execute([$empresaId]);
            $sucursales = $stSuc->fetchAll(PDO::FETCH_ASSOC);
        } else {
            if ($empresaId) {
                $stSuc = $db->prepare("SELECT id, nombre FROM sucursales WHERE empresa_id = ? AND activo = 1 ORDER BY nombre ASC");
                $stSuc->execute([$empresaId]);
                $sucursales = $stSuc->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Exception $_) {}
}

// ── Helpers de formato ───────────────────────────────────────────────────────
function dispEstado(array $d): string {
    if (!$d['activo']) return '<span class="d-badge danger">Desactivado</span>';
    if ($d['token_usado']) return '<span class="d-badge prod">Enrolado</span>';
    if (strtotime($d['token_expira']) < time()) return '<span class="d-badge warning">Expirado</span>';
    return '<span class="d-badge info">Pendiente</span>';
}

function dispUltimoContacto(?string $dt): string {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'Hace menos de 1 min';
    if ($diff < 3600)  return 'Hace ' . round($diff / 60) . ' min';
    if ($diff < 86400) return 'Hace ' . round($diff / 3600) . ' h';
    return date('d/m/Y', strtotime($dt));
}
?>

<?php if ($dispMsg): ?>
    <div class="d-alert success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($dispMsg) ?></div>
<?php endif; ?>
<?php if ($dispError): ?>
    <div class="d-alert danger"><i class="bi bi-x-circle"></i> <?= htmlspecialchars($dispError) ?></div>
<?php endif; ?>

<?php if ($dispToken): ?>
<div class="d-alert warning" style="flex-direction:column; align-items:flex-start; gap:12px;">
    <div style="font-weight:700; font-size:1rem;"><i class="bi bi-phone-vibrate"></i> ¡Código de activación generado! Muéstraselo al técnico ahora.</div>
    <div style="text-align:center; width:100%;">
        <div style="background:rgba(0,0,0,.06); border:3px dashed var(--c-warning);
                    border-radius:12px; padding:20px 32px; display:inline-block;">
            <div style="font-family:monospace; font-size:2.8rem; font-weight:900;
                        letter-spacing:0.3em; color:var(--c-text); user-select:all;">
                <?= htmlspecialchars($dispToken['codigo']) ?>
            </div>
            <div style="font-size:.75rem; color:var(--c-text-muted); margin-top:4px;">
                Para: <?= htmlspecialchars($dispToken['nombre']) ?> &nbsp;·&nbsp;
                Válido hasta: <?= date('d/m/Y H:i', strtotime($dispToken['expira'])) ?>
            </div>
        </div>
    </div>
    <div style="font-size:.82rem; color:var(--c-text-muted);">
        <i class="bi bi-info-circle"></i>
        El técnico abre el APK → pantalla de enrolamiento → ingresa este código.
        El código expira en 24 horas y es de uso único. No se volverá a mostrar.
    </div>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Formulario: nuevo dispositivo ──────────────────────────────────── -->
    <div class="col-lg-4">
        <div class="d-card">
            <div class="d-card-header"><i class="bi bi-phone-fill"></i> Nuevo Dispositivo</div>
            <div class="d-card-body">
                <?php if (!$dbOk): ?>
                    <div class="d-alert danger"><i class="bi bi-database-x"></i> Sin conexión a base de datos.</div>
                <?php else: ?>
                <form method="POST" action="?module=dispositivos">
                    <input type="hidden" name="action" value="generar_token">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nombre del dispositivo</label>
                        <input type="text" class="form-control" name="nombre"
                               placeholder="Ej: POS Farmacia Central"
                               maxlength="120" required>
                        <div class="form-text">Nombre descriptivo para identificar la máquina.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Sucursal</label>
                        <select class="form-select" name="sucursal_id" required>
                            <option value="">— Seleccionar —</option>
                            <?php foreach ($sucursales as $s): ?>
                                <option value="<?= htmlspecialchars($s['id']) ?>">
                                    <?= htmlspecialchars($s['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tipo de dispositivo</label>
                        <select class="form-select" name="tipo">
                            <option value="POS_ANDROID">POS Android (WangPos / Wisenet5)</option>
                            <option value="APK_MOVIL">APK Móvil (smartphone)</option>
                            <option value="POS_WEB">POS Web (MyPOS en la nube)</option>
                            <option value="OTRO">Otro</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-key-fill"></i> Generar Código de Activación
                    </button>
                </form>

                <hr style="margin:20px 0">
                <div style="font-size:.78rem; color:var(--c-text-muted);">
                    <i class="bi bi-info-circle"></i>
                    <strong>Flujo de enrolamiento:</strong><br>
                    1. Genera el código aquí (válido 24 h).<br>
                    2. El técnico instala el APK y abre la app.<br>
                    3. Ingresa el código → el dispositivo recibe su API key automáticamente.<br>
                    4. Listo: el POS ya puede emitir boletas.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Tabla de dispositivos ─────────────────────────────────────────── -->
    <div class="col-lg-8">
        <div class="d-card">
            <div class="d-card-header">
                <i class="bi bi-grid-3x2-gap-fill"></i>
                Dispositivos registrados
                <?php if ($dispositivos): ?>
                    <span class="d-badge info ms-2"><?= count($dispositivos) ?></span>
                <?php endif; ?>
            </div>
            <div class="d-card-body p-0">
                <?php if (empty($dispositivos)): ?>
                    <div style="padding:32px; text-align:center; color:var(--c-text-muted);">
                        <i class="bi bi-phone" style="font-size:2rem; opacity:.4"></i><br>
                        <span style="font-size:.88rem; margin-top:8px; display:block;">
                            No hay dispositivos registrados aún.<br>
                            Genera el primer código de activación con el formulario a la izquierda.
                        </span>
                    </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="d-table">
                        <thead>
                            <tr>
                                <th>Nombre / Sucursal</th>
                                <th>Tipo</th>
                                <th>Estado</th>
                                <th>Token</th>
                                <th>Dispositivo</th>
                                <th>Último contacto</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dispositivos as $d): ?>
                            <tr style="<?= !$d['activo'] ? 'opacity:.45' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($d['nombre']) ?></strong><br>
                                    <small style="color:var(--c-text-muted)">
                                        <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($d['sucursal_nombre']) ?>
                                    </small>
                                </td>
                                <td>
                                    <?php
                                    $iconoTipo = [
                                        'POS_ANDROID' => 'bi-phone',
                                        'APK_MOVIL'   => 'bi-phone-landscape',
                                        'POS_WEB'     => 'bi-globe',
                                        'OTRO'        => 'bi-box',
                                    ][$d['tipo']] ?? 'bi-box';
                                    ?>
                                    <i class="bi <?= $iconoTipo ?>"></i>
                                    <?= htmlspecialchars($d['tipo']) ?>
                                </td>
                                <td><?= dispEstado($d) ?></td>
                                <td style="font-family:monospace; font-size:.85rem;">
                                    <?php if (!$d['token_usado']): ?>
                                        <?php if (strtotime($d['token_expira']) > time()): ?>
                                            <span style="background:#e8f5e9; color:#2e7d32; padding:2px 8px; border-radius:4px; font-weight:700;">
                                                <?= htmlspecialchars($d['token_activacion']) ?>
                                            </span>
                                            <br><small style="color:var(--c-text-muted)">
                                                Expira <?= date('d/m H:i', strtotime($d['token_expira'])) ?>
                                            </small>
                                        <?php else: ?>
                                            <span style="color:var(--c-text-muted)">
                                                <?= htmlspecialchars($d['token_activacion']) ?>
                                            </span>
                                            <br><small style="color:#e53e3e">Expirado</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:var(--c-text-muted)">●●●●●●</span>
                                        <br><small style="color:#2e7d32">
                                            <i class="bi bi-check-circle"></i>
                                            <?= $d['enrolado_en'] ? date('d/m/Y', strtotime($d['enrolado_en'])) : 'usado' ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:.78rem;">
                                    <?php if ($d['device_modelo']): ?>
                                        <i class="bi bi-phone"></i> <?= htmlspecialchars($d['device_modelo']) ?><br>
                                    <?php endif; ?>
                                    <?php if ($d['device_version_app']): ?>
                                        <span style="color:var(--c-text-muted)">v<?= htmlspecialchars($d['device_version_app']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!$d['device_modelo'] && !$d['device_version_app']): ?>
                                        <span style="color:var(--c-text-muted)">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:.78rem; white-space:nowrap;">
                                    <?= dispUltimoContacto($d['ultimo_contacto']) ?>
                                </td>
                                <td>
                                    <?php if ($d['activo']): ?>
                                    <form method="POST" action="?module=dispositivos"
                                          onsubmit="return confirm('¿Desactivar «<?= htmlspecialchars(addslashes($d['nombre'])) ?>»?\nSu API key quedará revocada.')">
                                        <input type="hidden" name="action" value="desactivar">
                                        <input type="hidden" name="dispositivo_id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<div class="row g-4 mt-1">
    <div class="col-12">
        <div class="d-card">
            <div class="d-card-header"><i class="bi bi-book"></i> Guía rápida de enrolamiento</div>
            <div class="d-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div style="background:var(--c-bg); border-radius:10px; padding:16px; height:100%;">
                            <div style="font-weight:700; margin-bottom:8px;">
                                <span style="background:var(--c-primary); color:#fff; border-radius:50%; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; font-size:.8rem; margin-right:8px;">1</span>
                                Admin genera el código
                            </div>
                            <p style="font-size:.82rem; color:var(--c-text-muted); margin:0;">
                                Completa el formulario con el nombre del POS y la sucursal.
                                Obtienes un código de 6 letras (ej: <strong>A3F7K2</strong>) válido 24 h.
                            </p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="background:var(--c-bg); border-radius:10px; padding:16px; height:100%;">
                            <div style="font-weight:700; margin-bottom:8px;">
                                <span style="background:var(--c-primary); color:#fff; border-radius:50%; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; font-size:.8rem; margin-right:8px;">2</span>
                                Técnico instala el APK
                            </div>
                            <p style="font-size:.82rem; color:var(--c-text-muted); margin:0;">
                                Instala el APK en la máquina POS. Al abrir, la app muestra
                                la pantalla de enrolamiento. El técnico ingresa la URL del servidor
                                y el código de 6 letras.
                            </p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="background:var(--c-bg); border-radius:10px; padding:16px; height:100%;">
                            <div style="font-weight:700; margin-bottom:8px;">
                                <span style="background:var(--c-primary); color:#fff; border-radius:50%; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; font-size:.8rem; margin-right:8px;">3</span>
                                Listo — el POS opera
                            </div>
                            <p style="font-size:.82rem; color:var(--c-text-muted); margin:0;">
                                El servidor valida el código, genera una API key permanente y la
                                envía al APK. El dispositivo descarga sus CAFs y queda listo para
                                emitir boletas. El código ya no puede reutilizarse.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
