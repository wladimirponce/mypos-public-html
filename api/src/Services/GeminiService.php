<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Core\HttpException;

final class GeminiService
{
    private const PROMPT = <<<'PROMPT'
Eres un extractor de documentos de compra para un sistema POS chileno llamado MyPOS.
Analiza la imagen/PDF de una factura o guia de despacho de compra.
Devuelve SOLO JSON valido, sin markdown, sin explicacion.
Si un dato no esta disponible, usa null.
No inventes productos, totales ni RUT.
Estructura:
{
  "tipo_documento": "FACTURA_COMPRA|GUIA_DESPACHO|DESCONOCIDO",
  "proveedor_rut": null,
  "proveedor_nombre": null,
  "folio": null,
  "fecha_documento": null,
  "neto": 0,
  "iva": 0,
  "exento": 0,
  "total": 0,
  "moneda": "CLP",
  "confianza_global": 0,
  "items": [
    {
      "codigo_detectado": null,
      "nombre_detectado": null,
      "cantidad_detectada": 0,
      "costo_unitario_detectado": 0,
      "total_detectado": 0,
      "confianza": 0
    }
  ],
  "observaciones": []
}
Reglas:
- Montos CLP como enteros.
- Cantidades como decimal.
- Fechas YYYY-MM-DD si es posible.
- RUT chileno como string.
- Si no encuentra items, items = [].
- confianza entre 0 y 1.
PROMPT;

    public function procesarDocumentoCompra(string $absolutePath, string $mimeType, array $contexto = []): array
    {
        if (!is_file($absolutePath)) {
            throw new HttpException('Archivo para IA no encontrado', 404);
        }

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw new HttpException('No fue posible leer archivo para IA', 500);
        }

        $request = [
            'contents' => [[
                'parts' => [
                    ['text' => self::PROMPT],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => base64_encode($content),
                        ],
                    ],
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.1,
            ],
        ];

        $response = $this->generateContent($request);
        $json = $this->extraerJsonRespuesta($response);

        return [
            'resultado' => $this->normalizar($json),
            'raw_response' => $response,
            'modelo' => $this->model(),
        ];
    }

    public function configuracionPublica(bool $habilitada): array
    {
        return [
            'proveedor' => 'GEMINI',
            'modelo' => $this->model(),
            'habilitada' => $habilitada,
            'api_key_configurada' => $this->apiKey() !== '',
        ];
    }

    public function generateContent(array $request): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            throw new HttpException('Gemini no esta configurado para esta instalacion.', 422);
        }

        $url = rtrim($this->apiBase(), '/') . '/models/' . rawurlencode($this->model()) . ':generateContent';
        $payload = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new HttpException('No fue posible preparar solicitud Gemini', 500);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $apiKey,
                ],
                'content' => $payload,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = $this->httpStatus($http_response_header ?? []);
        if ($body === false) {
            throw new HttpException('No fue posible conectar con Gemini', 502);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new HttpException('Gemini respondio un formato invalido', 502);
        }

        if ($status >= 400) {
            throw new HttpException('Gemini rechazo la solicitud de procesamiento', 502);
        }

        return $decoded;
    }

    public function extraerJsonRespuesta(array $geminiResponse): array
    {
        $text = $geminiResponse['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new HttpException('Gemini no devolvio contenido procesable', 502);
        }

        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        $decoded = json_decode(trim($clean), true);
        if (!is_array($decoded)) {
            throw new HttpException('Gemini no devolvio JSON valido', 502);
        }

        return $decoded;
    }

    private function normalizar(array $json): array
    {
        $items = $json['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        return [
            'tipo_documento' => strtoupper((string) ($json['tipo_documento'] ?? 'DESCONOCIDO')),
            'proveedor_rut' => $this->nullableString($json['proveedor_rut'] ?? null),
            'proveedor_nombre' => $this->nullableString($json['proveedor_nombre'] ?? null),
            'folio' => $this->nullableString($json['folio'] ?? null),
            'fecha_documento' => $this->nullableString($json['fecha_documento'] ?? null),
            'neto' => $this->intAtLeast($json['neto'] ?? 0),
            'iva' => $this->intAtLeast($json['iva'] ?? 0),
            'exento' => $this->intAtLeast($json['exento'] ?? 0),
            'total' => $this->intAtLeast($json['total'] ?? 0),
            'moneda' => (string) ($json['moneda'] ?? 'CLP'),
            'confianza_global' => $this->confidence($json['confianza_global'] ?? 0),
            'items' => array_values(array_filter(array_map([$this, 'normalizarItem'], $items))),
            'observaciones' => is_array($json['observaciones'] ?? null) ? $json['observaciones'] : [],
        ];
    }

    private function normalizarItem(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        return [
            'codigo_detectado' => $this->nullableString($item['codigo_detectado'] ?? null),
            'nombre_detectado' => $this->nullableString($item['nombre_detectado'] ?? null) ?? 'Producto detectado',
            'cantidad_detectada' => is_numeric($item['cantidad_detectada'] ?? null) ? round((float) $item['cantidad_detectada'], 3) : 0,
            'costo_unitario_detectado' => $this->intAtLeast($item['costo_unitario_detectado'] ?? 0),
            'total_detectado' => $this->intAtLeast($item['total_detectado'] ?? 0),
            'confianza' => $this->confidence($item['confianza'] ?? 0),
        ];
    }

    private function intAtLeast(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
    }

    private function confidence(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, min(1.0, round((float) $value, 4)));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function apiKey(): string
    {
        return (string) ($_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: '');
    }

    private function model(): string
    {
        return (string) ($_ENV['GEMINI_MODEL'] ?? getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash');
    }

    private function apiBase(): string
    {
        return (string) ($_ENV['GEMINI_API_BASE'] ?? getenv('GEMINI_API_BASE') ?: 'https://generativelanguage.googleapis.com/v1beta');
    }

    private function httpStatus(array $headers): int
    {
        $line = $headers[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', (string) $line, $matches) === 1) {
            return (int) $matches[1];
        }

        return 200;
    }
}
