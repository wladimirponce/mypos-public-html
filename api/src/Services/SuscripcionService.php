<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Core\Payment\FlowAPI;
use Mypos\Core\Payment\FlowException;
use Mypos\Core\Payment\PayPalAPI;
use Mypos\Core\Payment\PayPalException;
use Mypos\Repositories\AuthRepository;
use Mypos\Repositories\SuscripcionRepository;
use Mypos\Support\AppConfig;
use Mypos\Support\PlanCatalog;

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
        if (!PlanCatalog::isValid($planId)) {
            throw new HttpException('Plan no valido', 422);
        }

        $plan = PlanCatalog::get($planId);
        return ['price_clp' => $plan['price_clp'], 'price_usd' => $plan['price_usd'], 'name' => $plan['nombre']];
    }

    public function createPaymentOrder(array $payload, int $empresaId, int $usuarioId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        if (!$this->authRepo->userHasEmpresaContext($usuarioId, $empresaId)) {
            throw new HttpException('Usuario no pertenece a la empresa', 403);
        }

        $gateway = strtolower((string) ($payload['gateway'] ?? 'flow'));
        $planId = PlanCatalog::normalize((string) ($payload['plan_id'] ?? 'mypos-start'));
        
        $setupFee = filter_var($payload['setup_fee'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $extraUsers = (int) ($payload['extra_users_count'] ?? 0);

        if (!in_array($gateway, ['flow', 'paypal'], true)) {
            throw new HttpException('Gateway de pago invalido', 422);
        }

        $plan = $this->getPlanDetails($planId);
        $targetPlan = PlanCatalog::get($planId);
        $currentUsage = (new PlanLimitService())->status($empresaId)['uso'];
        if ($currentUsage['sucursales'] > $targetPlan['max_sucursales'] || $currentUsage['usuarios'] > $targetPlan['max_usuarios']) {
            throw new HttpException(
                'No puedes contratar un plan con límites inferiores al uso actual. Desactiva locales o usuarios antes de cambiar de plan.',
                422,
                ['plan_limit' => ['uso_actual_supera_plan']]
            );
        }
        $ordenNumero = 'MP_' . time() . '_' . bin2hex(random_bytes(3));
        $correo = $this->userEmail($usuarioId);
        
        $baseMonto = $gateway === 'flow' ? $plan['price_clp'] : $plan['price_usd'];
        $moneda = $gateway === 'flow' ? 'CLP' : 'USD';

        // Precio especial recurrente (link de promoción): reemplaza el precio de
        // catálogo del plan de forma indefinida. Definido en CLP, aplica a Flow.
        if ($gateway === 'flow') {
            $sub = $this->repository->getSubscriptionStatus($empresaId);
            if ($sub !== null && !empty($sub['precio_especial_clp'])) {
                $baseMonto = (int) $sub['precio_especial_clp'];
            }
        }

        $montoExtraUsers = 0;
        if ($planId === 'mypos-escala' && $extraUsers > 0) {
            $montoExtraUsers = $gateway === 'flow' ? ($extraUsers * 5938) : ($extraUsers * 6.25);
        }
        
        $montoSetup = 0;
        if ($setupFee) {
            $montoSetup = $gateway === 'flow' ? 35688 : 38.00; // $29.990 + IVA
        }
        
        $montoTotal = $baseMonto + $montoExtraUsers + $montoSetup;

        $ordenId = $this->repository->createOrder(
            $ordenNumero,
            $empresaId,
            $usuarioId,
            $gateway,
            $planId,
            (float) $montoTotal,
            $moneda
        );

        try {
            if ($gateway === 'flow') {
                return $this->createFlowOrder($ordenId, $ordenNumero, $correo, (int) $montoTotal, $plan);
            }

            return $this->createPayPalOrder($ordenId, $ordenNumero, (float) $montoTotal, $plan);
        } catch (FlowException | PayPalException $exception) {
            $this->repository->markOrderRejected($ordenId);
            error_log($exception->getMessage());
            throw new HttpException(
                'No se pudo iniciar el pago en linea. Verifica la configuracion de ' . ucfirst($gateway) . ' o intenta con otro metodo.',
                503,
                AppConfig::debug() && !AppConfig::isProduction() ? ['payment' => [$exception->getMessage()]] : null
            );
        }
    }

    public function confirmFlowPayment(string $token): void
    {
        try {
            $status = $this->flowApi->getStatus($token);
        } catch (FlowException $exception) {
            error_log($exception->getMessage());
            throw new HttpException('No se pudo confirmar el pago en Flow', 503);
        }

        if ((int) $status['status'] !== 2) {
            throw new HttpException('Pago en Flow no completado', 400);
        }

        $orden = $this->repository->getOrderByFlowToken($token);
        if (!$orden) {
            throw new HttpException('Orden Flow no encontrada', 404);
        }

        if ($orden['estado'] !== 'completado') {
            $this->repository->markOrderCompleted((int) $orden['id']);
            $this->repository->updateOrActivateSubscription((int) $orden['empresa_id'], (string) $orden['plan_id']);
        }
    }

    public function confirmPayPalPayment(string $token): string
    {
        $orden = $this->repository->getOrderByPayPalOrderId($token);
        if (!$orden) {
            throw new HttpException('Orden PayPal no encontrada', 404);
        }

        if ($orden['estado'] !== 'completado') {
            try {
                $this->paypalApi->captureOrder($token);
            } catch (PayPalException $exception) {
                error_log($exception->getMessage());
                throw new HttpException('No se pudo capturar el pago en PayPal', 503);
            }

            $this->repository->markOrderCompleted((int) $orden['id']);
            $this->repository->updateOrActivateSubscription((int) $orden['empresa_id'], (string) $orden['plan_id']);
        }

        return $this->frontendUrl() . '/app/billing/return?gateway=paypal&status=success&order=' . urlencode((string) $orden['orden_numero']);
    }

    public function getCurrentStatus(int $empresaId, int $usuarioId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        if (!$this->authRepo->userHasEmpresaContext($usuarioId, $empresaId)) {
            throw new HttpException('Usuario no pertenece a la empresa', 403);
        }

        $status = $this->repository->getSubscriptionStatus($empresaId);
        if (!$status) {
            $limits = (new PlanLimitService())->status($empresaId);
            return [
                'has_subscription' => false,
                'estado' => 'inactiva',
                'plan_id' => $limits['plan']['id'],
                'plan_nombre' => $limits['plan']['nombre'],
                'limites' => $limits,
            ];
        }

        return [
            'has_subscription' => true,
            'plan_id' => PlanCatalog::normalize((string) $status['plan_id']),
            'plan_nombre' => PlanCatalog::get((string) $status['plan_id'])['nombre'],
            'limites' => (new PlanLimitService())->status($empresaId),
            'fecha_inicio' => $status['fecha_inicio'],
            'fecha_fin' => $status['fecha_fin'],
            'estado' => $status['estado'],
        ];
    }

    public function getPaymentConfig(): array
    {
        return [
            'flow' => [
                'configured' => $this->envValue('FLOW_API_KEY') !== '' && $this->envValue('FLOW_SECRET_KEY') !== '',
                'mode' => $this->envValue('FLOW_MODE') !== '' ? $this->envValue('FLOW_MODE') : 'sandbox',
            ],
            'paypal' => [
                'configured' => $this->envValue('PAYPAL_CLIENT_ID') !== ''
                    && ($this->envValue('PAYPAL_SECRET_KEY') !== '' || $this->envValue('PAYPAL_CLIENT_SECRET') !== ''),
                'mode' => $this->envValue('PAYPAL_MODE') !== '' ? $this->envValue('PAYPAL_MODE') : 'sandbox',
            ],
        ];
    }

    public function getOrderStatus(string $ordenNumero, int $empresaId, int $usuarioId): array
    {
        if ($ordenNumero === '') {
            throw new HttpException('orden obligatoria', 422);
        }

        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        if (!$this->authRepo->userHasEmpresaContext($usuarioId, $empresaId)) {
            throw new HttpException('Usuario no pertenece a la empresa', 403);
        }

        $orden = $this->repository->getOrderByNumber($ordenNumero);
        if (!$orden || (int) $orden['empresa_id'] !== $empresaId) {
            throw new HttpException('Orden no encontrada', 404);
        }

        return [
            'orden_numero' => $orden['orden_numero'],
            'gateway' => $orden['gateway'],
            'estado' => $orden['estado'],
            'plan_id' => $orden['plan_id'],
        ];
    }

    private function createFlowOrder(int $ordenId, string $ordenNumero, string $correo, int $monto, array $plan): array
    {
        $urlConfirmation = $this->apiBaseUrl() . '/api/v1/suscripciones/flow-webhook';
        $urlReturn = $this->frontendUrl() . '/app/billing/return?gateway=flow&order=' . urlencode($ordenNumero);

        $flowResp = $this->flowApi->createOrder(
            $ordenNumero,
            $correo,
            $monto,
            'Suscripcion MyPOS: ' . $plan['name'],
            $urlConfirmation,
            $urlReturn,
            ['orden_numero' => $ordenNumero]
        );

        $this->repository->updateOrderTokenFlow($ordenId, $flowResp['token']);

        return ['url' => $flowResp['url'], 'orden_numero' => $ordenNumero];
    }

    private function createPayPalOrder(int $ordenId, string $ordenNumero, float $monto, array $plan): array
    {
        $returnUrl = $this->apiBaseUrl() . '/api/v1/suscripciones/paypal-return?order=' . urlencode($ordenNumero);
        $cancelUrl = $this->frontendUrl() . '/app/billing/return?gateway=paypal&cancel=1&order=' . urlencode($ordenNumero);

        $paypalResp = $this->paypalApi->createOrder(
            $ordenNumero,
            $monto,
            'Suscripcion MyPOS: ' . $plan['name'],
            $returnUrl,
            $cancelUrl,
            'MyPOS SaaS',
            ['orden_numero' => $ordenNumero]
        );

        $this->repository->updateOrderTokenPayPal($ordenId, $paypalResp['id']);

        return ['url' => $paypalResp['approveUrl'], 'orden_numero' => $ordenNumero];
    }

    private function userEmail(int $usuarioId): string
    {
        $user = $this->authRepo->findUserById($usuarioId);

        return is_array($user) && isset($user['email']) && (string) $user['email'] !== ''
            ? (string) $user['email']
            : 'usuario@mypos.cl';
    }

    private function apiBaseUrl(): string
    {
        return rtrim(AppConfig::apiBaseUrl(), '/');
    }

    private function frontendUrl(): string
    {
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? getenv('FRONTEND_URL') ?: '';
        if ($frontendUrl !== '') {
            return rtrim($frontendUrl, '/');
        }

        $origins = AppConfig::corsAllowedOrigins();

        return rtrim((string) ($origins[0] ?? 'http://localhost:5173'), '/');
    }

    private function envValue(string $key): string
    {
        return (string) ($_ENV[$key] ?? getenv($key) ?: '');
    }
}
