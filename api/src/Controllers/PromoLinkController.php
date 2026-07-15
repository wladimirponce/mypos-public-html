<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Config\Database;
use Mypos\Core\Auth;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Repositories\AuthRepository;
use Mypos\Services\PromoLinkService;
use Mypos\Support\AppConfig;
use Throwable;

/**
 * Gestión de links de precio especial.
 *
 *   Público:
 *     GET  /api/v1/promos/resolve?codigo=XXX      (para el registro)
 *   Dueño de plataforma (PLATFORM_OWNER_EMAILS):
 *     GET   /api/v1/promos
 *     POST  /api/v1/promos
 *     PATCH /api/v1/promos/{id}/estado
 */
final class PromoLinkController
{
    private PromoLinkService $service;

    public function __construct()
    {
        $this->service = new PromoLinkService();
    }

    /** Público: resuelve un código para mostrar el precio especial en el registro. */
    public function resolve(): void
    {
        $this->respond(function (): array {
            $codigo = trim((string) ($_GET['codigo'] ?? ''));
            if ($codigo === '') {
                throw new HttpException('codigo requerido', 422);
            }

            return $this->service->resolver($codigo);
        });
    }

    public function index(): void
    {
        $this->respond(function (): array {
            $this->ownerGuard();
            return $this->service->listar();
        });
    }

    public function store(): void
    {
        $this->respond(function (): array {
            $ownerId = $this->ownerGuard();
            return $this->service->crear(Request::json(), $ownerId);
        }, 201);
    }

    public function toggle(array $params): void
    {
        $this->respond(function () use ($params): array {
            $this->ownerGuard();
            $payload = Request::json();
            $activo = filter_var($payload['activo'] ?? true, FILTER_VALIDATE_BOOLEAN);

            return $this->service->cambiarEstado((int) $params['id'], $activo);
        });
    }

    /**
     * Exige que el usuario autenticado sea dueño de plataforma.
     * @return int userId del dueño
     */
    private function ownerGuard(): int
    {
        $userId = Auth::id();
        $user = (new AuthRepository(Database::connection()))->findUserById($userId);
        $email = (string) ($user['email'] ?? '');

        if ($email === '' || (int) ($user['activo'] ?? 0) !== 1 || !AppConfig::isPlatformOwnerEmail($email)) {
            throw new HttpException('Acción reservada al operador de plataforma', 403);
        }

        return $userId;
    }

    private function respond(callable $callback, int $statusCode = 200): void
    {
        try {
            Response::success($callback(), null, $statusCode);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('[PromoLink] ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
