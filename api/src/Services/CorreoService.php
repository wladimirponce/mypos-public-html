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
            $overview = imap_fetch_overview($imap, (string) $uid, FT_UID);
            if (!is_array($overview) || !isset($overview[0])) {
                throw new HttpException('Mensaje no encontrado', 404);
            }
            $row = (array) $overview[0];
            $body = $this->fetchBody($imap, $uid);

            return [
                'mensaje' => [
                    'uid' => $uid,
                    'subject' => $this->decodeHeader((string) ($row['subject'] ?? '(sin asunto)')),
                    'from' => $this->decodeHeader((string) ($row['from'] ?? '')),
                    'to' => $this->decodeHeader((string) ($row['to'] ?? '')),
                    'date' => (string) ($row['date'] ?? ''),
                    'seen' => !empty($row['seen']),
                    'body_text' => $body['text'],
                    'body_html' => $body['html'],
                ],
            ];
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
        $structure = imap_fetchstructure($imap, (string) $uid, FT_UID);
        if (!isset($structure->parts) || !is_array($structure->parts)) {
            $text = imap_body($imap, (string) $uid, FT_UID) ?: '';
            return ['text' => trim($text), 'html' => null];
        }

        $text = null;
        $html = null;
        foreach ($structure->parts as $index => $part) {
            $section = (string) ($index + 1);
            $subtype = strtoupper((string) ($part->subtype ?? ''));
            if (($part->type ?? null) !== 0 || !in_array($subtype, ['PLAIN', 'HTML'], true)) {
                continue;
            }
            $content = imap_fetchbody($imap, (string) $uid, $section, FT_UID) ?: '';
            $content = $this->decodePart($content, (int) ($part->encoding ?? 0));
            if ($subtype === 'HTML') {
                $html = $content;
            } else {
                $text = $content;
            }
        }

        return ['text' => $text, 'html' => $html];
    }

    private function decodePart(string $content, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($content, true) ?: '',
            4 => quoted_printable_decode($content),
            default => $content,
        };
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
        $parts = function_exists('imap_mime_header_decode') ? imap_mime_header_decode($value) : false;
        if (!is_array($parts)) {
            return $value;
        }
        $decoded = '';
        foreach ($parts as $part) {
            $decoded .= (string) ($part->text ?? '');
        }
        return $decoded;
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
