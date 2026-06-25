<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\CorreoMensajeRepository;
use Mypos\Repositories\CorreoRepository;
use Mypos\Support\Env;
use PDO;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

final class CorreoService
{
    private CorreoRepository $repository;

    public function __construct(?CorreoRepository $repository = null)
    {
        $this->repository = $repository ?? new CorreoRepository(Database::connection());
    }

    public function configuracion(int $empresaId): array
    {
        $account = $this->repository->findActiveAccount($empresaId);
        if ($account === null) {
            return [
                'configurado' => false,
                'cuenta' => [
                    'email' => 'elida@mypos.cl',
                    'nombre' => 'Elida',
                    'username' => 'elida@mypos.cl',
                    'imap_host' => 'mail.mypos.cl',
                    'imap_port' => 993,
                    'imap_encryption' => 'ssl',
                    'imap_validate_cert' => 0,
                    'smtp_host' => 'mail.mypos.cl',
                    'smtp_port' => 465,
                    'smtp_encryption' => 'ssl',
                    'activo' => 1,
                    'password_configurada' => false,
                ],
            ];
        }

        return ['configurado' => true, 'cuenta' => $this->publicAccount($account)];
    }

    public function guardarConfiguracion(array $payload, int $usuarioId): array
    {
        $empresaId = (int) ($payload['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $email = $this->email((string) ($payload['email'] ?? ''));
        $password = trim((string) ($payload['password'] ?? ''));
        $encrypted = $password !== '' ? $this->encrypt($password) : null;

        $account = $this->repository->upsertAccount([
            'empresa_id' => $empresaId,
            'email' => $email,
            'nombre' => $this->nullable($payload['nombre'] ?? null),
            'username' => $this->email((string) ($payload['username'] ?? $email)),
            'password_encrypted' => $encrypted,
            'imap_host' => $this->host($payload['imap_host'] ?? 'mail.mypos.cl'),
            'imap_port' => $this->port($payload['imap_port'] ?? 993),
            'imap_encryption' => $this->encryption($payload['imap_encryption'] ?? 'ssl'),
            'imap_validate_cert' => $this->boolInt($payload['imap_validate_cert'] ?? 0),
            'smtp_host' => $this->host($payload['smtp_host'] ?? 'mail.mypos.cl'),
            'smtp_port' => $this->port($payload['smtp_port'] ?? 465),
            'smtp_encryption' => $this->encryption($payload['smtp_encryption'] ?? 'ssl'),
            'activo' => 1,
        ]);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $usuarioId,
            'modulo' => 'correo',
            'accion' => 'configurar',
            'entidad' => 'correo_cuentas',
            'entidad_id' => $account['id'] ?? null,
            'descripcion' => 'Configuracion de correo de empresa actualizada',
            'metadata' => ['email' => $email],
        ]);

        return ['cuenta' => $this->publicAccount($account)];
    }

