<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\EnvLoader;

/**
 * Cliente HTTP hacia el microservicio agent/ (FastAPI), para llamadas que
 * necesitan el LLM/quota-tracking que YA vive en agent/ (ej. proponer una
 * plantilla SQL para el módulo "Consultas IA"). Deliberadamente NO se
 * duplica una llamada directa a Gemini estilo GeminiParser: el agente ya
 * maneja cooldown de cuota compartida y fallback de proveedor (ver
 * agent/main.py::_is_quota_error / _mark_quota_exhausted); si admin llamara
 * a Gemini por su cuenta con la misma cuota, ambos procesos competirían sin
 * saber uno del otro.
 *
 * Reusa la misma convención que ya existe entre agent/ y el backend web
 * (MYPOS_AGENT_URL + AGENT_SECRET, ver web/mypos-backend/backend/src/Services/AgentService.php).
 * admin/autoload.php ya carga public_html/api/.env como fuente de entorno,
 * así que estas variables llegan sin configuración adicional si ya están
 * definidas ahí.
 */
class AgentHttpClient
{
    private const TIMEOUT = 25; // segundos

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string}
     */
    public static function postJson(string $path, array $payload): array
    {
        $baseUrl = rtrim(EnvLoader::getString('MYPOS_AGENT_URL', 'http://127.0.0.1/agent'), '/');
        $secret = EnvLoader::getString('AGENT_SECRET', '');

        if ($secret === '') {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'AGENT_SECRET no configurado en el entorno de admin.'];
        }

        $url = $baseUrl . '/' . ltrim($path, '/');
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'No se pudo serializar la solicitud.'];
        }

        [$status, $response, $transportError] = self::doPost($url, $body, $secret);

        if ($response === false) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $transportError !== '' ? $transportError : 'No se pudo conectar con el agente IA.'];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'status' => $status, 'data' => [], 'error' => 'Respuesta no valida del agente IA.'];
        }

        if ($status >= 400) {
            $detail = is_string($decoded['detail'] ?? null) ? (string)$decoded['detail'] : 'El agente IA no pudo responder.';
            return ['ok' => false, 'status' => $status, 'data' => $decoded, 'error' => $detail];
        }

        return ['ok' => true, 'status' => $status, 'data' => $decoded, 'error' => ''];
    }

    /**
     * @return array{0:int,1:string|false,2:string}
     */
    private static function doPost(string $url, string $body, string $secret): array
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
                    CURLOPT_TIMEOUT => self::TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => 10,
                ]);
                $response = curl_exec($handle);
                $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
                $error = $response === false ? curl_error($handle) : '';
                curl_close($handle);
                return [$status > 0 ? $status : 503, $response, $error];
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
                'timeout' => self::TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $status = 200;
        if (isset($http_response_header) && preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m) === 1) {
            $status = (int)$m[1];
        }
        return [$status, $response, $response === false ? 'file_get_contents fallo al abrir la URL del agente' : ''];
    }
}
