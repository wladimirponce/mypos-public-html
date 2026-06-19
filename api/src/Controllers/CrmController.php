<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Throwable;

final class CrmController
{
    private const ESTADOS_FALLBACK = ['NUEVO', 'CONTACTADO', 'CALIFICADO', 'CONVERTIDO', 'DESCARTADO'];

    public function index(): void
    {
        try {
            [$empresaId, $userId] = $this->auth();

            $tipo   = trim($_GET['tipo']   ?? '');
            $estado = trim($_GET['estado'] ?? '');
            $q      = trim($_GET['q']      ?? '');
            $fecha  = trim($_GET['fecha']  ?? '');
            $agente = (int)($_GET['agente'] ?? 0);
            $soloMios = !empty($_GET['solo_mios']);
            $modo   = trim($_GET['modo'] ?? '');
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $limit  = $modo === 'kanban' ? 500 : 40;
            $offset = $modo === 'kanban' ? 0 : ($page - 1) * $limit;

            $where  = ['wc.empresa_id = ?'];
            $params = [$empresaId];

            if ($tipo !== '') {
                $where[]  = 'wc.tipo_contacto = ?';
                $params[] = $tipo;
            }
            if ($estado !== '') {
                $where[]  = 'wc.estado_lead = ?';
                $params[] = $estado;
            }
            if ($q !== '') {
                $where[]  = '(wc.user_name LIKE ? OR wc.phone_number LIKE ?)';
                $like     = '%' . $q . '%';
                $params[] = $like;
                $params[] = $like;
            }
            if ($fecha === 'hoy') {
                $where[] = 'DATE(wc.created_at) = CURDATE()';
            } elseif ($fecha === 'semana') {
                $where[] = 'wc.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
            } elseif ($fecha === 'mes') {
                $where[] = 'wc.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
            }
            if ($soloMios) {
                $where[]  = 'wc.asignado_usuario_id = ?';
                $params[] = $userId;
            } elseif ($agente > 0) {
                $where[]  = 'wc.asignado_usuario_id = ?';
                $params[] = $agente;
            }

            $whereSql = 'WHERE ' . implode(' AND ', $where);

            $db = Database::connection();

            $countStmt = $db->prepare(
                "SELECT COUNT(*) FROM whatsapp_conversations wc $whereSql"
            );
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $listParams   = array_merge($params, [$limit, $offset]);
            $stmt = $db->prepare("
                SELECT
                    wc.id,
                    wc.empresa_id,
                    wc.phone_number,
                    wc.user_name,
                    wc.tipo_contacto,
                    wc.estado_lead,
                    wc.plan_interes,
                    wc.primer_mensaje,
                    wc.context_summary,
                    wc.notas_internas,
                    wc.cliente_id,
                    wc.last_activity,
                    wc.created_at,
                    COALESCE(wc.leido, 1)  AS leido,
                    wc.asignado_usuario_id,
                    u.nombre               AS agente_nombre,
                    COUNT(wm.id)           AS mensaje_count
                FROM whatsapp_conversations wc
                LEFT JOIN whatsapp_messages wm ON wm.conversation_id = wc.id
                LEFT JOIN usuarios u            ON u.id = wc.asignado_usuario_id
                $whereSql
                GROUP BY wc.id
                ORDER BY wc.last_activity DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute($listParams);

            Response::success([
                'data'  => $stmt->fetchAll(),
                'total' => $total,
                'page'  => $page,
                'pages' => max(1, (int)ceil($total / $limit)),
            ]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al obtener leads', null, 500);
        }
    }

    public function messages(array $params = []): void
    {
        try {
            [$empresaId] = $this->auth();

            $id = (int)($params['id'] ?? 0);
            if ($id <= 0) throw new HttpException('ID inválido', 400);

            $db   = Database::connection();
            $stmt = $db->prepare(
                'SELECT * FROM whatsapp_conversations WHERE id = ? AND empresa_id = ? LIMIT 1'
            );
            $stmt->execute([$id, $empresaId]);
            $conv = $stmt->fetch();
            if (!$conv) throw new HttpException('Conversación no encontrada', 404);

            $stmtM = $db->prepare(
                'SELECT id, direction, message_text, ai_response, intent, created_at
                 FROM whatsapp_messages
                 WHERE conversation_id = ?
                 ORDER BY created_at ASC'
            );
            $stmtM->execute([$id]);

            // Marcar como leída (defensivo: columna puede no existir aún)
            try {
                $db->prepare('UPDATE whatsapp_conversations SET leido = 1 WHERE id = ?')
                   ->execute([$id]);
            } catch (\Throwable $ignored) {}

            Response::success([
                'conversation' => $conv,
                'messages'     => $stmtM->fetchAll(),
            ]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al obtener mensajes', null, 500);
        }
    }

    public function update(array $params = []): void
    {
        try {
            [$empresaId] = $this->auth();

            $id = (int)($params['id'] ?? 0);
            if ($id <= 0) throw new HttpException('ID inválido', 400);

            $body = Request::json();
            $sets   = [];
            $values = [];

            if (isset($body['estado_lead'])) {
                $slugValido = $this->slugEtapaValido($empresaId, (string)$body['estado_lead']);
                if (!$slugValido) throw new HttpException('estado_lead inválido para esta empresa', 422);
                $sets[]   = 'estado_lead = ?';
                $values[] = $body['estado_lead'];
            }

            if (array_key_exists('notas_internas', $body)) {
                $sets[]   = 'notas_internas = ?';
                $values[] = $body['notas_internas'] === null
                    ? null
                    : mb_substr((string)$body['notas_internas'], 0, 65535);
            }

            if (array_key_exists('plan_interes', $body)) {
                $sets[]   = 'plan_interes = ?';
                $values[] = $body['plan_interes'] === null
                    ? null
                    : mb_substr((string)$body['plan_interes'], 0, 60);
            }

            if (array_key_exists('asignado_usuario_id', $body)) {
                $nuevoAgente = $body['asignado_usuario_id'] === null ? null : (int)$body['asignado_usuario_id'];
                if ($nuevoAgente !== null) {
                    $chk = Database::connection()->prepare(
                        'SELECT 1 FROM empresa_usuarios WHERE empresa_id = ? AND usuario_id = ? AND activo = 1 LIMIT 1'
                    );
                    $chk->execute([$empresaId, $nuevoAgente]);
                    if (!$chk->fetchColumn()) throw new HttpException('Agente no pertenece a la empresa', 422);
                }
                $sets[]   = 'asignado_usuario_id = ?';
                $values[] = $nuevoAgente;
            }

            if (empty($sets)) throw new HttpException('Sin campos a actualizar', 422);

            $values[] = $id;
            $values[] = $empresaId;

            $stmt = Database::connection()->prepare(
                'UPDATE whatsapp_conversations SET ' . implode(', ', $sets) .
                ' WHERE id = ? AND empresa_id = ?'
            );
            $stmt->execute($values);

            Response::success(['updated' => true]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al actualizar lead', null, 500);
        }
    }

    public function clienteInfo(array $params = []): void
    {
        try {
            [$empresaId] = $this->auth();
            $id = (int)($params['id'] ?? 0);
            if ($id <= 0) throw new HttpException('ID inválido', 400);

            $db   = Database::connection();
            $conv = $db->prepare(
                'SELECT cliente_id, phone_number, user_name FROM whatsapp_conversations
                 WHERE id = ? AND empresa_id = ? LIMIT 1'
            );
            $conv->execute([$id, $empresaId]);
            $row = $conv->fetch();
            if (!$row) throw new HttpException('Conversación no encontrada', 404);

            $result = ['cliente_id' => null, 'cliente' => null, 'ventas' => [], 'credito_pendiente' => 0];

            if ($row['cliente_id']) {
                $result['cliente_id'] = (int)$row['cliente_id'];

                // Datos del cliente
                $stmtC = $db->prepare(
                    'SELECT id, nombre, razon_social, rut, email, telefono, tipo_cliente,
                            permite_credito, limite_credito
                     FROM clientes WHERE id = ? AND empresa_id = ? LIMIT 1'
                );
                $stmtC->execute([$row['cliente_id'], $empresaId]);
                $result['cliente'] = $stmtC->fetch() ?: null;

                // Últimas 5 ventas + resumen
                $stmtV = $db->prepare(
                    'SELECT id, folio, fecha_venta, total, condicion_pago, estado
                     FROM ventas
                     WHERE cliente_id = ? AND empresa_id = ? AND estado != "ANULADA"
                     ORDER BY fecha_venta DESC LIMIT 5'
                );
                $stmtV->execute([$row['cliente_id'], $empresaId]);
                $result['ventas'] = $stmtV->fetchAll();

                // Resumen histórico
                $stmtR = $db->prepare(
                    'SELECT COUNT(*) AS total_ventas,
                            COALESCE(SUM(total), 0) AS total_comprado,
                            COALESCE(AVG(total), 0) AS ticket_promedio,
                            MAX(fecha_venta)         AS ultima_compra
                     FROM ventas
                     WHERE cliente_id = ? AND empresa_id = ? AND estado != "ANULADA"'
                );
                $stmtR->execute([$row['cliente_id'], $empresaId]);
                $result['resumen'] = $stmtR->fetch();

                // Crédito pendiente
                $stmtCr = $db->prepare(
                    'SELECT COALESCE(SUM(saldo_pendiente), 0) AS credito_pendiente
                     FROM creditos_clientes
                     WHERE cliente_id = ? AND empresa_id = ? AND estado IN ("PENDIENTE","PARCIAL")'
                );
                $stmtCr->execute([$row['cliente_id'], $empresaId]);
                $result['credito_pendiente'] = (int)($stmtCr->fetchColumn() ?? 0);
            }

            Response::success($result);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al obtener datos del cliente', null, 500);
        }
    }

    public function convertir(array $params = []): void
    {
        try {
            [$empresaId, $userId] = $this->auth();
            $id = (int)($params['id'] ?? 0);
            if ($id <= 0) throw new HttpException('ID inválido', 400);

            $db   = Database::connection();
            $conv = $db->prepare(
                'SELECT id, cliente_id, phone_number, user_name FROM whatsapp_conversations
                 WHERE id = ? AND empresa_id = ? LIMIT 1'
            );
            $conv->execute([$id, $empresaId]);
            $row = $conv->fetch();
            if (!$row) throw new HttpException('Conversación no encontrada', 404);

            $clienteId = $row['cliente_id'] ? (int)$row['cliente_id'] : null;

            if (!$clienteId) {
                // Buscar si ya existe cliente con ese teléfono
                $tel  = preg_replace('/\s+/', '', (string)$row['phone_number']);
                $stmtE = $db->prepare(
                    "SELECT id FROM clientes
                     WHERE empresa_id = ? AND REPLACE(REPLACE(telefono,' ',''),'+','') = ? AND deleted_at IS NULL LIMIT 1"
                );
                $stmtE->execute([$empresaId, ltrim($tel, '+')]);
                $existing = $stmtE->fetchColumn();

                if ($existing) {
                    $clienteId = (int)$existing;
                } else {
                    $nombre = mb_substr(trim((string)$row['user_name']) ?: 'Sin nombre', 0, 100);
                    $db->prepare(
                        'INSERT INTO clientes
                         (empresa_id, tipo_cliente, rut, nombre, razon_social, nombre_fantasia,
                          giro, email, telefono, direccion, comuna, ciudad,
                          activo, credito_habilitado, permite_credito, limite_credito, observacion)
                         VALUES (?, "PERSONA", "", ?, ?, "", "", "", ?, "", "", "", 1, 0, 0, 0, "Creado desde CRM WhatsApp")'
                    )->execute([$empresaId, $nombre, $nombre, $row['phone_number']]);
                    $clienteId = (int)$db->lastInsertId();
                }
            }

            // Actualizar conversación
            $db->prepare(
                'UPDATE whatsapp_conversations
                 SET cliente_id = ?, tipo_contacto = "CLIENTE_CAUTIVO", estado_lead = "CONVERTIDO"
                 WHERE id = ?'
            )->execute([$clienteId, $id]);

            Response::success(['cliente_id' => $clienteId]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al convertir lead', null, 500);
        }
    }

    public function stats(): void
    {
        try {
            [$empresaId] = $this->auth();
            $db = Database::connection();

            $funnelStmt = $db->prepare(
                'SELECT estado_lead, COUNT(*) AS total
                 FROM whatsapp_conversations
                 WHERE empresa_id = ?
                 GROUP BY estado_lead'
            );
            $funnelStmt->execute([$empresaId]);
            $funnelRows = $funnelStmt->fetchAll();
            $funnel = [];
            foreach ($funnelRows as $r) {
                $funnel[$r['estado_lead']] = (int)$r['total'];
            }

            $metaStmt = $db->prepare(
                'SELECT
                    COUNT(*) AS total,
                    SUM(DATE(created_at) = CURDATE()) AS hoy,
                    SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS semana,
                    SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS mes,
                    SUM(estado_lead = "CONVERTIDO") AS convertidos,
                    SUM(COALESCE(leido, 1) = 0) AS no_leidos
                 FROM whatsapp_conversations
                 WHERE empresa_id = ?'
            );
            $metaStmt->execute([$empresaId]);
            $meta = $metaStmt->fetch();

            $total = (int)($meta['total'] ?? 1);
            $conversion = $total > 0
                ? round(((int)($meta['convertidos'] ?? 0)) / $total * 100, 1)
                : 0;

            $intentsStmt = $db->prepare(
                'SELECT wm.intent, COUNT(*) AS total
                 FROM whatsapp_messages wm
                 JOIN whatsapp_conversations wc ON wc.id = wm.conversation_id
                 WHERE wm.intent IS NOT NULL
                   AND wc.empresa_id = ?
                 GROUP BY wm.intent
                 ORDER BY total DESC
                 LIMIT 6'
            );
            $intentsStmt->execute([$empresaId]);

            $mensajesStmt = $db->prepare(
                'SELECT COUNT(*) FROM whatsapp_messages wm
                 JOIN whatsapp_conversations wc ON wc.id = wm.conversation_id
                 WHERE wc.empresa_id = ?'
            );
            $mensajesStmt->execute([$empresaId]);

            // Métricas por agente
            $agentesMetrics = [];
            try {
                $agStmt = $db->prepare("
                    SELECT
                        u.id, u.nombre,
                        COUNT(DISTINCT CASE WHEN wc.estado_lead NOT IN ('CONVERTIDO','DESCARTADO') THEN wc.id END) AS conversaciones_activas,
                        ROUND(AVG(
                            TIMESTAMPDIFF(MINUTE, wc.created_at,
                                (SELECT MIN(wm2.created_at) FROM whatsapp_messages wm2
                                 WHERE wm2.conversation_id = wc.id AND wm2.direction = 'OUTBOUND')
                            )
                        ), 0) AS tiempo_respuesta_min,
                        COUNT(DISTINCT CASE WHEN DATE(wc.created_at) = CURDATE() THEN wc.id END) AS nuevas_hoy
                    FROM empresa_usuarios eu
                    JOIN usuarios u ON u.id = eu.usuario_id
                    LEFT JOIN whatsapp_conversations wc ON wc.asignado_usuario_id = u.id AND wc.empresa_id = eu.empresa_id
                    WHERE eu.empresa_id = ? AND eu.activo = 1
                    GROUP BY u.id, u.nombre
                    ORDER BY conversaciones_activas DESC
                ");
                $agStmt->execute([$empresaId]);
                $agentesMetrics = $agStmt->fetchAll();
            } catch (\Throwable $ignored) {}

            // Etapas con colores para el frontend
            $etapasStmt = [];
            try {
                $etSt = $db->prepare(
                    'SELECT slug, nombre, color, orden, es_convertido, es_descarte FROM crm_etapas WHERE empresa_id = ? AND activo = 1 ORDER BY orden ASC'
                );
                $etSt->execute([$empresaId]);
                $etapasStmt = $etSt->fetchAll();
            } catch (\Throwable $ignored) {}

            Response::success([
                'funnel'          => $funnel,
                'etapas'          => $etapasStmt,
                'total'           => $total,
                'hoy'             => (int)($meta['hoy'] ?? 0),
                'semana'          => (int)($meta['semana'] ?? 0),
                'mes'             => (int)($meta['mes'] ?? 0),
                'convertidos'     => (int)($meta['convertidos'] ?? 0),
                'no_leidos'       => (int)($meta['no_leidos'] ?? 0),
                'tasa_conversion' => $conversion,
                'mensajes_total'  => (int)$mensajesStmt->fetchColumn(),
                'top_intents'     => $intentsStmt->fetchAll(),
                'agentes_metrics' => $agentesMetrics,
            ]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al obtener estadísticas', null, 500);
        }
    }

    public function tareas(array $params = []): void
    {
        try {
            [$empresaId] = $this->auth();
            $id = (int)($params['id'] ?? 0);
            if ($id <= 0) throw new HttpException('ID inválido', 400);

            $db = Database::connection();
            $db->prepare(
                'SELECT 1 FROM whatsapp_conversations WHERE id = ? AND empresa_id = ? LIMIT 1'
            )->execute([$id, $empresaId]);

            $stmt = $db->prepare(
                'SELECT id, titulo, fecha_vencimiento, completada, completada_en, creado_por, created_at
                 FROM crm_tareas WHERE conversation_id = ? ORDER BY completada ASC, fecha_vencimiento ASC'
            );
            $stmt->execute([$id]);
            Response::success($stmt->fetchAll());
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al obtener tareas', null, 500);
        }
    }

    public function crearTarea(array $params = []): void
    {
        try {
            [$empresaId, $userId] = $this->auth();
            $id   = (int)($params['id'] ?? 0);
            if ($id <= 0) throw new HttpException('ID inválido', 400);

            $body = Request::json();
            $titulo = mb_substr(trim($body['titulo'] ?? ''), 0, 255);
            if ($titulo === '') throw new HttpException('Título obligatorio', 422);

            $fecha = null;
            if (!empty($body['fecha_vencimiento'])) {
                $fecha = date('Y-m-d H:i:s', strtotime($body['fecha_vencimiento'])) ?: null;
            }

            $db = Database::connection();
            $db->prepare(
                'INSERT INTO crm_tareas (conversation_id, titulo, fecha_vencimiento, creado_por)
                 VALUES (?, ?, ?, ?)'
            )->execute([$id, $titulo, $fecha, $userId]);

            $newId = (int)$db->lastInsertId();
            $stmt  = $db->prepare('SELECT * FROM crm_tareas WHERE id = ?');
            $stmt->execute([$newId]);
            Response::success($stmt->fetch());
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al crear tarea', null, 500);
        }
    }

    public function completarTarea(array $params = []): void
    {
        try {
            [$empresaId] = $this->auth();
            $tareaId = (int)($params['tarea_id'] ?? 0);
            if ($tareaId <= 0) throw new HttpException('ID inválido', 400);

            $db = Database::connection();
            $db->prepare(
                'UPDATE crm_tareas t
                 INNER JOIN whatsapp_conversations wc ON wc.id = t.conversation_id
                 SET t.completada = IF(t.completada=1,0,1),
                     t.completada_en = IF(t.completada=0, NOW(), NULL)
                 WHERE t.id = ? AND wc.empresa_id = ?'
            )->execute([$tareaId, $empresaId]);

            Response::success(['toggled' => true]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al actualizar tarea', null, 500);
        }
    }

    public function count(): void
    {
        try {
            [$empresaId] = $this->auth();
            $db   = Database::connection();
            $stmt = $db->prepare(
                "SELECT
                    SUM(estado_lead = 'NUEVO') AS nuevos,
                    SUM(COALESCE(leido, 1) = 0) AS no_leidos
                 FROM whatsapp_conversations
                 WHERE empresa_id = ?"
            );
            $stmt->execute([$empresaId]);
            $row = $stmt->fetch();
            Response::success([
                'nuevos'    => (int)($row['nuevos']    ?? 0),
                'no_leidos' => (int)($row['no_leidos'] ?? 0),
            ]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error', null, 500);
        }
    }

    public function reply(array $params = []): void
    {
        try {
            [$empresaId, $userId] = $this->auth();

            $id = (int)($params['id'] ?? 0);
            if ($id <= 0) throw new HttpException('ID inválido', 400);

            $body = Request::json();
            $texto = trim($body['texto'] ?? '');
            if ($texto === '') throw new HttpException('Texto vacío', 422);
            if (mb_strlen($texto) > 4096) throw new HttpException('Mensaje demasiado largo', 422);

            $db   = Database::connection();

            // Obtener conversación + config WhatsApp de la empresa
            $stmt = $db->prepare("
                SELECT wc.phone_number, wc.empresa_id,
                       ewc.phone_number_id, ewc.access_token
                FROM whatsapp_conversations wc
                LEFT JOIN empresa_whatsapp_config ewc ON ewc.empresa_id = wc.empresa_id AND ewc.activo = 1
                WHERE wc.id = ? AND wc.empresa_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $empresaId]);
            $row = $stmt->fetch();
            if (!$row) throw new HttpException('Conversación no encontrada', 404);

            // Token y URL: empresa > env
            $phoneNumberId = $row['phone_number_id'] ?: getenv('WHATSAPP_PHONE_NUMBER_ID');
            $accessToken   = $row['access_token']    ?: getenv('WHATSAPP_ACCESS_TOKEN');
            $apiUrl        = $phoneNumberId
                ? 'https://graph.facebook.com/v25.0/' . $phoneNumberId . '/messages'
                : (getenv('WHATSAPP_API_URL') ?: '');

            if (!$accessToken || !$apiUrl) {
                throw new HttpException('Sin configuración WhatsApp para esta empresa', 503);
            }

            // Enviar por WhatsApp Business API
            $payload = json_encode([
                'messaging_product' => 'whatsapp',
                'to'                => $row['phone_number'],
                'type'              => 'text',
                'text'              => ['body' => $texto],
            ]);

            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$accessToken}\r\n",
                'content'       => $payload,
                'timeout'       => 15,
                'ignore_errors' => true,
            ]]);

            set_error_handler(fn() => true, E_WARNING);
            $result = file_get_contents($apiUrl, false, $ctx);
            restore_error_handler();

            $status = 0;
            if (isset($http_response_header[0])) {
                preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
                $status = (int)($m[1] ?? 0);
            }

            if (!$result || $status >= 400) {
                $detail = $result ? substr($result, 0, 200) : 'sin respuesta';
                error_log("CRM reply WhatsApp error $status: $detail");
                throw new HttpException('Error al enviar por WhatsApp', 502);
            }

            // Guardar en historial (con respondido_por para métricas de tiempo de respuesta)
            try {
                $db->prepare(
                    'INSERT INTO whatsapp_messages (conversation_id, direction, respondido_por, message_text) VALUES (?, "OUTBOUND", ?, ?)'
                )->execute([$id, $userId, $texto]);
            } catch (\Throwable $e) {
                // respondido_por columna puede no existir aún (pre migración 061)
                $db->prepare(
                    'INSERT INTO whatsapp_messages (conversation_id, direction, message_text) VALUES (?, "OUTBOUND", ?)'
                )->execute([$id, $texto]);
            }

            $db->prepare(
                'UPDATE whatsapp_conversations SET last_activity = NOW(), estado_lead = IF(estado_lead="NUEVO","CONTACTADO",estado_lead) WHERE id = ?'
            )->execute([$id]);

            Response::success(['sent' => true]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al enviar mensaje', null, 500);
        }
    }

    // ── Etapas pipeline ───────────────────────────────────────────────────

    public function etapas(): void
    {
        try {
            [$empresaId] = $this->auth();
            $db   = Database::connection();
            $stmt = $db->prepare(
                'SELECT id, nombre, slug, color, orden, es_convertido, es_descarte, activo
                 FROM crm_etapas WHERE empresa_id = ? ORDER BY orden ASC'
            );
            $stmt->execute([$empresaId]);
            Response::success($stmt->fetchAll());
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            // Si la tabla no existe aún, devolver etapas por defecto
            Response::success(array_map(fn($s, $i) => [
                'id' => $i + 1, 'nombre' => ucfirst(strtolower($s)), 'slug' => $s,
                'color' => ['#3b82f6','#f59e0b','#8b5cf6','#10b981','#ef4444'][$i] ?? '#6366f1',
                'orden' => $i + 1, 'es_convertido' => $s === 'CONVERTIDO' ? 1 : 0,
                'es_descarte' => $s === 'DESCARTADO' ? 1 : 0, 'activo' => 1,
            ], self::ESTADOS_FALLBACK, array_keys(self::ESTADOS_FALLBACK)));
        }
    }

    public function saveEtapa(array $params = []): void
    {
        try {
            [$empresaId] = $this->auth();
            $body  = Request::json();
            $etapaId = (int)($params['etapa_id'] ?? 0);

            $db = Database::connection();

            if ($etapaId > 0) {
                // Actualizar nombre/color/orden — slug no cambia (es la PK lógica en las conversaciones)
                $sets = []; $vals = [];
                if (isset($body['nombre'])) { $sets[] = 'nombre = ?'; $vals[] = mb_substr(trim($body['nombre']), 0, 80); }
                if (isset($body['color']))  { $sets[] = 'color = ?';  $vals[] = mb_substr(trim($body['color']),  0, 20); }
                if (isset($body['orden']))  { $sets[] = 'orden = ?';  $vals[] = (int)$body['orden']; }
                if (isset($body['activo'])) { $sets[] = 'activo = ?'; $vals[] = $body['activo'] ? 1 : 0; }
                if (empty($sets)) throw new HttpException('Sin campos a actualizar', 422);
                $vals[] = $etapaId; $vals[] = $empresaId;
                $db->prepare('UPDATE crm_etapas SET ' . implode(', ', $sets) . ' WHERE id = ? AND empresa_id = ?')->execute($vals);
            } else {
                // Crear nueva etapa
                $nombre = mb_substr(trim($body['nombre'] ?? ''), 0, 80);
                $color  = mb_substr(trim($body['color']  ?? '#6366f1'), 0, 20);
                $orden  = (int)($body['orden'] ?? 99);
                $slug   = strtoupper(preg_replace('/[^A-Z0-9_]/i', '_', $nombre));
                if ($nombre === '') throw new HttpException('nombre es obligatorio', 422);
                $db->prepare(
                    'INSERT INTO crm_etapas (empresa_id, nombre, slug, color, orden) VALUES (?,?,?,?,?)'
                )->execute([$empresaId, $nombre, $slug, $color, $orden]);
                $etapaId = (int)$db->lastInsertId();
            }

            $stmt = $db->prepare('SELECT * FROM crm_etapas WHERE id = ?');
            $stmt->execute([$etapaId]);
            Response::success($stmt->fetch());
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al guardar etapa', null, 500);
        }
    }

    /** Valida que un slug exista para esta empresa (con fallback si tabla no existe). */
    private function slugEtapaValido(int $empresaId, string $slug): bool
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT 1 FROM crm_etapas WHERE empresa_id = ? AND slug = ? AND activo = 1 LIMIT 1'
            );
            $stmt->execute([$empresaId, $slug]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            // Tabla no existe aún — validar contra lista por defecto
            return in_array($slug, self::ESTADOS_FALLBACK, true);
        }
    }

    /** Autentica y valida tenancy. Retorna [empresaId, userId]. */
    private function auth(): array
    {
        $claims    = (new AuthMiddleware())->handle();
        $userId    = (int)$claims['user_id'];
        $empresaId = (int)($_GET['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            $body      = Request::json();
            $empresaId = (int)($body['empresa_id'] ?? 0);
        }
        if ($empresaId <= 0) throw new HttpException('empresa_id obligatorio', 422);
        (new TenantMiddleware())->handle($userId, $empresaId);
        return [$empresaId, $userId];
    }

    // ── Templates HSM ──────────────────────────────────────────────────────

    public function templates(): void
    {
        try {
            [$empresaId] = $this->auth();
            $db   = Database::connection();
            $stmt = $db->prepare(
                'SELECT id, nombre, template_name, idioma, categoria, header_texto, body_texto, footer_texto, variables, activo
                 FROM whatsapp_templates WHERE empresa_id = ? ORDER BY nombre ASC'
            );
            $stmt->execute([$empresaId]);
            Response::success($stmt->fetchAll());
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al obtener templates', null, 500);
        }
    }

    public function saveTemplate(array $params = []): void
    {
        try {
            [$empresaId] = $this->auth();
            $body = Request::json();
            $id   = (int)($params['id'] ?? 0);

            $nombre       = mb_substr(trim($body['nombre']        ?? ''), 0, 100);
            $tplName      = mb_substr(strtolower(trim($body['template_name'] ?? '')), 0, 100);
            $idioma       = mb_substr(trim($body['idioma']        ?? 'es'), 0, 10);
            $categoria    = $body['categoria']    ?? 'UTILITY';
            $headerTexto  = mb_substr(trim($body['header_texto']  ?? ''), 0, 255) ?: null;
            $bodyTexto    = trim($body['body_texto'] ?? '');
            $footerTexto  = mb_substr(trim($body['footer_texto']  ?? ''), 0, 255) ?: null;
            $variables    = isset($body['variables']) ? json_encode($body['variables']) : null;

            if ($nombre === '' || $tplName === '' || $bodyTexto === '') {
                throw new HttpException('nombre, template_name y body_texto son obligatorios', 422);
            }
            if (!in_array($categoria, ['MARKETING','UTILITY','AUTHENTICATION'], true)) {
                throw new HttpException('categoria inválida', 422);
            }

            $db = Database::connection();
            if ($id > 0) {
                $db->prepare(
                    'UPDATE whatsapp_templates
                     SET nombre=?, template_name=?, idioma=?, categoria=?, header_texto=?, body_texto=?, footer_texto=?, variables=?
                     WHERE id=? AND empresa_id=?'
                )->execute([$nombre, $tplName, $idioma, $categoria, $headerTexto, $bodyTexto, $footerTexto, $variables, $id, $empresaId]);
            } else {
                $db->prepare(
                    'INSERT INTO whatsapp_templates (empresa_id, nombre, template_name, idioma, categoria, header_texto, body_texto, footer_texto, variables)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$empresaId, $nombre, $tplName, $idioma, $categoria, $headerTexto, $bodyTexto, $footerTexto, $variables]);
                $id = (int)$db->lastInsertId();
            }
            $stmt = $db->prepare('SELECT * FROM whatsapp_templates WHERE id = ?');
            $stmt->execute([$id]);
            Response::success($stmt->fetch());
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al guardar template', null, 500);
        }
    }

    public function sendTemplate(array $params = []): void
    {
        try {
            [$empresaId] = $this->auth();
            $convId     = (int)($params['id'] ?? 0);
            if ($convId <= 0) throw new HttpException('ID inválido', 400);

            $body       = Request::json();
            $templateId = (int)($body['template_id'] ?? 0);
            $variables  = $body['variables'] ?? [];

            $db = Database::connection();

            // Cargar conversación
            $stmtC = $db->prepare(
                'SELECT phone_number FROM whatsapp_conversations WHERE id=? AND empresa_id=? LIMIT 1'
            );
            $stmtC->execute([$convId, $empresaId]);
            $conv = $stmtC->fetch();
            if (!$conv) throw new HttpException('Conversación no encontrada', 404);

            // Cargar template
            $stmtT = $db->prepare('SELECT * FROM whatsapp_templates WHERE id=? AND empresa_id=? AND activo=1 LIMIT 1');
            $stmtT->execute([$templateId, $empresaId]);
            $tpl = $stmtT->fetch();
            if (!$tpl) throw new HttpException('Template no encontrado', 404);

            // Config WhatsApp de la empresa
            $stmtWA = $db->prepare('SELECT phone_number_id, access_token FROM empresa_whatsapp_config WHERE empresa_id=? AND activo=1 LIMIT 1');
            $stmtWA->execute([$empresaId]);
            $wa = $stmtWA->fetch();
            if (!$wa) throw new HttpException('WhatsApp no configurado para esta empresa', 400);

            $components = [];
            if (!empty($variables)) {
                $parameters = array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], array_values($variables));
                $components[] = ['type' => 'body', 'parameters' => $parameters];
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $conv['phone_number'],
                'type'              => 'template',
                'template'          => [
                    'name'       => $tpl['template_name'],
                    'language'   => ['code' => $tpl['idioma']],
                    'components' => $components,
                ],
            ];

            $apiUrl = 'https://graph.facebook.com/v25.0/' . $wa['phone_number_id'] . '/messages';
            $ctx    = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$wa['access_token']}\r\n",
                'content'       => json_encode($payload),
                'ignore_errors' => true,
                'timeout'       => 15,
            ]]);
            $result = file_get_contents($apiUrl, false, $ctx);
            $resp   = json_decode($result ?: '{}', true);

            if (!empty($resp['error'])) {
                throw new HttpException('Meta error: ' . ($resp['error']['message'] ?? 'desconocido'), 502);
            }

            // Guardar como OUTBOUND en historial
            $db->prepare(
                'INSERT INTO whatsapp_messages (conversation_id, direction, message_text) VALUES (?, "OUTBOUND", ?)'
            )->execute([$convId, "[Template: {$tpl['nombre']}] " . $tpl['body_texto']]);

            Response::success(['sent' => true]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al enviar template', null, 500);
        }
    }

