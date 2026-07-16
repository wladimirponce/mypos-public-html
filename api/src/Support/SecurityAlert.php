<?php

declare(strict_types=1);

namespace Mypos\Support;

final class SecurityAlert
{
    private const ALLOWED_CONTEXT = [
        'empresa_id', 'usuario_id', 'path', 'ip', 'resource', 'status',
        'count', 'window_seconds', 'reason', 'component', 'provider', 'amount',
    ];

    /**
     * Emite una alerta operativa. Es defensivo por diseño: cualquier fallo se
     * traga (log a error_log) para no romper NUNCA la operación principal que la
     * dispara. Las alertas son notificaciones, no la fuente de verdad (esa es la
     * auditoría).
     *
     * @param array<string,mixed> $context
     */
    public static function emit(string $event, string $severity, array $context = []): void
    {
        try {
            $payload = [
                'event_id' => bin2hex(random_bytes(12)),
                'event' => preg_replace('/[^a-z0-9._-]/i', '_', $event) ?: 'security.unknown',
                'severity' => self::severity($severity),
                'occurred_at' => date('c'),
                'correlation_id' => RequestContext::correlationId(),
                'environment' => AppConfig::env(),
                'context' => self::safeContext($context),
            ];

            SafeLogger::warning('Operational security alert', $payload);

            $url = self::envString('SECURITY_ALERT_WEBHOOK_URL');
            if ($url === '') {
                return;
            }

            self::sendWebhook($url, $payload);
        } catch (\Throwable $e) {
            error_log('[SecurityAlert] emit fallo (no crítico): ' . $e->getMessage());
        }
    }

    /** @param array<string,mixed> $payload */
    private static function sendWebhook(string $url, array $payload): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://')) {
            SafeLogger::error('Security alert webhook rejected: HTTPS URL required');
            return;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body) || !function_exists('curl_init')) {
            SafeLogger::error('Security alert webhook unavailable', ['component' => 'curl']);
            return;
        }

        $headers = ['Content-Type: application/json'];
        $signingSecret = self::envString('SECURITY_ALERT_SIGNING_SECRET');
        if ($signingSecret !== '') {
            $headers[] = 'X-MyPOS-Signature: sha256=' . hash_hmac('sha256', $body, $signingSecret);
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($status < 200 || $status >= 300) {
            SafeLogger::error('Security alert delivery failed', [
                'status' => $status,
                'reason' => $error !== '' ? $error : 'non_success_response',
            ]);
        }
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,scalar>
     */
    private static function safeContext(array $context): array
    {
        $safe = [];
        foreach (self::ALLOWED_CONTEXT as $key) {
            // isset() ya descarta null; solo se conservan escalares no sensibles.
            if (isset($context[$key]) && is_scalar($context[$key])) {
                $safe[$key] = $context[$key];
            }
        }

        return $safe;
    }

    private static function severity(string $severity): string
    {
        $severity = strtolower($severity);
        return in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'medium';
    }

    private static function envString(string $key): string
    {
        $value = Env::get($key, '');

        return is_string($value) ? trim($value) : '';
    }
}
