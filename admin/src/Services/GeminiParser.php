<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\EnvLoader;

/**
 * Parsea el Set de Pruebas del SII usando la API de Gemini (Google AI).
 *
 * No requiere librerías externas — usa cURL nativo de PHP.
 * La API key se lee del .env: GEMINI_API_KEY=...
 *
 * Retorna el mismo esquema que CertSetManager::importarTxt() esperaría
 * del parser nativo, para que el resultado sea intercambiable.
 */
class GeminiParser
{
    private const MODEL    = 'gemini-2.0-flash';
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const TIMEOUT  = 45; // segundos

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = EnvLoader::getString('GEMINI_API_KEY');
        if ($this->apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY no está configurada en el archivo .env');
        }
    }

    /**
     * Devuelve true si la clave está definida en el entorno.
     * Útil para decidir si usar este parser sin instanciar la clase.
     */
    public static function disponible(): bool
    {
        return EnvLoader::getString('GEMINI_API_KEY') !== '';
    }

    /**
     * Envía el contenido crudo del .txt del SII a Gemini y retorna
     * la estructura analizada como array PHP.
     *
     * @param  string $rawContent  Contenido binario/crudo del archivo .txt
     * @return array               Misma estructura que set.json del CertSetManager
     * @throws \RuntimeException   Si la API falla o la respuesta no es JSON válido
     */
    public function parsearSetTxt(string $rawContent): array
    {
        // Convertir a UTF-8 antes de enviar (el SII entrega Latin-1 / Windows-1252)
        $enc = mb_detect_encoding($rawContent, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'ISO-8859-1';
        if ($enc !== 'UTF-8') {
            $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $enc);
        }

        $prompt   = $this->buildPrompt($rawContent);
        $response = $this->llamarApi($prompt);
        $parsed   = $this->extraerJson($response);

        $this->validar($parsed);

        return $parsed;
    }

    // =========================================================
    //  PROMPT
    // =========================================================

    private function buildPrompt(string $txt): string
    {
        return <<<PROMPT
Eres un extractor preciso de datos del SII (Servicio de Impuestos Internos de Chile).

Analiza el archivo TXT del Set de Pruebas SII adjunto y retorna la información en JSON válido.

═══════════════════════ REGLAS ESTRICTAS ═══════════════════════

TIPOS DE SET
- "BOLETA ELECTRONICA": precios con IVA incluido, casos CASO-1 / CASO-2 / ...
- "SET BASICO": precios NETOS (sin IVA), casos CASO NNNNNN-1 / CASO NNNNNN-2 / ...

ÍTEMS
- Si el nombre contiene "EXENTO" o "SERVICIO EXENTO": exento=true
- Si contiene "AFECTO": exento=false
- Si NO dice nada: exento=false (por defecto)
- Si OBSERVACION dice "Unidad de medida en Kg": agregar unidadMedida="Kg" a los ítems de ese caso
- Si OBSERVACION dice "item N ... exento": marcar ese ítem (por posición) como exento=true

DESCUENTOS
- "DESCUENTO ITEM" en la columna → descuento=(int)porcentaje en ese ítem
- "DESCUENTO GLOBAL ITEMES AFECTOS  NN%" → descuentoGlobal=(int)NN en el caso

NC / ND (sin ítems de precio)
- Tienen REFERENCIA y RAZON REFERENCIA en lugar de ítems
- referencia.caso_ref = "ATENCION-NUM" del caso al que referencia
- referencia.razon = texto de RAZON REFERENCIA
- Las NC de devolución parcial (CASO 6) SÍ tienen ítems con cantidad (sin precio)
- Si el caso NO muestra una tabla de ítems, devolver "items": [] — NUNCA
  inventar ítems ni copiar texto de otras secciones del archivo

TIPOS DTE (mapeo)
- FACTURA ELECTRONICA           → 33
- FACTURA NO AFECTA O EXENTA    → 34
- BOLETA ELECTRONICA            → 39
- NOTA DE DEBITO ELECTRONICA    → 56
- NOTA DE CREDITO ELECTRONICA   → 61
- GUIA DE DESPACHO              → 52

GUÍAS DE DESPACHO
- Extraer campo motivo (texto de MOTIVO:)
- Extraer trasladoPor (texto de TRASLADO POR:) — puede ser null
- ⚠️ Si la tabla de ítems tiene SOLO la columna CANTIDAD (sin PRECIO UNITARIO,
  típico en traslado interno entre bodegas), el número de cada fila es la
  CANTIDAD y el precio es 0. Ejemplo: "ITEM 1   71" → cantidad=71, precio=0

LIBRO DE COMPRAS
- Sección "SET LIBRO DE COMPRAS"
- Cada registro: tipo (int DTE), folio (int), obs (string), exento (int, 0 si no aparece), afecto (int)
- MAPEO DE TIPOS para el libro de compras (distinguir papel vs electrónico):
  · "FACTURA ELECTRONICA" → 33
  · "FACTURA NO AFECTA" → 34
  · "FACTURA DE COMPRA ELECTRONICA" → 46
  · "NOTA DE CREDITO ELECTRONICA" → 61
  · "NOTA DE DEBITO ELECTRONICA" → 56
  · "FACTURA" (sin "ELECTRONICA") → 30  ← papel
  · "FACTURA DE COMPRA" (sin "ELECTRONICA") → 45  ← papel
  · "NOTA DE CREDITO" (sin "ELECTRONICA") → 60  ← papel
  · "NOTA DE DEBITO" (sin "ELECTRONICA") → 55  ← papel

NÚMEROS DE ATENCIÓN
- SET BASICO → atencion_basico
- SET LIBRO DE VENTAS → atencion_ventas
- SET LIBRO DE COMPRAS → atencion_compras
- SET GUIA DE DESPACHO → atencion_guias
- SET LIBRO DE GUIAS → atencion_libro_guias
- Extraer solo los dígitos (null si la sección no existe en el archivo)

═══════════════════════ ESQUEMA JSON REQUERIDO ═══════════════════════

Retorna SOLO el JSON, sin markdown, sin explicaciones:

{
  "tipo": "boletas",
  "atencion_basico": null,
  "atencion_ventas": null,
  "atencion_compras": null,
  "atencion_guias": null,
  "atencion_libro_guias": null,
  "boletas": [
    {
      "caso": "CASO-1",
      "tipoDTE": 39,
      "referencia": { "codigo": "SET", "razon": "CASO-1" },
      "items": [
        {
          "nombre": "Cambio de aceite",
          "cantidad": 1,
          "precio": 19900,
          "descuento": 0,
          "exento": false,
          "unidadMedida": "UN"
        }
      ]
    }
  ],
  "facturas": [
    {
      "caso": "4884260-1",
      "tipoDTE": 33,
      "atencion": "4884260",
      "num": 1,
      "descuentoGlobal": 0,
      "motivo": null,
      "trasladoPor": null,
      "referencia": null,
      "items": [
        {
          "nombre": "Cajón AFECTO",
          "cantidad": 187,
          "precio": 4595,
          "descuento": 0,
          "exento": false,
          "unidadMedida": "UN"
        }
      ]
    }
  ],
  "libroCompras": [
    {
      "tipo": 30,
      "folio": 234,
      "obs": "FACTURA DEL GIRO CON DERECHO A CREDITO",
      "exento": 0,
      "afecto": 6814
    },
    {
      "tipo": 60,
      "folio": 451,
      "obs": "NOTA DE CREDITO POR DESCUENTO A FACTURA 234",
      "exento": 0,
      "afecto": 2624
    }
  ]
}

═══════════════════════ ARCHIVO A PROCESAR ═══════════════════════

$txt

═══════════════════════ FIN DEL ARCHIVO ═══════════════════════
PROMPT;
    }

    // =========================================================
    //  LLAMADA A LA API
    // =========================================================

    private function llamarApi(string $prompt): string
    {
        $url = self::BASE_URL . self::MODEL . ':generateContent?key=' . urlencode($this->apiKey);

        $body = json_encode([
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature'      => 0,      // determinista: no queremos variación en datos fiscales
                'maxOutputTokens'  => 8192,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errmsg = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new \RuntimeException("Gemini — cURL error ({$errno}): {$errmsg}");
        }

        if ($status !== 200) {
            $decoded = json_decode($raw, true);
            $msg     = $decoded['error']['message'] ?? $raw;
            throw new \RuntimeException("Gemini — HTTP {$status}: {$msg}");
        }

        return $raw;
    }

    // =========================================================
    //  EXTRAER Y VALIDAR JSON
    // =========================================================

    private function extraerJson(string $raw): array
    {
        $resp = json_decode($raw, true);
        if (!is_array($resp)) {
            throw new \RuntimeException('Gemini — la respuesta no es JSON válido');
        }

        // Verificar que no hubo bloqueo de seguridad
        $finishReason = $resp['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'SAFETY') {
            throw new \RuntimeException('Gemini — respuesta bloqueada por filtros de seguridad');
        }

        $text = $resp['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($text === null || trim($text) === '') {
            // Intentar promptFeedback para diagnóstico
            $feedback = $resp['promptFeedback']['blockReason'] ?? 'desconocida';
            throw new \RuntimeException("Gemini — respuesta vacía (razón de bloqueo: {$feedback})");
        }

        // responseMimeType=application/json ya garantiza JSON puro, pero igual limpiamos
        $text   = trim($text);
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            throw new \RuntimeException('Gemini — el texto devuelto no es un JSON válido: ' . substr($text, 0, 200));
        }

        return $parsed;
    }

    /**
     * Validación mínima: el JSON debe tener al menos boletas o facturas.
     */
    private function validar(array $data): void
    {
        $tieneBoletas  = !empty($data['boletas'])  && is_array($data['boletas']);
        $tieneFacturas = !empty($data['facturas']) && is_array($data['facturas']);

        if (!$tieneBoletas && !$tieneFacturas) {
            throw new \RuntimeException(
                'Gemini — no se reconocieron casos de boleta ni de facturación en la respuesta. ' .
                'Verifique que el archivo sea el .txt del Set de Pruebas del SII.'
            );
        }
    }
}
