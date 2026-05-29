<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\UploadService;
use Throwable;

final class UploadController
{
    private UploadService $service;

    public function __construct()
    {
        $this->service = new UploadService();
    }

    public function producto(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->subirProducto($userId, $_POST, $_FILES['archivo'] ?? []);
        }, 'Archivo subido correctamente');
    }

    public function documentoIa(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->subirDocumentoIa($userId, $_POST, $_FILES['archivo'] ?? []);
        }, 'Documento subido correctamente');
    }

    public function logo(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->subirLogo($userId, $_POST, $_FILES['archivo'] ?? []);
        }, 'Archivo subido correctamente');
    }

    public function certificadoSii(): void
    {
        $this->respond(function (int $userId): array {
            return $this->service->subirCertificadoSii($userId, $_POST, $_FILES['archivo'] ?? []);
        }, 'Certificado válido y procesado correctamente');
    }

    public function show(array $params): void
    {
        $this->respond(function () use ($params): array {
            return $this->service->metadataArchivo((int) ($_GET['empresa_id'] ?? 0), (int) $params['id']);
        });
    }

    public function download(array $params): void
    {
        try {
            $this->authenticate((int) ($_GET['empresa_id'] ?? 0));
            $file = $this->service->archivoDescargable((int) ($_GET['empresa_id'] ?? 0), (int) $params['id']);

            header('Content-Type: ' . $file['mime_type']);
            header('Content-Length: ' . (string) $file['size_bytes']);
            header('Content-Disposition: attachment; filename="' . addslashes($file['nombre_original']) . '"');
            readfile($file['absolute_path']);
            exit;
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    public function destroy(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            return $this->service->eliminar($userId, (int) ($_GET['empresa_id'] ?? ($_POST['empresa_id'] ?? 0)), (int) $params['id']);
        }, 'Archivo eliminado correctamente');
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = $this->requestEmpresaId();
            $this->authenticate($empresaId, $userId);

            Response::success($callback($userId), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function authenticate(int $empresaId, ?int $userId = null): void
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        if ($userId === null) {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
        }

        (new TenantMiddleware())->handle($userId, $empresaId);
    }

    private function requestEmpresaId(): int
    {
        if (isset($_GET['empresa_id'])) {
            return (int) $_GET['empresa_id'];
        }

        return (int) ($_POST['empresa_id'] ?? 0);
    }
}
