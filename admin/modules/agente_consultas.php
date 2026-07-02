<?php
/**
 * Consultas IA no resueltas — bandeja del agente.
 *
 * Fuente de datos: tabla `agente_consultas_log` (migración 067, misma BD).
 * El agente Python inserta vía POST /api/v1/agente/consultas-log; este módulo
 * revisa propuestas, las aprueba y crea skills seguras en agent/skills/.
 *
 * Si existe el archivo legacy agent/tmp/agent_unanswered.txt (formato
 * anterior, o entradas escritas por el fallback del agente cuando el backend
 * no responde), se importa automáticamente a la tabla al abrir el módulo y
 * el archivo se renombra con sufijo .imported-YYYYmmdd-His.
 */

use App\Core\Database;
use App\Services\AgentHttpClient;

$agentBasePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'agent';
$agentLogPath = $agentBasePath . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'agent_unanswered.txt';
$agentSkillsDir = $agentBasePath . DIRECTORY_SEPARATOR . 'skills';
$msg = '';
$error = '';

/** Columnas que el editor JSON por entrada puede modificar. */
const AC_EDITABLE_COLUMNS = [
    'status', 'consulta', 'respuesta', 'respuesta_ia', 'respuesta_ia_tipo', 'skill_path',
];

function acDb(?string &$error = null): ?PDO
{
    try {
        return Database::getInstance();
    } catch (Throwable $e) {
        $error = 'Sin conexion a la base de datos: ' . $e->getMessage();
        return null;
    }
}

/** Convierte una fila de agente_consultas_log a la forma que usa la UI. */
function acRowToEntry(array $row): array
{
    $propuesta = json_decode((string)($row['propuesta'] ?? ''), true);
    return [
        'id' => (string)$row['uid'],
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'status' => (string)($row['status'] ?? 'pendiente'),
        'source' => (string)($row['source'] ?? 'agent'),
        'thread_id' => (string)($row['thread_id'] ?? ''),
        'empresa_id' => $row['empresa_id'] !== null ? (int)$row['empresa_id'] : null,
        'sucursal_id' => $row['sucursal_id'] !== null ? (int)$row['sucursal_id'] : null,
        'operador' => (string)($row['operador'] ?? ''),
        'consulta' => (string)($row['consulta'] ?? ''),
        'respuesta' => (string)($row['respuesta'] ?? ''),
        'respuesta_ia' => (string)($row['respuesta_ia'] ?? ''),
        'respuesta_ia_tipo' => (string)($row['respuesta_ia_tipo'] ?? ''),
        'propuesta' => is_array($propuesta) ? $propuesta : [],
        'skill_path' => (string)($row['skill_path'] ?? ''),
    ];
}

/** @return array<int, array> entradas en orden de creación (la UI las invierte) */
function acLoadEntries(PDO $db): array
{
    $rows = $db->query('SELECT * FROM agente_consultas_log ORDER BY id ASC')->fetchAll();
    return array_map('acRowToEntry', $rows);
}

