<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Repositories\AuthRepository;
use Mypos\Services\AuthService;
use Throwable;

final class AuthController
{
    private AuthService $service;

    public function __construct()
    {
        $this->service = new AuthService(new AuthRepository(Database::connection()));
    }

    public function register(): void
    {
        $this->respond(function (): array {
            $payload = Request::json();
            return $this->service->register($payload);
        }, 'Registro completado correctamente');
    }

    public function login(): void
    {
        $this->respond(function (): array {
            $payload = Request::json();

            return $this->service->login(
                (string) ($payload['email'] ?? ''),
                (string) ($payload['password'] ?? '')
            );
        }, 'Login correcto');
    }

    public function me(): void
    {
        $this->respond(function (): array {
            $claims = (new AuthMiddleware())->handle();

            return $this->service->me($claims, isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : null);
        });
    }

    public function logout(): void
    {
        $claims = (new AuthMiddleware())->handle();
        $this->service->logout($claims);

        // Futuro: registrar/revocar token en tabla de sesiones o tokens revocados.
        Response::successNull('Sesión cerrada correctamente');
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            Response::success($callback(), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor: ' . $exception->getMessage(), null, 500);
        }
    }
}