    public function syncTemplates(): void
    {
        try {
            [$empresaId] = $this->auth();
            $db = Database::connection();

            // Obtener config WhatsApp de la empresa
            $stmtWA = $db->prepare(
                'SELECT phone_number_id, access_token, waba_id FROM empresa_whatsapp_config WHERE empresa_id = ? AND activo = 1 LIMIT 1'
            );
            $stmtWA->execute([$empresaId]);
            $wa = $stmtWA->fetch();
            if (!$wa) throw new HttpException('WhatsApp no configurado para esta empresa', 400);

            $accessToken = $wa['access_token'];
            $wabaId      = $wa['waba_id'];

            // Si no tiene waba_id guardado, intentar resolverlo desde el phone_number_id
            if (!$wabaId && $wa['phone_number_id']) {
                $resolveUrl = "https://graph.facebook.com/v25.0/{$wa['phone_number_id']}?fields=whatsapp_business_account";
                $ctx = stream_context_create(['http' => [
                    'method'        => 'GET',
                    'header'        => "Authorization: Bearer {$accessToken}\r\n",
                    'timeout'       => 10,
                    'ignore_errors' => true,
                ]]);
                $res  = file_get_contents($resolveUrl, false, $ctx);
                $data = json_decode($res ?: '{}', true);
                $wabaId = $data['whatsapp_business_account']['id'] ?? null;
                if ($wabaId) {
                    $db->prepare('UPDATE empresa_whatsapp_config SET waba_id = ? WHERE empresa_id = ?')
                       ->execute([$wabaId, $empresaId]);
                }
            }

            if (!$wabaId) throw new HttpException('No se pudo obtener el WABA ID. Ingrésalo manualmente en la configuración.', 400);

            // Obtener templates desde Meta
            $url = "https://graph.facebook.com/v25.0/{$wabaId}/message_templates?limit=100&fields=name,language,category,status,components";
            $ctx = stream_context_create(['http' => [
                'method'        => 'GET',
                'header'        => "Authorization: Bearer {$accessToken}\r\n",
                'timeout'       => 20,
                'ignore_errors' => true,
            ]]);
            $res  = file_get_contents($url, false, $ctx);
            $data = json_decode($res ?: '{}', true);

            if (!empty($data['error'])) {
                throw new HttpException('Meta error: ' . ($data['error']['message'] ?? 'desconocido'), 502);
            }

            $templates = $data['data'] ?? [];
            $importados = 0; $actualizados = 0;

            foreach ($templates as $tpl) {
                if (($tpl['status'] ?? '') !== 'APPROVED') continue;

                $nombre      = $tpl['name'];
                $idioma      = $tpl['language'] ?? 'es';
                $categoria   = $tpl['category'] ?? 'UTILITY';
                $headerTexto = null; $bodyTexto = ''; $footerTexto = null;
                $variables   = [];

                foreach ($tpl['components'] ?? [] as $comp) {
                    $type = strtoupper($comp['type'] ?? '');
                    $text = $comp['text'] ?? '';
                    if ($type === 'HEADER') $headerTexto = $text;
                    if ($type === 'BODY')   { $bodyTexto = $text; }
                    if ($type === 'FOOTER') $footerTexto = $text;
                }

                // Extraer variables {{1}}, {{2}}... del body
                preg_match_all('/\{\{(\d+)\}\}/', $bodyTexto, $matches);
                if (!empty($matches[1])) {
                    $variables = array_map(fn($n) => "variable_{$n}", array_unique($matches[1]));
                }

                if ($bodyTexto === '') continue;

                // Upsert por (empresa_id, template_name)
                $check = $db->prepare(
                    'SELECT id FROM whatsapp_templates WHERE empresa_id = ? AND template_name = ? LIMIT 1'
                );
                $check->execute([$empresaId, $nombre]);
                $existingId = $check->fetchColumn();

                if ($existingId) {
                    $db->prepare(
                        'UPDATE whatsapp_templates SET nombre=?, idioma=?, categoria=?, header_texto=?, body_texto=?, footer_texto=?, variables=?, activo=1
                         WHERE id=?'
                    )->execute([
                        $nombre, $idioma, $categoria, $headerTexto, $bodyTexto, $footerTexto,
                        $variables ? json_encode($variables) : null,
                        $existingId,
                    ]);
                    $actualizados++;
                } else {
                    $db->prepare(
                        'INSERT INTO whatsapp_templates (empresa_id, nombre, template_name, idioma, categoria, header_texto, body_texto, footer_texto, variables)
                         VALUES (?,?,?,?,?,?,?,?,?)'
                    )->execute([
                        $empresaId, $nombre, $nombre, $idioma, $categoria,
                        $headerTexto, $bodyTexto, $footerTexto,
                        $variables ? json_encode($variables) : null,
                    ]);
                    $importados++;
                }
            }

            $totalAprobados = count(array_filter($templates, fn($t) => ($t['status'] ?? '') === 'APPROVED'));

            Response::success([
                'importados'   => $importados,
                'actualizados' => $actualizados,
                'total_meta'   => count($templates),
                'aprobados'    => $totalAprobados,
                'waba_id'      => $wabaId,
            ]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al sincronizar templates', null, 500);
        }
    }

    // ── Broadcast ──────────────────────────────────────────────────────────

    public function broadcasts(): void
    {
        try {
            [$empresaId] = $this->auth();
            $db   = Database::connection();
            $stmt = $db->prepare(
                'SELECT b.id, b.titulo, b.estado, b.total, b.enviados, b.errores, b.created_at,
                        t.nombre AS template_nombre
                 FROM crm_broadcasts b
                 JOIN whatsapp_templates t ON t.id = b.template_id
                 WHERE b.empresa_id = ? ORDER BY b.created_at DESC LIMIT 50'
            );
            $stmt->execute([$empresaId]);
            Response::success($stmt->fetchAll());
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al obtener campañas', null, 500);
        }
    }

    public function createBroadcast(): void
    {
        try {
            [$empresaId, $userId] = $this->auth();
            $body       = Request::json();
            $templateId = (int)($body['template_id'] ?? 0);
            $titulo     = mb_substr(trim($body['titulo'] ?? ''), 0, 255);
            $variables  = $body['variables'] ?? [];
            $filtroEst  = $body['filtro_estado'] ?? null;
            $filtroTipo = $body['filtro_tipo']   ?? null;

            if ($templateId <= 0 || $titulo === '') throw new HttpException('template_id y titulo son obligatorios', 422);

            $db = Database::connection();

            // Verificar template
            $stmtT = $db->prepare('SELECT id FROM whatsapp_templates WHERE id=? AND empresa_id=? AND activo=1 LIMIT 1');
            $stmtT->execute([$templateId, $empresaId]);
            if (!$stmtT->fetchColumn()) throw new HttpException('Template no encontrado', 404);

            // Config WhatsApp
            $stmtWA = $db->prepare('SELECT phone_number_id, access_token FROM empresa_whatsapp_config WHERE empresa_id=? AND activo=1 LIMIT 1');
            $stmtWA->execute([$empresaId]);
            $wa = $stmtWA->fetch();
            if (!$wa) throw new HttpException('WhatsApp no configurado', 400);

            // Calcular destinatarios
            $whereR = ['wc.empresa_id = ? AND wc.estado_lead != "DESCARTADO"'];
            $rParams = [$empresaId];
            if ($filtroEst)  { $whereR[] = 'wc.estado_lead = ?';   $rParams[] = $filtroEst; }
            if ($filtroTipo) { $whereR[] = 'wc.tipo_contacto = ?'; $rParams[] = $filtroTipo; }

            $stmtR = $db->prepare(
                'SELECT wc.id, wc.phone_number FROM whatsapp_conversations wc WHERE ' . implode(' AND ', $whereR) . ' LIMIT 500'
            );
            $stmtR->execute($rParams);
            $destinatarios = $stmtR->fetchAll();

            if (empty($destinatarios)) throw new HttpException('Sin destinatarios para los filtros seleccionados', 422);

            // Crear broadcast
            $db->prepare(
                'INSERT INTO crm_broadcasts (empresa_id, template_id, titulo, variables_json, filtro_estado, filtro_tipo, total, estado, creado_por, iniciado_at)
                 VALUES (?,?,?,?,?,?,?,"ENVIANDO",?,NOW())'
            )->execute([$empresaId, $templateId, $titulo, json_encode($variables), $filtroEst, $filtroTipo, count($destinatarios), $userId]);
            $broadcastId = (int)$db->lastInsertId();

            // Cargar template completo para envío
            $stmtTpl = $db->prepare('SELECT * FROM whatsapp_templates WHERE id=?');
            $stmtTpl->execute([$templateId]);
            $tpl = $stmtTpl->fetch();

            $enviados = 0; $errores = 0;
            $components = [];
            if (!empty($variables)) {
                $parameters = array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], array_values($variables));
                $components[] = ['type' => 'body', 'parameters' => $parameters];
            }

            $apiUrl = 'https://graph.facebook.com/v25.0/' . $wa['phone_number_id'] . '/messages';

            foreach ($destinatarios as $dest) {
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $dest['phone_number'],
                    'type'              => 'template',
                    'template'          => [
                        'name'       => $tpl['template_name'],
                        'language'   => ['code' => $tpl['idioma']],
                        'components' => $components,
                    ],
                ];
                $ctx = stream_context_create(['http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$wa['access_token']}\r\n",
                    'content'       => json_encode($payload),
                    'ignore_errors' => true,
                    'timeout'       => 10,
                ]]);
                $res  = file_get_contents($apiUrl, false, $ctx);
                $resp = json_decode($res ?: '{}', true);
                $ok   = empty($resp['error']);

