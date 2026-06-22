<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\SubscriptionMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\AgentService;
use Throwable;

final class AgentController
{
    private AgentService $service;

    public function __construct()
    {
        $this->service = new AgentService();
    }

    public function chat(): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) ($claims['user_id'] ?? 0);
            $email = (string) ($claims['email'] ?? '');
            $payload = Request::json();

            $empresaId = (int) ($payload['empresa_id'] ?? 0);
            if ($empresaId <= 0) {
                throw new HttpException('empresa_id obligatorio', 422);
            }

            (new TenantMiddleware())->handle($userId, $empresaId);
            (new SubscriptionMiddleware())->handle();

            $message = trim((string) ($payload['message'] ?? ''));
            if ($message === '') {
                throw new HttpException('El mensaje es obligatorio', 422, [
                    'message' => ['El mensaje es obligatorio'],
                ]);
            }

            $agentPayload = [
                'message' => $message,
                'empresa_id' => $empresaId,
                'sucursal_id' => $this->positiveInt($payload['sucursal_id'] ?? null),
                'operator_name' => trim((string) ($payload['operator_name'] ?? $email)),
                'thread_id' => $this->optionalString($payload['thread_id'] ?? null),
            ];

            Response::success($this->service->chat($agentPayload));
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($number) && $number > 0 ? $number : null;
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
