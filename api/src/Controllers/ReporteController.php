<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\ReporteService;
use Throwable;

final class ReporteController
{
    private ReporteService $service;

    public function __construct()
    {
        $this->service = new ReporteService();
    }

    public function resumenVentas(): void
    {
        $this->respond(fn (): array => $this->service->resumenVentas($_GET));
    }

    public function ventasPorDia(): void
    {
        $this->respond(fn (): array => $this->service->ventasPorDia($_GET));
    }

    public function ventasPorMetodoPago(): void
    {
        $this->respond(fn (): array => $this->service->ventasPorMetodoPago($_GET));
    }

    public function ventasPorProducto(): void
    {
        $this->respond(fn (): array => $this->service->ventasPorProducto($_GET));
    }

    public function ventasPorRubro(): void
    {
        $this->respond(fn (): array => $this->service->ventasPorRubro($_GET));
    }

    public function ventasPorUsuario(): void
    {
        $this->respond(fn (): array => $this->service->ventasPorUsuario($_GET));
    }

    public function dashboard(): void
    {
        $this->respond(fn (): array => $this->service->dashboard($_GET));
    }

    public function calidadDatos(): void
    {
        $this->respond(fn (): array => $this->service->calidadDatos($_GET));
    }

    public function analiticaAvanzada(): void
    {
        $this->respond(fn (): array => $this->service->analiticaAvanzada($_GET));
    }

    public function saludFinanciera(): void
    {
        $this->respond(function (): array {
            $data = $this->service->saludFinanciera($_GET);

            // Provisión de impuestos del mes en curso: reutiliza el cálculo F29
            // (no hay lógica tributaria nueva) para avisar cuánto apartar. Es
            // best-effort: si falla, la salud financiera se devuelve igual.
            try {
                $empresaId = (int) ($_GET['empresa_id'] ?? 0);
                if ($empresaId > 0) {
                    $periodo = date('Y-m');
                    $f29 = (new \Mypos\Services\F29Service())->calcular(\Mypos\Core\Auth::id(), [
                        'empresa_id' => $empresaId,
                        'periodo'    => $periodo,
                    ]);
                    $provision = max(0, (int) ($f29['total_a_pagar'] ?? 0));
                    $data['provision_impuestos_mes'] = $provision;
                    $data['periodo_impuestos']       = $periodo;
                    if ($provision > 0) {
                        $data['mensajes'][] = sprintf(
                            'Aparta %s para los impuestos de este mes (IVA + PPM estimados con las ventas y compras cargadas hasta hoy). Ese dinero es del SII, no lo gastes en otra cosa.',
                            '$' . number_format($provision, 0, ',', '.')
                        );
                    }
                }
            } catch (\Throwable $e) {
                // Sin provisión si el F29 del período aún no se puede calcular.
            }

            return $data;
        });
    }

    private function respond(callable $callback): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);

            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }


            Response::success($callback());
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
