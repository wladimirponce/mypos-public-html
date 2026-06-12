<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Core\HttpException;

final class GeminiService
{
    private const MAX_TOTAL_INPUT_BYTES = 18874368;

    private const PROMPT = <<<'PROMPT'
Eres un extractor de documentos de compra para un sistema POS chileno llamado MyPOS.
Analiza la imagen/PDF de una factura o guia de despacho de compra.
Devuelve SOLO JSON valido, sin markdown, sin explicacion.
Si un dato no esta disponible, usa null.
No inventes productos, totales ni RUT.
Estructura:
{
  "tipo_documento": "FACTURA_COMPRA|GUIA_DESPACHO_COMPRA|BOLETA_COMPRA|DESCONOCIDO",
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
        "codigo_barra_detectado": null,
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
- Codigos EAN/UPC numericos de 8 a 14 digitos van en codigo_barra_detectado, no en codigo_detectado.
- Si no encuentra items, items = [].
- confianza entre 0 y 1.
PROMPT;

    public function procesarDocumentoCompra(string $absolutePath, string $mimeType, array $contexto = []): array
    {
        return $this->procesarDocumentoCompraMultipagina([['absolute_path' => $absolutePath, 'mime_type' => $mimeType]], $contexto);
    }

    public function procesarDocumentoCompraMultipagina(array $files, array $contexto = []): array
    {
        if ($files === [] || count($files) > 10) {
            throw new HttpException('El documento debe incluir entre 1 y 10 paginas', 422);
        }

        $totalBytes = 0;
        foreach ($files as $file) {
            $absolutePath = (string) ($file['absolute_path'] ?? '');
            if (!is_file($absolutePath)) {
                throw new HttpException('Archivo para IA no encontrado', 404);
            }
            $size = filesize($absolutePath);
            if ($size === false) {
                throw new HttpException('No fue posible determinar el tamano del archivo para IA', 500);
            }
            $totalBytes += $size;
        }
        if ($totalBytes > self::MAX_TOTAL_INPUT_BYTES) {
            throw new HttpException(
                'Las imagenes seleccionadas pesan demasiado para procesarlas juntas. Reduce su resolucion o envia menos paginas.',
                422
            );
        }

        $parts = [['text' => self::PROMPT . "\nLas imagenes o PDF adjuntos pertenecen al mismo documento y estan ordenados por pagina."]];
        foreach ($files as $file) {
            $absolutePath = (string) ($file['absolute_path'] ?? '');
            $content = file_get_contents($absolutePath);
            if ($content === false) {
                throw new HttpException('No fue posible leer archivo para IA', 500);
            }
            $parts[] = ['inline_data' => [
                'mime_type' => (string) ($file['mime_type'] ?? 'application/octet-stream'),
                'data' => base64_encode($content),
            ]];
        }

        $request = [
            'contents' => [[
                'parts' => $parts,
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.1,
            ],
        ];

        $response = $this->generateContent($request);
        $json = $this->extraerJsonRespuesta($response);
        $usage = is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [];
        $tokensOutput = null;
        if (isset($usage['candidatesTokenCount']) || isset($usage['thoughtsTokenCount'])) {
            $tokensOutput = (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0);
        }

        return [
            'resultado' => $this->normalizar($json),
            'raw_response' => $response,
            'modelo' => $this->model(),
            'tokens_input' => isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
            'tokens_output' => $tokensOutput,
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
            throw new HttpException('No fue posible preparar solicitud Gemini: ' . json_last_error_msg(), 500);
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
            throw new HttpException($this->geminiErrorMessage($status), $status === 429 ? 429 : 502);
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

        $documentType = strtoupper((string) ($json['tipo_documento'] ?? 'DESCONOCIDO'));
        if ($documentType === 'GUIA_DESPACHO') {
            $documentType = 'GUIA_DESPACHO_COMPRA';
        }

        return [
            'tipo_documento' => $documentType,
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
            'codigo_barra_detectado' => $this->nullableString($item['codigo_barra_detectado'] ?? null),
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

    private function geminiErrorMessage(int $status): string
    {
        return match ($status) {
            400 => 'Gemini rechazo el formato o tamano de las imagenes enviadas.',
            401, 403 => 'Gemini rechazo las credenciales o permisos configurados.',
            404 => 'El modelo Gemini configurado no existe o no esta disponible.',
            429 => 'Gemini alcanzo temporalmente su limite de solicitudes o cuota.',
            default => 'Gemini no esta disponible temporalmente.',
        };
    }
}
