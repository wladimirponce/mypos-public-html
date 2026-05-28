<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Repositories\CentroCostoRepository;
use Mypos\Repositories\ProductoRepository;
use Mypos\Repositories\RubroRepository;
use PDOException;

final class ProductoService
{
    private ProductoRepository $productos;
    private RubroRepository $rubros;
    private CentroCostoRepository $centros;

    public function __construct()
    {
        $connection = Database::connection();
        $this->productos = new ProductoRepository($connection);
        $this->rubros = new RubroRepository($connection);
        $this->centros = new CentroCostoRepository($connection);
    }

    public function rubros(int $userId, int $empresaId): array
    {
        $this->tenant($userId, $empresaId);

        return ['rubros' => $this->rubros->list($empresaId)];
    }

    public function createRubro(int $userId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->tenant($userId, $empresaId);
        $this->requireText($data, 'nombre');

        return ['id' => $this->guard(fn (): int => $this->rubros->create($data))];
    }

    public function updateRubro(int $userId, int $id, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->tenant($userId, $empresaId);
        $this->requireText($data, 'nombre');
        $this->notFoundUnless($this->rubros->update($id, $empresaId, $data));

        return ['id' => $id];
    }

    public function deleteRubro(int $userId, int $id, int $empresaId): array
    {
        $this->tenant($userId, $empresaId);
        $this->notFoundUnless($this->rubros->deactivate($id, $empresaId));

        return ['id' => $id, 'activo' => 0];
    }

    public function centros(int $userId, int $empresaId): array
    {
        $this->tenant($userId, $empresaId);

        return ['centros_costo' => $this->centros->list($empresaId)];
    }

    public function createCentro(int $userId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->tenant($userId, $empresaId);
        $this->requireText($data, 'codigo');
        $this->requireText($data, 'nombre');

        return ['id' => $this->guard(fn (): int => $this->centros->create($data))];
    }

    public function updateCentro(int $userId, int $id, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->tenant($userId, $empresaId);
        $this->requireText($data, 'codigo');
        $this->requireText($data, 'nombre');
        $this->notFoundUnless($this->centros->update($id, $empresaId, $data));

        return ['id' => $id];
    }

    public function deleteCentro(int $userId, int $id, int $empresaId): array
    {
        $this->tenant($userId, $empresaId);
        $this->notFoundUnless($this->centros->deactivate($id, $empresaId));

        return ['id' => $id, 'activo' => 0];
    }

    public function listProductos(int $userId, int $empresaId, ?string $q): array
    {
        $this->tenant($userId, $empresaId);

        return ['productos' => $this->productos->list($empresaId, $q)];
    }

    public function createProducto(int $userId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->tenant($userId, $empresaId);
        $this->validateProducto($data);

        return ['id' => $this->guard(fn (): int => $this->productos->create($data))];
    }

    public function showProducto(int $userId, int $id, int $empresaId): array
    {
        $this->tenant($userId, $empresaId);
        $producto = $this->productos->find($id, $empresaId);

        if ($producto === null) {
            throw new HttpException('Producto no encontrado', 404);
        }

        return ['producto' => $producto];
    }

    public function updateProducto(int $userId, int $id, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->tenant($userId, $empresaId);
        $this->validateProducto($data);
        $this->notFoundUnless($this->productos->update($id, $empresaId, $data));

        return ['id' => $id];
    }

    public function deleteProducto(int $userId, int $id, int $empresaId): array
    {
        $this->tenant($userId, $empresaId);
        $this->notFoundUnless($this->productos->deactivate($id, $empresaId));

        return ['id' => $id, 'activo' => 0];
    }

    public function listRelated(int $userId, int $productoId, int $empresaId, string $type): array
    {
        $this->validateProductAccess($userId, $productoId, $empresaId);

        return match ($type) {
            'codigos' => ['codigos_barra' => $this->productos->listBarcodes($productoId, $empresaId)],
            'imagenes' => ['imagenes' => $this->productos->listImages($productoId, $empresaId)],
            'impuestos' => ['impuestos' => $this->productos->listTaxes($productoId, $empresaId)],
            'descuentos' => ['descuentos' => $this->productos->listDiscounts($productoId, $empresaId)],
            'comisiones' => ['comisiones' => $this->productos->listCommissions($productoId, $empresaId)],
            default => throw new HttpException('Tipo no soportado', 400),
        };
    }

