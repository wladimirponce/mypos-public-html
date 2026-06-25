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
                body_html = VALUES(body_html)'
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

    // ---------------------------------------------------------------
    // Hilos (Sprint 2)
    // ---------------------------------------------------------------

    /**
     * Busca el hilo de algun mensaje existente cuyo message_id este en la lista
     * de referencias (in-reply-to / references) del mensaje entrante.
     *
     * @param array<int, string> $messageIds
     */
    public function hiloIdPorReferencias(int $cuentaId, array $messageIds): ?int
    {
        $messageIds = array_values(array_filter(array_map('trim', $messageIds), static fn (string $v): bool => $v !== ''));
        if ($messageIds === []) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $statement = $this->connection->prepare(
            'SELECT hilo_id FROM correo_mensajes
             WHERE cuenta_id = ? AND message_id IN (' . $placeholders . ') AND hilo_id IS NOT NULL
             LIMIT 1'
        );
        $statement->execute(array_merge([$cuentaId], $messageIds));
        $value = $statement->fetchColumn();

        return $value !== false && $value !== null ? (int) $value : null;
    }

    public function hiloIdPorAsunto(int $cuentaId, string $asuntoNormalizado): ?int
    {
        if ($asuntoNormalizado === '') {
            return null;
        }
        $statement = $this->connection->prepare(
            'SELECT id FROM correo_hilos
             WHERE cuenta_id = :cuenta_id AND asunto_normalizado = :asunto
             ORDER BY id ASC LIMIT 1'
        );
        $statement->execute(['cuenta_id' => $cuentaId, 'asunto' => $asuntoNormalizado]);
        $value = $statement->fetchColumn();

        return $value !== false && $value !== null ? (int) $value : null;
    }

    public function crearHilo(int $empresaId, int $cuentaId, string $asuntoNormalizado, ?string $rootMessageId, ?string $fecha): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO correo_hilos (empresa_id, cuenta_id, asunto_normalizado, root_message_id, ultimo_mensaje_fecha)
             VALUES (:empresa_id, :cuenta_id, :asunto, :root, :fecha)'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'cuenta_id' => $cuentaId,
            'asunto' => $asuntoNormalizado,
            'root' => $rootMessageId,
            'fecha' => $fecha,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function asignarHilo(int $cuentaId, int $uid, string $carpeta, int $hiloId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE correo_mensajes SET hilo_id = :hilo_id
             WHERE cuenta_id = :cuenta_id AND uid = :uid AND carpeta = :carpeta'
        );
        $statement->execute(['hilo_id' => $hiloId, 'cuenta_id' => $cuentaId, 'uid' => $uid, 'carpeta' => $carpeta]);
    }

    /**
     * Recalcula totales del hilo a partir de sus mensajes de la carpeta entrada.
     */
    public function recalcularHilo(int $hiloId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE correo_hilos h
             SET total_mensajes = (SELECT COUNT(*) FROM correo_mensajes m WHERE m.hilo_id = h.id),
                 no_leidos = (SELECT COUNT(*) FROM correo_mensajes m WHERE m.hilo_id = h.id AND m.seen = 0),
                 ultimo_mensaje_fecha = (SELECT MAX(m.fecha) FROM correo_mensajes m WHERE m.hilo_id = h.id)
             WHERE h.id = :hilo_id'
        );
        $statement->execute(['hilo_id' => $hiloId]);
    }

    /**
     * Mensajes aun sin hilo asignado (para backfill), de mas antiguo a mas nuevo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mensajesSinHilo(int $empresaId): array
    {
        $statement = $this->connection->prepare(
            'SELECT cuenta_id, uid, carpeta, message_id, in_reply_to, referencias, asunto, fecha
             FROM correo_mensajes
             WHERE empresa_id = :empresa_id AND hilo_id IS NULL
             ORDER BY fecha ASC, uid ASC'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Listado paginado de hilos (conversaciones) de una carpeta, con datos del
     * ultimo mensaje. Soporta busqueda por asunto/remitente del ultimo mensaje.
     */
    public function paginarHilos(int $empresaId, string $carpeta, int $page, int $perPage, array $options = []): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $q = trim((string) ($options['q'] ?? ''));
        $params = ['empresa_id' => $empresaId, 'carpeta' => $carpeta];
        $filtroBusqueda = '';
        if ($q !== '') {
            $filtroBusqueda = ' AND (m.asunto LIKE :q OR m.body_text LIKE :q OR m.remitente LIKE :q OR m.destinatarios LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        $grupo = (string) ($options['grupo'] ?? '');
        if (in_array($grupo, ['proveedor', 'cliente', 'banco', 'otro'], true)) {
            $filtroBusqueda .= ' AND EXISTS (SELECT 1 FROM correo_contactos c
                WHERE c.empresa_id = m.empresa_id AND c.email = m.remitente AND c.tipo = :grupo)';
            $params['grupo'] = $grupo;
        }

        $estado = (string) ($options['estado'] ?? '');
        if (in_array($estado, ['pendiente', 'esperando', 'resuelto'], true)) {
            $filtroBusqueda .= ' AND EXISTS (SELECT 1 FROM correo_hilos he
                WHERE he.id = m.hilo_id AND he.estado = :estado)';
            $params['estado'] = $estado;
        }

        // Cada hilo en una carpeta se representa por su mensaje MAS RECIENTE alli.
        // Seleccionamos esa fila directamente con una subconsulta correlacionada,
        // evitando GROUP BY/GROUP_CONCAT.
        $where =
            'm.empresa_id = :empresa_id AND m.carpeta = :carpeta
             AND m.hilo_id IS NOT NULL
             AND m.id = (
                SELECT m2.id FROM correo_mensajes m2
                WHERE m2.hilo_id = m.hilo_id AND m2.carpeta = m.carpeta
                ORDER BY m2.fecha DESC, m2.uid DESC
                LIMIT 1
             )' . $filtroBusqueda;

        $countStmt = $this->connection->prepare('SELECT COUNT(*) FROM correo_mensajes m WHERE ' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql =
            'SELECT m.hilo_id, m.id, m.asunto,
                    COALESCE(m.remitente_nombre, m.remitente) AS remitente,
                    m.destinatarios, m.snippet, m.fecha,
                    h.total_mensajes, h.no_leidos, h.estado
             FROM correo_mensajes m
             INNER JOIN correo_hilos h ON h.id = m.hilo_id
             WHERE ' . $where . '
             ORDER BY m.fecha DESC
             LIMIT :limit OFFSET :offset';

        $statement = $this->connection->prepare($sql);
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
     * Todos los mensajes de un hilo (conversacion completa), de mas antiguo a mas nuevo.
     */
    public function mensajesDeHilo(int $empresaId, int $hiloId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, uid, carpeta, remitente, remitente_nombre, destinatarios, cc,
                    asunto, body_text, body_html, fecha, seen, flagged
             FROM correo_mensajes
             WHERE empresa_id = :empresa_id AND hilo_id = :hilo_id
             ORDER BY fecha ASC, uid ASC'
        );
        $statement->execute(['empresa_id' => $empresaId, 'hilo_id' => $hiloId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    // ---------------------------------------------------------------
    // IA: resumenes + candidatos para busqueda contextual (Sprint 4)
    // ---------------------------------------------------------------

    public function resumenCacheado(int $empresaId, int $hiloId, string $hashContenido): ?string
    {
        $statement = $this->connection->prepare(
            'SELECT resumen FROM correo_resumenes_ia
             WHERE empresa_id = :empresa_id AND hilo_id = :hilo_id AND hash_contenido = :hash
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'hilo_id' => $hiloId, 'hash' => $hashContenido]);
        $value = $statement->fetchColumn();

        return $value !== false && $value !== null ? (string) $value : null;
    }

    public function guardarResumen(int $empresaId, int $hiloId, string $resumen, ?string $modelo, string $hashContenido): void
    {
        $del = $this->connection->prepare(
            'DELETE FROM correo_resumenes_ia WHERE empresa_id = :empresa_id AND hilo_id = :hilo_id'
        );
        $del->execute(['empresa_id' => $empresaId, 'hilo_id' => $hiloId]);

        $ins = $this->connection->prepare(
            'INSERT INTO correo_resumenes_ia (empresa_id, hilo_id, resumen, modelo, hash_contenido)
             VALUES (:empresa_id, :hilo_id, :resumen, :modelo, :hash)'
        );
        $ins->execute([
            'empresa_id' => $empresaId,
            'hilo_id' => $hiloId,
            'resumen' => $resumen,
            'modelo' => $modelo,
            'hash' => $hashContenido,
        ]);
    }

    /**
     * Candidatos para busqueda contextual: mensajes recientes (filtrados por
     * texto si se entrega q). Devuelve cuerpo recortado para limitar tokens.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscarMensajesIa(int $empresaId, string $q, int $limit): array
    {
        $limit = min(40, max(1, $limit));
        $where = "empresa_id = :empresa_id AND carpeta = 'inbox'";
        $params = ['empresa_id' => $empresaId];
        if ($q !== '') {
            $where .= ' AND (asunto LIKE :q OR body_text LIKE :q OR remitente LIKE :q OR destinatarios LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $statement = $this->connection->prepare(
            'SELECT id, hilo_id, asunto, remitente, fecha, LEFT(COALESCE(body_text, snippet, \'\'), 1200) AS cuerpo
             FROM correo_mensajes
             WHERE ' . $where . '
             ORDER BY fecha DESC
             LIMIT :limit'
        );
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, $key === 'empresa_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    // ---------------------------------------------------------------
    // Contactos / agenda (Sprint 3)
    // ---------------------------------------------------------------

    /**
     * Remitentes distintos de la bandeja de entrada (para construir la agenda).
     *
     * @return array<int, array{remitente:string, remitente_nombre:?string}>
     */
    public function remitentesDistintos(int $empresaId): array
    {
        $statement = $this->connection->prepare(
            "SELECT remitente, MAX(remitente_nombre) AS remitente_nombre
             FROM correo_mensajes
             WHERE empresa_id = :empresa_id AND carpeta = 'inbox' AND remitente IS NOT NULL AND remitente <> ''
             GROUP BY remitente"
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, int> email(lower) => id
     */
    public function mapaEmails(string $tabla, int $empresaId): array
    {
        $tabla = $tabla === 'proveedores' ? 'proveedores' : 'clientes';
        $statement = $this->connection->prepare(
            "SELECT id, LOWER(email) AS email FROM {$tabla}
             WHERE empresa_id = :empresa_id AND email IS NOT NULL AND email <> ''"
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $mapa = [];
        foreach ($statement->fetchAll() as $row) {
            $mapa[(string) $row['email']] = (int) $row['id'];
        }

        return $mapa;
    }

    public function upsertContacto(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO correo_contactos (empresa_id, email, nombre, tipo, proveedor_id, cliente_id)
             VALUES (:empresa_id, :email, :nombre, :tipo, :proveedor_id, :cliente_id)
             ON DUPLICATE KEY UPDATE
                nombre = COALESCE(VALUES(nombre), nombre),
                tipo = VALUES(tipo),
                proveedor_id = VALUES(proveedor_id),
                cliente_id = VALUES(cliente_id)'
        );
        $statement->execute([
            'empresa_id' => $data['empresa_id'],
            'email' => $data['email'],
            'nombre' => $data['nombre'] ?? null,
            'tipo' => $data['tipo'] ?? 'otro',
            'proveedor_id' => $data['proveedor_id'] ?? null,
            'cliente_id' => $data['cliente_id'] ?? null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarContactos(int $empresaId, ?string $tipo = null): array
    {
        $sql = 'SELECT id, email, nombre, tipo, proveedor_id, cliente_id FROM correo_contactos WHERE empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];
        if ($tipo !== null && $tipo !== '') {
            $sql .= ' AND tipo = :tipo';
            $params['tipo'] = $tipo;
        }
        $sql .= ' ORDER BY COALESCE(nombre, email) ASC';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, int> tipo => cantidad
     */
    public function contarContactosPorTipo(int $empresaId): array
    {
        $statement = $this->connection->prepare(
            'SELECT tipo, COUNT(*) AS n FROM correo_contactos WHERE empresa_id = :empresa_id GROUP BY tipo'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $out = ['proveedor' => 0, 'cliente' => 0, 'banco' => 0, 'otro' => 0];
        foreach ($statement->fetchAll() as $row) {
            $out[(string) $row['tipo']] = (int) $row['n'];
        }

        return $out;
    }

    public function actualizarEstadoHilo(int $empresaId, int $hiloId, string $estado): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE correo_hilos SET estado = :estado WHERE empresa_id = :empresa_id AND id = :hilo_id'
        );
        $statement->execute(['estado' => $estado, 'empresa_id' => $empresaId, 'hilo_id' => $hiloId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Conteo de hilos por estado, contando solo los que tienen mensajes en la carpeta dada.
     *
     * @return array<string, int>
     */
    public function contarHilosPorEstado(int $empresaId, string $carpeta): array
    {
        $statement = $this->connection->prepare(
            'SELECT h.estado, COUNT(DISTINCT h.id) AS n
             FROM correo_hilos h
             WHERE h.empresa_id = :empresa_id
               AND EXISTS (SELECT 1 FROM correo_mensajes m WHERE m.hilo_id = h.id AND m.carpeta = :carpeta)
             GROUP BY h.estado'
        );
        $statement->execute(['empresa_id' => $empresaId, 'carpeta' => $carpeta]);
        $out = ['pendiente' => 0, 'esperando' => 0, 'resuelto' => 0];
        foreach ($statement->fetchAll() as $row) {
            $out[(string) $row['estado']] = (int) $row['n'];
        }

        return $out;
    }

    public function marcarHiloLeido(int $empresaId, int $hiloId): array
    {
        $select = $this->connection->prepare(
            'SELECT uid FROM correo_mensajes WHERE empresa_id = :empresa_id AND hilo_id = :hilo_id AND seen = 0'
        );
        $select->execute(['empresa_id' => $empresaId, 'hilo_id' => $hiloId]);
        $uids = $select->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $update = $this->connection->prepare(
            'UPDATE correo_mensajes SET seen = 1 WHERE empresa_id = :empresa_id AND hilo_id = :hilo_id AND seen = 0'
        );
        $update->execute(['empresa_id' => $empresaId, 'hilo_id' => $hiloId]);

        return array_map('intval', $uids);
    }
}
