<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Services\ImportacionCatalogoService;
use Throwable;

final class ImportacionCatalogoController
{
    private ImportacionCatalogoService $service;

    public function __construct()
    {
        $this->service = new ImportacionCatalogoService();
    }

    public function index(): void
    {
        $this->respond(fn (int $userId): array => $this->service->listar($userId, $this->queryEmpresaId()));
    }

    public function store(): void
    {
        $this->respond(function (int $userId): array {
            if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
                $parser = new \Mypos\Services\ImportParserService();
                $tmpPath = $_FILES['archivo']['tmp_name'];
                $ext = strtolower(pathinfo((string) $_FILES['archivo']['name'], PATHINFO_EXTENSION));

                $expectedFields = ['codigo_interno', 'codigo_barra', 'nombre', 'descripcion', 'precio_compra', 'precio_venta', 'proveedor'];
                if ($ext === 'xlsx') {
                    $items = $parser->parseXlsx($tmpPath, $expectedFields);
                } else {
                    $items = $parser->parseCsv($tmpPath, $expectedFields);
                }

                $data = [
                    'empresa_id' => $this->queryEmpresaId(),
                    'tipo' => $_POST['tipo'] ?? 'PRODUCTOS',
                    'origen' => $_POST['origen'] ?? 'archivo',
                    'items' => $items,
                ];
                return $this->service->crear($userId, $data);
            }

            return $this->service->crear($userId, Request::json());
        }, 'Importacion cargada');
    }

    public function show(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->detalle($userId, $this->queryEmpresaId(), (int) $params['id']));
    }

    public function validate(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->validar($userId, $this->queryEmpresaId(), (int) $params['id']), 'Importacion validada');
    }

    public function apply(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->aplicar($userId, $this->queryEmpresaId(), (int) $params['id']), 'Importacion aplicada');
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

    private function queryEmpresaId(): int
    {
        if (isset($_GET['empresa_id'])) {
            return (int) $_GET['empresa_id'];
        }
        if (isset($_POST['empresa_id'])) {
            return (int) $_POST['empresa_id'];
        }

        return (int) (Request::json()['empresa_id'] ?? 0);
    }
}
