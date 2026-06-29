<?php
/**
 * Módulo: SaaS
 * Permite gestionar suscripción y features de la empresa activa
 */
if (!defined('DTE_API_BOOTSTRAP_ONLY')) exit;

use App\Repositories\SaaSRepository;

$empresaId = $_SESSION['active_empresa_id'] ?? 0;
if (!$empresaId) {
    echo '<div class="d-alert warning">Seleccione una empresa primero.</div>';
    return;
}

$repo = new SaaSRepository();
$msg = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if ($_POST['accion'] === 'guardar_suscripcion') {
        $repo->actualizarSuscripcion($empresaId, [
            'plan' => $_POST['plan'],
            'cuota_mensual' => $_POST['cuota_mensual'],
            'dia_corte' => $_POST['dia_corte'],
            'estado_pago' => $_POST['estado_pago']
        ]);
        $msg = '<div class="d-alert success">Suscripción actualizada.</div>';
    } elseif ($_POST['accion'] === 'toggle_feature') {
        $repo->setFeatureToggle($empresaId, $_POST['feature'], $_POST['valor']);
        $msg = '<div class="d-alert success">Feature actualizado.</div>';
    }
}

$suscripcion = $repo->getSuscripcion($empresaId) ?: ['plan'=>'basico', 'cuota_mensual'=>0, 'dia_corte'=>5, 'estado_pago'=>'al_dia'];
$toggles = $repo->getFeatureToggles($empresaId);

$features_disponibles = [
    'pos_offline' => 'Modo Offline POS',
    'compras_ia' => 'Compras asistidas por IA',
    'multisucursal_extra' => '+10 Sucursales Extra'
];
?>

<?= $msg ?>

<div class="row">
    <!-- Suscripcion -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-credit-card"></i> Suscripción y Cobranza</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="accion" value="guardar_suscripcion">
                    <div class="mb-3">
                        <label>Plan Contratado</label>
                        <select name="plan" class="form-select">
                            <option value="basico" <?= $suscripcion['plan'] === 'basico' ? 'selected' : '' ?>>Plan Básico</option>
                            <option value="comercial" <?= $suscripcion['plan'] === 'comercial' ? 'selected' : '' ?>>Plan Comercial</option>
                            <option value="empresa" <?= $suscripcion['plan'] === 'empresa' ? 'selected' : '' ?>>Plan Empresa</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label>Cuota Mensual ($)</label>
                            <input type="number" name="cuota_mensual" class="form-control" value="<?= htmlspecialchars((string)$suscripcion['cuota_mensual']) ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label>Día de Corte</label>
                            <input type="number" name="dia_corte" class="form-control" min="1" max="31" value="<?= htmlspecialchars((string)$suscripcion['dia_corte']) ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Estado de Pago</label>
                        <select name="estado_pago" class="form-select <?= $suscripcion['estado_pago']==='moroso'?'is-invalid':($suscripcion['estado_pago']==='suspendido'?'bg-danger text-white':'') ?>">
                            <option value="al_dia" <?= $suscripcion['estado_pago'] === 'al_dia' ? 'selected' : '' ?>>🟢 Al Día</option>
                            <option value="moroso" <?= $suscripcion['estado_pago'] === 'moroso' ? 'selected' : '' ?>>🟡 Moroso</option>
                            <option value="suspendido" <?= $suscripcion['estado_pago'] === 'suspendido' ? 'selected' : '' ?>>🔴 Suspendido</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Guardar Suscripción</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Feature Toggles -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-sliders"></i> Features Extra (Overrides)</div>
            <div class="card-body">
                <p class="text-muted small">Habilita funciones específicas para este cliente sin importar su plan base.</p>
                <table class="table">
                    <tbody>
                        <?php foreach($features_disponibles as $key => $label): 
                            $activo = isset($toggles[$key]) && $toggles[$key] === '1';
                        ?>
                        <tr>
                            <td><?= $label ?></td>
                            <td class="text-end">
                                <form method="post">
                                    <input type="hidden" name="accion" value="toggle_feature">
                                    <input type="hidden" name="feature" value="<?= $key ?>">
                                    <input type="hidden" name="valor" value="<?= $activo ? '0' : '1' ?>">
                                    <?php if($activo): ?>
                                        <button type="submit" class="btn btn-sm btn-success">Activado</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Desactivado</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
