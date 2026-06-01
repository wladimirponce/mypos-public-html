<?php
/**
 * Módulo: Sucursales
 * Permite gestionar las sucursales de la empresa activa
 */
if (!defined('DTE_API_BOOTSTRAP_ONLY')) exit;

$empresaId = $_SESSION['active_empresa_id'] ?? 0;
if (!$empresaId) {
    echo '<div class="d-alert warning">Seleccione una empresa primero.</div>';
    return;
}

$db = \App\Core\Database::getInstance();
$msg = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if ($_POST['accion'] === 'crear') {
        try {
            $stmt = $db->prepare("INSERT INTO saas_sucursal (empresa_id, nombre, direccion, codigo_sii) VALUES (?, ?, ?, ?)");
            $stmt->execute([$empresaId, trim($_POST['nombre']), trim($_POST['direccion']), trim($_POST['codigo_sii'])]);
            $msg = '<div class="d-alert success">Sucursal creada exitosamente.</div>';
        } catch (Exception $e) {
            $msg = '<div class="d-alert danger">Error: ' . $e->getMessage() . '</div>';
        }
    } elseif ($_POST['accion'] === 'desactivar') {
        $stmt = $db->prepare("UPDATE saas_sucursal SET activa = 0 WHERE id = ? AND empresa_id = ?");
        $stmt->execute([(int)$_POST['id'], $empresaId]);
        $msg = '<div class="d-alert success">Sucursal desactivada.</div>';
    }
}

// Obtener lista
$stmt = $db->prepare("SELECT * FROM saas_sucursal WHERE empresa_id = ? ORDER BY creado_en DESC");
$stmt->execute([$empresaId]);
$sucursales = $stmt->fetchAll();
?>

<?= $msg ?>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Sucursales de la Empresa</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Dirección</th>
                            <th>Código SII</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($sucursales)): ?>
                            <tr><td colspan="6" class="text-center text-muted">No hay sucursales registradas</td></tr>
                        <?php else: ?>
                            <?php foreach($sucursales as $s): ?>
                            <tr>
                                <td><?= $s['id'] ?></td>
                                <td><strong><?= htmlspecialchars($s['nombre']) ?></strong></td>
                                <td><?= htmlspecialchars($s['direccion']) ?></td>
                                <td><?= htmlspecialchars($s['codigo_sii']) ?: '<span class="text-muted">N/A</span>' ?></td>
                                <td>
                                    <?php if($s['activa']): ?>
                                        <span class="badge bg-success">Activa</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($s['activa']): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('¿Desactivar esta sucursal?');">
                                        <input type="hidden" name="accion" value="desactivar">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i></button>
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
            <div class="card-header">Nueva Sucursal</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="accion" value="crear">
                    <div class="mb-3">
                        <label>Nombre Interno</label>
                        <input type="text" name="nombre" class="form-control" placeholder="Ej: Sucursal Centro" required>
                    </div>
                    <div class="mb-3">
                        <label>Dirección Física</label>
                        <input type="text" name="direccion" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Código SII (Opcional)</label>
                        <input type="text" name="codigo_sii" class="form-control" placeholder="Ej: 1">
                        <small class="text-muted">El código otorgado por el SII para esta sucursal.</small>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Crear Sucursal</button>
                </form>
            </div>
        </div>
    </div>
</div>