    public function createBarcode(int $userId, int $productoId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->validateProductAccess($userId, $productoId, $empresaId);
        $this->requireText($data, 'codigo_barra');

        return ['id' => $this->guard(fn (): int => $this->productos->createBarcode($productoId, $data))];
    }

    public function deleteBarcode(int $userId, int $productoId, int $barcodeId, int $empresaId): array
    {
        $this->validateProductAccess($userId, $productoId, $empresaId);
        $this->notFoundUnless($this->productos->deleteBarcode($barcodeId, $productoId, $empresaId));

        return ['id' => $barcodeId, 'activo' => 0];
    }

    public function createImage(int $userId, int $productoId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->validateProductAccess($userId, $productoId, $empresaId);
        $this->requireText($data, 'imagen_url');

        return ['id' => $this->guard(fn (): int => $this->productos->createImage($productoId, $data))];
    }

    public function deleteImage(int $userId, int $productoId, int $imageId, int $empresaId): array
    {
        $this->validateProductAccess($userId, $productoId, $empresaId);
        $this->notFoundUnless($this->productos->deleteImage($imageId, $productoId, $empresaId));

        return ['id' => $imageId];
    }

    public function createTax(int $userId, int $productoId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->validateProductAccess($userId, $productoId, $empresaId);
        $impuestoId = (int) ($data['impuesto_id'] ?? 0);

        if ($impuestoId <= 0) {
            throw new HttpException('Error de validación', 422, ['impuesto_id' => ['El impuesto_id es obligatorio']]);
        }

        if (!$this->productos->activeTaxExists($impuestoId)) {
            throw new HttpException('Impuesto no encontrado o inactivo', 422, ['impuesto_id' => ['El impuesto no existe o está inactivo']]);
        }

        return ['id' => $this->guard(fn (): int => $this->productos->createTax($productoId, $data))];
    }

    public function deleteTax(int $userId, int $productoId, int $taxId, int $empresaId): array
    {
        $this->validateProductAccess($userId, $productoId, $empresaId);
        $this->notFoundUnless($this->productos->deleteTax($taxId, $productoId, $empresaId));

        return ['id' => $taxId, 'activo' => 0];
    }

    public function createDiscount(int $userId, int $productoId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->validateProductAccess($userId, $productoId, $empresaId);
        $this->validateDiscount($data);

        return ['id' => $this->guard(fn (): int => $this->productos->createDiscount($productoId, $data))];
    }

    public function updateDiscount(int $userId, int $productoId, int $discountId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->validateProductAccess($userId, $productoId, $empresaId);
        $this->validateDiscount($data);
        $this->notFoundUnless($this->productos->updateDiscount($discountId, $productoId, $empresaId, $data));

        return ['id' => $discountId];
    }

    public function deleteDiscount(int $userId, int $productoId, int $discountId, int $empresaId): array
    {
        $this->validateProductAccess($userId, $productoId, $empresaId);
        $this->notFoundUnless($this->productos->deleteDiscount($discountId, $productoId, $empresaId));

        return ['id' => $discountId, 'activo' => 0];
    }

    public function createCommission(int $userId, int $productoId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->validateProductAccess($userId, $productoId, $empresaId);
        $this->validateCommission($data);

        return ['id' => $this->guard(fn (): int => $this->productos->createCommission($productoId, $data))];
    }

    public function updateCommission(int $userId, int $productoId, int $commissionId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->validateProductAccess($userId, $productoId, $empresaId);
        $this->validateCommission($data);
        $this->notFoundUnless($this->productos->updateCommission($commissionId, $productoId, $empresaId, $data));

        return ['id' => $commissionId];
    }

