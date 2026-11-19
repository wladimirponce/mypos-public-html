<?php

declare(strict_types=1);

namespace Mypos\Support;

use Mypos\Core\HttpException;

/**
 * Cliente HTTP puro para la API de MercadoPago (Orders / Point).
 *
 * No conoce empresas ni base de datos: se instancia con el access_token de la
 * empresa (ya descifrado) y expone solo las operaciones que el flujo necesita.
 * La verdad final del pago se obtiene siempre con obtenerOrden(); el webhook es
 * solo el disparador.
 *
 * @see https://www.mercadopago.cl/developers -> Orders API (type=point)
 */
final class MercadoPagoClient
{
    private const BASE_URL = 'https://api.mercadopago.com';

    public function __construct(
        private readonly string $accessToken,
        private readonly int $timeout = 30
    ) {
        if (trim($this->accessToken) === '') {
            throw new HttpException('Falta el access_token de MercadoPago', 500);
        }
    }

    /**
     * Crea una order tipo point sobre una terminal. Exige X-Idempotency-Key.
     *
     * @param array<string, mixed> $payload cuerpo completo de la order
     * @return array{status:int, body:array<string,mixed>, raw:string}
     */
    public function crearOrdenPoint(array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/orders', $payload, [
            'X-Idempotency-Key: ' . $idempotencyKey,
        ]);
    }

    /**
     * Consulta el estado real de una order por su id.
     *
     * @return array{status:int, body:array<string,mixed>, raw:string}
     */
    public function obtenerOrden(string $orderId): array
    {
        return $this->request('GET', '/v1/orders/' . rawurlencode($orderId));
    }

    /**
     * Cancela una order pendiente (para liberar la terminal si el cajero aborta).
     *
     * @return array{status:int, body:array<string,mixed>, raw:string}
     */
    public function cancelarOrden(string $orderId, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/orders/' . rawurlencode($orderId) . '/cancel', [], [
            'X-Idempotency-Key: ' . $idempotencyKey,
        ]);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<int, string> $extraHeaders
     * @return array{status:int, body:array<string,mixed>, raw:string}
     */
    private function request(string $method, string $path, ?array $body = null, array $extraHeaders = []): array
    {
        $handle = curl_init(self::BASE_URL . $path);
        if ($handle === false) {
            throw new HttpException('No se pudo inicializar la conexion con MercadoPago', 502);
        }

        $headers = array_merge([
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($body !== null && ($method === 'POST' || $method === 'PUT')) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new HttpException('Error de red al llamar a MercadoPago: ' . $error, 502);
        }

        $decoded = json_decode((string) $response, true);

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : [],
            'raw' => (string) $response,
        ];
    }

    /** UUID v4 para X-Idempotency-Key. */
    public static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
