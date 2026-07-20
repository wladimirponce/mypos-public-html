<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\Auth;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Services\InteligenciaService;
use Throwable;

final class InteligenciaController
{
    private InteligenciaService $service;

    public function __construct(?InteligenciaService $service = null)
    {
        $this->service = $service ?? new InteligenciaService();
    }

    public function feedback(): void
    {
        $this->respond(fn (): array => $this->service->feedback((int) Auth::id(), Request::json()));
    }

    public function alertas(): void
    {
        $this->respond(fn (): array => $this->service->alertas($_GET));
    }

    public function impacto(): void
    {
        $this->respond(fn (): array => $this->service->impacto($_GET));
    }

    private function respond(callable $fn): void
    {
        try {
            Response::success($fn());
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            error_log('[Inteligencia] ' . $e->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
