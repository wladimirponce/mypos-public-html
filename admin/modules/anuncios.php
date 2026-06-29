<?php
/**
 * Módulo: Anuncios (Broadcast)
 * Permite enviar mensajes globales a todas las instancias de MyPOS
 */
if (!defined('DTE_API_BOOTSTRAP_ONLY')) exit;

$db = \App\Core\Database::getInstance();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if ($_POST['accion'] === 'crear') {
        try {
            $stmt = $db->prepare("INSERT INTO saas_broadcasts (titulo, mensaje, tipo, fecha_inicio, fecha_fin) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                trim($_POST['titulo']),
                trim($_POST['mensaje']),
                $_POST['tipo'],
                $_POST['fecha_inicio'],
                $_POST['fecha_fin']
            ]);
            $msg = '<div class="d-alert success">Anuncio creado exitosamente y ahora visible en MyPOS.</div>';
        } catch (Exception $e) {
            $msg = '<div class="d-alert danger">Error: ' . $e->getMessage() . '</div>';
        }
    } elseif ($_POST['accion'] === 'desactivar') {
        $stmt = $db->prepare("UPDATE saas_broadcasts SET activo = 0 WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
        $msg = '<div class="d-alert success">Anuncio desactivado.</div>';
    }
}

$stmt = $db->query("SELECT * FROM saas_broadcasts ORDER BY creado_en DESC");
$anuncios = $stmt->fetchAll();
?>

<?= $msg ?>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-broadcast"></i> Anuncios Globales Activos e Históricos</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Título</th>
                            <th>Tipo</th>
                            <th>Vigencia</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($anuncios)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No hay anuncios registrados</td></tr>
                        <?php else: ?>
                            <?php foreach($anuncios as $a): 
                                $isVigente = $a['activo'] && strtotime($a['fecha_inicio']) <= time() && strtotime($a['fecha_fin']) >= time();
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($a['titulo']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($a['mensaje']) ?></small>
                                </td>
                                <td><span class="badge bg-<?= $a['tipo'] === 'info' ? 'info' : ($a['tipo'] === 'warning' ? 'warning text-dark' : 'success') ?>"><?= strtoupper($a['tipo']) ?></span></td>
                                <td style="font-size: 0.8rem">
                                    <?= date('d/m H:i', strtotime($a['fecha_inicio'])) ?> a<br>
                                    <?= date('d/m H:i', strtotime($a['fecha_fin'])) ?>
                                </td>
                                <td>
                                    <?php if($isVigente): ?>
                                        <span class="badge bg-success shadow-sm" style="animation: pulse 2s infinite;"><i class="bi bi-record-circle"></i> Transmitiendo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactivo / Expirado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($a['activo']): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('¿Detener este anuncio?');">
                                        <input type="hidden" name="accion" value="desactivar">
                                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-stop-circle"></i> Parar</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-megaphone"></i> Enviar Nuevo Anuncio</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="accion" value="crear">
                    <div class="mb-3">
                        <label>Título (Corto)</label>
                        <input type="text" name="titulo" class="form-control" placeholder="Ej: Mantenimiento Programado" required>
                    </div>
                    <div class="mb-3">
                        <label>Mensaje Detallado</label>
                        <textarea name="mensaje" class="form-control" rows="3" placeholder="Información para todos los usuarios..." required></textarea>
                    </div>
                    <div class="mb-3">
                        <label>Tipo (Color)</label>
                        <select name="tipo" class="form-select">
                            <option value="info">Azul (Información / Novedad)</option>
                            <option value="warning">Amarillo (Advertencia / Corte)</option>
                            <option value="success">Verde (Éxito / Mejora)</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label>Inicio Vigencia</label>
                            <input type="datetime-local" name="fecha_inicio" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label>Fin Vigencia</label>
                            <input type="datetime-local" name="fecha_fin" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime('+1 day')) ?>" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send"></i> Emitir a todo MyPOS</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes pulse {
    0% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.05); opacity: 0.8; }
    100% { transform: scale(1); opacity: 1; }
}
</style>
