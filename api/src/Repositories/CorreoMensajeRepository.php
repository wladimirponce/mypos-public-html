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
     * Soporta busqueda (asunto/cuerpo/remitente/destinatarios) y orden.
     *
     * @param array{q?:string, sort?:string, order?:string} $options
     */
    public function paginar(int $empresaId, string $carpeta, int $page, int $perPage, array $options = []): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $q = trim((string) ($options['q'] ?? ''));
        $where = 'empresa_id = :empresa_id AND carpeta = :carpeta';
        $params = ['empresa_id' => $empresaId, 'carpeta' => $carpeta];
        if ($q !== '') {
            // LIKE sobre las mismas columnas indexadas en FULLTEXT; robusto para
            // terminos cortos y sin depender del umbral de palabra de InnoDB.
            $where .= ' AND (asunto LIKE :q OR body_text LIKE :q OR remitente LIKE :q OR destinatarios LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        $count = $this->connection->prepare('SELECT COUNT(*) FROM correo_mensajes WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sortColumn = match ((string) ($options['sort'] ?? 'fecha')) {
            'asunto' => 'asunto',
            'remitente' => 'remitente',
            default => 'fecha',
        };
        $sortOrder = strtolower((string) ($options['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $statement = $this->connection->prepare(
            'SELECT id, uid, message_id, remitente, remitente_nombre, destinatarios,
                    asunto, snippet, fecha, seen, flagged, tiene_adjuntos
             FROM correo_mensajes
             WHERE ' . $where . '
             ORDER BY ' . $sortColumn . ' ' . $sortOrder . ', uid DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, $key === 'empresa_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
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

    /**
     * Detalle completo de un mensaje (incluye cuerpo) desde BD.
     */
    public function find(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, cuenta_id, uid, carpeta, message_id, remitente, remitente_nombre,
                    destinatarios, cc, asunto, body_text, body_html, fecha, seen, flagged
             FROM correo_mensajes
             WHERE empresa_id = :empresa_id AND id = :id
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function updateSeen(int $empresaId, int $id, bool $seen): void
    {
        $statement = $this->connection->prepare(
            'UPDATE correo_mensajes SET seen = :seen WHERE empresa_id = :empresa_id AND id = :id'
        );
        $statement->execute(['seen' => $seen ? 1 : 0, 'empresa_id' => $empresaId, 'id' => $id]);
    }

    public function moverCarpeta(int $empresaId, int $id, string $carpeta): void
    {
        $statement = $this->connection->prepare(
            'UPDATE correo_mensajes SET carpeta = :carpeta WHERE empresa_id = :empresa_id AND id = :id'
        );
        $statement->execute(['carpeta' => $carpeta, 'empresa_id' => $empresaId, 'id' => $id]);
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
