<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class CorreoMensajeRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function syncState(int $cuentaId, string $carpeta = 'inbox'): array
    {
        $statement = $this->connection->prepare(
            'SELECT uid_validity, last_uid, last_sync_at
             FROM correo_sync_estado
             WHERE cuenta_id = :cuenta_id AND carpeta = :carpeta
             LIMIT 1'
        );
        $statement->execute(['cuenta_id' => $cuentaId, 'carpeta' => $carpeta]);
        $row = $statement->fetch();

        return is_array($row) ? $row : ['uid_validity' => null, 'last_uid' => 0, 'last_sync_at' => null];
    }

    public function saveSyncState(int $cuentaId, string $carpeta, ?int $uidValidity, int $lastUid): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO correo_sync_estado (cuenta_id, carpeta, uid_validity, last_uid, last_sync_at)
             VALUES (:cuenta_id, :carpeta, :uid_validity, :last_uid, NOW())
             ON DUPLICATE KEY UPDATE
                uid_validity = VALUES(uid_validity),
                last_uid = GREATEST(last_uid, VALUES(last_uid)),
                last_sync_at = NOW()'
        );
        $statement->execute([
            'cuenta_id' => $cuentaId,
            'carpeta' => $carpeta,
            'uid_validity' => $uidValidity,
            'last_uid' => $lastUid,
        ]);
    }

    /**
     * Borra el estado y los mensajes de una carpeta cuando cambia UIDVALIDITY
     * (los UID dejan de ser estables y hay que resincronizar desde cero).
     */
    public function resetCarpeta(int $cuentaId, string $carpeta): void
    {
        $delMensajes = $this->connection->prepare(
            'DELETE FROM correo_mensajes WHERE cuenta_id = :cuenta_id AND carpeta = :carpeta'
        );
        $delMensajes->execute(['cuenta_id' => $cuentaId, 'carpeta' => $carpeta]);

        $delEstado = $this->connection->prepare(
            'DELETE FROM correo_sync_estado WHERE cuenta_id = :cuenta_id AND carpeta = :carpeta'
        );
        $delEstado->execute(['cuenta_id' => $cuentaId, 'carpeta' => $carpeta]);
    }

    public function upsertMensaje(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO correo_mensajes (
                empresa_id, cuenta_id, uid, carpeta, message_id, in_reply_to, referencias,
                remitente, remitente_nombre, destinatarios, cc, asunto, snippet,
                body_text, body_html, fecha, seen, flagged, tiene_adjuntos, tamano
             ) VALUES (
                :empresa_id, :cuenta_id, :uid, :carpeta, :message_id, :in_reply_to, :referencias,
                :remitente, :remitente_nombre, :destinatarios, :cc, :asunto, :snippet,
                :body_text, :body_html, :fecha, :seen, :flagged, :tiene_adjuntos, :tamano
             )
             ON DUPLICATE KEY UPDATE
                seen = VALUES(seen),
                flagged = VALUES(flagged),
                snippet = VALUES(snippet),
                body_text = VALUES(body_text),
                body_html = VALUES(body_html),
                updated_at = NOW()'
        );
        $statement->execute([
            'empresa_id' => $data['empresa_id'],
            'cuenta_id' => $data['cuenta_id'],
            'uid' => $data['uid'],
            'carpeta' => $data['carpeta'] ?? 'inbox',
            'message_id' => $data['message_id'] ?? null,
            'in_reply_to' => $data['in_reply_to'] ?? null,
            'referencias' => $data['referencias'] ?? null,
            'remitente' => $data['remitente'] ?? null,
            'remitente_nombre' => $data['remitente_nombre'] ?? null,
            'destinatarios' => $data['destinatarios'] ?? null,
            'cc' => $data['cc'] ?? null,
            'asunto' => $data['asunto'] ?? null,
            'snippet' => $data['snippet'] ?? null,
            'body_text' => $data['body_text'] ?? null,
            'body_html' => $data['body_html'] ?? null,
            'fecha' => $data['fecha'] ?? null,
            'seen' => $data['seen'] ?? 0,
            'flagged' => $data['flagged'] ?? 0,
            'tiene_adjuntos' => $data['tiene_adjuntos'] ?? 0,
            'tamano' => $data['tamano'] ?? null,
        ]);
    }

    /**
     * Listado paginado de una carpeta leyendo desde BD (sin tocar IMAP).
     */
    public function paginar(int $empresaId, string $carpeta, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $count = $this->connection->prepare(
            'SELECT COUNT(*) FROM correo_mensajes WHERE empresa_id = :empresa_id AND carpeta = :carpeta'
        );
        $count->execute(['empresa_id' => $empresaId, 'carpeta' => $carpeta]);
        $total = (int) $count->fetchColumn();

        $statement = $this->connection->prepare(
            'SELECT id, uid, message_id, remitente, remitente_nombre, destinatarios,
                    asunto, snippet, fecha, seen, flagged, tiene_adjuntos
             FROM correo_mensajes
             WHERE empresa_id = :empresa_id AND carpeta = :carpeta
             ORDER BY fecha DESC, uid DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('empresa_id', $empresaId, PDO::PARAM_INT);
        $statement->bindValue('carpeta', $carpeta, PDO::PARAM_STR);
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $items = $statement->fetchAll();

        return [
            'items' => is_array($items) ? $items : [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    public function maxUid(int $cuentaId, string $carpeta): int
    {
        $statement = $this->connection->prepare(
            'SELECT COALESCE(MAX(uid), 0) FROM correo_mensajes WHERE cuenta_id = :cuenta_id AND carpeta = :carpeta'
        );
        $statement->execute(['cuenta_id' => $cuentaId, 'carpeta' => $carpeta]);

        return (int) $statement->fetchColumn();
    }
}
