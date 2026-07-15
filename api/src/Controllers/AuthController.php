<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Repositories\AuthRepository;
use Mypos\Repositories\AuthSessionRepository;
use Mypos\Services\AuthService;
use Mypos\Services\AuthSessionService;
use Throwable;

final class AuthController
{
    private AuthService $service;
    private AuthSessionService $sessions;

    public function __construct()
    {
        $connection = Database::connection();
        $this->service = new AuthService(new AuthRepository($connection));
        $this->sessions = new AuthSessionService($connection, new AuthSessionRepository($connection));
    }

    public function register(): void
    {
        $this->respond(function (): array {
            $payload = Request::json();
            return $this->withRefreshSession($this->service->register($payload));
        }, 'Registro completado correctamente');
    }

    public function login(): void
    {
        $this->respond(function (): array {
            $payload = Request::json();

            return $this->withRefreshSession($this->service->login(
                (string) ($payload['email'] ?? ''),
                (string) ($payload['password'] ?? '')
            ));
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
        $claims = [];
        try {
            $claims = (new AuthMiddleware())->handle();
        } catch (HttpException) {
            // El refresh se debe revocar aunque el access token ya haya expirado.
        }
        if (AuthSessionService::enabled()) {
            AuthSessionService::assertTrustedOrigin();
            $this->sessions->revoke(AuthSessionService::cookieToken());
            AuthSessionService::clearCookie();
        }
        $this->service->logout($claims);
        Response::successNull('Sesión cerrada correctamente');
    }

    public function refresh(): void
    {
        $this->respond(function (): array {
            if (!AuthSessionService::enabled()) {
                throw new HttpException('Renovacion de sesion no habilitada', 404);
            }
            AuthSessionService::assertTrustedOrigin();
            $rotated = $this->sessions->rotate(AuthSessionService::cookieToken());
            AuthSessionService::setCookie($rotated['token']);
            return $this->service->resumeSession($rotated['user_id']);
        }, 'Sesion renovada');
    }

    public function verifyEmail(): void
    {
        $this->respond(function (): array {
            $payload = Request::json();
            return $this->service->verifyEmail((string) ($payload['token'] ?? ''));
        }, 'Correo verificado correctamente');
    }

    public function resendVerificationEmail(): void
    {
        $this->respond(function (): array {
            $payload = Request::json();

            return $this->service->resendVerificationEmail(
                (string) ($payload['email'] ?? '')
            );
        }, 'Si la cuenta requiere verificacion, enviaremos un nuevo enlace.');
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            Response::success($callback(), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor.', null, 500);
        }
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private function withRefreshSession(array $response): array
    {
        $userId = (int) ($response['user']['id'] ?? 0);
        if (AuthSessionService::enabled() && $userId > 0 && isset($response['token'])) {
            AuthSessionService::setCookie($this->sessions->start($userId));
        }
        return $response;
    }
}
