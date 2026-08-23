<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\Auth;
use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Services\ProductoImportService;
use Throwable;

final class ProductoImportController
{
    private ProductoImportService $service;

    public function __construct()
    {
        $this->service = new ProductoImportService();
    }

    /** POST /api/v1/importaciones/maestro  — subir archivo y obtener preview */
    public function store(): void
    {
        $this->respond(function (int $userId): array {
            $empresaId = $this->empresaId();

            if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                throw new HttpException('Debe subir un archivo CSV o Excel', 422);
            }

            $tmpPath = (string) $_FILES['archivo']['tmp_name'];
            $mime = (string) ($_FILES['archivo']['type'] ?? '');

            return $this->service->upload($userId, $empresaId, $tmpPath, $mime);
        }, 'Archivo analizado correctamente');
    }

    /** GET /api/v1/importaciones/maestro/{id} — preview de items */
    public function show(array $params): void
    {
        $this->respond(
            fn (int $userId): array => $this->service->show($userId, $this->empresaId(), (int) $params['id'])
        );
    }

    /** POST /api/v1/importaciones/maestro/{id}/aplicar */
    public function apply(array $params): void
    {
        $this->respond(
            fn (int $userId): array => $this->service->aplicar($userId, $this->empresaId(), (int) $params['id']),
            'Importación aplicada correctamente'
        );
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            Response::success($callback((int) $claims['user_id']), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    /**
     * Resuelve la empresa del request.
     *
     * Antes leia solo $_GET y $_POST. `aplicar` recibe la empresa en el cuerpo
     * JSON, donde ambos superglobales estan vacios, asi que devolvia 0 y el
     * servicio respondia "No tienes acceso a esta empresa o sucursal": el usuario
     * cargaba el archivo y veia la vista previa —esas dos rutas viajan por query
     * string y multipart— pero no podia aplicar la importacion nunca.
     *
     * `Auth::empresaId()` cubre los tres transportes y es el mismo resolutor que
     * usa el router para validar la pertenencia al tenant.
     */
    private function empresaId(): int
    {
        return Auth::empresaId() ?? 0;
    }
}
