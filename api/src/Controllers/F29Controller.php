<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Services\F29Service;
use Throwable;

final class F29Controller
{
    private F29Service $service;

    public function __construct()
    {
        $this->service = new F29Service();
    }

    /** GET /api/v1/f29/calcular?empresa_id=X&periodo=YYYY-MM */
    public function calcular(): void
    {
        $this->respond(fn (int $uid): array => $this->service->calcular($uid, $_GET));
    }

    /** GET /api/v1/f29/historial?empresa_id=X */
    public function historial(): void
    {
        $this->respond(fn (int $uid): array => ['historial' => $this->service->historial($uid, $_GET)]);
    }

    /** POST /api/v1/f29  { empresa_id, periodo, tasa_ppm, remanente_cf_anterior, notas } */
    public function guardar(): void
    {
        $this->respond(fn (int $uid): array => $this->service->guardar($uid, Request::json()), 'Borrador guardado');
    }

    /** POST /api/v1/f29/{periodo}/declarar?empresa_id=X */
    public function declarar(array $params): void
    {
        $this->respond(
            fn (int $uid): array => $this->service->declarar($uid, array_merge($_GET, ['periodo' => $params['periodo']])),
            'Período marcado como declarado'
        );
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            Response::success($callback((int) $claims['user_id']), $message);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
