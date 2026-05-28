<?php

declare(strict_types=1);

namespace Mypos\Core\Payment;

use Exception;

class PayPalException extends Exception {}

class PayPalAPI
{
    private string $clientId;
    private string $secretKey;
    private string $apiUrl;

    public function __construct()
    {
        $this->clientId = $_ENV['PAYPAL_CLIENT_ID'] ?? getenv('PAYPAL_CLIENT_ID') ?: '';
        $secretKey = $_ENV['PAYPAL_SECRET_KEY'] ?? getenv('PAYPAL_SECRET_KEY') ?: '';
        $clientSecret = $_ENV['PAYPAL_CLIENT_SECRET'] ?? getenv('PAYPAL_CLIENT_SECRET') ?: '';
        $this->secretKey = $secretKey !== '' ? $secretKey : $clientSecret;

        $mode = $_ENV['PAYPAL_MODE'] ?? getenv('PAYPAL_MODE') ?: 'sandbox';
        
        if ($mode === 'production') {
            $this->apiUrl = 'https://api-m.paypal.com';
        } else {
            $this->apiUrl = 'https://api-m.sandbox.paypal.com';
        }
    }

    private function getAccessToken(): string
    {
        if (empty($this->clientId) || empty($this->secretKey)) {
            throw new PayPalException('PayPal no configurado: definir PAYPAL_CLIENT_ID y PAYPAL_SECRET_KEY');
        }

        $ch = curl_init();
        $auth = base64_encode($this->clientId . ':' . $this->secretKey);
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl . '/v1/oauth2/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new PayPalException("cURL PayPal token: {$err}");
        }

        $data = json_decode((string)$resp, true);
        if ($code !== 200 || empty($data['access_token'])) {
            throw new PayPalException('PayPal token error: ' . ($data['error_description'] ?? "HTTP {$code}"));
        }
        return (string)$data['access_token'];
    }

    /**
     * @return array{id:string,approveUrl:string}
     */
    public function createOrder(
        string $commerceOrder,
        float $amountUsd,
        string $subject,
        string $returnUrl,
        string $cancelUrl,
        string $brandName,
        array $customData = []
    ): array {
        $token = $this->getAccessToken();
        $amountStr = number_format($amountUsd, 2, '.', '');

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $commerceOrder,
                'description' => $subject,
                'amount' => [
                    'currency_code' => 'USD',
                    'value' => $amountStr,
                    'breakdown' => ['item_total' => ['currency_code' => 'USD', 'value' => $amountStr]],
                ],
                'items' => [[
                    'name' => $subject,
                    'quantity' => '1',
                    'unit_amount' => ['currency_code' => 'USD', 'value' => $amountStr],
                ]],
                'custom_id' => json_encode(array_merge($customData, ['commerceOrder' => $commerceOrder])),
            ]],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'brand_name' => $brandName,
                'locale' => 'es-CL',
                'landing_page' => 'BILLING',
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
            ],
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl . '/v2/checkout/orders',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
                'PayPal-Request-Id: ' . uniqid('REQ_'),
                'Prefer: return=representation',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new PayPalException("cURL PayPal createOrder: {$err}");
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            throw new PayPalException("PayPal createOrder respuesta no JSON (HTTP {$code})");
        }

        if ($code >= 400 || empty($data['id'])) {
            throw new PayPalException('PayPal createOrder: ' . ($data['message'] ?? "HTTP {$code}"));
        }

        $approveUrl = '';
        foreach (($data['links'] ?? []) as $l) {
            if (($l['rel'] ?? '') === 'approve') {
                $approveUrl = $l['href'];
                break;
            }
        }
        if ($approveUrl === '') {
            throw new PayPalException('PayPal approve link ausente');
        }

        return [
            'id' => (string)$data['id'],
            'approveUrl' => $approveUrl,
        ];
    }

    /**
     * @return array
     */
    public function captureOrder(string $paypalOrderId): array
    {
        $token = $this->getAccessToken();
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl . "/v2/checkout/orders/{$paypalOrderId}/capture",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
                'PayPal-Request-Id: ' . uniqid('CAP_'),
                'Prefer: return=representation',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new PayPalException("cURL PayPal capture: {$err}");
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            throw new PayPalException("PayPal capture respuesta no JSON (HTTP {$code})");
        }

        if ($code >= 400) {
            throw new PayPalException('PayPal capture: ' . ($data['message'] ?? "HTTP {$code}"));
        }
        if (($data['status'] ?? '') !== 'COMPLETED') {
            throw new PayPalException('Estado PayPal no COMPLETED. Status: ' . ($data['status'] ?? 'None'));
        }

        return $data;
    }
}
