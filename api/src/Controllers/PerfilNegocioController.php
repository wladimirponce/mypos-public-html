<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\PerfilNegocioService;
use Throwable;

/**
 * Controlador de perfiles de negocio y capacidades.
 *
 * Endpoints:
 *   GET  /api/v1/perfil-negocio/perfiles                — catálogo de perfiles disponibles
 *   GET  /api/v1/perfil-negocio/capacidades?empresa_id= — capacidades activas de la empresa
 *   POST /api/v1/perfil-negocio/activar                 — activa un perfil completo para la empresa
 *   POST /api/v1/perfil-negocio/toggle-capacidad        — activa/desactiva una capacidad individual
 */
final class PerfilNegocioController
{
    private PerfilNegocioService $service;

    public function __construct()
    {
        $this->service = new PerfilNegocioService();
    }

    /**
     * GET /api/v1/perfil-negocio/perfiles
     *
     * Lista los perfiles disponibles. No requiere empresa_id.
     */
    public function perfiles(): void
    {
        $this->respond(function (): array {
            return $this->service->perfilesDisponibles();
        }, requireTenant: false);
    }

    /**
     * GET /api/v1/perfil-negocio/capacidades?empresa_id=
     *
     * Devuelve el perfil activo y el mapa de capacidades (código → bool)
     * para la empresa. El frontend usa este endpoint para saber qué módulos mostrar.
     */
    public function capacidades(): void
    {
        $this->respond(function (): array {
            return $this->service->capacidadesActivas((int) ($_GET['empresa_id'] ?? 0));
        });
    }

    /**
     * POST /api/v1/perfil-negocio/activar
     *
     * Body JSON: { "empresa_id": 5, "perfil": "FARMACIA" }
     *
     * Activa el perfil y siembra los atributos estándar del rubro.
     * Idempotente: puede llamarse varias veces; INSERT IGNORE protege datos existentes.
     */
    public function activar(): void
    {
        $this->respond(function (): array {
            $body      = json_decode(file_get_contents('php://input'), true) ?? [];
            $empresaId = (int) ($body['empresa_id'] ?? 0);
            $perfil    = strtoupper(trim((string) ($body['perfil'] ?? '')));

            $claims    = (new AuthMiddleware())->handle();
            $usuarioId = (int) $claims['user_id'];

            return $this->service->activarPerfil($empresaId, $perfil, $usuarioId);
        }, requireTenant: true, message: 'Perfil activado correctamente');
    }

    /**
     * POST /api/v1/perfil-negocio/toggle-capacidad
     *
     * Body JSON: { "empresa_id": 5, "codigo": "MERMA_OPERATIVA", "activo": true }
     *
     * Activa o desactiva una capacidad individual sin cambiar el perfil ni los atributos.
     */
    public function toggleCapacidad(): void
    {
        $this->respond(function (): array {
            $body      = json_decode(file_get_contents('php://input'), true) ?? [];
            $empresaId = (int) ($body['empresa_id'] ?? 0);
            $codigo    = strtoupper(trim((string) ($body['codigo'] ?? '')));
            $activo    = (bool) ($body['activo'] ?? false);

            $claims    = (new AuthMiddleware())->handle();
            $usuarioId = (int) $claims['user_id'];

            return $this->service->toggleCapacidad($empresaId, $codigo, $activo, $usuarioId);
        }, requireTenant: true, message: 'Capacidad actualizada');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function respond(callable $callback, bool $requireTenant = true, ?string $message = null): void
    {
        try {
            $claims    = (new AuthMiddleware())->handle();
            $userId    = (int) $claims['user_id'];
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);

            if ($requireTenant && $empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }

            Response::success($callback(), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('[PerfilNegocio] ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
