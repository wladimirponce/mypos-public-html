<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use PDO;

/**
 * Registro de consultas no resueltas del agente IA (bandeja "Consultas IA").
 *
 * El agente Python llama a POST /api/v1/agente/consultas-log cuando una
 * consulta no pudo responderse; admin revisa/aprueba directamente sobre la
 * tabla (misma BD). Reemplaza al archivo agent/tmp/agent_unanswered.txt,
 * que dos procesos reescribian completo sin lock compartido.
 *
 * empresa_id llega YA validado por AuthMiddleware + TenantMiddleware en el
 * controller: este servicio nunca lo toma del body.
 */
final class AgenteConsultasLogService
{
    private PDO $connection;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection ?? Database::connection();
    }

    /**
     * @param array<string, mixed> $payload cuerpo enviado por el agente
     * @return array{id: int, uid: string}
     */
    public function registrar(int $empresaId, ?int $sucursalId, array $payload): array
    {
        $uid = trim((string) ($payload['uid'] ?? ''));
        if ($uid === '') {
            $uid = bin2hex(random_bytes(16));
        }

        $propuesta = $payload['propuesta'] ?? null;
        $propuestaJson = is_array($propuesta)
            ? json_encode($propuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        // INSERT IGNORE via ON DUPLICATE: si el agente reintenta con el mismo
        // uid (p. ej. tras timeout de red), no duplica la entrada.
        $stmt = $this->connection->prepare(
            'INSERT INTO agente_consultas_log
                (uid, empresa_id, sucursal_id, thread_id, operador, source, status,
                 consulta, respuesta, propuesta)
             VALUES
                (:uid, :empresa_id, :sucursal_id, :thread_id, :operador, :source, :status,
                 :consulta, :respuesta, :propuesta)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );

        $stmt->execute([
            ':uid' => mb_substr($uid, 0, 64),
            ':empresa_id' => $empresaId,
            ':sucursal_id' => $sucursalId,
            ':thread_id' => mb_substr(trim((string) ($payload['thread_id'] ?? '')), 0, 200),
            ':operador' => mb_substr(trim((string) ($payload['operador'] ?? '')), 0, 200),
            ':source' => 'agent',
            ':status' => 'pendiente',
            ':consulta' => mb_substr((string) ($payload['consulta'] ?? ''), 0, 4000),
            ':respuesta' => mb_substr((string) ($payload['respuesta'] ?? ''), 0, 4000),
            ':propuesta' => $propuestaJson,
        ]);

        $id = (int) $this->connection->lastInsertId();

        return ['id' => $id, 'uid' => $uid];
    }
}