                $db->prepare(
                    'INSERT INTO crm_broadcast_recipients (broadcast_id, conversation_id, estado, error_detalle, enviado_at)
                     VALUES (?,?,?,?,?)'
                )->execute([
                    $broadcastId,
                    $dest['id'],
                    $ok ? 'ENVIADO' : 'ERROR',
                    $ok ? null : ($resp['error']['message'] ?? 'error desconocido'),
                    $ok ? date('Y-m-d H:i:s') : null,
                ]);

                if ($ok) {
                    $enviados++;
                    // Guardar en historial de mensajes
                    $db->prepare(
                        'INSERT INTO whatsapp_messages (conversation_id, direction, message_text) VALUES (?, "OUTBOUND", ?)'
                    )->execute([$dest['id'], "[Broadcast: {$tpl['nombre']}] " . $tpl['body_texto']]);
                } else {
                    $errores++;
                }

                // Pausa de 50ms entre mensajes (respetar rate limit de Meta: ~80 msg/min)
                usleep(50000);
            }

            $db->prepare(
                'UPDATE crm_broadcasts SET estado="COMPLETADO", enviados=?, errores=?, completado_at=NOW() WHERE id=?'
            )->execute([$enviados, $errores, $broadcastId]);

            Response::success([
                'broadcast_id' => $broadcastId,
                'total'        => count($destinatarios),
                'enviados'     => $enviados,
                'errores'      => $errores,
            ]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al crear campaña', null, 500);
        }
    }

    public function agentes(): void
    {
        try {
            [$empresaId] = $this->auth();
            $db   = Database::connection();
            $stmt = $db->prepare(
                'SELECT u.id, u.nombre, u.email,
                        COUNT(CASE WHEN wc.estado_lead NOT IN ("CONVERTIDO","DESCARTADO") THEN 1 END) AS conversaciones_activas
                 FROM empresa_usuarios eu
                 JOIN usuarios u ON u.id = eu.usuario_id
                 LEFT JOIN whatsapp_conversations wc ON wc.asignado_usuario_id = u.id AND wc.empresa_id = eu.empresa_id
                 WHERE eu.empresa_id = ? AND eu.activo = 1
                 GROUP BY u.id, u.nombre, u.email'
            );
            $stmt->execute([$empresaId]);
            Response::success($stmt->fetchAll());
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al obtener agentes', null, 500);
        }
    }
}
