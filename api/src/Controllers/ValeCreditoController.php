<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\ValeCreditoService;
use Throwable;

final class ValeCreditoController
{
    private ValeCreditoService $service;

    public function __construct()
    {
        $this->service = new ValeCreditoService();
    }

    public function index(): void
    {
        $this->respond(function (): array {
            $filters = array_filter([
                'estado' => $_GET['estado'] ?? null,
                'origen' => $_GET['origen'] ?? null,
                'q' => $_GET['q'] ?? null,
            ]);

            return $this->service->listar($this->getEmpresaId(), $filters);
        });
    }

    public function store(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->emitir($userId, Request::json());
        }, 'Vale de credito emitido');
    }

    public function show(array $params): void
    {
        $this->respond(fn (): array => $this->service->detalle($this->getEmpresaId(), (int) $params['id']));
    }

    /** GET /api/v1/vales/codigo/{codigo} — consulta rápida del POS antes de canjear. */
    public function porCodigo(array $params): void
    {
        $this->respond(fn (): array => $this->service->consultarPorCodigo($this->getEmpresaId(), (string) $params['codigo']));
    }

    public function anular(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            $payload = Request::json();
            $motivo = !empty($payload['motivo']) ? trim((string) $payload['motivo']) : null;

            return $this->service->anular($userId, $this->getEmpresaId(), (int) $params['id'], $motivo);
        }, 'Vale de credito anulado');
    }

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
            error_log('[ValeCredito] ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function getEmpresaId(): int
    {
        if (isset($_GET['empresa_id'])) {
            return (int) $_GET['empresa_id'];
        }
        $payload = Request::json();

        return (int) ($payload['empresa_id'] ?? 0);
    }
}
