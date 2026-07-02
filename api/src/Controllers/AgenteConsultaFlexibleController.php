<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\AuditoriaService;
use Mypos\Services\ConsultaFlexibleService;
use Throwable;

/**
 * Endpoint de solo lectura llamado por el agente IA (cuenta de servicio) para
 * ejecutar skills tipo "sql_readonly" aprobadas desde admin. Ver
 * ConsultaFlexibleService para la cadena de confianza completa.
 */
final class AgenteConsultaFlexibleController
{
    private ConsultaFlexibleService $service;

    public function __construct()
    {
        $this->service = new ConsultaFlexibleService();
    }

    public function ejecutar(): void
    {
        $userId = 0;
        $empresaId = 0;
        $skillId = '';

        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = (int) ($_GET['empresa_id'] ?? ($_POST['empresa_id'] ?? 0));
            $sucursalId = isset($_GET['sucursal_id']) ? (int) $_GET['sucursal_id'] : null;
            $skillId = (string) ($_GET['skill_id'] ?? ($_POST['skill_id'] ?? ''));

            if ($empresaId <= 0) {
                throw new HttpException('empresa_id obligatorio', 422);
            }
            if ($skillId === '') {
                throw new HttpException('skill_id obligatorio', 422);
            }

            (new TenantMiddleware())->handle($userId, $empresaId, $sucursalId);

            $payload = Request::json();
            $paramsExtraidos = is_array($payload['params'] ?? null) ? $payload['params'] : [];

            $resultado = $this->service->ejecutar($empresaId, $sucursalId, $skillId, $paramsExtraidos);

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'usuario_id' => $userId,
                'modulo' => 'agente_ia',
                'accion' => 'consulta_flexible',
                'entidad' => 'skill',
                'entidad_id' => $skillId,
                'descripcion' => 'Ejecucion de skill sql_readonly via agente IA',
                'metadata' => [
                    'skill_id' => $skillId,
                    'params_extraidos' => $paramsExtraidos,
                    'row_count' => $resultado['row_count'],
                    'truncated' => $resultado['truncated'],
                ],
                'severidad' => 'INFO',
                'resultado' => 'OK',
            ]);

            Response::success($resultado);
        } catch (HttpException $exception) {
            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId ?: null,
                'usuario_id' => $userId ?: null,
                'modulo' => 'agente_ia',
                'accion' => 'consulta_flexible',
                'entidad' => 'skill',
                'entidad_id' => $skillId !== '' ? $skillId : null,
                'descripcion' => 'Error ejecutando skill sql_readonly: ' . $exception->getMessage(),
                'severidad' => $exception->statusCode() >= 500 ? 'ERROR' : 'WARNING',
                'resultado' => 'ERROR',
            ]);
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('AgenteConsultaFlexibleController: ' . $exception->getMessage());
            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId ?: null,
                'usuario_id' => $userId ?: null,
                'modulo' => 'agente_ia',
                'accion' => 'consulta_flexible',
                'entidad' => 'skill',
                'entidad_id' => $skillId !== '' ? $skillId : null,
                'descripcion' => 'Error interno ejecutando skill sql_readonly',
                'severidad' => 'CRITICAL',
                'resultado' => 'ERROR',
            ]);
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
