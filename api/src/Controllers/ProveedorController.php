<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\ProveedorService;
use Throwable;

final class ProveedorController
{
    private ProveedorService $service;

    public function __construct()
    {
        $this->service = new ProveedorService();
    }

    public function store(): void
    {
        $this->respond(fn (int $userId): array => $this->service->crear(Request::json(), $userId), 'Proveedor creado correctamente');
    }

    public function index(): void
    {
        $this->respond(fn (): array => $this->service->listar($_GET));
    }

    public function show(array $params): void
    {
        $this->respond(fn (): array => $this->service->ver((int) $params['id'], (int) ($_GET['empresa_id'] ?? 0)));
    }

    public function update(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->actualizar((int) $params['id'], Request::json(), $userId), 'Proveedor actualizado correctamente');
    }

    public function destroy(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->eliminar((int) $params['id'], (int) ($_GET['empresa_id'] ?? 0), $userId), 'Proveedor eliminado correctamente');
    }

    public function productProviders(array $params): void
    {
        $this->respond(fn (): array => $this->service->proveedoresPorProducto((int) ($_GET['empresa_id'] ?? 0), (int) $params['producto_id']));
    }

    public function providerProducts(array $params): void
    {
        $this->respond(fn (): array => $this->service->productosPorProveedor((int) ($_GET['empresa_id'] ?? 0), (int) $params['id']));
    }

    public function attachProduct(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->asociarProducto((int) $params['producto_id'], Request::json(), $userId), 'Producto asociado a proveedor');
    }

    public function updateProductProvider(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->actualizarProductoProveedor((int) $params['relacion_id'], Request::json(), $userId), 'Relacion proveedor-producto actualizada');
    }

    public function deleteProductProvider(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->eliminarProductoProveedor((int) ($_GET['empresa_id'] ?? 0), (int) $params['relacion_id'], $userId), 'Relacion proveedor-producto eliminada');
    }

    public function productPrices(array $params): void
    {
        $this->respond(fn (): array => $this->service->preciosPorProducto((int) ($_GET['empresa_id'] ?? 0), (int) $params['producto_id']));
    }

    public function providerPrices(array $params): void
    {
        $this->respond(fn (): array => $this->service->preciosPorProveedor((int) ($_GET['empresa_id'] ?? 0), (int) $params['id']));
    }

    public function storeProductPrice(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->registrarPrecioProducto((int) $params['producto_id'], Request::json(), $userId), 'Precio proveedor registrado');
    }

    public function storePriceListImport(array $params): void
    {
        $this->respond(function (int $userId) use ($params): array {
            if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
                $parser = new \Mypos\Services\ImportParserService();
                $tmpPath = $_FILES['archivo']['tmp_name'];
                $ext = strtolower(pathinfo((string) $_FILES['archivo']['name'], PATHINFO_EXTENSION));

                $expectedFields = ['codigo_proveedor', 'codigo_barra', 'codigo_interno', 'precio_compra', 'unidad_compra', 'factor_conversion'];
                if ($ext === 'xlsx') {
                    $items = $parser->parseXlsx($tmpPath, $expectedFields);
                } else {
                    $items = $parser->parseCsv($tmpPath, $expectedFields);
                }

                $payload = [
                    'empresa_id' => $this->requestEmpresaId(),
                    'origen' => $_POST['origen'] ?? 'archivo',
                    'items' => $items,
                ];
                return $this->service->cargarListaPrecios($userId, (int) $params['id'], $payload);
            }

            return $this->service->cargarListaPrecios($userId, (int) $params['id'], Request::json());
        }, 'Lista de precios cargada');
    }

    public function showPriceListImport(array $params): void
    {
        $this->respond(fn (): array => $this->service->detalleListaPrecios((int) ($_GET['empresa_id'] ?? 0), (int) $params['id'], (int) $params['importacion_id']));
    }

    public function validatePriceListImport(array $params): void
    {
        $this->respond(fn (): array => $this->service->validarListaPrecios((int) ($_GET['empresa_id'] ?? 0), (int) $params['id'], (int) $params['importacion_id']), 'Lista de precios validada');
    }

    public function applyPriceListImport(array $params): void
    {
        $this->respond(fn (): array => $this->service->aplicarListaPrecios((int) ($_GET['empresa_id'] ?? 0), (int) $params['id'], (int) $params['importacion_id']), 'Lista de precios aplicada');
    }

    private function respond(callable $callback, ?string $message = null): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = $this->requestEmpresaId();
            if ($empresaId > 0) {
                (new TenantMiddleware())->handle($userId, $empresaId);
            }
            Response::success($callback($userId), $message);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function requestEmpresaId(): int
    {
        if (isset($_GET['empresa_id'])) {
            return (int) $_GET['empresa_id'];
        }
        if (isset($_POST['empresa_id'])) {
            return (int) $_POST['empresa_id'];
        }
        $payload = Request::json();
        return (int) ($payload['empresa_id'] ?? 0);
    }
}
