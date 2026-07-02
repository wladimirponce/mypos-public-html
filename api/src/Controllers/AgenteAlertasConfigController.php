<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Services\Agente\AlertasConfigService;
use Throwable;

/**
 * Preferencias de alertas proactivas del agente IA (Configuración → Alertas).
 * GET  /api/v1/agente/alertas-config  → config efectiva (fila + defaults)
 * PUT  /api/v1/agente/alertas-config  → guarda (whitelist de tipos/parametros)
 *
 * Registrado con protectedRoute('configuracion.ver'/'configuracion.editar'):
 * auth, tenant y permisos los resuelve el router como cualquier otro modulo.
 */
final class AgenteAlertasConfigController
{
    private AlertasConfigService $service;

    public function __construct()
    {
        $this->service = new AlertasConfigService();
    }

    public function ver(): void
    {
        try {
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);
            Response::success($this->service->config($empresaId));
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('AgenteAlertasConfigController::ver ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    public function guardar(): void
    {
        try {
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);
            $payload = Request::json();
            Response::success($this->service->guardar($empresaId, $payload));
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('AgenteAlertasConfigController::guardar ' . $exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
