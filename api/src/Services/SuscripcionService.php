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
use Mypos\Support\SubscriptionChargePolicy;

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

    /**
     * Monto mensual real en CLP de una empresa.
     *
     * La regla dirigida define QUIEN paga; el monto personalizado define CUANTO.
     * Prioridad: `empresas_suscripcion.precio_especial_clp` (link de promocion o
     * columna "Monto mensual" del panel admin) > monto de la regla dirigida >
     * precio de catalogo del plan.
     *
     * @param array{price_clp:int,...} $plan
     * @param array<string,mixed>|null $suscripcion
     * @param array{amount_clp:int,...}|null $chargeRule
     */
    private function montoMensualClp(array $plan, ?array $suscripcion, ?array $chargeRule): int
    {
        return $this->montoNegociadoClp($suscripcion, $chargeRule) ?? (int) $plan['price_clp'];
    }

    /**
     * Estado efectivo de la suscripcion considerando el vencimiento del periodo.
     *
     * @param array<string,mixed> $suscripcion
     */
    private function estadoVigente(array $suscripcion): string
    {
        $estado = (string) $suscripcion['estado'];
        if ($estado !== 'activa') {
            return $estado;
        }

        $fechaFin = $suscripcion['fecha_fin'] ? strtotime((string) $suscripcion['fecha_fin']) : 0;

        return $fechaFin >= time() ? 'activa' : 'vencida';
    }

    /**
     * Monto pactado fuera del catalogo, o null si la empresa paga precio de lista.
     *
     * `null` es la senal que usa la SPA para mostrar la grilla de planes en vez de
     * una unica tarjeta con el valor acordado.
     *
     * @param array<string,mixed>|null $suscripcion
     * @param array{amount_clp:int,...}|null $chargeRule
     */
    private function montoNegociadoClp(?array $suscripcion, ?array $chargeRule): ?int
    {
        $precioEspecial = (int) ($suscripcion['precio_especial_clp'] ?? 0);
        if ($precioEspecial > 0) {
            return $precioEspecial;
        }

        return $chargeRule !== null ? (int) $chargeRule['amount_clp'] : null;
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
        $chargeRule = SubscriptionChargePolicy::forEmpresa($empresaId);
        
        $setupFee = filter_var($payload['setup_fee'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $extraUsers = (int) ($payload['extra_users_count'] ?? 0);

        if (!in_array($gateway, ['flow', 'paypal'], true)) {
            throw new HttpException('Gateway de pago invalido', 422);
        }

        if ($chargeRule !== null && $gateway !== 'flow') {
            throw new HttpException('El pago mensual de esta empresa se realiza exclusivamente mediante Flow', 422);
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

        // Precio especial recurrente (link de promoción o edición desde el panel
        // admin): reemplaza el precio de catálogo del plan de forma indefinida.
        // Definido en CLP, aplica a Flow.
        if ($gateway === 'flow') {
            $sub = $this->repository->getSubscriptionStatus($empresaId);
            $baseMonto = $this->montoMensualClp($plan, $sub, $chargeRule);
        }

        $montoExtraUsers = 0;
        if ($planId === 'mypos-escala' && $extraUsers > 0) {
            $montoExtraUsers = $gateway === 'flow' ? ($extraUsers * 5938) : ($extraUsers * 6.25);
        }
        
        $montoSetup = 0;
        if ($setupFee) {
            $montoSetup = $gateway === 'flow' ? 35688 : 38.00; // $29.990 + IVA
        }
        
        // Con regla dirigida se cobra la cuota mensual exacta, sin extras ni setup.
        $montoTotal = $chargeRule !== null
            ? $baseMonto
            : $baseMonto + $montoExtraUsers + $montoSetup;

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
            $empresaId = (int) $orden['empresa_id'];
            $chargeRule = SubscriptionChargePolicy::forEmpresa($empresaId);

            // El monto de la orden lo calcula el backend al crearla, por lo que un
            // desajuste solo aparece cuando el monto mensual cambio mientras el
            // cliente pagaba. Rechazar dejaria un cobro cursado sin servicio: se
            // deja trazado y se acredita igual.
            $montoEsperado = $this->montoMensualClp(
                PlanCatalog::get((string) $orden['plan_id']),
                $this->repository->getSubscriptionStatus($empresaId),
                $chargeRule
            );
            if ((int) $orden['monto'] !== $montoEsperado) {
                error_log(sprintf(
                    '[Suscripciones] Orden %s pagada por %d CLP; monto mensual vigente %d CLP (empresa %d)',
                    (string) $orden['orden_numero'],
                    (int) $orden['monto'],
                    $montoEsperado,
                    $empresaId
                ));
            }

            $restartPeriod = $chargeRule !== null
                && !$this->repository->hasCompletedFlowPayment($empresaId);
            $this->repository->markOrderCompleted((int) $orden['id']);
            $this->repository->updateOrActivateSubscription(
                (int) $orden['empresa_id'],
                (string) $orden['plan_id'],
                $restartPeriod
            );
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

        // Toda empresa paga: `payment_required` es siempre true y el estado que se
        // informa es el real, para que la SPA lleve al muro de pago a quien esta
        // vencido en vez de dejarlo chocando contra 402 en cada modulo.
        $chargeRule = SubscriptionChargePolicy::forEmpresa($empresaId);
        $status = $this->repository->getSubscriptionStatus($empresaId);
        if (!$status) {
            $limits = (new PlanLimitService())->status($empresaId);
            return [
                'has_subscription' => false,
                'estado' => 'inactiva',
                'plan_id' => $limits['plan']['id'],
                'plan_nombre' => $limits['plan']['nombre'],
                'limites' => $limits,
                'payment_required' => true,
                'monthly_amount_clp' => $this->montoNegociadoClp(null, $chargeRule),
                'payment_gateway' => $chargeRule['gateway'] ?? null,
                'exenta' => false,
            ];
        }

        return [
            'has_subscription' => true,
            'plan_id' => PlanCatalog::normalize((string) $status['plan_id']),
            'plan_nombre' => PlanCatalog::get((string) $status['plan_id'])['nombre'],
            'limites' => (new PlanLimitService())->status($empresaId),
            'fecha_inicio' => $status['fecha_inicio'],
            'fecha_fin' => $status['fecha_fin'],
            // El periodo puede haber vencido sin que el middleware haya alcanzado a
            // marcar la fila; se informa vencida igual para no mostrar un "activa"
            // que la primera llamada operativa va a desmentir con un 402.
            'estado' => $this->estadoVigente($status),
            'payment_required' => true,
            'monthly_amount_clp' => $this->montoNegociadoClp($status, $chargeRule),
            'payment_gateway' => $chargeRule['gateway'] ?? null,
            'exenta' => false,
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
