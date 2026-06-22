<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Core\HttpException;
use Mypos\Support\Env;

final class AgentService
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function chat(array $payload): array
    {
        $agentUrl = rtrim((string) Env::get('MYPOS_AGENT_URL', 'http://127.0.0.1/agent'), '/');
        $secret = trim((string) Env::get('AGENT_SECRET', ''));

        if ($secret === '') {
            throw new HttpException('Agente IA no configurado', 503);
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new HttpException('No se pudo preparar la solicitud al agente', 422);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Agent-Secret: ' . $secret,
                ],
                'content' => $body,
                'timeout' => 45,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($agentUrl . '/chat', false, $context);
        $statusCode = $this->statusCode($http_response_header ?? []);

        if ($response === false) {
            throw new HttpException('No se pudo conectar con el agente IA', 503);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new HttpException('Respuesta invalida del agente IA', 502);
        }

        if ($statusCode >= 400) {
            $detail = is_string($decoded['detail'] ?? null) ? (string) $decoded['detail'] : 'El agente IA no pudo responder';
            throw new HttpException($detail, $statusCode >= 500 ? 502 : $statusCode);
        }

        return [
            'thread_id' => (string) ($decoded['thread_id'] ?? ''),
            'reply' => (string) ($decoded['reply'] ?? ''),
            'escalated' => (bool) ($decoded['escalated'] ?? false),
        ];
    }

    /**
     * @param array<int, string> $headers
     */
    private function statusCode(array $headers): int
    {
        $statusLine = $headers[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) !== 1) {
            return 200;
        }

        return (int) $matches[1];
    }
}