    public function deleteCommission(int $userId, int $productoId, int $commissionId, int $empresaId): array
    {
        $this->validateProductAccess($userId, $productoId, $empresaId);
        $this->notFoundUnless($this->productos->deleteCommission($commissionId, $productoId, $empresaId));

        return ['id' => $commissionId, 'activo' => 0];
    }

    public function search(int $userId, int $empresaId, string $code): array
    {
        $this->tenant($userId, $empresaId);

        if (trim($code) === '') {
            throw new HttpException('Error de validación', 422, ['codigo' => ['El código es obligatorio']]);
        }

        $producto = $this->productos->searchByCode($empresaId, $code);

        if ($producto === null) {
            return ['producto' => null];
        }

        $producto['impuestos'] = $this->productos->listTaxes((int) $producto['id'], $empresaId);
        $producto['descuentos_activos'] = $this->productos->listDiscounts((int) $producto['id'], $empresaId, true);
        $producto['comisiones_activas'] = $this->productos->listCommissions((int) $producto['id'], $empresaId, true);
        $producto['codigo_barra_usado'] = $producto['codigo_barra_usado'] ?? null;

        return ['producto' => $producto];
    }

    private function validateProductAccess(int $userId, int $productoId, int $empresaId): void
    {
        $this->tenant($userId, $empresaId);

        if (!$this->productos->productExists($productoId, $empresaId)) {
            throw new HttpException('Producto no encontrado', 404);
        }
    }

    private function validateProducto(array $data): void
    {
        $this->requireText($data, 'codigo');
        $this->requireText($data, 'nombre');
        $this->nonNegativeInt($data, 'precio_costo');
        $this->nonNegativeInt($data, 'precio_venta');

        if (isset($data['stock_minimo']) && !is_numeric($data['stock_minimo'])) {
            throw new HttpException('Error de validación', 422, ['stock_minimo' => ['El stock_minimo debe ser numérico']]);
        }
    }

    private function validateDiscount(array $data): void
    {
        if (!in_array($data['tipo_descuento'] ?? '', ['PORCENTAJE', 'MONTO'], true)) {
            throw new HttpException('Error de validación', 422, ['tipo_descuento' => ['Tipo de descuento inválido']]);
        }

        $this->nonNegativeInt($data, 'valor_descuento');
    }

    private function validateCommission(array $data): void
    {
        if (!in_array($data['tipo_comision'] ?? '', ['PORCENTAJE_VENTA', 'MONTO_FIJO', 'PORCENTAJE_MARGEN'], true)) {
            throw new HttpException('Error de validación', 422, ['tipo_comision' => ['Tipo de comisión inválido']]);
        }

        $this->nonNegativeInt($data, 'valor_comision');
    }

    private function empresaId(array $data): int
    {
        $empresaId = (int) ($data['empresa_id'] ?? 0);

        if ($empresaId <= 0) {
            throw new HttpException('Error de validación', 422, ['empresa_id' => ['La empresa_id es obligatoria']]);
        }

        return $empresaId;
    }

    private function requireText(array $data, string $field): void
    {
        if (trim((string) ($data[$field] ?? '')) === '') {
            throw new HttpException('Error de validación', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }
    }

    private function nonNegativeInt(array $data, string $field): void
    {
        if (!isset($data[$field]) || !is_int($data[$field]) && !ctype_digit((string) $data[$field]) || (int) $data[$field] < 0) {
            throw new HttpException('Error de validación', 422, [$field => ["El campo {$field} debe ser un entero >= 0"]]);
        }
    }

    private function tenant(int $userId, int $empresaId): void
    {
        (new TenantMiddleware())->handle($userId, $empresaId);
    }

    private function notFoundUnless(bool $ok): void
    {
        if (!$ok) {
            throw new HttpException('Registro no encontrado', 404);
        }
    }

    private function guard(callable $callback): int
    {
        try {
            return $callback();
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new HttpException('Registro duplicado o referencia inválida', 422);
            }

            throw $exception;
        }
    }
}
