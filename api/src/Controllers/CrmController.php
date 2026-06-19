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
    private const ESTADOS_VALIDOS = ['NUEVO', 'CONTACTADO', 'CALIFICADO', 'CONVERTIDO', 'DESCARTADO'];

    public function index(): void
    {
        try {
            [$empresaId] = $this->auth();

            $tipo   = trim($_GET['tipo']   ?? '');
            $estado = trim($_GET['estado'] ?? '');
            $q      = trim($_GET['q']      ?? '');
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $limit  = 40;
            $offset = ($page - 1) * $limit;

            $where  = ['(wc.empresa_id = ? OR (wc.empresa_id IS NULL AND ? IS NULL))'];
            $params = [$empresaId, $empresaId];

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
                    COUNT(wm.id) AS mensaje_count
                FROM whatsapp_conversations wc
                LEFT JOIN whatsapp_messages wm ON wm.conversation_id = wc.id
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
                'SELECT * FROM whatsapp_conversations WHERE id = ? AND (empresa_id = ? OR empresa_id IS NULL) LIMIT 1'
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
                if (!in_array($body['estado_lead'], self::ESTADOS_VALIDOS, true)) {
                    throw new HttpException('estado_lead inválido', 422);
                }
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

            if (empty($sets)) throw new HttpException('Sin campos a actualizar', 422);

            $values[] = $id;
            $values[] = $empresaId;

            $stmt = Database::connection()->prepare(
                'UPDATE whatsapp_conversations SET ' . implode(', ', $sets) .
                ' WHERE id = ? AND (empresa_id = ? OR empresa_id IS NULL)'
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
                 WHERE id = ? AND (empresa_id = ? OR empresa_id IS NULL) LIMIT 1'
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
                 WHERE id = ? AND (empresa_id = ? OR empresa_id IS NULL) LIMIT 1'
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
                 WHERE (empresa_id = ? OR empresa_id IS NULL)
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
                 WHERE (empresa_id = ? OR empresa_id IS NULL)'
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
                   AND (wc.empresa_id = ? OR wc.empresa_id IS NULL)
                 GROUP BY wm.intent
                 ORDER BY total DESC
                 LIMIT 6'
            );
            $intentsStmt->execute([$empresaId]);

            $mensajesStmt = $db->prepare(
                'SELECT COUNT(*) FROM whatsapp_messages wm
                 JOIN whatsapp_conversations wc ON wc.id = wm.conversation_id
                 WHERE (wc.empresa_id = ? OR wc.empresa_id IS NULL)'
            );
            $mensajesStmt->execute([$empresaId]);

            Response::success([
                'funnel'        => $funnel,
                'total'         => $total,
                'hoy'           => (int)($meta['hoy'] ?? 0),
                'semana'        => (int)($meta['semana'] ?? 0),
                'mes'           => (int)($meta['mes'] ?? 0),
                'convertidos'   => (int)($meta['convertidos'] ?? 0),
                'no_leidos'     => (int)($meta['no_leidos'] ?? 0),
                'tasa_conversion' => $conversion,
                'mensajes_total' => (int)$mensajesStmt->fetchColumn(),
                'top_intents'   => $intentsStmt->fetchAll(),
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
                'SELECT 1 FROM whatsapp_conversations WHERE id = ? AND (empresa_id = ? OR empresa_id IS NULL) LIMIT 1'
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
                 WHERE t.id = ? AND (wc.empresa_id = ? OR wc.empresa_id IS NULL)'
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
                 WHERE (empresa_id = ? OR empresa_id IS NULL)"
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
            [$empresaId] = $this->auth();

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
                WHERE wc.id = ? AND (wc.empresa_id = ? OR wc.empresa_id IS NULL)
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

            // Guardar en historial
            $db->prepare(
                'INSERT INTO whatsapp_messages (conversation_id, direction, message_text) VALUES (?, "OUTBOUND", ?)'
            )->execute([$id, $texto]);

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
}
