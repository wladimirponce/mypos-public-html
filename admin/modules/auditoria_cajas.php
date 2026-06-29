<?php
/**
 * Módulo: Auditoría de Cajas (Shadow Mode)
 * Permite a los super-administradores ver el estado de las cajas POS en tiempo real
 */
if (!defined('DTE_API_BOOTSTRAP_ONLY')) exit;

$empresaId = $_SESSION['active_empresa_id'] ?? 0;
if (!$empresaId) {
    echo '<div class="d-alert warning">Seleccione una empresa primero.</div>';
    return;
}

// Simularemos datos en tiempo real para la empresa
// En producción, esto consultaría a la DB sincronizada de cajas o mediante websockets al POS
$cajasMock = [
    ['id' => 1, 'sucursal' => 'Casa Matriz', 'cajero' => 'Juan Pérez', 'estado' => 'abierta', 'apertura' => date('Y-m-d 08:30:00'), 'ventas' => 45, 'total_efectivo' => 125000, 'total_tarjeta' => 450000],
    ['id' => 2, 'sucursal' => 'Casa Matriz', 'cajero' => 'María López', 'estado' => 'cerrada', 'apertura' => date('Y-m-d 09:00:00', strtotime('-1 day')), 'ventas' => 120, 'total_efectivo' => 340000, 'total_tarjeta' => 890000],
    ['id' => 3, 'sucursal' => 'Sucursal Norte', 'cajero' => 'Carlos Díaz', 'estado' => 'abierta', 'apertura' => date('Y-m-d 14:15:00'), 'ventas' => 12, 'total_efectivo' => 45000, 'total_tarjeta' => 12000],
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0"><i class="bi bi-eye text-primary"></i> Auditoría Remota de Cajas (Shadow Mode)</h4>
        <p class="text-muted small mb-0">Monitorea en tiempo real las operaciones de las cajas registradoras sin interrumpir a los cajeros.</p>
    </div>
    <button class="btn btn-outline-primary btn-sm" onclick="location.reload()">
        <i class="bi bi-arrow-clockwise"></i> Actualizar Ahora
    </button>
</div>

<div class="row g-4">
    <?php foreach($cajasMock as $c): 
        $isOpen = $c['estado'] === 'abierta';
        $total = $c['total_efectivo'] + $c['total_tarjeta'];
    ?>
    <div class="col-md-4">
        <div class="card h-100 border-<?= $isOpen ? 'success' : 'secondary' ?> shadow-sm">
            <div class="card-header bg-transparent border-bottom-0 pt-3 pb-0 d-flex justify-content-between align-items-center">
                <span class="badge bg-<?= $isOpen ? 'success' : 'secondary' ?> shadow-sm">
                    <?php if($isOpen): ?><i class="bi bi-record-circle" style="animation: pulse 2s infinite;"></i><?php endif; ?>
                    CAJA <?= $isOpen ? 'ABIERTA' : 'CERRADA' ?>
                </span>
                <small class="text-muted fw-bold">POS #<?= $c['id'] ?></small>
            </div>
            <div class="card-body">
                <h5 class="card-title mb-1"><?= htmlspecialchars($c['cajero']) ?></h5>
                <p class="card-text text-muted small mb-3"><i class="bi bi-shop"></i> <?= htmlspecialchars($c['sucursal']) ?></p>
                
                <div class="bg-light p-3 rounded mb-3 text-center border">
                    <div class="text-uppercase small text-muted fw-bold mb-1">Total Recaudado</div>
                    <h3 class="mb-0 text-dark">$<?= number_format($total, 0, ',', '.') ?></h3>
                </div>
                
                <div class="d-flex justify-content-between text-muted small mb-2">
                    <span><i class="bi bi-cash"></i> Efectivo:</span>
                    <strong class="text-dark">$<?= number_format($c['total_efectivo'], 0, ',', '.') ?></strong>
                </div>
                <div class="d-flex justify-content-between text-muted small mb-3">
                    <span><i class="bi bi-credit-card"></i> Tarjetas:</span>
                    <strong class="text-dark">$<?= number_format($c['total_tarjeta'], 0, ',', '.') ?></strong>
                </div>
                
                <div class="d-flex justify-content-between text-muted small border-top pt-2">
                    <span>Apertura: <?= date('H:i', strtotime($c['apertura'])) ?></span>
                    <span><i class="bi bi-receipt"></i> <?= $c['ventas'] ?> ventas</span>
                </div>
            </div>
            <?php if($isOpen): ?>
            <div class="card-footer bg-transparent border-top-0 pb-3">
                <button class="btn btn-sm btn-outline-danger w-100" onclick="alert('Se enviará comando de bloqueo a la caja #<?= $c['id'] ?>')">
                    <i class="bi bi-lock"></i> Bloquear Caja Remotamente
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<style>
@keyframes pulse {
    0% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.1); opacity: 0.7; }
    100% { transform: scale(1); opacity: 1; }
}
</style>
