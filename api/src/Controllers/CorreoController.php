<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Services\CorreoService;
use Throwable;

final class CorreoController
{
    private CorreoService $service;

    public function __construct()
    {
        $this->service = new CorreoService();
    }

    public function configuracion(): void
    {
        $this->respond(fn (): array => $this->service->configuracion((int) ($_GET['empresa_id'] ?? 0)));
    }

    public function guardarConfiguracion(): void
    {
        $this->respond(fn (int $userId): array => $this->service->guardarConfiguracion(Request::json(), $userId), 'Cuenta de correo configurada');
    }

    public function inbox(): void
    {
        $this->respond(fn (): array => $this->service->inbox($_GET));
    }

    public function bandeja(): void
    {
        $this->respond(fn (): array => $this->service->bandeja($_GET));
    }

    public function sincronizar(): void
    {
        $this->respond(
            fn (): array => $this->service->sincronizar(
                (int) ($_GET['empresa_id'] ?? 0),
                (int) ($_GET['max'] ?? 300)
            ),
            'Bandeja sincronizada'
        );
    }

    public function mensaje(array $params): void
    {
        $this->respond(fn (): array => $this->service->mensaje((int) ($_GET['empresa_id'] ?? 0), (int) $params['uid']));
    }

    public function enviar(): void
    {
        $this->respond(fn (int $userId): array => $this->service->enviar(Request::json(), $userId), 'Correo enviado');
    }

    public function probar(): void
    {
        $this->respond(fn (): array => $this->service->probar((int) ($_GET['empresa_id'] ?? 0)));
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            Response::success($callback((int) $claims['user_id']), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('[CorreoController] ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