function acFindEntry(PDO $db, string $uid): ?array
{
    $stmt = $db->prepare('SELECT * FROM agente_consultas_log WHERE uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $uid]);
    $row = $stmt->fetch();
    return $row ? acRowToEntry($row) : null;
}

/**
 * UPDATE parcial de una entrada. $fields usa nombres de columna reales;
 * `propuesta` acepta array y se serializa aquí. Placeholders únicos por
 * columna (EMULATE_PREPARES=false: nunca repetir un placeholder con nombre).
 */
function acUpdateEntry(PDO $db, string $uid, array $fields): bool
{
    if (isset($fields['propuesta']) && is_array($fields['propuesta'])) {
        $fields['propuesta'] = json_encode(
            $fields['propuesta'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
    $sets = [];
    $binds = [':uid_where' => $uid];
    foreach ($fields as $column => $value) {
        $sets[] = "`$column` = :set_$column";
        $binds[":set_$column"] = $value;
    }
    if ($sets === []) {
        return true;
    }
    $sql = 'UPDATE agente_consultas_log SET ' . implode(', ', $sets)
        . ', updated_at = CURRENT_TIMESTAMP WHERE uid = :uid_where';
    return $db->prepare($sql)->execute($binds);
}

/**
 * Importa el archivo legacy (JSON estructurado o texto libre) a la tabla.
 * INSERT IGNORE por uid: reimportar no duplica. Renombra el archivo al
 * terminar para no reimportar en cada carga. Devuelve cuántas insertó.
 */
function acImportLegacy(PDO $db, string $path, ?string &$importError = null): int
{
    if (!is_file($path)) {
        return 0;
    }
    $raw = trim((string)@file_get_contents($path));
    if ($raw === '') {
        @rename($path, $path . '.imported-' . date('Ymd-His'));
        return 0;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        // Formato TXT antiguo: una sola entrada legacy con el contenido crudo.
        $data = [[
            'id' => 'legacy_' . bin2hex(random_bytes(6)),
            'status' => 'pendiente',
            'source' => 'legacy_txt',
            'consulta' => 'Contenido legacy importado desde texto libre',
            'respuesta' => $raw,
            'propuesta' => ['titulo' => 'Migrar registro legacy', 'resoluble' => false],
        ]];
    } elseif (!array_is_list($data)) {
        $data = [$data];
    }

    $stmt = $db->prepare(
        'INSERT IGNORE INTO agente_consultas_log
            (uid, empresa_id, sucursal_id, thread_id, operador, source, status,
             consulta, respuesta, respuesta_ia, respuesta_ia_tipo, propuesta, skill_path)
         VALUES
            (:uid, :empresa_id, :sucursal_id, :thread_id, :operador, :source, :status,
             :consulta, :respuesta, :respuesta_ia, :respuesta_ia_tipo, :propuesta, :skill_path)'
    );

    $inserted = 0;
    foreach ($data as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $uid = trim((string)($entry['id'] ?? ''));
        if ($uid === '') {
            $uid = 'legacy_' . bin2hex(random_bytes(6));
        }
        $propuesta = $entry['propuesta'] ?? null;
        try {
            $stmt->execute([
                ':uid' => mb_substr($uid, 0, 64),
                ':empresa_id' => isset($entry['empresa_id']) && is_numeric($entry['empresa_id'])
                    ? (int)$entry['empresa_id'] : null,
                ':sucursal_id' => isset($entry['sucursal_id']) && is_numeric($entry['sucursal_id'])
                    ? (int)$entry['sucursal_id'] : null,
                ':thread_id' => mb_substr((string)($entry['thread_id'] ?? ''), 0, 200),
                ':operador' => mb_substr((string)($entry['operador'] ?? ''), 0, 200),
                ':source' => mb_substr((string)($entry['source'] ?? 'legacy_txt'), 0, 30),
                ':status' => mb_substr((string)($entry['status'] ?? 'pendiente'), 0, 20),
                ':consulta' => (string)($entry['consulta'] ?? ''),
                ':respuesta' => (string)($entry['respuesta'] ?? ''),
                ':respuesta_ia' => (string)($entry['respuesta_ia'] ?? ''),
                ':respuesta_ia_tipo' => mb_substr((string)($entry['respuesta_ia_tipo'] ?? ''), 0, 40),
                ':propuesta' => is_array($propuesta)
                    ? json_encode($propuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                ':skill_path' => mb_substr((string)($entry['skill_path'] ?? ''), 0, 255),
            ]);
            $inserted += $stmt->rowCount() > 0 ? 1 : 0;
        } catch (Throwable $e) {
            $importError = 'Error importando entrada legacy: ' . $e->getMessage();
            return $inserted; // no renombrar: reintentar en la próxima carga
        }
    }

    @rename($path, $path . '.imported-' . date('Ymd-His'));
    return $inserted;
}

function acSlug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string)$value, '_');
    return $value !== '' ? $value : 'skill';
}

/**
 * Pares intent→tool que el agente acepta. DEBE coincidir con
 * agent/skill_engine.py::ALLOWED_TOOLS_BY_INTENT (fuente de verdad):
 * una skill con un par que no esté aquí se crea "muerta" — skill_engine
 * la descarta silenciosamente al cargar y nunca matchea.
 */
function acAllowedToolsByIntent(): array
{
    return [
        'ventas' => 'ventas_periodo',
        'top_productos' => 'ventas_por_producto',
        'stock_critico' => 'stock_critico',
        'reposicion' => 'sugerencias_reposicion',
        'cajas' => 'estado_cajas',
        'cierres' => 'cierres_pendientes',
        'iva' => 'resumen_iva',
        'compras' => 'compras_pendientes',
        'folios' => 'estado_folios_sii',
        'cliente' => 'buscar_cliente',
        'producto' => 'buscar_producto',
        'stock_producto' => 'buscar_producto',
        'ayuda' => 'ayuda',
    ];
}

$db = acDb($dbError);
if ($db === null) {
    $error = $dbError . ' — este modulo requiere la migracion 067_agente_consultas_log.sql aplicada.';
}

$importedCount = 0;
if ($db !== null) {
    try {
        $importedCount = acImportLegacy($db, $agentLogPath, $importError);
        if (!empty($importError)) {
            $error = $importError;
        } elseif ($importedCount > 0) {
            $msg = "$importedCount entrada(s) legacy importadas desde el archivo a la tabla.";
        }
    } catch (Throwable $e) {
        $error = 'No se pudo leer agente_consultas_log: ' . $e->getMessage()
            . ' — ¿esta aplicada la migracion 067_agente_consultas_log.sql?';
        $db = null;
    }
}

if ($db !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    $id = (string)($_POST['id'] ?? '');

    if ($accion === 'guardar_json') {
        $contenido = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['contenido'] ?? '')));
        $decoded = json_decode($contenido, true);
        if (!is_array($decoded)) {
            $error = 'No se guardo: el contenido no es JSON valido.';
        } elseif ($id === '' || acFindEntry($db, $id) === null) {
            $error = 'No se encontro la entrada a editar.';
        } else {
            $fields = [];
            foreach (AC_EDITABLE_COLUMNS as $column) {
                if (array_key_exists($column, $decoded)) {
                    $fields[$column] = (string)$decoded[$column];
                }
            }
            if (array_key_exists('propuesta', $decoded) && is_array($decoded['propuesta'])) {
                $fields['propuesta'] = $decoded['propuesta'];
            }
            if (acUpdateEntry($db, $id, $fields)) {
                $msg = 'Entrada actualizada (solo campos editables: '
                    . implode(', ', AC_EDITABLE_COLUMNS) . ', propuesta).';
            } else {
                $error = 'No se pudo actualizar la entrada.';
            }
        }
    }

    if ($accion === 'eliminar') {
        $stmt = $db->prepare('DELETE FROM agente_consultas_log WHERE uid = :uid');
        $stmt->execute([':uid' => $id]);
        if ($stmt->rowCount() > 0) {
            $msg = 'Propuesta eliminada.';
        } else {
            $error = 'No se encontro la propuesta seleccionada.';
        }
    }

    if ($accion === 'eliminar_descartadas') {
        $stmt = $db->prepare("DELETE FROM agente_consultas_log WHERE status = 'descartada'");
        $stmt->execute();
        $msg = $stmt->rowCount() . ' propuesta(s) descartada(s) eliminada(s).';
    }

    if ($accion === 'responder_ia') {
        $entry = acFindEntry($db, $id);
        if ($entry === null) {
            $error = 'No se encontro la propuesta seleccionada.';
        } elseif ($entry['consulta'] === '') {
            $error = 'La entry no tiene una consulta original que enviar al LLM.';
        } else {
            $result = AgentHttpClient::postJson('/skills/answer-directly', ['consulta' => $entry['consulta']]);
            if (!$result['ok']) {
                $error = 'No se pudo generar respuesta con IA: ' . $result['error'];
            } else {
                $data = $result['data'];
                $tipo = (string)($data['tipo'] ?? '');
                $fields = [
                    'respuesta_ia' => (string)($data['respuesta'] ?? ''),
                    'respuesta_ia_tipo' => $tipo,
                ];
                if ($tipo === 'respuesta_directa') {
                    $fields['status'] = 'aprobada';
                }
                acUpdateEntry($db, $id, $fields);
                $msg = $tipo === 'requiere_datos'
                    ? 'La IA indica que esta pregunta necesita datos en vivo: usa "Generar SQL con IA" en vez de esto.'
                    : 'Respuesta generada con IA (ver detalle seleccionado).';
            }
        }
    }

    if (in_array($accion, ['seleccionar', 'aprobar', 'descartar'], true)) {
        $status = [
            'seleccionar' => 'seleccionada',
            'aprobar' => 'aprobada',
            'descartar' => 'descartada',
        ][$accion];
        if (acFindEntry($db, $id) === null) {
            $error = 'No se encontro la propuesta seleccionada.';
        } elseif (acUpdateEntry($db, $id, ['status' => $status])) {
            $msg = "Propuesta marcada como $status.";
        } else {
            $error = 'No se pudo actualizar el estado.';
        }
    }

    if ($accion === 'crear_skill') {
        $entry = acFindEntry($db, $id);

        // Ámbito de la skill: global (default) o solo la empresa de origen.
        // agent/skill_engine.py::_scope_allows() filtra al matchear.
        $scope = (string)($_POST['scope'] ?? 'global');
        if ($scope !== 'global' && preg_match('/^empresa:\d+$/', $scope) !== 1) {
            $scope = 'global';
        }

        if ($entry === null) {
            $error = 'No se encontro la propuesta seleccionada.';
        } else {
            $proposal = $entry['propuesta'];
            $esSqlReadonly = trim((string)($proposal['sql_template_sugerido'] ?? '')) !== '';
            $esRespuestaDirecta = !$esSqlReadonly
                && empty($proposal['tool_sugerida'])
                && (string)($entry['respuesta_ia_tipo'] ?? '') === 'respuesta_directa'
                && trim((string)($entry['respuesta_ia'] ?? '')) !== '';

            if (!is_dir($agentSkillsDir) && !@mkdir($agentSkillsDir, 0755, true) && !is_dir($agentSkillsDir)) {
                $error = 'No se pudo crear el directorio agent/skills.';
            } elseif ($esRespuestaDirecta) {
                // --- Skill tipo respuesta_directa: texto curado, sin ejecucion ---
                $intent = acSlug((string)($proposal['intent_sugerido'] ?? ('respuesta_' . substr($id, 0, 8))));
                $skillId = acSlug($intent . '_' . substr($id, 0, 8));
                $skill = [
                    'id' => $skillId,
                    'status' => 'aprobada',
                    'created_at' => date('c'),
                    'source_entry_id' => $id,
                    'schema_version' => 1,
                    'tipo' => 'respuesta_directa',
                    'scope' => $scope,
                    'patterns' => !empty($proposal['patterns_sugeridos'])
                        ? $proposal['patterns_sugeridos']
                        : [$entry['consulta']],
                    'respuesta' => trim((string)$entry['respuesta_ia']),
                    'notes' => $proposal['notas'] ?? 'Creada desde "Responder con IA".',
                ];
                $skillPath = $agentSkillsDir . DIRECTORY_SEPARATOR . $skillId . '.json';
                $json = json_encode($skill, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false || @file_put_contents($skillPath, $json . "\n", LOCK_EX) === false) {
                    $error = 'No se pudo crear el archivo de skill.';
                } else {
                    acUpdateEntry($db, $id, ['status' => 'creada', 'skill_path' => $skillPath]);
                    $msg = "Skill respuesta_directa creada ($scope): " . basename($skillPath);
                }
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
                    'scope' => $scope,
                    'intent' => $intent,
                    'patterns' => $proposal['patterns_sugeridos'] ?? [$entry['consulta']],
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
                        acUpdateEntry($db, $id, ['status' => 'creada', 'skill_path' => $skillPath]);
                        $msg = 'Skill sql_readonly creada (validada contra la whitelist): ' . basename($skillPath);
                    }
                }
            } else {
                // --- Skill tipo intent+tool (formato original) ---
                $intent = (string)($proposal['intent_sugerido'] ?? 'pendiente');
                $tool = (string)($proposal['tool_sugerida'] ?? '');
                $allowed = acAllowedToolsByIntent();
                if ($intent === '' || $tool === '') {
                    $error = 'La propuesta no tiene intent/tool suficientes para crear una skill segura.';
                } elseif (($allowed[$intent] ?? null) !== $tool) {
                    $error = "Par intent/tool invalido: '$intent' → '$tool'. "
                        . 'El agente la descartaria silenciosamente. Pares validos: '
                        . implode(', ', array_map(
                            static fn (string $i, string $t): string => "$i→$t",
                            array_keys($allowed),
                            $allowed
                        )) . '.';
                } else {
                    $skillId = acSlug($intent . '_' . substr($id, 0, 8));
                    $skillPath = $agentSkillsDir . DIRECTORY_SEPARATOR . $skillId . '.json';
                    $skill = [
                        'id' => $skillId,
                        'status' => 'aprobada',
                        'created_at' => date('c'),
                        'source_entry_id' => $id,
                        'scope' => $scope,
                        'intent' => $intent,
                        'tool' => $tool,
                        'patterns' => $proposal['patterns_sugeridos'] ?? [$entry['consulta']],
                        'params' => $proposal['params_sugeridos'] ?? [],
                        'notes' => $proposal['notas'] ?? '',
                    ];
                    $json = json_encode($skill, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json === false || @file_put_contents($skillPath, $json . "\n", LOCK_EX) === false) {
                        $error = 'No se pudo crear el archivo de skill.';
                    } else {
                        acUpdateEntry($db, $id, ['status' => 'creada', 'skill_path' => $skillPath]);
                        $msg = 'Skill creada: ' . basename($skillPath);
                    }
                }
            }
        }
    }

    if ($accion === 'generar_sql_ia') {
        $entry = acFindEntry($db, $id);
        if ($entry === null) {
            $error = 'No se encontro la propuesta seleccionada.';
        } elseif ($entry['consulta'] === '') {
            $error = 'La entry no tiene una consulta original que enviar al LLM.';
        } else {
            $result = AgentHttpClient::postJson('/skills/propose-sql', ['consulta' => $entry['consulta']]);
            $proposal = $entry['propuesta'];

            if (!$result['ok']) {
                $error = 'No se pudo generar SQL con IA: ' . $result['error'];
            } elseif (($result['data']['resoluble'] ?? null) === false) {
                $proposal['notas'] = trim(
                    ($proposal['notas'] ?? '') . "\n[IA] No resoluble via SQL: " .
                    (string)($result['data']['notes'] ?? 'sin detalle')
                );
                acUpdateEntry($db, $id, ['propuesta' => $proposal]);
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
                acUpdateEntry($db, $id, ['propuesta' => $proposal, 'status' => 'seleccionada']);
                $msg = 'Borrador de SQL generado. Revisa y edita antes de "Crear skill".';
            }
        }
    }
}

