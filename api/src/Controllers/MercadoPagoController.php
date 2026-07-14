<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\MercadoPagoService;
use Throwable;

/**
 * Endpoints de la integracion MercadoPago Point:
 *  - configuracion (credenciales por empresa) y terminales por sucursal/caja
 *  - cobro en terminal (iniciar / consultar estado)
 *  - webhook publico de notificaciones
 *
 * El registro de rutas (public/index.php) ya aplica permisos via protectedRoute;
 * aqui se resuelve tenant + usuario igual que el resto de controladores.
 */
final class MercadoPagoController
{
    private MercadoPagoService $service;

    public function __construct()
    {
        $this->service = new MercadoPagoService();
    }

    // ── Configuracion ─────────────────────────────────────────────────────────

    public function getConfig(): void
    {
        $this->respond(fn (): array => $this->service->obtenerConfig($this->getEmpresaId()));
    }

    public function putConfig(): void
    {
        $this->respond(
            fn (int $userId): array => $this->service->guardarConfig($userId, $this->getEmpresaId(), Request::json()),
            'Configuracion MercadoPago guardada'
        );
    }

    // ── Terminales ──────────────────────────────────────────────────────────────

    public function indexTerminales(): void
    {
        $this->respond(function (): array {
            $filters = array_filter([
                'sucursal_id' => $_GET['sucursal_id'] ?? null,
                'activo' => $_GET['activo'] ?? null,
            ], static fn ($v) => $v !== null && $v !== '');

            return $this->service->listarTerminales($this->getEmpresaId(), $filters);
        });
    }

    public function storeTerminal(): void
    {
        $this->respond(
            fn (int $userId): array => $this->service->crearTerminal($userId, $this->getEmpresaId(), Request::json()),
            'Terminal creada'
        );
    }

    public function updateTerminal(array $params): void
    {
        $this->respond(
            fn (int $userId): array => $this->service->actualizarTerminal($userId, $this->getEmpresaId(), (int) $params['id'], Request::json()),
            'Terminal actualizada'
        );
    }

    public function estadoTerminal(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            $payload = Request::json();
            $activo = in_array($payload['activo'] ?? null, [true, 1, '1', 'true', 'on'], true);

            return $this->service->cambiarEstadoTerminal($userId, $this->getEmpresaId(), (int) $params['id'], $activo);
        }, 'Estado de terminal actualizado');
    }

    // ── Cobro ─────────────────────────────────────────────────────────────────

    public function iniciarCobro(): void
    {
        $this->respond(function (int $userId): array {
            $payload = Request::json();
            $terminalRefId = (int) ($payload['terminal_id_ref'] ?? 0);
            $monto = (int) ($payload['monto'] ?? 0);
            $descripcion = isset($payload['descripcion']) ? (string) $payload['descripcion'] : null;

            if ($terminalRefId <= 0) {
                throw new HttpException('terminal_id_ref obligatorio', 422);
            }

            return $this->service->iniciarCobro($userId, $this->getEmpresaId(), $terminalRefId, $monto, $descripcion);
        }, 'Cobro iniciado en terminal');
    }

    public function estadoCobro(array $params): void
    {
        $this->respond(fn (): array => $this->service->estadoCobro($this->getEmpresaId(), (int) $params['id']));
    }

    // ── Webhook (publico, sin auth) ─────────────────────────────────────────────

    /**
     * Recibe la notificacion de MercadoPago. Responde 200 de inmediato y procesa
     * despues (fastcgi_finish_request), como exige MercadoPago (< 22s o reintenta).
     */
    public function webhook(): void
    {
        $payload = Request::json();

        http_response_code(200);
        echo 'ok';

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        try {
            $this->service->procesarWebhook($payload, $this->lowercaseHeaders());
        } catch (Throwable $exception) {
            error_log('[MercadoPago][webhook] ' . $exception->getMessage());
        }
    }

    // ── Infra ──────────────────────────────────────────────────────────────────

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = $this->getEmpresaId();
            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }
            Response::success($callback($userId), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('[MercadoPago] ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function getEmpresaId(): int
    {
        if (isset($_GET['empresa_id'])) {
            return (int) $_GET['empresa_id'];
        }

        return (int) (Request::json()['empresa_id'] ?? 0);
    }

    /** @return array<string,string> */
    private function lowercaseHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $key => $value) {
                $headers[strtolower((string) $key)] = (string) $value;
            }

            return $headers;
        }

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }
}
