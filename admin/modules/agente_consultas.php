<?php
/**
 * Consultas IA no resueltas.
 *
 * Mantiene un archivo .txt con JSON estructurado para revisar propuestas,
 * aprobarlas y crear skills seguras consumibles por el agente.
 */

use App\Services\AgentHttpClient;

$agentBasePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'agent';
$agentLogPath = $agentBasePath . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'agent_unanswered.txt';
$agentLogDir = dirname($agentLogPath);
$agentSkillsDir = $agentBasePath . DIRECTORY_SEPARATOR . 'skills';
$msg = '';
$error = '';

function acLoadEntries(string $path, ?string &$error = null): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = (string)@file_get_contents($path);
    if (trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [[
            'id' => 'legacy_' . bin2hex(random_bytes(6)),
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'status' => 'pendiente',
            'source' => 'legacy_txt',
            'consulta' => 'Contenido legacy importado desde texto libre',
            'respuesta' => $raw,
            'propuesta' => [
                'titulo' => 'Migrar registro legacy',
                'resoluble' => false,
                'tipo' => 'legacy_txt',
                'accion_sugerida' => 'revisar_manual',
                'intent_sugerido' => '',
                'tool_sugerida' => '',
                'confianza' => 'pendiente',
                'patterns_sugeridos' => [],
                'params_sugeridos' => new stdClass(),
                'notas' => 'Este registro venia del formato TXT anterior. Guarde el JSON para convertir el archivo.',
            ],
        ]];
    }
    if (array_is_list($data)) {
        return $data;
    }
    return [$data];
}

function acSaveEntries(string $path, array $entries): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function acFindIndex(array $entries, string $id): ?int
{
    foreach ($entries as $idx => $entry) {
        if ((string)($entry['id'] ?? '') === $id) {
            return $idx;
        }
    }
    return null;
}

function acSlug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string)$value, '_');
    return $value !== '' ? $value : 'skill';
}