$entries = [];
$lastUpdated = 'Sin datos';
if ($db !== null) {
    try {
        $entries = acLoadEntries($db);
        foreach ($entries as $e) {
            $candidate = $e['updated_at'] !== '' ? $e['updated_at'] : $e['created_at'];
            if ($lastUpdated === 'Sin datos' || $candidate > $lastUpdated) {
                $lastUpdated = $candidate;
            }
        }
    } catch (Throwable $e) {
        $error = 'No se pudo leer agente_consultas_log: ' . $e->getMessage()
            . ' — ¿esta aplicada la migracion 067_agente_consultas_log.sql?';
    }
}

$selectedId = (string)($_GET['selected'] ?? ($_POST['id'] ?? ''));
$selectedEntry = null;
foreach ($entries as $entry) {
    if ((string)$entry['id'] === $selectedId) {
        $selectedEntry = $entry;
        break;
    }
}
if ($selectedEntry === null && !empty($entries)) {
    $selectedEntry = $entries[count($entries) - 1];
}

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
            <span class="d-badge info"><?= count($entries) ?> total</span>
            <span class="d-badge secondary"><?= htmlspecialchars($lastUpdated) ?></span>
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
            Fuente de datos: tabla <code>agente_consultas_log</code> (migracion 067). El agente inserta
            via API; si el backend no responde, cae al archivo legacy y este modulo lo importa al abrir.
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
                            $proposal = $entry['propuesta'];
                            $id = (string)$entry['id'];
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
                                    Empresa: <?= $entry['empresa_id'] !== null ? (int)$entry['empresa_id'] : '-' ?>
                                    · Tool: <?= htmlspecialchars((string)($proposal['tool_sugerida'] ?? '-')) ?>
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
                                    <form method="POST" action="dashboard.php?module=agente_consultas" style="display:inline-flex; gap:4px; align-items:center;">
                                        <input type="hidden" name="accion" value="crear_skill">
                                        <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                                        <select name="scope" class="d-input" style="font-size:.75rem; padding:2px 6px; width:auto;" title="Ámbito de la skill">
                                            <option value="global">Global</option>
                                            <?php if ($entry['empresa_id'] !== null): ?>
                                                <option value="empresa:<?= (int)$entry['empresa_id'] ?>">Solo empresa <?= (int)$entry['empresa_id'] ?></option>
                                            <?php endif; ?>
                                        </select>
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

                <?php if ($selectedEntry): ?>
                <details>
                    <summary class="fw-semibold" style="cursor:pointer;">Editar entrada seleccionada (JSON, avanzado)</summary>
                    <form method="POST" action="dashboard.php?module=agente_consultas" class="mt-2">
                        <input type="hidden" name="accion" value="guardar_json">
                        <input type="hidden" name="id" value="<?= htmlspecialchars((string)$selectedEntry['id']) ?>">
                        <textarea
                            name="contenido"
                            class="d-input"
                            spellcheck="false"
                            style="width:100%; min-height:28vh; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:.8rem; line-height:1.45;"
                        ><?= htmlspecialchars(json_encode($selectedEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>

                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mt-3">
                            <div class="text-muted" style="font-size:.82rem;">
                                Solo se guardan los campos editables (status, consulta, respuesta,
                                respuesta_ia, respuesta_ia_tipo, skill_path, propuesta) de ESTA entrada.
                            </div>
                            <button type="submit" class="d-btn d-btn-primary">
                                <i class="bi bi-save"></i> Guardar entrada
                            </button>
                        </div>
                    </form>
                </details>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
