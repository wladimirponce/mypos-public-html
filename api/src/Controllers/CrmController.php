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

    public function count(): void
    {
        try {
            [$empresaId] = $this->auth();
            $db   = Database::connection();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM whatsapp_conversations
                 WHERE (empresa_id = ? OR empresa_id IS NULL) AND estado_lead = 'NUEVO'"
            );
            $stmt->execute([$empresaId]);
            Response::success(['nuevos' => (int)$stmt->fetchColumn()]);
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
