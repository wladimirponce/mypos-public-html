<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Services\ProductoService;
use Throwable;

final class ProductoController
{
    private ProductoService $service;

    public function __construct()
    {
        $this->service = new ProductoService();
    }

    public function index(): void
    {
        $this->respond(fn (int $userId): array => $this->service->listProductos($userId, $this->queryEmpresaId(), $_GET['q'] ?? null));
    }

    public function store(): void
    {
        $this->respond(fn (int $userId): array => $this->service->createProducto($userId, Request::json()), 'Producto creado');
    }

    public function show(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->showProducto($userId, (int) $params['id'], $this->queryEmpresaId()));
    }

    public function update(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->updateProducto($userId, (int) $params['id'], Request::json()), 'Producto actualizado');
    }

    public function destroy(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->deleteProducto($userId, (int) $params['id'], $this->queryEmpresaId()), 'Producto desactivado');
    }

    public function search(): void
    {
        $this->respond(fn (int $userId): array => $this->service->search($userId, $this->queryEmpresaId(), (string) ($_GET['codigo'] ?? '')));
    }

    public function listBarcodes(array $params): void
    {
        $this->related($params, 'codigos');
    }

    public function storeBarcode(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->createBarcode($userId, (int) $params['id'], Request::json()), 'Código de barra creado');
    }

    public function deleteBarcode(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->deleteBarcode($userId, (int) $params['id'], (int) $params['codigo_barra_id'], $this->queryEmpresaId()), 'Código de barra desactivado');
    }

    public function listImages(array $params): void
    {
        $this->related($params, 'imagenes');
    }

    public function storeImage(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->createImage($userId, (int) $params['id'], Request::json()), 'Imagen registrada');
    }

    public function deleteImage(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->deleteImage($userId, (int) $params['id'], (int) $params['imagen_id'], $this->queryEmpresaId()), 'Imagen eliminada');
    }

    public function listTaxes(array $params): void
    {
        $this->related($params, 'impuestos');
    }

    public function storeTax(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->createTax($userId, (int) $params['id'], Request::json()), 'Impuesto asociado');
    }

    public function deleteTax(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->deleteTax($userId, (int) $params['id'], (int) $params['producto_impuesto_id'], $this->queryEmpresaId()), 'Impuesto desactivado');
    }

    public function listDiscounts(array $params): void
    {
        $this->related($params, 'descuentos');
    }

    public function storeDiscount(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->createDiscount($userId, (int) $params['id'], Request::json()), 'Descuento creado');
    }

    public function updateDiscount(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->updateDiscount($userId, (int) $params['id'], (int) $params['descuento_id'], Request::json()), 'Descuento actualizado');
    }

    public function deleteDiscount(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->deleteDiscount($userId, (int) $params['id'], (int) $params['descuento_id'], $this->queryEmpresaId()), 'Descuento desactivado');
    }

    public function listCommissions(array $params): void
    {
        $this->related($params, 'comisiones');
    }

    public function storeCommission(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->createCommission($userId, (int) $params['id'], Request::json()), 'Comisión creada');
    }

    public function updateCommission(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->updateCommission($userId, (int) $params['id'], (int) $params['comision_id'], Request::json()), 'Comisión actualizada');
    }

    public function deleteCommission(array $params): void
    {
        $this->respond(fn (int $userId): array => $this->service->deleteCommission($userId, (int) $params['id'], (int) $params['comision_id'], $this->queryEmpresaId()), 'Comisión desactivada');
    }

    private function related(array $params, string $type): void
    {
        $this->respond(fn (int $userId): array => $this->service->listRelated($userId, (int) $params['id'], $this->queryEmpresaId(), $type));
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
        return (int) ($_GET['empresa_id'] ?? 0);
    }
}
