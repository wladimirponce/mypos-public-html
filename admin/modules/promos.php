<?php
/**
 * Módulo Promos — Links de precio especial para el registro.
 *
 * Nivel plataforma (no depende de la empresa activa). El operador crea un link
 * con un precio mensual custom; se lo envía a un prospecto, que se registra en
 * https://www.mypos.cl/register?plan=<plan>&promo=<codigo> y paga ese precio
 * de forma recurrente (hasta cambiarlo). Puede limitarse por cantidad de usos
 * y por fecha de expiración.
 *
 * Comparte la BD con el backend web: escribe en `suscripcion_promo_links` (la
 * misma tabla que asegura la migración 077 y que el web lee al registrar/cobrar).
 */

if (!isset($db) || !$dbOk) {
    echo '<div class="d-alert danger"><i class="bi bi-database-x"></i> Sin conexión a la base de datos.</div>';
    return;
}

// URL pública del sitio (donde vive /register). Ajustar si cambia el dominio.
$PUBLIC_URL = 'https://www.mypos.cl';

// Planes y su precio normal (con IVA) — debe coincidir con PlanCatalog del web.
$PLANES = [
    'mypos-start'  => ['nombre' => 'MyPOS Start',  'normal' => 23788],
    'mypos-pro'    => ['nombre' => 'MyPOS Pro',    'normal' => 30928],
    'mypos-cadena' => ['nombre' => 'MyPOS Cadena', 'normal' => 35688],
    'mypos-escala' => ['nombre' => 'MyPOS Escala', 'normal' => 47588],
];

// El panel no modifica el esquema en tiempo de ejecucion. Esto evita que una
// pantalla administrativa cree tablas incompletas o diferentes al backend.
$schemaReady = false;
try {
    $schemaCheck = $db->query(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'suscripcion_promo_links'
           AND COLUMN_NAME = 'max_usos'"
    );
    $schemaReady = (int) $schemaCheck->fetchColumn() === 1;
} catch (Throwable $e) {
    error_log('[Promos] no se pudo validar el esquema: ' . $e->getMessage());
}

if (!$schemaReady) {
    echo '<div class="d-alert danger"><i class="bi bi-database-exclamation"></i> '
        . 'Falta aplicar la migracion <strong>077_auth_public_tokens_hardening</strong>. '
        . 'El modulo queda bloqueado para evitar datos inconsistentes.</div>';
    return;
}

$msg = '';
$err = '';

// ——— Acciones ————————————————————————————————————————————————————————————————
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];

    if ($accion === 'crear') {
        $planId = $_POST['plan_id'] ?? '';
        $precio = (int) ($_POST['precio_clp'] ?? 0);
        $desc   = trim($_POST['descripcion'] ?? '');
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
        $expira = trim($_POST['fecha_expiracion'] ?? '');
        $maxUsosRaw = trim((string) ($_POST['max_usos'] ?? ''));
        $maxUsos = $maxUsosRaw === '' ? null : (int) $maxUsosRaw;

        if (!isset($PLANES[$planId])) {
            $err = 'Plan no válido.';
        } elseif ($precio <= 0) {
            $err = 'El precio especial debe ser mayor a 0.';
        } elseif ($codigo !== '' && !preg_match('/^[A-Z0-9\-]{3,60}$/', $codigo)) {
            $err = 'El código solo admite letras, números y guiones (3–60).';
        } elseif ($maxUsos !== null && $maxUsos <= 0) {
            $err = 'El limite de usos debe ser mayor a 0.';
        } else {
            if ($codigo === '') {
                do {
                    $codigo = strtoupper(bin2hex(random_bytes(4)));
                    $chk = $db->prepare('SELECT 1 FROM suscripcion_promo_links WHERE codigo = ?');
                    $chk->execute([$codigo]);
                } while ($chk->fetchColumn());
            }
            try {
                $stmt = $db->prepare(
                    'INSERT INTO suscripcion_promo_links
                        (codigo, descripcion, plan_id, precio_clp, moneda, activo, fecha_expiracion, max_usos)
                     VALUES (?, ?, ?, ?, "CLP", 1, ?, ?)'
                );
                $stmt->execute([
                    $codigo,
                    $desc !== '' ? $desc : null,
                    $planId,
                    $precio,
                    $expira !== '' ? $expira : null,
                    $maxUsos,
                ]);
                $msg = "Link creado: código <strong>{$codigo}</strong>.";
            } catch (PDOException $e) {
                $err = ((int) $e->getCode() === 23000)
                    ? 'Ya existe un link con ese código.'
                    : 'No se pudo crear el link.';
            }
        }
    } elseif ($accion === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $activo = ($_POST['activo'] ?? '0') === '1' ? 1 : 0;
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE suscripcion_promo_links SET activo = ? WHERE id = ?');
            $stmt->execute([$activo, $id]);
            $msg = 'Estado del link actualizado.';
        }
    }
}