$entries = acLoadEntries($agentLogPath, $loadError);
if (!empty($loadError)) {
    $error = $loadError;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'guardar_json') {
        $contenido = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['contenido'] ?? '')));
        $contenido = $contenido === '' ? '[]' : $contenido;
        $decoded = json_decode($contenido, true);
        if (!is_array($decoded)) {
            $error = 'No se guardo: el contenido no es JSON valido.';
        } else {
            $entries = array_is_list($decoded) ? $decoded : [$decoded];
            if (acSaveEntries($agentLogPath, $entries)) {
                $msg = 'JSON guardado correctamente.';
            } else {
                $error = 'No se pudo guardar el archivo. Revise permisos en agent/tmp.';
            }
        }
    }

    if ($accion === 'eliminar') {
        $id = (string)($_POST['id'] ?? '');
        $idx = acFindIndex($entries, $id);
        if ($idx === null) {
            $error = 'No se encontro la propuesta seleccionada.';
        } else {
            array_splice($entries, $idx, 1);
            if (acSaveEntries($agentLogPath, $entries)) {
                $msg = 'Propuesta eliminada.';
            } else {
                $error = 'No se pudo eliminar la propuesta. Revise permisos en agent/tmp.';
            }
        }
    }

    if ($accion === 'eliminar_descartadas') {
        $antes = count($entries);
        $entries = array_values(array_filter(
            $entries,
            static fn (array $e): bool => (string)($e['status'] ?? '') !== 'descartada'
        ));
        $eliminadas = $antes - count($entries);
        if (acSaveEntries($agentLogPath, $entries)) {
            $msg = "$eliminadas propuesta(s) descartada(s) eliminada(s).";
        } else {
            $error = 'No se pudo eliminar las propuestas descartadas.';
        }
    }

    if ($accion === 'responder_ia') {
        $id = (string)($_POST['id'] ?? '');
        $idx = acFindIndex($entries, $id);
        if ($idx === null) {
            $error = 'No se encontro la propuesta seleccionada.';
        } else {
            $consulta = (string)($entries[$idx]['consulta'] ?? '');
            if ($consulta === '') {
                $error = 'La entry no tiene una consulta original que enviar al LLM.';
            } else {
                $result = AgentHttpClient::postJson('/skills/answer-directly', ['consulta' => $consulta]);
                if (!$result['ok']) {
                    $error = 'No se pudo generar respuesta con IA: ' . $result['error'];
                } else {
                    $data = $result['data'];
                    $tipo = (string)($data['tipo'] ?? '');
                    $entries[$idx]['respuesta_ia'] = (string)($data['respuesta'] ?? '');
                    $entries[$idx]['respuesta_ia_tipo'] = $tipo;
                    $entries[$idx]['updated_at'] = date('c');
                    if ($tipo === 'respuesta_directa') {
                        $entries[$idx]['status'] = 'aprobada';
                    }
                    acSaveEntries($agentLogPath, $entries);
                    $msg = $tipo === 'requiere_datos'
                        ? 'La IA indica que esta pregunta necesita datos en vivo: usa "Generar SQL con IA" en vez de esto.'
                        : 'Respuesta generada con IA (ver detalle seleccionado).';
                }
            }
        }
    }

    if (in_array($accion, ['seleccionar', 'aprobar', 'descartar'], true)) {
        $id = (string)($_POST['id'] ?? '');
        $idx = acFindIndex($entries, $id);
        if ($idx === null) {
            $error = 'No se encontro la propuesta seleccionada.';
        } else {
            $status = [
                'seleccionar' => 'seleccionada',
                'aprobar' => 'aprobada',
                'descartar' => 'descartada',
            ][$accion];
            $entries[$idx]['status'] = $status;
            $entries[$idx]['updated_at'] = date('c');
            if (acSaveEntries($agentLogPath, $entries)) {
                $msg = "Propuesta marcada como $status.";
            } else {
                $error = 'No se pudo actualizar el estado.';
            }
        }
    }

    if ($accion === 'crear_skill') {
        $id = (string)($_POST['id'] ?? '');
        $idx = acFindIndex($entries, $id);
        if ($idx === null) {
            $error = 'No se encontro la propuesta seleccionada.';
        } else {
            $entry = $entries[$idx];
            $proposal = is_array($entry['propuesta'] ?? null) ? $entry['propuesta'] : [];
            $esSqlReadonly = trim((string)($proposal['sql_template_sugerido'] ?? '')) !== '';

            if (!is_dir($agentSkillsDir) && !@mkdir($agentSkillsDir, 0755, true) && !is_dir($agentSkillsDir)) {
                $error = 'No se pudo crear el directorio agent/skills.';
            } elseif ($esSqlReadonly) {
                // --- Skill tipo sql_readonly: requiere pasar el validador de whitelist ---
                require_once $agentBasePath . DIRECTORY_SEPARATOR . 'sql_whitelist_validator.php';

                $intent = acSlug((string)($proposal['intent_sugerido'] ?? ('consulta_' . substr($id, 0, 8))));
                $skillId = acSlug($intent . '_' . substr($id, 0, 8));
                $rowLimitRaw = $proposal['row_limit_sugerido'] ?? 50;
                $skill = [
                    'id' => $skillId,
                    'status' => 'aprobada',
                    'created_at' => date('c'),
                    'source_entry_id' => $id,
                    'schema_version' => 1,
                    'tipo' => 'sql_readonly',
                    'intent' => $intent,
                    'patterns' => $proposal['patterns_sugeridos'] ?? [(string)($entry['consulta'] ?? '')],
                    'sql_template' => trim((string)$proposal['sql_template_sugerido']),
                    'tablas_referenciadas' => $proposal['tablas_referenciadas_sugeridas'] ?? [],
                    'params_permitidos' => $proposal['params_sugeridos_sql'] ?? [],
                    'row_limit' => is_numeric($rowLimitRaw) ? (int)$rowLimitRaw : 50,
                    'notes' => $proposal['notas'] ?? '',
                ];

                $reason = null;
                if (!SqlWhitelistValidator::validateSkillEnvelope($skill, $reason)) {
                    $error = 'No se pudo crear la skill (falla de validacion de seguridad): ' . $reason;
                } else {
                    $skillPath = $agentSkillsDir . DIRECTORY_SEPARATOR . $skillId . '.json';
                    $json = json_encode($skill, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json === false || @file_put_contents($skillPath, $json . "\n", LOCK_EX) === false) {
                        $error = 'No se pudo crear el archivo de skill.';
                    } else {
                        $entries[$idx]['status'] = 'creada';
                        $entries[$idx]['skill_path'] = $skillPath;
                        $entries[$idx]['updated_at'] = date('c');
                        acSaveEntries($agentLogPath, $entries);
                        $msg = 'Skill sql_readonly creada (validada contra la whitelist): ' . basename($skillPath);
                    }
                }
            } else {
                // --- Skill tipo intent+tool (formato original, sin cambios) ---
                $intent = (string)($proposal['intent_sugerido'] ?? 'pendiente');
                $tool = (string)($proposal['tool_sugerida'] ?? '');
                if ($intent === '' || $tool === '') {
                    $error = 'La propuesta no tiene intent/tool suficientes para crear una skill segura.';
                } else {
                    $skillId = acSlug($intent . '_' . substr($id, 0, 8));
                    $skillPath = $agentSkillsDir . DIRECTORY_SEPARATOR . $skillId . '.json';
                    $skill = [
                        'id' => $skillId,
                        'status' => 'aprobada',
                        'created_at' => date('c'),
                        'source_entry_id' => $id,
                        'intent' => $intent,
                        'tool' => $tool,
                        'patterns' => $proposal['patterns_sugeridos'] ?? [(string)($entry['consulta'] ?? '')],
                        'params' => $proposal['params_sugeridos'] ?? [],
                        'notes' => $proposal['notas'] ?? '',
                    ];
                    $json = json_encode($skill, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json === false || @file_put_contents($skillPath, $json . "\n", LOCK_EX) === false) {
                        $error = 'No se pudo crear el archivo de skill.';
                    } else {
                        $entries[$idx]['status'] = 'creada';
                        $entries[$idx]['skill_path'] = $skillPath;
                        $entries[$idx]['updated_at'] = date('c');
                        acSaveEntries($agentLogPath, $entries);
                        $msg = 'Skill creada: ' . basename($skillPath);
                    }
                }
            }
        }
    }

    if ($accion === 'generar_sql_ia') {
        $id = (string)($_POST['id'] ?? '');
        $idx = acFindIndex($entries, $id);
        if ($idx === null) {
            $error = 'No se encontro la propuesta seleccionada.';
        } else {
            $consulta = (string)($entries[$idx]['consulta'] ?? '');
            if ($consulta === '') {
                $error = 'La entry no tiene una consulta original que enviar al LLM.';
            } else {
                $result = AgentHttpClient::postJson('/skills/propose-sql', ['consulta' => $consulta]);
                $proposal = is_array($entries[$idx]['propuesta'] ?? null) ? $entries[$idx]['propuesta'] : [];

                if (!$result['ok']) {
                    $error = 'No se pudo generar SQL con IA: ' . $result['error'];
                } elseif (($result['data']['resoluble'] ?? null) === false) {
                    $proposal['notas'] = trim(
                        ($proposal['notas'] ?? '') . "\n[IA] No resoluble via SQL: " .
                        (string)($result['data']['notes'] ?? 'sin detalle')
                    );
                    $entries[$idx]['propuesta'] = $proposal;
                    $entries[$idx]['updated_at'] = date('c');
                    acSaveEntries($agentLogPath, $entries);
                    $msg = 'El LLM indica que esta consulta no se puede resolver con las tablas permitidas (ver notas).';
                } else {
                    $data = $result['data'];
                    $proposal['tipo_sugerido'] = 'sql_readonly';
                    $proposal['sql_template_sugerido'] = (string)($data['sql_template'] ?? '');
                    $proposal['tablas_referenciadas_sugeridas'] = $data['tablas_referenciadas'] ?? [];
                    $proposal['params_sugeridos_sql'] = $data['params_permitidos'] ?? [];
                    $proposal['row_limit_sugerido'] = $data['row_limit'] ?? null;
                    if (!empty($data['patterns'])) {
                        $proposal['patterns_sugeridos'] = $data['patterns'];
                    }
                    $proposal['notas'] = trim(
                        ($proposal['notas'] ?? '') . "\n[IA] " . (string)($data['notes'] ?? '')
                    );
                    $entries[$idx]['propuesta'] = $proposal;
                    $entries[$idx]['status'] = 'seleccionada';
                    $entries[$idx]['updated_at'] = date('c');
                    acSaveEntries($agentLogPath, $entries);
                    $msg = 'Borrador de SQL generado. Revisa y edita antes de "Crear skill".';
                }
            }
        }
    }
}

