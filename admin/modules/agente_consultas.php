<?php
/**
 * Consultas IA no resueltas.
 *
 * Lee y edita el archivo de aprendizaje generado por el microservicio agent/.
 */

$agentLogPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'agent'
    . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'agent_unanswered.txt';
$agentLogDir = dirname($agentLogPath);
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_agente_consultas') {
    $contenido = (string)($_POST['contenido'] ?? '');
    $contenido = str_replace(["\r\n", "\r"], "\n", $contenido);

    if (!is_dir($agentLogDir) && !@mkdir($agentLogDir, 0755, true) && !is_dir($agentLogDir)) {
        $error = 'No se pudo crear el directorio del log del agente.';
    } elseif (@file_put_contents($agentLogPath, $contenido, LOCK_EX) === false) {
        $error = 'No se pudo guardar el archivo. Revise permisos de escritura en agent/tmp.';
    } else {
        $msg = 'Archivo guardado correctamente.';
    }
}

$contenidoActual = '';
if (is_file($agentLogPath)) {
    $contenidoActual = (string)@file_get_contents($agentLogPath);
}

$fileSize = is_file($agentLogPath) ? (int)filesize($agentLogPath) : 0;
$updatedAt = is_file($agentLogPath) ? date('Y-m-d H:i:s', (int)filemtime($agentLogPath)) : 'Aun no creado';
?>

<div class="d-card">
    <div class="d-card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div><i class="bi bi-chat-left-text"></i> Consultas IA no resueltas</div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="d-badge info"><?= number_format($fileSize) ?> bytes</span>
            <span class="d-badge secondary"><?= htmlspecialchars($updatedAt) ?></span>
        </div>
    </div>
    <div class="d-card-body">
        <?php if ($msg): ?>
            <div class="d-alert success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="d-alert danger"><i class="bi bi-x-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="d-alert info">
            <i class="bi bi-info-circle"></i>
            Aqui se registran consultas donde el asistente devolvio una respuesta de fallback, error o no disponibilidad. Ruta:
            <code><?= htmlspecialchars($agentLogPath) ?></code>
        </div>

        <form method="POST" action="dashboard.php?module=agente_consultas">
            <input type="hidden" name="accion" value="guardar_agente_consultas">
            <textarea
                name="contenido"
                class="d-input"
                spellcheck="false"
                style="width:100%; min-height:62vh; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:.82rem; line-height:1.45; white-space:pre;"
            ><?= htmlspecialchars($contenidoActual) ?></textarea>

            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mt-3">
                <div class="text-muted" style="font-size:.82rem;">
                    El guardado reemplaza el contenido completo del archivo.
                </div>
                <button type="submit" class="d-btn d-btn-primary">
                    <i class="bi bi-save"></i> Guardar archivo
                </button>
            </div>
        </form>
    </div>
</div>
