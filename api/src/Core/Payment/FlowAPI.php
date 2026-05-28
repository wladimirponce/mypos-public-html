<?php

declare(strict_types=1);

namespace Mypos\Core\Payment;

use Exception;

class FlowException extends Exception {}

class FlowAPI
{
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;
    private string $paymentUrl;

    public function __construct()
    {
        $this->apiKey = $_ENV['FLOW_API_KEY'] ?? getenv('FLOW_API_KEY') ?: '';
        $this->secretKey = $_ENV['FLOW_SECRET_KEY'] ?? getenv('FLOW_SECRET_KEY') ?: '';

        $mode = $_ENV['FLOW_MODE'] ?? getenv('FLOW_MODE') ?: 'sandbox';

        if ($mode === 'production') {
            $this->baseUrl = 'https://www.flow.cl/api';
            $this->paymentUrl = 'https://www.flow.cl/app/web/pay.php';
        } else {
            $this->baseUrl = 'https://sandbox.flow.cl/api';
            $this->paymentUrl = 'https://sandbox.flow.cl/app/web/pay.php';
        }
    }

    private function sign(array $params): string
    {
        ksort($params);
        $buf = '';
        foreach ($params as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            }
            $buf .= $k . $v;
        }

        return hash_hmac('sha256', $buf, $this->secretKey);
    }

    private function request(string $endpoint, array $params, string $method = 'GET'): array
    {
        $this->ensureConfigured();

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $ch = curl_init();
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($method === 'POST') {
            $opt[CURLOPT_POST] = true;
            $opt[CURLOPT_POSTFIELDS] = http_build_query($params);
            $opt[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];
        } else {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
            $opt[CURLOPT_HTTPHEADER] = ['Accept: application/json'];
        }

        $opt[CURLOPT_URL] = $url;
        curl_setopt_array($ch, $opt);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new FlowException("cURL Flow: {$err}");
        }

        $data = json_decode((string) $resp, true);
        if (!is_array($data)) {
            throw new FlowException("Respuesta Flow no JSON (HTTP {$code})");
        }

        if ($code !== 200) {
            throw new FlowException('Flow error: ' . ($data['message'] ?? "HTTP {$code}"));
        }

        return $data;
    }

    private function ensureConfigured(): void
    {
        if ($this->apiKey === '' || $this->secretKey === '') {
            throw new FlowException('Flow no configurado: definir FLOW_API_KEY y FLOW_SECRET_KEY');
        }
    }

    /**
     * @return array{token:string,url:string}
     */
    public function createOrder(
        string $commerceOrder,
        string $correo,
        int $amountClp,
        string $subject,
        string $urlConfirmation,
        string $urlReturn,
        array $optional = []
    ): array {
        $params = [
            'apiKey' => $this->apiKey,
            'commerceOrder' => $commerceOrder,
            'subject' => $subject,
            'currency' => 'CLP',
            'amount' => $amountClp,
            'email' => $correo,
            'urlConfirmation' => $urlConfirmation,
            'urlReturn' => $urlReturn,
        ];

        if (!empty($optional)) {
            $params['optional'] = json_encode($optional);
        }

        $params['s'] = $this->sign($params);

        $resp = $this->request('/payment/create', $params, 'POST');
        if (empty($resp['token'])) {
            throw new FlowException('Flow no devolvio token');
        }

        $paymentUrl = isset($resp['url']) && is_string($resp['url']) && $resp['url'] !== ''
            ? $resp['url']
            : $this->paymentUrl;

        return [
            'token' => (string) $resp['token'],
            'url' => $paymentUrl . '?token=' . rawurlencode((string) $resp['token']),
        ];
    }

    public function getStatus(string $token): array
    {
        $params = ['apiKey' => $this->apiKey, 'token' => $token];
        $params['s'] = $this->sign($params);
        $status = $this->request('/payment/getStatus', $params, 'GET');

        if (!isset($status['status'])) {
            throw new FlowException('getStatus sin status');
        }

        return $status;
    }
}
