<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Services\ConfiguracionService;
use Mypos\Services\GeminiService;
use Throwable;

final class IaController
{
    public function configuracion(): void
    {
        try {
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);
            if ($empresaId <= 0) {
                throw new HttpException('empresa_id obligatorio', 422);
            }

            $effective = (new ConfiguracionService())->efectiva($empresaId);
            $data = (new GeminiService())->configuracionPublica((bool) ($effective['ia_documentos_habilitada'] ?? true));

            Response::success($data);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
