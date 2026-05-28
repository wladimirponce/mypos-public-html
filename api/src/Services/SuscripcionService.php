<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Core\Payment\FlowAPI;
use Mypos\Core\Payment\PayPalAPI;
use Mypos\Repositories\SuscripcionRepository;
use Mypos\Repositories\AuthRepository;
use Throwable;

class SuscripcionService
{
    private SuscripcionRepository $repository;
    private AuthRepository $authRepo;
    private FlowAPI $flowApi;
    private PayPalAPI $paypalApi;

    public function __construct()
    {
        $db = Database::connection();
        $this->repository = new SuscripcionRepository($db);
        $this->authRepo = new AuthRepository($db);
        $this->flowApi = new FlowAPI();
        $this->paypalApi = new PayPalAPI();
    }

    private function getPlanDetails(string $planId): array
    {
        // This could come from DB, but we'll hardcode it per requirements for now.
        $plans = [
            'pos' => ['price_clp' => 11888, 'price_usd' => 12.50, 'name' => 'Plan POS ($9.990 + IVA)'],
            'multisucursal' => ['price_clp' => 35688, 'price_usd' => 38.00, 'name' => 'Plan MultiSucursal ($29.990 + IVA)'],
        ];

        if (!isset($plans[$planId])) {
            throw new HttpException(400, 'Plan no válido');
        }

        return $plans[$planId];
    }

    public function createPaymentOrder(array $payload, int $empresaId, int $usuarioId): array
    {
        $gateway = $payload['gateway'] ?? 'flow';
        $planId = $payload['plan_id'] ?? 'pos';

        if (!in_array($gateway, ['flow', 'paypal'], true)) {
            throw new HttpException(400, 'Gateway de pago inválido');
        }

        $plan = $this->getPlanDetails($planId);
        $ordenNumero = 'MP_' . time() . '_' . bin2hex(random_bytes(3));
        $correo = 'usuario@mypos.cl'; // En producción esto debería sacarse de AuthRepository

        $monto = $gateway === 'flow' ? $plan['price_clp'] : $plan['price_usd'];
        $moneda = $gateway === 'flow' ? 'CLP' : 'USD';

        $ordenId = $this->repository->createOrder(
            $ordenNumero,
            $empresaId,
            $usuarioId,
            $gateway,
            $planId,
            (float) $monto,
            $moneda
        );

        $base = $_ENV['API_BASE_URL'] ?? 'http://localhost:8082';
        // In reality, this API_BASE_URL handles the backend API.
        $frontendUrl = $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'http://localhost:5173';
        $frontendUrls = explode(',', $frontendUrl);
        $appUrl = $frontendUrls[0] ?? 'http://localhost:5173';

        if ($gateway === 'flow') {
            $urlConfirmation = $base . '/api/v1/suscripciones/flow-webhook';
            $urlReturn = $appUrl . '/app/billing/return?gateway=flow&order=' . $ordenNumero;

            $flowResp = $this->flowApi->createOrder(
                $ordenNumero,
                $correo,
                (int) $monto,
                'Suscripción MyPOS: ' . $plan['name'],
                $urlConfirmation,
                $urlReturn
            );

            $this->repository->updateOrderTokenFlow($ordenId, $flowResp['token']);

            return ['url' => $flowResp['url'], 'orden_numero' => $ordenNumero];
        }

        if ($gateway === 'paypal') {
            $returnUrl = $base . '/api/v1/suscripciones/paypal-return?order=' . $ordenNumero;
            $cancelUrl = $appUrl . '/app/billing/return?gateway=paypal&cancel=1&order=' . $ordenNumero;

            $paypalResp = $this->paypalApi->createOrder(
                $ordenNumero,
                (float) $monto,
                'Suscripción MyPOS: ' . $plan['name'],
                $returnUrl,
                $cancelUrl,
                'MyPOS SaaS'
            );

            $this->repository->updateOrderTokenPayPal($ordenId, $paypalResp['id']);

            return ['url' => $paypalResp['approveUrl'], 'orden_numero' => $ordenNumero];
        }

        throw new HttpException('Gateway de pago no implementado', 400);
    }

    public function confirmFlowPayment(string $token): void
    {
        $status = $this->flowApi->getStatus($token);

        // 2 means pagado
        if ((int)$status['status'] !== 2) {
            throw new HttpException('Pago en Flow no completado', 400);
        }

        $orden = $this->repository->getOrderByFlowToken($token);
        if (!$orden) {
            throw new HttpException('Orden Flow no encontrada', 404);
        }

        if ($orden['estado'] !== 'completado') {
            $this->repository->markOrderCompleted((int)$orden['id']);
            $this->repository->updateOrActivateSubscription((int)$orden['empresa_id'], $orden['plan_id']);
        }
    }

    public function confirmPayPalPayment(string $token): string
    {
        $orden = $this->repository->getOrderByPayPalOrderId($token);
        if (!$orden) {
            throw new HttpException('Orden PayPal no encontrada', 404);
        }

        if ($orden['estado'] !== 'completado') {
            $this->paypalApi->captureOrder($token);
            $this->repository->markOrderCompleted((int)$orden['id']);
            $this->repository->updateOrActivateSubscription((int)$orden['empresa_id'], $orden['plan_id']);
        }

        $frontendUrl = $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'http://localhost:5173';
        $frontendUrls = explode(',', $frontendUrl);
        $appUrl = $frontendUrls[0] ?? 'http://localhost:5173';

        return $appUrl . '/app/billing/return?gateway=paypal&status=success&order=' . $orden['orden_numero'];
    }

    public function getCurrentStatus(int $empresaId): array
    {
        $status = $this->repository->getSubscriptionStatus($empresaId);
        if (!$status) {
            return [
                'has_subscription' => false,
                'estado' => 'inactiva'
            ];
        }

        return [
            'has_subscription' => true,
            'plan_id' => $status['plan_id'],
            'fecha_inicio' => $status['fecha_inicio'],
            'fecha_fin' => $status['fecha_fin'],
            'estado' => $status['estado']
        ];
    }
}
