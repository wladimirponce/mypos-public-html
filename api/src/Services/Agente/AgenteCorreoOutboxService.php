<?php

declare(strict_types=1);

namespace Mypos\Services\Agente;

use Mypos\Config\Database;
use Mypos\Services\MailService;
use PDO;
use Throwable;

final class AgenteCorreoOutboxService
{
    private const MAX_INTENTOS = 5;

    public function __construct(private ?PDO $db = null, private ?MailService $mailService = null)
    {
        $this->db ??= Database::connection();
    }

    public function encolar(int $empresaId, string $destinatario, string $razonSocial, string $asunto, string $html, string $intencion, string $motivo): int
    {
        if ($empresaId <= 0 || filter_var($destinatario, FILTER_VALIDATE_EMAIL) === false) {
            return 0;
        }
        $stmt = $this->db->prepare(
            "INSERT INTO agente_correos_outbox
                (empresa_id,destinatario,razon_social,asunto,html,intencion,motivo,estado,proximo_intento_at)
             VALUES (:empresa_id,:destinatario,:razon_social,:asunto,:html,:intencion,:motivo,'pendiente',NOW())"
        );
        $stmt->execute([
            'empresa_id' => $empresaId,
            'destinatario' => mb_substr($destinatario, 0, 190),
            'razon_social' => mb_substr($razonSocial, 0, 190),
            'asunto' => mb_substr($asunto, 0, 190),
            'html' => $html,
            'intencion' => mb_substr($intencion, 0, 80),
            'motivo' => mb_substr($motivo, 0, 500),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array{procesados:int,enviados:int,reintentados:int,fallidos:int} */
    public function procesarPendientes(int $limite = 25): array
    {
        $limite = max(1, min($limite, 100));
        $ids = $this->db->query(
            "SELECT id FROM agente_correos_outbox
             WHERE (estado='pendiente' AND (proximo_intento_at IS NULL OR proximo_intento_at<=NOW()))
                OR (estado='procesando' AND locked_at<DATE_SUB(NOW(), INTERVAL 15 MINUTE))
             ORDER BY id LIMIT {$limite}"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $resumen = ['procesados' => 0, 'enviados' => 0, 'reintentados' => 0, 'fallidos' => 0];

        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if (!$this->reclamar($id)) continue;
            $resumen['procesados']++;
            $correo = $this->buscar($id);
            if ($correo === null) continue;

            $mail = $this->mailService ?? new MailService();
            $ok = $mail->enviarAlertaAgenteEncolada(
                (string) $correo['destinatario'],
                (string) $correo['razon_social'],
                (string) $correo['asunto'],
                (string) $correo['html'],
            );
            if ($ok) {
                $this->marcarEnviado($id);
                $resumen['enviados']++;
                continue;
            }

            $intentos = (int) $correo['intentos'];
            if ($intentos >= self::MAX_INTENTOS) {
                $this->marcarFallido($id, 'El servidor de correo rechazo el envio');
                $resumen['fallidos']++;
            } else {
                $this->programarReintento($id, $intentos, 'El servidor de correo rechazo el envio');
                $resumen['reintentados']++;
            }
        }
        return $resumen;
    }

    private function reclamar(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE agente_correos_outbox SET estado='procesando',locked_at=NOW(),intentos=intentos+1
             WHERE id=:id AND ((estado='pendiente' AND (proximo_intento_at IS NULL OR proximo_intento_at<=NOW()))
                OR (estado='procesando' AND locked_at<DATE_SUB(NOW(), INTERVAL 15 MINUTE)))"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    private function buscar(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM agente_correos_outbox WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function marcarEnviado(int $id): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE agente_correos_outbox SET estado='enviado',enviado_at=NOW(),locked_at=NULL,ultimo_error=NULL WHERE id=:id");
            $stmt->execute(['id' => $id]);
            $stmt = $this->db->prepare(
                "UPDATE agente_alertas_log
                 SET estado='enviada',
                     canal=CASE WHEN canal LIKE '%whatsapp%' THEN 'email+whatsapp' ELSE 'email' END
                 WHERE correo_outbox_id=:outbox_id"
            );
            $stmt->execute(['outbox_id' => $id]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function programarReintento(int $id, int $intentos, string $error): void
    {
        $minutos = min(60, (2 ** max(0, $intentos - 1)) * 5);
        $stmt = $this->db->prepare("UPDATE agente_correos_outbox SET estado='pendiente',proximo_intento_at=:proximo,locked_at=NULL,ultimo_error=:error WHERE id=:id");
        $stmt->execute([
            'id' => $id,
            'proximo' => date('Y-m-d H:i:s', time() + $minutos * 60),
            'error' => mb_substr($error, 0, 500),
        ]);
    }

    private function marcarFallido(int $id, string $error): void
    {
        $stmt = $this->db->prepare("UPDATE agente_correos_outbox SET estado='fallido',locked_at=NULL,ultimo_error=:error WHERE id=:id");
        $stmt->execute(['id' => $id, 'error' => mb_substr($error, 0, 500)]);
        $stmt = $this->db->prepare(
            "UPDATE agente_alertas_log
             SET estado='fallida',
                 canal=CASE WHEN canal LIKE '%whatsapp%' THEN 'email+whatsapp' ELSE 'email' END
             WHERE correo_outbox_id=:outbox_id"
        );
        $stmt->execute(['outbox_id' => $id]);
    }
}