    public function inbox(array $filters): array
    {
        $empresaId = (int) ($filters['empresa_id'] ?? 0);
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 25)));
        $account = $this->requireAccount($empresaId);
        $imap = $this->openImap($account);

        try {
            $uids = imap_search($imap, 'ALL', SE_UID) ?: [];
            rsort($uids, SORT_NUMERIC);
            $uids = array_slice($uids, 0, $limit);
            $items = [];
            foreach ($uids as $uid) {
                $overview = imap_fetch_overview($imap, (string) $uid, FT_UID);
                $row = is_array($overview) && isset($overview[0]) ? (array) $overview[0] : [];
                $items[] = [
                    'uid' => (int) $uid,
                    'subject' => $this->decodeHeader((string) ($row['subject'] ?? '(sin asunto)')),
                    'from' => $this->decodeHeader((string) ($row['from'] ?? '')),
                    'date' => (string) ($row['date'] ?? ''),
                    'seen' => !empty($row['seen']),
                    'answered' => !empty($row['answered']),
                    'size' => (int) ($row['size'] ?? 0),
                ];
            }

            return ['items' => $items, 'cuenta' => $this->publicAccount($account)];
        } finally {
            imap_close($imap);
        }
    }

    public function mensaje(int $empresaId, int $uid): array
    {
        if ($uid <= 0) {
            throw new HttpException('Mensaje invalido', 422);
        }
        $account = $this->requireAccount($empresaId);
        $imap = $this->openImap($account);

        try {
            $messageNo = $this->messageNumber($imap, $uid);
            $overview = @imap_fetch_overview($imap, (string) $uid, FT_UID);
            if (!is_array($overview) || !isset($overview[0])) {
                throw new HttpException('Mensaje no encontrado', 404);
            }
            $row = (array) $overview[0];
            try {
                $body = $this->fetchBody($imap, $uid, $messageNo);
            } catch (Throwable $exception) {
                error_log('[CorreoService] mensaje body error: ' . $exception->getMessage());
                $fallbackBody = $this->safeFetchRawBody($imap, $uid, $messageNo);
                $body = [
                    'text' => $fallbackBody !== '' ? $fallbackBody : 'No se pudo leer el contenido de este mensaje.',
                    'html' => null,
                ];
            }
            if (
                trim((string) ($body['text'] ?? '')) === ''
                && trim((string) ($body['html'] ?? '')) === ''
            ) {
                $body['text'] = $this->emptyBodyMessage($imap, $uid, $row, $messageNo);
                $body['html'] = null;
            }

            return [
                'mensaje' => [
                    'uid' => $uid,
                    'subject' => $this->cleanText($this->decodeHeader((string) ($row['subject'] ?? '(sin asunto)'))),
                    'from' => $this->cleanText($this->decodeHeader((string) ($row['from'] ?? ''))),
                    'to' => $this->cleanText($this->decodeHeader((string) ($row['to'] ?? ''))),
                    'date' => (string) ($row['date'] ?? ''),
                    'seen' => !empty($row['seen']),
                    'body_text' => $this->cleanText($body['text']),
                    'body_html' => $this->cleanText($body['html']),
                ],
            ];
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            error_log('[CorreoService] mensaje error: ' . $exception->getMessage());
            $row = isset($row) && is_array($row) ? $row : [];

            $summary = '';
            try {
                $summary = $this->emptyBodyMessage($imap, $uid, $row);
            } catch (Throwable $summaryException) {
                error_log('[CorreoService] resumen fallback error: ' . $summaryException->getMessage());
            }
            $bodyText = $summary !== ''
                ? $summary
                : 'No se pudo extraer el cuerpo de este mensaje desde IMAP, pero el mensaje existe en la bandeja.';

            return [
                'mensaje' => [
                    'uid' => $uid,
                    'subject' => $this->safeCleanHeader($row['subject'] ?? 'Mensaje de correo'),
                    'from' => $this->safeCleanHeader($row['from'] ?? ''),
                    'to' => $this->safeCleanHeader($row['to'] ?? ''),
                    'date' => (string) ($row['date'] ?? ''),
                    'seen' => !empty($row['seen']),
                    'body_text' => $bodyText,
                    'body_html' => null,
                ],
            ];
        } finally {
            @imap_close($imap);
        }
    }

    public function enviar(array $payload, int $usuarioId): array
    {
        $empresaId = (int) ($payload['empresa_id'] ?? 0);
        $account = $this->requireAccount($empresaId);
        $to = $this->addresses($payload['to'] ?? []);
        $cc = $this->addresses($payload['cc'] ?? []);
        $subject = trim((string) ($payload['subject'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));

        if ($to === []) {
            throw new HttpException('Debes indicar al menos un destinatario', 422);
        }
        if ($subject === '') {
            throw new HttpException('El asunto es obligatorio', 422);
        }
        if ($body === '') {
            throw new HttpException('El cuerpo del correo es obligatorio', 422);
        }

        $password = $this->password($account);
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $account['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = (string) $account['username'];
        $mail->Password = $password;
        $mail->SMTPSecure = $this->phpMailerEncryption((string) $account['smtp_encryption']);
        $mail->Port = (int) $account['smtp_port'];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string) $account['email'], (string) ($account['nombre'] ?: $account['email']));
        foreach ($to as $address) {
            $mail->addAddress($address);
        }
        foreach ($cc as $address) {
            $mail->addCC($address);
        }
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();

        $rawMime = '';
        try {
            $rawMime = (string) $mail->getSentMIMEMessage();
        } catch (Throwable) {
            $rawMime = '';
        }
        $this->guardarEnviado($account, $empresaId, $to, $cc, $subject, $body, $rawMime);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $usuarioId,
            'modulo' => 'correo',
            'accion' => 'enviar',
            'entidad' => 'correo',
            'descripcion' => 'Correo enviado desde cuenta de empresa',
            'metadata' => ['from' => $account['email'], 'to_count' => count($to), 'cc_count' => count($cc)],
        ]);

        return ['enviado' => true];
    }

    /**
     * Refleja el correo enviado en la carpeta Sent del servidor IMAP y guarda
     * una copia local en BD (carpeta enviados) para verlo de inmediato.
     */
    private function guardarEnviado(array $account, int $empresaId, array $to, array $cc, string $subject, string $body, string $rawMime): void
    {
        $cuentaId = (int) ($account['id'] ?? 0);
        if ($cuentaId <= 0) {
            return;
        }

        if ($rawMime !== '' && function_exists('imap_append')) {
            try {
                $imap = $this->openImap($account);
                try {
                    if (@imap_append($imap, $this->sentMailbox($account), $rawMime, '\\Seen') !== true) {
                        @imap_append($imap, $this->sentMailbox($account, 'INBOX.Sent'), $rawMime, '\\Seen');
                    }
                } finally {
                    @imap_close($imap);
                }
            } catch (Throwable $exception) {
                error_log('[CorreoService] imap_append Sent: ' . $exception->getMessage());
            }
        }

        try {
            $repository = new CorreoMensajeRepository(Database::connection());
            $localUid = $repository->maxUid($cuentaId, 'enviados') + 1;
            $repository->upsertMensaje([
                'empresa_id' => $empresaId,
                'cuenta_id' => $cuentaId,
                'uid' => $localUid,
                'carpeta' => 'enviados',
                'remitente' => (string) $account['email'],
                'remitente_nombre' => $this->trimOrNull((string) ($account['nombre'] ?? ''), 190),
                'destinatarios' => $this->trimOrNull(implode(', ', array_merge($to, $cc)), 60000),
                'asunto' => $this->trimOrNull($subject, 500),
                'snippet' => $this->trimOrNull($this->makeSnippet($body, null), 300),
                'body_text' => $this->cleanText($body),
                'fecha' => date('Y-m-d H:i:s'),
                'seen' => 1,
            ]);
        } catch (Throwable $exception) {
            error_log('[CorreoService] guardarEnviado BD: ' . $exception->getMessage());
        }
    }

    private function sentMailbox(array $account, string $folder = 'Sent'): string
    {
        $flags = '/imap';
        if (($account['imap_encryption'] ?? 'ssl') === 'ssl') {
            $flags .= '/ssl';
        } elseif (($account['imap_encryption'] ?? '') === 'tls') {
            $flags .= '/tls';
        } else {
            $flags .= '/notls';
        }
        if ((int) ($account['imap_validate_cert'] ?? 0) === 0) {
            $flags .= '/novalidate-cert';
        }

        return sprintf('{%s:%d%s}%s', $account['imap_host'], (int) $account['imap_port'], $flags, $folder);
    }

    public function probar(int $empresaId): array
    {
        $account = $this->requireAccount($empresaId);
        $imap = $this->openImap($account);
        imap_close($imap);

        return ['ok' => true, 'message' => 'Conexion IMAP correcta'];
    }

    /**
     * Sincroniza incrementalmente los mensajes nuevos de INBOX hacia la BD.
     * Lee solo UIDs mayores al ultimo sincronizado. Pensado para correr por
     * cron y, manualmente, desde el endpoint de sincronizacion.
     */
    public function sincronizar(int $empresaId, int $maxMensajes = 300): array
    {
        $account = $this->requireAccount($empresaId);
        $cuentaId = (int) $account['id'];
        $carpeta = 'inbox';
        $repository = new CorreoMensajeRepository(Database::connection());
        $imap = $this->openImap($account);

        try {
            $lastUid = $repository->maxUid($cuentaId, $carpeta);
            $sequence = ($lastUid + 1) . ':*';
            $overviews = @imap_fetch_overview($imap, $sequence, FT_UID);
            $overviews = is_array($overviews) ? $overviews : [];

            // El rango "n:*" devuelve el ultimo mensaje aunque no haya nuevos: filtrar.
            $nuevos = array_values(array_filter(
                $overviews,
                static fn ($overview): bool => (int) ($overview->uid ?? 0) > $lastUid
            ));
            usort($nuevos, static fn ($a, $b): int => (int) ($b->uid ?? 0) <=> (int) ($a->uid ?? 0));
            $nuevos = array_slice($nuevos, 0, $maxMensajes);

            $sincronizados = 0;
            $maxUidProcesado = $lastUid;
            $insertados = [];
            foreach ($nuevos as $overview) {
                $uid = (int) ($overview->uid ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                try {
                    $messageNo = $this->messageNumber($imap, $uid);
                    $body = $this->fetchBody($imap, $uid, $messageNo);
                    $bodyText = (string) ($body['text'] ?? '');
                    $bodyHtml = $body['html'] ?? null;
                    $fromRaw = $this->decodeHeader((string) ($overview->from ?? ''));

                    $datos = [
                        'empresa_id' => $empresaId,
                        'cuenta_id' => $cuentaId,
                        'uid' => $uid,
                        'carpeta' => $carpeta,
                        'message_id' => $this->trimOrNull((string) ($overview->message_id ?? ''), 255),
                        'in_reply_to' => $this->trimOrNull((string) ($overview->in_reply_to ?? ''), 255),
                        'referencias' => $this->trimOrNull((string) ($overview->references ?? ''), 60000),
                        'remitente' => $this->trimOrNull($this->extractEmail($fromRaw), 320),
                        'remitente_nombre' => $this->trimOrNull($this->extractName($fromRaw), 190),
                        'destinatarios' => $this->trimOrNull($this->decodeHeader((string) ($overview->to ?? '')), 60000),
                        'cc' => null,
                        'asunto' => $this->trimOrNull($this->cleanText($this->decodeHeader((string) ($overview->subject ?? ''))), 500),
                        'snippet' => $this->trimOrNull($this->makeSnippet($bodyText, $bodyHtml), 300),
                        'body_text' => $this->cleanText($bodyText),
                        'body_html' => $this->cleanText($bodyHtml),
                        'fecha' => $this->parseDate((string) ($overview->date ?? '')),
                        'seen' => !empty($overview->seen) ? 1 : 0,
                        'flagged' => !empty($overview->flagged) ? 1 : 0,
                        'tiene_adjuntos' => 0,
                        'tamano' => (int) ($overview->size ?? 0),
                    ];
                    $repository->upsertMensaje($datos);
                    $insertados[] = $datos;

                    $sincronizados++;
                    $maxUidProcesado = max($maxUidProcesado, $uid);
                } catch (Throwable $exception) {
                    error_log('[CorreoService] sync mensaje uid ' . $uid . ': ' . $exception->getMessage());
                }
            }

            $repository->saveSyncState($cuentaId, $carpeta, null, $maxUidProcesado);

            // Threading: asignar hilos en orden cronologico (padres antes que respuestas).
            usort($insertados, static fn (array $a, array $b): int => strcmp((string) ($a['fecha'] ?? ''), (string) ($b['fecha'] ?? '')));
            $hilosAfectados = [];
            foreach ($insertados as $datos) {
                $hiloId = $this->asignarHiloAMensaje($repository, $empresaId, $datos);
                if ($hiloId > 0) {
                    $hilosAfectados[$hiloId] = true;
                }
            }
            foreach (array_keys($hilosAfectados) as $hiloId) {
                $repository->recalcularHilo((int) $hiloId);
            }

            if ($sincronizados > 0) {
                try {
                    $this->reconstruirContactos($empresaId);
                } catch (Throwable $exception) {
                    error_log('[CorreoService] reconstruirContactos en sync: ' . $exception->getMessage());
                }
            }

            return ['sincronizados' => $sincronizados, 'ultimo_uid' => $maxUidProcesado];
        } finally {
            @imap_close($imap);
        }
    }

    /**
     * Bandeja paginada leyendo desde BD (sin tocar IMAP). Base de la UI nueva.
     */
    public function bandeja(array $filters): array
    {
        $empresaId = (int) ($filters['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $carpeta = in_array($filters['carpeta'] ?? 'inbox', ['inbox', 'enviados', 'papelera'], true)
            ? (string) $filters['carpeta']
            : 'inbox';
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);

        $repository = new CorreoMensajeRepository(Database::connection());

        return $repository->paginar($empresaId, $carpeta, $page, $perPage, [
            'q' => (string) ($filters['q'] ?? ''),
            'sort' => (string) ($filters['sort'] ?? 'fecha'),
            'order' => (string) ($filters['order'] ?? 'desc'),
        ]);
    }

    /**
     * Detalle de un mensaje desde BD. Marca como leido (BD + IMAP best-effort).
     */
    public function mensajeBd(int $empresaId, int $id): array
    {
        if ($id <= 0) {
            throw new HttpException('Mensaje invalido', 422);
        }
        $repository = new CorreoMensajeRepository(Database::connection());
        $mensaje = $repository->find($empresaId, $id);
        if ($mensaje === null) {
            throw new HttpException('Mensaje no encontrado', 404);
        }

        if ((int) ($mensaje['seen'] ?? 0) === 0) {
            $repository->updateSeen($empresaId, $id, true);
            $mensaje['seen'] = 1;
            $this->marcarLeidoImap($empresaId, (int) $mensaje['uid']);
        }

        return ['mensaje' => $mensaje];
    }

    /**
     * Mueve un mensaje a la papelera en BD y en el servidor IMAP.
     */
    public function eliminar(int $empresaId, int $id, int $usuarioId): array
    {
        if ($id <= 0) {
            throw new HttpException('Mensaje invalido', 422);
        }
        $repository = new CorreoMensajeRepository(Database::connection());
        $mensaje = $repository->find($empresaId, $id);
        if ($mensaje === null) {
            throw new HttpException('Mensaje no encontrado', 404);
        }

        $repository->moverCarpeta($empresaId, $id, 'papelera');
        $this->moverPapeleraImap($empresaId, (int) $mensaje['uid']);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $usuarioId,
            'modulo' => 'correo',
            'accion' => 'eliminar',
            'entidad' => 'correo_mensajes',
            'entidad_id' => $id,
            'descripcion' => 'Correo movido a papelera',
            'metadata' => ['uid' => (int) $mensaje['uid'], 'asunto' => $mensaje['asunto'] ?? ''],
        ]);

        return ['eliminado' => true];
    }

    /**
     * Reenvia un mensaje existente citando el cuerpo original.
     */
    public function reenviar(array $payload, int $usuarioId): array
    {
        $empresaId = (int) ($payload['empresa_id'] ?? 0);
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException('Mensaje invalido', 422);
        }
        $repository = new CorreoMensajeRepository(Database::connection());
        $original = $repository->find($empresaId, $id);
        if ($original === null) {
            throw new HttpException('Mensaje no encontrado', 404);
        }

        $cuerpoOriginal = trim((string) ($original['body_text'] ?? ''));
        if ($cuerpoOriginal === '' && !empty($original['body_html'])) {
            $cuerpoOriginal = $this->htmlToText((string) $original['body_html']);
        }
        $extra = trim((string) ($payload['body'] ?? ''));
        $asuntoBase = (string) ($original['asunto'] ?? '');
        $asunto = stripos($asuntoBase, 'fwd:') === 0 ? $asuntoBase : 'Fwd: ' . $asuntoBase;

        $body = ($extra !== '' ? $extra . "\n\n" : '')
            . "---------- Mensaje reenviado ----------\n"
            . 'De: ' . (string) ($original['remitente'] ?? '') . "\n"
            . 'Fecha: ' . (string) ($original['fecha'] ?? '') . "\n"
            . 'Asunto: ' . $asuntoBase . "\n"
            . 'Para: ' . (string) ($original['destinatarios'] ?? '') . "\n\n"
            . $cuerpoOriginal;

        return $this->enviar([
            'empresa_id' => $empresaId,
            'to' => $payload['to'] ?? [],
            'cc' => $payload['cc'] ?? [],
            'subject' => $asunto,
            'body' => $body,
        ], $usuarioId);
    }

    /**
     * Listado paginado de hilos (conversaciones) de una carpeta.
     */
    public function hilos(array $filters): array
    {
        $empresaId = (int) ($filters['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $carpeta = in_array($filters['carpeta'] ?? 'inbox', ['inbox', 'enviados', 'papelera'], true)
            ? (string) $filters['carpeta']
            : 'inbox';
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);

        $repository = new CorreoMensajeRepository(Database::connection());

        $resultado = $repository->paginarHilos($empresaId, $carpeta, $page, $perPage, [
            'q' => (string) ($filters['q'] ?? ''),
            'grupo' => (string) ($filters['grupo'] ?? ''),
            'estado' => (string) ($filters['estado'] ?? ''),
            'contacto' => (string) ($filters['contacto'] ?? ''),
        ]);
        $resultado['conteos_estado'] = $repository->contarHilosPorEstado($empresaId, $carpeta);

        return $resultado;
    }

    /**
     * Cambia el estado de una conversacion (pendiente/esperando/resuelto).
     */
    public function cambiarEstadoHilo(int $empresaId, int $hiloId, string $estado, int $usuarioId): array
    {
        if ($hiloId <= 0) {
            throw new HttpException('Hilo invalido', 422);
        }
        if (!in_array($estado, ['pendiente', 'esperando', 'resuelto'], true)) {
            throw new HttpException('Estado invalido', 422);
        }
        $repository = new CorreoMensajeRepository(Database::connection());
        if (!$repository->actualizarEstadoHilo($empresaId, $hiloId, $estado)) {
            throw new HttpException('Hilo no encontrado', 404);
        }

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $usuarioId,
            'modulo' => 'correo',
            'accion' => 'estado_hilo',
            'entidad' => 'correo_hilos',
            'entidad_id' => $hiloId,
            'descripcion' => 'Estado de conversacion actualizado a ' . $estado,
            'metadata' => ['estado' => $estado],
        ]);

        return ['estado' => $estado];
    }

    /**
     * Agenda: contactos agrupados por tipo + conteos. Construye la agenda si
     * aun no existe (primera vez).
     */
    public function contactos(array $filters): array
    {
        $empresaId = (int) ($filters['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $repository = new CorreoMensajeRepository(Database::connection());
        $conteos = $repository->contarContactosPorTipo($empresaId);
        if (array_sum($conteos) === 0) {
            $this->reconstruirContactos($empresaId);
            $conteos = $repository->contarContactosPorTipo($empresaId);
        }

        $tipo = in_array($filters['tipo'] ?? '', ['proveedor', 'cliente', 'banco', 'otro'], true)
            ? (string) $filters['tipo']
            : null;

        return [
            'contactos' => $repository->listarContactos($empresaId, $tipo),
            'conteos' => $conteos,
        ];
    }

    /**
     * Reconstruye la agenda desde los remitentes de la bandeja, matcheando con
     * proveedores/clientes existentes y clasificando bancos por dominio.
     */
    public function reconstruirContactos(int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $repository = new CorreoMensajeRepository(Database::connection());
        $proveedores = $repository->mapaEmails('proveedores', $empresaId);
        $clientes = $repository->mapaEmails('clientes', $empresaId);
        $dominiosProveedor = $this->mapaDominios($proveedores);
        $dominiosCliente = $this->mapaDominios($clientes);

        $procesados = 0;
        foreach ($repository->remitentesDistintos($empresaId) as $row) {
            $email = strtolower(trim((string) ($row['remitente'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $clasificacion = $this->clasificarContacto($email, $proveedores, $clientes, $dominiosProveedor, $dominiosCliente);

            $repository->upsertContacto([
                'empresa_id' => $empresaId,
                'email' => $email,
                'nombre' => $this->trimOrNull((string) ($row['remitente_nombre'] ?? ''), 190),
                'tipo' => $clasificacion['tipo'],
                'proveedor_id' => $clasificacion['proveedor_id'],
                'cliente_id' => $clasificacion['cliente_id'],
            ]);
            $procesados++;
        }

        return ['procesados' => $procesados];
    }

    /**
     * Construye un mapa dominio=>id a partir de un mapa email=>id, excluyendo
     * dominios de correo gratuito (gmail, hotmail, etc.) para no agrupar de mas.
     *
     * @param array<string, int> $emails
     * @return array<string, int>
     */
    private function mapaDominios(array $emails): array
    {
        $dominios = [];
        foreach ($emails as $email => $id) {
            $dominio = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
            if ($dominio === '' || $this->esDominioGratuito($dominio)) {
                continue;
            }
            // Primer id gana; varios proveedores con igual dominio = misma empresa.
            $dominios[$dominio] ??= $id;
        }

        return $dominios;
    }

    /**
     * @param array<string, int> $proveedores email=>id
     * @param array<string, int> $clientes email=>id
     * @param array<string, int> $dominiosProveedor dominio=>id
     * @param array<string, int> $dominiosCliente dominio=>id
     * @return array{tipo:string, proveedor_id:?int, cliente_id:?int}
     */
    private function clasificarContacto(string $email, array $proveedores, array $clientes, array $dominiosProveedor, array $dominiosCliente): array
    {
        // 1) Email exacto.
        if (isset($proveedores[$email])) {
            return ['tipo' => 'proveedor', 'proveedor_id' => $proveedores[$email], 'cliente_id' => null];
        }
        if (isset($clientes[$email])) {
            return ['tipo' => 'cliente', 'proveedor_id' => null, 'cliente_id' => $clientes[$email]];
        }

        $dominio = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        // 2) Dominio corporativo coincidente (no free-mail).
        if ($dominio !== '' && !$this->esDominioGratuito($dominio)) {
            if (isset($dominiosProveedor[$dominio])) {
                return ['tipo' => 'proveedor', 'proveedor_id' => $dominiosProveedor[$dominio], 'cliente_id' => null];
            }
            if (isset($dominiosCliente[$dominio])) {
                return ['tipo' => 'cliente', 'proveedor_id' => null, 'cliente_id' => $dominiosCliente[$dominio]];
            }
        }

        // 3) Banco por dominio.
        if ($dominio !== '' && $this->esDominioBanco($dominio)) {
            return ['tipo' => 'banco', 'proveedor_id' => null, 'cliente_id' => null];
        }

        return ['tipo' => 'otro', 'proveedor_id' => null, 'cliente_id' => null];
    }

    private function esDominioGratuito(string $dominio): bool
    {
        return in_array($dominio, [
            'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.cl', 'outlook.com',
            'outlook.cl', 'live.com', 'live.cl', 'yahoo.com', 'yahoo.es', 'icloud.com',
            'me.com', 'aol.com', 'protonmail.com', 'proton.me', 'gmx.com', 'zoho.com',
        ], true);
    }

    private function esDominioBanco(string $dominio): bool
    {
        if (str_contains($dominio, 'banco')) {
            return true;
        }
        $bancos = [
            'bancochile.cl', 'bancoestado.cl', 'santander.cl', 'santander.com',
            'bci.cl', 'scotiabank.cl', 'itau.cl', 'bice.cl', 'security.cl',
            'consorcio.cl', 'bancofalabella.cl', 'falabella.com', 'ripley.cl',
            'tbanc.cl', 'coopeuch.cl', 'bancoripley.cl', 'edwards.cl',
        ];
        foreach ($bancos as $banco) {
            if ($dominio === $banco || str_ends_with($dominio, '.' . $banco)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Conversacion completa de un hilo. Marca sus mensajes como leidos (BD + IMAP).
     */
    public function hilo(int $empresaId, int $hiloId): array
    {
        if ($hiloId <= 0) {
            throw new HttpException('Hilo invalido', 422);
        }
        $repository = new CorreoMensajeRepository(Database::connection());
        $mensajes = $repository->mensajesDeHilo($empresaId, $hiloId);
        if ($mensajes === []) {
            throw new HttpException('Hilo no encontrado', 404);
        }

        $uidsNoLeidos = $repository->marcarHiloLeido($empresaId, $hiloId);
        if ($uidsNoLeidos !== []) {
            $repository->recalcularHilo($hiloId);
            $this->marcarLeidosImap($empresaId, $uidsNoLeidos);
            foreach ($mensajes as &$mensaje) {
                $mensaje['seen'] = 1;
            }
            unset($mensaje);
        }

        return ['hilo_id' => $hiloId, 'mensajes' => $mensajes];
    }

    /**
     * Backfill: agrupa en hilos todos los mensajes que aun no tienen hilo_id.
     */
    public function reconstruirHilos(int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $repository = new CorreoMensajeRepository(Database::connection());
        $pendientes = $repository->mensajesSinHilo($empresaId);
        $afectados = [];
        foreach ($pendientes as $row) {
            $hiloId = $this->asignarHiloAMensaje($repository, $empresaId, $row);
            if ($hiloId > 0) {
                $afectados[$hiloId] = true;
            }
        }
        foreach (array_keys($afectados) as $hiloId) {
            $repository->recalcularHilo((int) $hiloId);
        }

        return ['procesados' => count($pendientes), 'hilos' => count($afectados)];
    }

    /**
     * Resuelve (o crea) el hilo de un mensaje ya guardado y se lo asigna.
     *
     * @param array<string, mixed> $row con cuenta_id, uid, carpeta, message_id, in_reply_to, referencias, asunto, fecha
     */
    private function asignarHiloAMensaje(CorreoMensajeRepository $repository, int $empresaId, array $row): int
    {
        $cuentaId = (int) ($row['cuenta_id'] ?? 0);
        $uid = (int) ($row['uid'] ?? 0);
        $carpeta = (string) ($row['carpeta'] ?? 'inbox');
        if ($cuentaId <= 0 || $uid <= 0) {
            return 0;
        }

        $referencias = $this->extraerMessageIds(
            (string) ($row['referencias'] ?? ''),
            (string) ($row['in_reply_to'] ?? '')
        );
        $asuntoNorm = $this->normalizarAsunto((string) ($row['asunto'] ?? ''));

        $hiloId = $repository->hiloIdPorReferencias($cuentaId, $referencias);
        if ($hiloId === null) {
            $hiloId = $repository->hiloIdPorAsunto($cuentaId, $asuntoNorm);
        }
        if ($hiloId === null) {
            $hiloId = $repository->crearHilo(
                $empresaId,
                $cuentaId,
                $asuntoNorm,
                $this->trimOrNull((string) ($row['message_id'] ?? ''), 255),
                $row['fecha'] !== null ? (string) $row['fecha'] : null
            );
        }

        $repository->asignarHilo($cuentaId, $uid, $carpeta, $hiloId);

        return $hiloId;
    }

    private function normalizarAsunto(string $asunto): string
    {
        $asunto = trim($asunto);
        // Quitar prefijos de respuesta/reenvio repetidos (re:, fwd:, fw:, rv:, res:).
        do {
            $previo = $asunto;
            $asunto = preg_replace('/^\s*(re|rv|fwd|fw|res)\s*:\s*/i', '', $asunto) ?? $asunto;
        } while ($asunto !== $previo);

        $asunto = preg_replace('/\s+/', ' ', $asunto) ?? $asunto;

        return mb_strtolower(trim($asunto));
    }

    /**
     * @return array<int, string>
     */
    private function extraerMessageIds(string $referencias, string $inReplyTo): array
    {
        $combinado = trim($inReplyTo . ' ' . $referencias);
        if ($combinado === '') {
            return [];
        }
        if (preg_match_all('/<[^>]+>/', $combinado, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[0] ?? []));
    }

    private function marcarLeidosImap(int $empresaId, array $uids): void
    {
        $uids = array_values(array_filter(array_map('intval', $uids), static fn (int $u): bool => $u > 0));
        if ($uids === []) {
            return;
        }
        try {
            $account = $this->requireAccount($empresaId);
            $imap = $this->openImap($account);
            try {
                @imap_setflag_full($imap, implode(',', $uids), '\\Seen', ST_UID);
            } finally {
                @imap_close($imap);
            }
        } catch (Throwable $exception) {
            error_log('[CorreoService] marcarLeidosImap: ' . $exception->getMessage());
        }
    }

    private function marcarLeidoImap(int $empresaId, int $uid): void
    {
        try {
            $account = $this->requireAccount($empresaId);
            $imap = $this->openImap($account);
            try {
                @imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
            } finally {
                @imap_close($imap);
            }
        } catch (Throwable $exception) {
            error_log('[CorreoService] marcarLeidoImap: ' . $exception->getMessage());
        }
    }

    private function moverPapeleraImap(int $empresaId, int $uid): void
    {
        try {
            $account = $this->requireAccount($empresaId);
            $imap = $this->openImap($account);
            try {
                $movido = @imap_mail_move($imap, (string) $uid, 'Trash', CP_UID);
                if ($movido !== true) {
                    @imap_mail_move($imap, (string) $uid, 'INBOX.Trash', CP_UID);
                }
                @imap_expunge($imap);
            } finally {
                @imap_close($imap);
            }
        } catch (Throwable $exception) {
            error_log('[CorreoService] moverPapeleraImap: ' . $exception->getMessage());
        }
    }

    private function requireAccount(int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $account = $this->repository->findActiveAccount($empresaId);
        if ($account === null) {
            throw new HttpException('No hay cuenta de correo configurada para esta empresa', 422);
        }
        if (empty($account['password_encrypted'])) {
            throw new HttpException('La cuenta de correo no tiene password configurada', 422);
        }

        return $account;
    }

    private function openImap(array $account): mixed
    {
        if (!function_exists('imap_open')) {
            throw new HttpException('La extension IMAP de PHP no esta habilitada en el servidor', 500);
        }

        $flags = '/imap';
        if (($account['imap_encryption'] ?? 'ssl') === 'ssl') {
            $flags .= '/ssl';
        } elseif (($account['imap_encryption'] ?? '') === 'tls') {
            $flags .= '/tls';
        } else {
            $flags .= '/notls';
        }
        if ((int) ($account['imap_validate_cert'] ?? 0) === 0) {
            $flags .= '/novalidate-cert';
        }
        $mailbox = sprintf('{%s:%d%s}INBOX', $account['imap_host'], (int) $account['imap_port'], $flags);
        $imap = @imap_open($mailbox, (string) $account['username'], $this->password($account));
        if ($imap === false) {
            $error = imap_last_error();
            $message = 'No se pudo conectar al correo. Revisa usuario, password y servidor.';
            if (is_string($error) && trim($error) !== '') {
                $message .= ' Detalle: ' . $this->safeImapError($error);
            }
            throw new HttpException($message, 422);
        }

        return $imap;
    }

    private function messageNumber(mixed $imap, int $uid): int
    {
        $messageNo = function_exists('imap_msgno') ? @imap_msgno($imap, $uid) : 0;
        return is_int($messageNo) && $messageNo > 0 ? $messageNo : $uid;
    }

    private function fetchBody(mixed $imap, int $uid, ?int $messageNo = null): array
    {
        $messageNo ??= $this->messageNumber($imap, $uid);
        try {
            $structure = @imap_fetchstructure($imap, $messageNo);
            if (!is_object($structure)) {
                return ['text' => $this->safeFetchRawBody($imap, $uid, $messageNo), 'html' => null];
            }

            $body = ['text' => null, 'html' => null];
            $this->collectBodyParts($imap, $uid, $structure, '', $body, $messageNo);

            if ($body['text'] === null && $body['html'] === null) {
                $body['text'] = $this->safeFetchRawBody($imap, $uid, $messageNo);
            }
            if ($body['text'] === null && is_string($body['html']) && $body['html'] !== '') {
                $body['text'] = $this->htmlToText($body['html']);
            }
            if (($body['text'] === null || trim((string) $body['text']) === '') && ($body['html'] === null || trim((string) $body['html']) === '')) {
                $body['text'] = $this->fetchBodyByHeuristics($imap, $uid, $messageNo);
            }

            return $body;
        } catch (Throwable $exception) {
            error_log('[CorreoService] fetchBody fallback: ' . $exception->getMessage());
            return ['text' => $this->safeFetchRawBody($imap, $uid, $messageNo), 'html' => null];
        }
    }

    private function collectBodyParts(mixed $imap, int $uid, object $part, string $sectionPrefix, array &$body, int $messageNo): void
    {
        if (isset($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $index => $child) {
                if (!is_object($child)) {
                    continue;
                }
                $section = $sectionPrefix === '' ? (string) ($index + 1) : $sectionPrefix . '.' . ($index + 1);
                try {
                    $this->collectBodyParts($imap, $uid, $child, $section, $body, $messageNo);
                } catch (Throwable $exception) {
                    error_log('[CorreoService] collectBodyParts skip: ' . $exception->getMessage());
                }
            }
            return;
        }

        $type = (int) ($part->type ?? 0);
        $subtype = strtoupper((string) ($part->subtype ?? ''));
        if ($type !== 0 || !in_array($subtype, ['PLAIN', 'HTML'], true)) {
            return;
        }

        $section = $sectionPrefix === '' ? '1' : $sectionPrefix;
        $content = @imap_fetchbody($imap, $messageNo, $section, FT_PEEK);
        if (!is_string($content) || $content === '') {
            $content = @imap_fetchbody($imap, $uid, $section, FT_UID | FT_PEEK);
        }
        if (!is_string($content) || $content === '') {
            return;
        }

            $content = trim($this->decodePart($content, (int) ($part->encoding ?? 0), $this->partCharset($part)));
        if ($content === '') {
            return;
        }

        if ($subtype === 'HTML' && $body['html'] === null) {
            $body['html'] = $content;
            return;
        }

        if ($subtype === 'PLAIN' && $body['text'] === null) {
            $body['text'] = $content;
        }
    }

    private function safeFetchRawBody(mixed $imap, int $uid, ?int $messageNo = null): string
    {
        try {
            return $this->fetchRawBody($imap, $uid, $messageNo);
        } catch (Throwable $exception) {
            error_log('[CorreoService] raw body error: ' . $exception->getMessage());
            return '';
        }
    }

    private function fetchRawBody(mixed $imap, int $uid, ?int $messageNo = null): string
    {
        $messageNo ??= $this->messageNumber($imap, $uid);
        $candidates = ['', '1', '1.1', '1.2', '1.3', '2', '2.1', '2.2', '3', 'TEXT'];
        foreach ($candidates as $section) {
            $text = $section === ''
                ? @imap_body($imap, $messageNo, FT_PEEK)
                : @imap_fetchbody($imap, $messageNo, $section, FT_PEEK);

            if ((!is_string($text) || trim($text) === '') && $section === '') {
                $text = @imap_body($imap, $uid, FT_UID | FT_PEEK);
            }
            if ((!is_string($text) || trim($text) === '') && $section !== '') {
                $text = @imap_fetchbody($imap, $uid, $section, FT_UID | FT_PEEK);
            }

            if (!is_string($text) || trim($text) === '') {
                continue;
            }

            $decoded = $this->toUtf8(quoted_printable_decode($text));
            $decoded = trim($decoded);
            if ($decoded !== '') {
                return $decoded;
            }
        }

        return '';
    }

    private function fetchBodyByHeuristics(mixed $imap, int $uid, ?int $messageNo = null): string
    {
        $messageNo ??= $this->messageNumber($imap, $uid);
        $sections = ['1', '1.1', '1.2', '1.3', '2', '2.1', '2.2', '3', 'TEXT'];
        foreach ($sections as $section) {
            $raw = @imap_fetchbody($imap, $messageNo, $section, FT_PEEK);
            if (!is_string($raw) || trim($raw) === '') {
                $raw = @imap_fetchbody($imap, $uid, $section, FT_UID | FT_PEEK);
            }
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            foreach ($this->decodeCandidates($raw) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '' || $this->looksLikeMimeHeadersOnly($candidate)) {
                    continue;
                }

                return str_contains($candidate, '<') && str_contains($candidate, '>')
                    ? $this->htmlToText($candidate)
                    : $candidate;
            }
        }

        $header = @imap_fetchheader($imap, $messageNo, FT_PREFETCHTEXT);
        if (!is_string($header) || trim($header) === '') {
            $header = @imap_fetchheader($imap, $uid, FT_UID | FT_PREFETCHTEXT);
        }
        if (is_string($header) && trim($header) !== '') {
            return "Este mensaje no contiene un cuerpo legible para IMAP.\n\n" . $this->summarizeHeaders($header);
        }

        return 'Este mensaje no contiene un cuerpo legible para IMAP.';
    }

    /**
     * @return array<int, string>
     */
    private function decodeCandidates(string $raw): array
    {
        $base64 = preg_replace('/\s+/', '', $raw) ?? $raw;
        $decodedBase64 = base64_decode($base64, true);

        return array_values(array_filter([
            $this->cleanText($raw),
            $this->cleanText(quoted_printable_decode($raw)),
            is_string($decodedBase64) ? $this->cleanText($decodedBase64) : null,
            is_string($decodedBase64) ? $this->cleanText(quoted_printable_decode($decodedBase64)) : null,
        ], static fn (?string $value): bool => $value !== null && trim($value) !== ''));
    }

    private function looksLikeMimeHeadersOnly(string $value): bool
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", "\n", $value)))));
        if ($lines === []) {
            return true;
        }

        $headerLines = 0;
        foreach ($lines as $line) {
            if (preg_match('/^(content-type|content-transfer-encoding|content-disposition|mime-version|boundary):/i', $line) === 1) {
                $headerLines++;
            }
        }

        return $headerLines > 0 && $headerLines >= count($lines) - 1;
    }

    private function summarizeHeaders(string $header): string
    {
        $keep = [];
        foreach (explode("\n", str_replace("\r", "\n", $header)) as $line) {
            $line = trim($line);
            if (preg_match('/^(from|to|subject|date|content-type):/i', $line) === 1) {
                $keep[] = $this->cleanText($line) ?? $line;
            }
        }

        return implode("\n", array_slice($keep, 0, 8));
    }

    private function emptyBodyMessage(mixed $imap, int $uid, array $overview, ?int $messageNo = null): string
    {
        $messageNo ??= $this->messageNumber($imap, $uid);
        $header = @imap_fetchheader($imap, $messageNo, FT_PREFETCHTEXT);
        if (!is_string($header) || trim($header) === '') {
            $header = @imap_fetchheader($imap, $uid, FT_UID | FT_PREFETCHTEXT);
        }
        $summary = is_string($header) && trim($header) !== ''
            ? $this->summarizeHeaders($header)
            : '';

        if ($summary === '') {
            $summary = implode("\n", array_filter([
                'From: ' . $this->decodeHeader((string) ($overview['from'] ?? '')),
                'To: ' . $this->decodeHeader((string) ($overview['to'] ?? '')),
                'Subject: ' . $this->decodeHeader((string) ($overview['subject'] ?? '')),
                'Date: ' . (string) ($overview['date'] ?? ''),
            ], static fn (string $line): bool => trim(substr($line, strpos($line, ':') + 1)) !== ''));
        }

        return trim(
            "Este mensaje no contiene un cuerpo legible desde IMAP.\n\n" .
            "Puede ser un aviso sin texto plano/HTML o un correo cuyo contenido viene solo como adjunto.\n\n" .
            $summary
        );
    }

    private function decodePart(string $content, int $encoding, ?string $charset = null): string
    {
        $decoded = match ($encoding) {
            3 => base64_decode($content, true) ?: '',
            4 => quoted_printable_decode($content),
            default => $content,
        };

        return $this->toUtf8($decoded, $charset);
    }

    private function publicAccount(array $account): array
    {
        $passwordConfigured = !empty($account['password_encrypted']);
        unset($account['password_encrypted']);
        $account['password_configurada'] = $passwordConfigured;
        return $account;
    }

    private function encrypt(string $plain): string
    {
        $key = $this->encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new HttpException('No se pudo cifrar la password de correo', 500);
        }

        return base64_encode($iv . $tag . $cipher);
    }

    private function decrypt(string $encrypted): string
    {
        $raw = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) <= 28) {
            throw new HttpException('Password de correo invalida', 500);
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new HttpException('No se pudo descifrar la password de correo', 500);
        }

        return $plain;
    }

    private function encryptionKey(): string
    {
        $secret = (string) Env::get('CORREO_ENCRYPTION_KEY', Env::get('APP_KEY', Env::get('JWT_SECRET', '')));
        if (trim($secret) === '') {
            throw new HttpException('Falta configurar CORREO_ENCRYPTION_KEY para guardar passwords de correo', 500);
        }

        return hash('sha256', $secret, true);
    }

    private function password(array $account): string
    {
        return $this->decrypt((string) $account['password_encrypted']);
    }

    private function decodeHeader(string $value): string
    {
        try {
            $parts = function_exists('imap_mime_header_decode') ? @imap_mime_header_decode($value) : false;
        } catch (Throwable) {
            return $value;
        }
        if (!is_array($parts)) {
            return $value;
        }
        $decoded = '';
        foreach ($parts as $part) {
            $text = (string) ($part->text ?? '');
            $charset = isset($part->charset) ? (string) $part->charset : null;
            $decoded .= $this->toUtf8($text, $charset);
        }
        return $this->cleanText($decoded) ?? '';
    }

    private function partCharset(object $part): ?string
    {
        foreach (['parameters', 'dparameters'] as $property) {
            if (!isset($part->{$property}) || !is_array($part->{$property})) {
                continue;
            }
            foreach ($part->{$property} as $parameter) {
                if (!is_object($parameter)) {
                    continue;
                }
                $attribute = strtolower((string) ($parameter->attribute ?? ''));
                if ($attribute === 'charset') {
                    return (string) ($parameter->value ?? '');
                }
            }
        }

        return null;
    }

    private function toUtf8(string $value, ?string $charset = null): string
    {
        $charset = trim((string) $charset);
        if ($charset === '' || strcasecmp($charset, 'default') === 0) {
            $charset = 'UTF-8';
        }

        if (strcasecmp($charset, 'UTF-8') === 0 && preg_match('//u', $value) === 1) {
            return $value;
        }

        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
            $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        // Ultimo recurso: descartar bytes no validos para no romper la respuesta.
        return preg_replace('/[\x80-\xFF]/', '', $value) ?? '';
    }

    private function cleanText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;
        if ($text === '') {
            return '';
        }

        $text = $this->toUtf8($text);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return is_string($clean) ? $clean : $text;
    }

    private function safeCleanHeader(mixed $value): string
    {
        try {
            return $this->cleanText($this->decodeHeader((string) $value)) ?? '';
        } catch (Throwable) {
            return preg_replace('/[^\P{C}\t\r\n]+/u', '', (string) $value) ?? '';
        }
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<style[\s\S]*?<\/style>/i', ' ', $html) ?? $html;
        $html = preg_replace('/<script[\s\S]*?<\/script>/i', ' ', $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/p\s*>/i', "\n\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function addresses(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $addresses = [];
        foreach ($items as $item) {
            $email = trim((string) $item);
            if ($email === '') {
                continue;
            }
            $addresses[] = $this->email($email);
        }

        return array_values(array_unique($addresses));
    }

    private function email(string $value): string
    {
        $email = trim(strtolower($value));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('Email invalido', 422);
        }

        return $email;
    }

    private function host(mixed $value): string
    {
        $host = trim((string) $value);
        if ($host === '' || strlen($host) > 190) {
            throw new HttpException('Host de correo invalido', 422);
        }

        return $host;
    }

    private function port(mixed $value): int
    {
        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            throw new HttpException('Puerto de correo invalido', 422);
        }

        return $port;
    }

    private function encryption(mixed $value): string
    {
        $encryption = strtolower(trim((string) $value));
        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            throw new HttpException('Cifrado de correo invalido', 422);
        }

        return $encryption;
    }

    private function phpMailerEncryption(string $value): string
    {
        return match ($value) {
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            'none' => '',
            default => PHPMailer::ENCRYPTION_SMTPS,
        };
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function boolInt(mixed $value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) ? 1 : 0;
    }

    private function safeImapError(string $error): string
    {
        return str_replace(["\r", "\n"], ' ', mb_substr($error, 0, 180));
    }

    private function trimOrNull(?string $value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $maxLength);
    }

    private function extractEmail(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $match) === 1) {
            return strtolower(trim($match[1]));
        }
        if (filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
            return strtolower(trim($value));
        }

        return '';
    }

    private function extractName(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(.*?)<[^>]+>/', $value, $match) === 1) {
            return trim(trim($match[1]), " \"'");
        }

        return '';
    }

    private function makeSnippet(string $text, ?string $html): string
    {
        $source = trim($text) !== '' ? $text : $this->htmlToText((string) $html);
        $source = preg_replace('/\s+/', ' ', (string) $this->cleanText($source)) ?? '';

        return trim(mb_substr(trim($source), 0, 280));
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }
}
