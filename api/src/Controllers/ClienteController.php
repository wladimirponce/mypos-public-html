<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\ClienteService;
use Mypos\Services\CreditoService;
use Throwable;

final class ClienteController
{
    private ClienteService $service;
    private CreditoService $creditoService;

    public function __construct()
    {
        $this->service = new ClienteService();
        $this->creditoService = new CreditoService();
    }

    public function store(): void
    {
        $this->respond(fn (int $userId): array => $this->service->crear(Request::json(), $userId), 'Cliente creado correctamente');
    }

    public function index(): void
    {
        $this->respond(fn (): array => $this->service->listar($_GET));
    }

    public function show(array $params): void
    {
        $this->respond(fn (): array => $this->service->ver((int) $params['id'], (int) ($_GET['empresa_id'] ?? 0)));
    }

    public function update(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->actualizar((int) $params['id'], Request::json(), $userId), 'Cliente actualizado correctamente');
    }

    public function destroy(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->eliminar((int) $params['id'], (int) ($_GET['empresa_id'] ?? 0), $userId), 'Cliente eliminado correctamente');
    }

    public function accountState(array $params): void
    {
        $this->respond(fn (): array => $this->creditoService->estadoCuenta((int) $params['id'], $_GET));
    }

    public function history(array $params): void
    {
        $this->respond(fn (): array => $this->creditoService->historialCliente((int) $params['id'], $_GET));
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = $this->requestEmpresaId();
            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }
            Response::success($callback($userId), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function requestEmpresaId(): int
    {
        if (isset($_GET['empresa_id'])) {
            return (int) $_GET['empresa_id'];
        }
        $payload = Request::json();
        return (int) ($payload['empresa_id'] ?? 0);
    }
}
