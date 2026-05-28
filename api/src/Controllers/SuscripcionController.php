<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\Auth;
use Mypos\Core\Request;
use Mypos\Services\SuscripcionService;

use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Throwable;

class SuscripcionController
{
    private SuscripcionService $service;

    public function __construct()
    {
        $this->service = new SuscripcionService();
    }

    public function createOrder(): void
    {
        $this->respond(function (): array {
            $payload = Request::json();
            $empresaId = Auth::empresaId();
            $usuarioId = Auth::id();

            if ($empresaId === null) {
                throw new HttpException('empresa_id obligatorio', 422);
            }
            
            return $this->service->createPaymentOrder($payload, $empresaId, $usuarioId);
        }, 201);
    }

    public function flowWebhook(): void
    {
        // Flow envia form-urlencoded
        $token = $_POST['token'] ?? null;
        
        if (!$token) {
            http_response_code(400);
            echo 'Missing token';
            return;
        }

        try {
            $this->service->confirmFlowPayment($token);
            http_response_code(200);
            echo 'OK';
        } catch (\Throwable $e) {
            http_response_code(400);
            echo $e->getMessage();
        }
    }

    public function flowReturn(): void
    {
        // El frontend captura directamente desde Flow con un URL de retorno en el SPA.
        // Este endpoint no es estrictamente necesario ya que Flow redirige al frontend.
    }

    public function paypalReturn(): void
    {
        $token = $_GET['token'] ?? null;
        
        if (!$token) {
            http_response_code(400);
            echo 'Missing token';
            return;
        }

        try {
            $redirectUrl = $this->service->confirmPayPalPayment($token);
            header('Location: ' . $redirectUrl);
            exit;
        } catch (\Throwable $e) {
            http_response_code(400);
            echo $e->getMessage();
        }
    }

    public function status(): void
    {
        $this->respond(function (): array {
            $empresaId = Auth::empresaId();

            if ($empresaId === null) {
                throw new HttpException('empresa_id obligatorio', 422);
            }

            return $this->service->getCurrentStatus($empresaId);
        });
    }

    public function orderStatus(): void
    {
        $this->respond(function (): array {
            $empresaId = Auth::empresaId();
            $usuarioId = Auth::id();
            $ordenNumero = trim((string) ($_GET['order'] ?? ''));

            if ($empresaId === null) {
                throw new HttpException('empresa_id obligatorio', 422);
            }

            return $this->service->getOrderStatus($ordenNumero, $empresaId, $usuarioId);
        });
    }

    public function paymentConfig(): void
    {
        $this->respond(function (): array {
            Auth::id();

            return $this->service->getPaymentConfig();
        });
    }

    private function respond(callable $callback, int $statusCode = 200): void
    {
        try {
            Response::success($callback(), null, $statusCode);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
