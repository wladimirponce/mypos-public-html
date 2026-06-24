<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
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
            $overview = @imap_fetch_overview($imap, (string) $uid, FT_UID);
            if (!is_array($overview) || !isset($overview[0])) {
                throw new HttpException('Mensaje no encontrado', 404);
            }
            $row = (array) $overview[0];
            try {
                $body = $this->fetchBody($imap, $uid);
            } catch (Throwable $exception) {
                error_log('[CorreoService] mensaje body error: ' . $exception->getMessage());
                $fallbackBody = $this->safeFetchRawBody($imap, $uid);
                $body = [
                    'text' => $fallbackBody !== '' ? $fallbackBody : 'No se pudo leer el contenido de este mensaje.',
                    'html' => null,
                ];
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
            throw new HttpException('No se pudo leer este mensaje de correo.', 422);
        } finally {
            imap_close($imap);
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

    public function probar(int $empresaId): array
    {
        $account = $this->requireAccount($empresaId);
        $imap = $this->openImap($account);
        imap_close($imap);

        return ['ok' => true, 'message' => 'Conexion IMAP correcta'];
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

    private function fetchBody(mixed $imap, int $uid): array
    {
        try {
            $structure = @imap_fetchstructure($imap, (string) $uid, FT_UID);
            if (!is_object($structure)) {
                return ['text' => $this->safeFetchRawBody($imap, $uid), 'html' => null];
            }

            $body = ['text' => null, 'html' => null];
            $this->collectBodyParts($imap, $uid, $structure, '', $body);

            if ($body['text'] === null && $body['html'] === null) {
                $body['text'] = $this->safeFetchRawBody($imap, $uid);
            }
            if ($body['text'] === null && is_string($body['html']) && $body['html'] !== '') {
                $body['text'] = $this->htmlToText($body['html']);
            }

            return $body;
        } catch (Throwable $exception) {
            error_log('[CorreoService] fetchBody fallback: ' . $exception->getMessage());
            return ['text' => $this->safeFetchRawBody($imap, $uid), 'html' => null];
        }
    }

    private function collectBodyParts(mixed $imap, int $uid, object $part, string $sectionPrefix, array &$body): void
    {
        if (isset($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $index => $child) {
                if (!is_object($child)) {
                    continue;
                }
                $section = $sectionPrefix === '' ? (string) ($index + 1) : $sectionPrefix . '.' . ($index + 1);
                try {
                    $this->collectBodyParts($imap, $uid, $child, $section, $body);
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
        $content = @imap_fetchbody($imap, (string) $uid, $section, FT_UID);
        if (!is_string($content) || $content === '') {
            $content = @imap_fetchbody($imap, (string) $uid, $section, FT_UID | FT_PEEK);
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

    private function safeFetchRawBody(mixed $imap, int $uid): string
    {
        try {
            return $this->fetchRawBody($imap, $uid);
        } catch (Throwable $exception) {
            error_log('[CorreoService] raw body error: ' . $exception->getMessage());
            return '';
        }
    }

    private function fetchRawBody(mixed $imap, int $uid): string
    {
        $candidates = ['', '1', '1.1', '1.2', '2', '2.1', '2.2', 'TEXT'];
        foreach ($candidates as $section) {
            $text = $section === ''
                ? @imap_body($imap, (string) $uid, FT_UID | FT_PEEK)
                : @imap_fetchbody($imap, (string) $uid, $section, FT_UID | FT_PEEK);

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

        return preg_match('//u', $value) === 1 ? $value : utf8_encode($value);
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
}