// ——— Listado ————————————————————————————————————————————————————————————————
$links = $db->query(
    'SELECT id, codigo, descripcion, plan_id, precio_clp, moneda, activo,
            fecha_expiracion, usos, max_usos, creado_el
     FROM suscripcion_promo_links
     ORDER BY creado_el DESC'
)
            ->fetchAll(PDO::FETCH_ASSOC);

$fmt = static fn (int $n): string => '$' . number_format($n, 0, ',', '.');
?>

<?php if ($msg): ?><div class="d-alert success"><i class="bi bi-check-circle"></i> <?= $msg ?></div><?php endif; ?>
<?php if ($err): ?><div class="d-alert danger"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="d-alert info">
    <i class="bi bi-info-circle"></i>
    Crea un link con precio mensual especial y envíaselo a un prospecto. Al registrarse con él,
    paga ese precio cada mes (hasta que lo cambies). Puedes limitar sus usos o dejarlo sin limite.
</div>

<!-- Crear -->
<form method="POST" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:18px;">
    <input type="hidden" name="accion" value="crear">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
        <label>Plan
            <select name="plan_id" class="form-control" required>
                <?php foreach ($PLANES as $id => $p): ?>
                    <option value="<?= $id ?>"><?= htmlspecialchars($p['nombre']) ?> (normal <?= $fmt($p['normal']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Precio especial (CLP con IVA) / mes
            <input type="number" name="precio_clp" min="1" step="1" class="form-control" placeholder="ej. 9990" required>
        </label>
        <label>Código (opcional)
            <input type="text" name="codigo" class="form-control" placeholder="autogenerado" maxlength="60">
        </label>
        <label>Descripción (opcional)
            <input type="text" name="descripcion" class="form-control" placeholder="ej. Feria PYME 2026" maxlength="180">
        </label>
        <label>Expira (opcional)
            <input type="date" name="fecha_expiracion" class="form-control">
        </label>
        <label>Maximo de usos (opcional)
            <input type="number" name="max_usos" min="1" step="1" class="form-control" placeholder="sin limite">
        </label>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-top:12px;">
        <i class="bi bi-plus-lg"></i> Crear link
    </button>
</form>

<!-- Listado -->
<div style="overflow-x:auto;background:#fff;border:1px solid #e2e8f0;border-radius:10px;">
    <table class="table" style="width:100%;margin:0;">
        <thead>
            <tr>
                <th>Código</th><th>Plan</th><th style="text-align:right;">Precio especial</th>
                <th style="text-align:right;">Usos</th><th>Expira</th><th>Activo</th><th>Link</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$links): ?>
            <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:18px;">Aún no hay links. Crea el primero arriba.</td></tr>
        <?php endif; ?>
        <?php foreach ($links as $l):
            $planNombre = $PLANES[$l['plan_id']]['nombre'] ?? $l['plan_id'];
            $normal     = $PLANES[$l['plan_id']]['normal'] ?? 0;
            $url = $PUBLIC_URL . '/register?plan=' . rawurlencode($l['plan_id']) . '&promo=' . rawurlencode($l['codigo']);
        ?>
            <tr style="<?= (int) $l['activo'] === 1 ? '' : 'opacity:.5;' ?>">
                <td><strong><?= htmlspecialchars($l['codigo']) ?></strong></td>
                <td><?= htmlspecialchars($planNombre) ?></td>
                <td style="text-align:right;">
                    <strong><?= $fmt((int) $l['precio_clp']) ?></strong>
                    <?php if ($normal > (int) $l['precio_clp']): ?>
                        <br><small style="color:#94a3b8;text-decoration:line-through;"><?= $fmt($normal) ?></small>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;">
                    <?= (int) $l['usos'] ?> / <?= $l['max_usos'] !== null ? (int) $l['max_usos'] : '∞' ?>
                </td>
                <td><?= $l['fecha_expiracion'] ? htmlspecialchars($l['fecha_expiracion']) : '—' ?></td>
                <td>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="accion" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                        <input type="hidden" name="activo" value="<?= (int) $l['activo'] === 1 ? '0' : '1' ?>">
                        <button type="submit" class="btn btn-sm <?= (int) $l['activo'] === 1 ? 'btn-success' : 'btn-secondary' ?>">
                            <?= (int) $l['activo'] === 1 ? 'Activo' : 'Inactivo' ?>
                        </button>
                    </form>
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            onclick="navigator.clipboard.writeText('<?= htmlspecialchars($url, ENT_QUOTES) ?>').then(()=>{this.innerHTML='<i class=&quot;bi bi-check&quot;></i> Copiado'})"
                            title="<?= htmlspecialchars($url, ENT_QUOTES) ?>">
                        <i class="bi bi-clipboard"></i> Copiar link
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
