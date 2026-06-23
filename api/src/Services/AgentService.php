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

        [$statusCode, $response, $effectiveUrl, $transportError] = $this->postJson($agentUrl . '/chat', $body, $secret);

        if ($response === false) {
            error_log('No se pudo conectar con el agente IA: url=' . $effectiveUrl . ' error=' . $transportError);
            throw new HttpException(
                'No se pudo conectar con el agente IA'
                . ($transportError !== '' ? ': ' . $transportError : ''),
                503
            );
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            error_log(
                'Respuesta invalida del agente IA: HTTP '
                . $statusCode
                . ' url='
                . $effectiveUrl
                . ' body='
                . $this->safeSnippet($response)
            );
            throw new HttpException('El asistente no pudo responder en este momento. Intenta nuevamente en unos minutos.', 502);
        }

        if ($statusCode >= 400) {
            $detail = is_string($decoded['detail'] ?? null) ? (string) $decoded['detail'] : 'El agente IA no pudo responder';
            error_log(
                'Error del agente IA: HTTP '
                . $statusCode
                . ' url='
                . $effectiveUrl
                . ' detail='
                . $this->safeSnippet($detail)
            );

            throw new HttpException(
                $this->friendlyAgentMessage($statusCode, $detail),
                $statusCode >= 500 ? 502 : $statusCode,
                [
                    'agent_status' => [(string) $statusCode],
                    'agent_detail' => [$this->safeSnippet($detail)],
                    'agent_error_type' => [is_string($decoded['error_type'] ?? null) ? (string) $decoded['error_type'] : ''],
                ]
            );
        }

        return [
            'thread_id' => (string) ($decoded['thread_id'] ?? ''),
            'reply' => (string) ($decoded['reply'] ?? ''),
            'escalated' => (bool) ($decoded['escalated'] ?? false),
        ];
    }

    /**
     * @return array{0:int,1:string|false,2:string,3:string}
     */
    private function postJson(string $url, string $body, string $secret): array
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle !== false) {
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'X-Agent-Secret: ' . $secret,
                    ],
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_TIMEOUT => 45,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                ]);
                if (defined('CURL_IPRESOLVE_V4')) {
                    curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                }

                $response = curl_exec($handle);
                $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
                $transportError = $response === false ? curl_error($handle) : '';
                curl_close($handle);

                return [
                    $statusCode > 0 ? $statusCode : 503,
                    $response,
                    $effectiveUrl !== '' ? $effectiveUrl : $url,
                    $transportError,
                ];
            }
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

        $response = @file_get_contents($url, false, $context);

        return [
            $this->statusCode($http_response_header ?? []),
            $response,
            $url,
            $response === false ? 'file_get_contents fallo al abrir la URL del agente' : '',
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

    private function safeSnippet(string $response): string
    {
        $snippet = preg_replace('/\s+/', ' ', strip_tags($response));
        if (!is_string($snippet)) {
            return '';
        }

        return substr(trim($snippet), 0, 300);
    }

    private function friendlyAgentMessage(int $statusCode, string $detail): string
    {
        $text = strtolower($detail);

        if (
            str_contains($text, 'resource_exhausted')
            || str_contains($text, 'quota')
            || str_contains($text, '429')
            || str_contains($text, 'rate limit')
        ) {
            return 'La IA alcanzo su limite temporal. Espera unos minutos o usa una consulta directa como ventas de hoy, cajas, stock o producto.';
        }

        if (
            str_contains($text, 'unavailable')
            || str_contains($text, 'high demand')
            || str_contains($text, '503')
            || str_contains($text, 'overloaded')
        ) {
            return 'El servicio de IA esta con alta demanda. Intentemos de nuevo en unos minutos.';
        }

        if ($statusCode === 504 || str_contains($text, 'timeout') || str_contains($text, 'no respondio')) {
            return 'La IA tardo demasiado en responder. Intenta con una pregunta mas directa o vuelve a probar en unos minutos.';
        }

        if ($statusCode >= 500) {
            return 'El asistente no pudo responder en este momento. Intenta nuevamente en unos minutos.';
        }

        return $detail !== '' ? $detail : 'No se pudo completar la consulta al asistente.';
    }
}