$entries = acLoadEntries($agentLogPath, $reloadError);
if (!$error && !empty($reloadError)) {
    $error = $reloadError;
}

$selectedId = (string)($_GET['selected'] ?? '');
$selectedEntry = null;
if ($selectedId !== '') {
    $idx = acFindIndex($entries, $selectedId);
    $selectedEntry = $idx !== null ? $entries[$idx] : null;
}
if ($selectedEntry === null && !empty($entries)) {
    $selectedEntry = $entries[count($entries) - 1];
}

$contenidoActual = is_file($agentLogPath) ? (string)@file_get_contents($agentLogPath) : "[]\n";
$fileSize = is_file($agentLogPath) ? (int)filesize($agentLogPath) : 0;
$updatedAt = is_file($agentLogPath) ? date('Y-m-d H:i:s', (int)filemtime($agentLogPath)) : 'Aun no creado';
$counts = ['pendiente' => 0, 'seleccionada' => 0, 'aprobada' => 0, 'creada' => 0, 'descartada' => 0];
foreach ($entries as $entry) {
    $status = (string)($entry['status'] ?? 'pendiente');
    $counts[$status] = ($counts[$status] ?? 0) + 1;
}
?>

<div class="d-card">
    <div class="d-card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div><i class="bi bi-chat-left-text"></i> Consultas IA no resueltas</div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="d-badge warning"><?= (int)($counts['pendiente'] ?? 0) ?> pendientes</span>
            <span class="d-badge success"><?= (int)($counts['creada'] ?? 0) ?> creadas</span>
            <span class="d-badge info"><?= number_format($fileSize) ?> bytes</span>
            <span class="d-badge secondary"><?= htmlspecialchars($updatedAt) ?></span>
            <?php if (($counts['descartada'] ?? 0) > 0): ?>
                <form method="POST" action="dashboard.php?module=agente_consultas" style="display:inline;"
                      onsubmit="return confirm('¿Eliminar las <?= (int)$counts['descartada'] ?> propuestas descartadas? No se puede deshacer.');">
                    <input type="hidden" name="accion" value="eliminar_descartadas">
                    <button class="d-btn d-btn-sm d-btn-outline" type="submit">
                        <i class="bi bi-trash3"></i> Eliminar descartadas (<?= (int)$counts['descartada'] ?>)
                    </button>
                </form>
            <?php endif; ?>
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
            El archivo sigue siendo `.txt`, pero ahora contiene JSON estructurado con propuestas. Ruta:
            <code><?= htmlspecialchars($agentLogPath) ?></code>
        </div>

        <div class="row g-3">
            <div class="col-lg-5">
                <div class="d-card" style="box-shadow:none;">
                    <div class="d-card-header">Propuestas</div>
                    <div class="d-card-body" style="max-height:70vh; overflow:auto;">
                        <?php if (empty($entries)): ?>
                            <div class="text-muted">Aun no hay consultas registradas.</div>
                        <?php endif; ?>
                        <?php foreach (array_reverse($entries) as $entry): ?>
                            <?php
                            $proposal = is_array($entry['propuesta'] ?? null) ? $entry['propuesta'] : [];
                            $id = (string)($entry['id'] ?? '');
                            $status = (string)($entry['status'] ?? 'pendiente');
                            $badgeClass = [
                                'pendiente' => 'warning',
                                'seleccionada' => 'info',
                                'aprobada' => 'success',
                                'creada' => 'success',
                                'descartada' => 'secondary',
                            ][$status] ?? 'secondary';
                            $tieneToolPath = !empty($proposal['intent_sugerido']) && !empty($proposal['tool_sugerida']);
                            $tieneSqlPath = trim((string)($proposal['sql_template_sugerido'] ?? '')) !== '';
                            $sinPropuestaViable = !$tieneToolPath && !$tieneSqlPath;
                            ?>
                            <div class="border rounded p-3 mb-3" style="border-color:var(--c-border)!important;">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <strong><?= htmlspecialchars((string)($proposal['intent_sugerido'] ?? 'sin_intent')) ?></strong>
                                    <span class="d-badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
                                </div>
                                <div class="mt-2" style="font-size:.88rem;"><?= htmlspecialchars((string)($entry['consulta'] ?? '')) ?></div>
                                <div class="text-muted mt-1" style="font-size:.75rem;">
                                    Tool: <?= htmlspecialchars((string)($proposal['tool_sugerida'] ?? '-')) ?>
                                    · <?= htmlspecialchars((string)($entry['created_at'] ?? '')) ?>
                                </div>
                                <div class="d-flex gap-2 flex-wrap mt-3">
                                    <a class="d-btn d-btn-sm d-btn-outline" href="dashboard.php?module=agente_consultas&selected=<?= urlencode($id) ?>">
                                        <i class="bi bi-eye"></i> Ver
                                    </a>
                                    <form method="POST" action="dashboard.php?module=agente_consultas" style="display:inline;">
                                        <input type="hidden" name="accion" value="seleccionar">
                                        <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                                        <button class="d-btn d-btn-sm d-btn-outline" type="submit"><i class="bi bi-check2-square"></i> Seleccionar</button>
                                    </form>
                                    <form method="POST" action="dashboard.php?module=agente_consultas" style="display:inline;">
                                        <input type="hidden" name="accion" value="aprobar">
                                        <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                                        <button class="d-btn d-btn-sm d-btn-primary" type="submit"><i class="bi bi-hand-thumbs-up"></i> Aprobar</button>
                                    </form>
                                    <form method="POST" action="dashboard.php?module=agente_consultas" style="display:inline;">
                                        <input type="hidden" name="accion" value="generar_sql_ia">
                                        <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                                        <button class="d-btn d-btn-sm d-btn-outline" type="submit"><i class="bi bi-magic"></i> Generar SQL con IA</button>
                                    </form>
                                    <?php if ($sinPropuestaViable): ?>
                                        <form method="POST" action="dashboard.php?module=agente_consultas" style="display:inline;">
                                            <input type="hidden" name="accion" value="responder_ia">
                                            <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                                            <button class="d-btn d-btn-sm d-btn-outline" type="submit"><i class="bi bi-stars"></i> Responder con IA</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" action="dashboard.php?module=agente_consultas" style="display:inline;">
                                        <input type="hidden" name="accion" value="crear_skill">
                                        <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                                        <button class="d-btn d-btn-sm d-btn-success" type="submit"><i class="bi bi-plus-circle"></i> Crear skill</button>
                                    </form>
                                    <form method="POST" action="dashboard.php?module=agente_consultas" style="display:inline;">
                                        <input type="hidden" name="accion" value="descartar">
                                        <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                                        <button class="d-btn d-btn-sm d-btn-outline" type="submit"><i class="bi bi-x-circle"></i> Descartar</button>
                                    </form>
                                    <form method="POST" action="dashboard.php?module=agente_consultas" style="display:inline;"
                                          onsubmit="return confirm('¿Eliminar esta propuesta? No se puede deshacer.');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                                        <button class="d-btn d-btn-sm d-btn-outline" type="submit"><i class="bi bi-trash3"></i> Eliminar</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="d-card mb-3" style="box-shadow:none;">
                    <div class="d-card-header">Detalle seleccionado</div>
                    <div class="d-card-body">
                        <?php if ($selectedEntry): ?>
                            <pre style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:.75rem;max-height:32vh;overflow-y:auto;overflow-x:hidden;white-space:pre-wrap;word-break:break-word;"><?= htmlspecialchars(json_encode($selectedEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                        <?php else: ?>
                            <div class="text-muted">Seleccione una propuesta para revisar su JSON.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <details>
                    <summary class="fw-semibold" style="cursor:pointer;">Editor JSON completo (avanzado)</summary>
                    <form method="POST" action="dashboard.php?module=agente_consultas" class="mt-2">
                        <input type="hidden" name="accion" value="guardar_json">
                        <textarea
                            name="contenido"
                            class="d-input"
                            spellcheck="false"
                            style="width:100%; min-height:28vh; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:.8rem; line-height:1.45;"
                        ><?= htmlspecialchars($contenidoActual) ?></textarea>

                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mt-3">
                            <div class="text-muted" style="font-size:.82rem;">
                                El guardado valida JSON y reemplaza el archivo completo.
                            </div>
                            <button type="submit" class="d-btn d-btn-primary">
                                <i class="bi bi-save"></i> Guardar JSON
                            </button>
                        </div>
                    </form>
                </details>
            </div>
        </div>
    </div>
</div>
