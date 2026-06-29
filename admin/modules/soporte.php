<?php
/**
 * Módulo: Soporte
 * Permite gestionar los tickets de soporte de los clientes
 */
if (!defined('DTE_API_BOOTSTRAP_ONLY')) exit;

use App\Repositories\SoporteRepository;

$repo = new SoporteRepository();
$msg = '';

$empresaId = $_SESSION['active_empresa_id'] ?? 0;
// Soporte se puede ver a nivel global o por empresa. 
// Para el Command Center, a veces es mejor ver todos, pero dejémoslo filtrado por empresa si está seleccionada.
// Si quisiéramos ver todos: $tickets = $repo->getTickets(0);
$tickets = $repo->getTickets($empresaId);

// Procesar formulario de respuesta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'responder') {
    try {
        $repo->agregarMensaje((int)$_POST['ticket_id'], null, $_POST['mensaje']);
        $msg = '<div class="d-alert success">Respuesta enviada.</div>';
        // Recargar tickets
        $tickets = $repo->getTickets($empresaId);
    } catch (Exception $e) {
        $msg = '<div class="d-alert danger">Error: ' . $e->getMessage() . '</div>';
    }
}
?>

<?= $msg ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-headset"></i> Tickets de Soporte (<?= $empresaId ? 'Empresa Actual' : 'Todas las Empresas' ?>)</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Asunto</th>
                    <th>Empresa</th>
                    <th>Usuario</th>
                    <th>Estado</th>
                    <th>Prioridad</th>
                    <th>Fecha</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($tickets)): ?>
                    <tr><td colspan="8" class="text-center text-muted">No hay tickets activos</td></tr>
                <?php else: ?>
                    <?php foreach($tickets as $t): ?>
                    <tr>
                        <td>#<?= $t['id'] ?></td>
                        <td><strong><?= htmlspecialchars($t['asunto']) ?></strong></td>
                        <td><?= htmlspecialchars($t['empresa_nombre']) ?></td>
                        <td><?= htmlspecialchars($t['usuario_nombre'] ?? 'N/A') ?></td>
                        <td>
                            <?php 
                            $badge = match($t['estado']) {
                                'abierto' => 'bg-danger',
                                'en_progreso' => 'bg-warning text-dark',
                                'resuelto', 'cerrado' => 'bg-success',
                                default => 'bg-secondary'
                            };
                            ?>
                            <span class="badge <?= $badge ?>"><?= strtoupper($t['estado']) ?></span>
                        </td>
                        <td><?= ucfirst($t['prioridad']) ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($t['creado_en'])) ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="verTicket(<?= $t['id'] ?>)">Ver / Responder</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal estático simple para responder -->
<div id="ticketModal" style="display:none; position:fixed; top:10%; left:50%; transform:translate(-50%,0); width:600px; max-width:90%; background:#fff; z-index:1050; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.5); padding:20px;">
    <h5 id="modalTitle">Responder Ticket</h5>
    <div id="modalBody" style="max-height: 300px; overflow-y:auto; background:#f8f9fa; padding:10px; border-radius:4px; margin-bottom:15px; font-size:0.9rem;">
        <!-- Mensajes cargados por ajax/fetch -->
    </div>
    <form method="post">
        <input type="hidden" name="accion" value="responder">
        <input type="hidden" name="ticket_id" id="modalTicketId" value="">
        <div class="mb-3">
            <textarea name="mensaje" class="form-control" rows="3" placeholder="Escribe tu respuesta aquí..." required></textarea>
        </div>
        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('ticketModal').style.display='none'">Cerrar</button>
            <button type="submit" class="btn btn-primary">Enviar Respuesta</button>
        </div>
    </form>
</div>

<script>
async function verTicket(id) {
    document.getElementById('modalTicketId').value = id;
    document.getElementById('modalTitle').innerText = 'Cargando ticket #' + id + '...';
    document.getElementById('ticketModal').style.display = 'block';
    
    try {
        const res = await fetch(`api.php?action=soporte_ver_ticket&ticket_id=${id}`);
        const json = await res.json();
        if (json.ok && json.data) {
            document.getElementById('modalTitle').innerText = 'Ticket #' + id + ': ' + json.data.asunto;
            let html = '';
            for (let m of json.data.mensajes) {
                const isCentral = (m.usuario_id === null);
                html += `<div style="margin-bottom:10px; text-align: ${isCentral ? 'right' : 'left'}">
                    <strong style="color: ${isCentral ? 'blue' : 'black'}">${isCentral ? 'Soporte DTE' : (m.usuario_nombre || 'Cliente')}</strong>
                    <div style="background:${isCentral ? '#e3f2fd' : '#e2e3e5'}; display:inline-block; padding:8px 12px; border-radius:15px; margin-top:4px; max-width:85%; text-align:left;">
                        ${m.mensaje}
                    </div>
                </div>`;
            }
            document.getElementById('modalBody').innerHTML = html;
        }
    } catch (e) {
        document.getElementById('modalBody').innerHTML = '<span class="text-danger">Error al cargar mensajes.</span>';
    }
}
</script>
