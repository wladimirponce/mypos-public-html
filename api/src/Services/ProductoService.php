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

    public function listProductos(int $userId, int $empresaId, ?string $q, int $page = 1, int $perPage = 200): array
    {
        $this->tenant($userId, $empresaId);

        $page = max(1, $page);
        $perPage = max(1, min($perPage, 500));
        $total = $this->productos->countList($empresaId, $q);

        return [
            'productos' => $this->productos->list($empresaId, $q, $perPage, ($page - 1) * $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    public function createProducto(int $userId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->tenant($userId, $empresaId);
        $this->validateProducto($data);

        $productoId = $this->guard(fn (): int => $this->productos->create($data));

        $codigosBarra = $data['codigos_barra'] ?? [];
        foreach ($codigosBarra as $bc) {
            $codigoBarraVal = isset($bc['codigo_barra']) ? trim((string)$bc['codigo_barra']) : '';
            if ($codigoBarraVal !== '') {
                $imagenUrlVal = isset($bc['imagen_url']) ? trim((string)$bc['imagen_url']) : null;
                $principalVal = !empty($bc['principal']) ? 1 : 0;

                $this->productos->createBarcode($productoId, [
                    'empresa_id' => $empresaId,
                    'codigo_barra' => $codigoBarraVal,
                    'tipo_codigo' => $bc['tipo_codigo'] ?? 'BARRA',
                    'principal' => $principalVal,
                    'descripcion' => $principalVal ? 'Código de barra principal' : 'Código de barra adicional',
                    'imagen_url' => $imagenUrlVal,
                ]);

                $this->asociarImagenPorCodigoBarra($userId, $empresaId, $productoId, $codigoBarraVal, $imagenUrlVal);
            }
        }

        return ['id' => $productoId];
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

        $codigosBarra = $data['codigos_barra'] ?? [];

        $db = Database::connection();
        
        // 1. Desactivar todos los códigos de barra actuales para este producto
        $db->prepare(
            'UPDATE productos_codigos_barra SET principal = 0, activo = 0, updated_at = CURRENT_TIMESTAMP WHERE producto_id = :producto_id AND empresa_id = :empresa_id'
        )->execute(['producto_id' => $id, 'empresa_id' => $empresaId]);

        // 2. Reactivar/crear los códigos de barra provistos en la lista
        foreach ($codigosBarra as $bc) {
            $codigoBarraVal = isset($bc['codigo_barra']) ? trim((string)$bc['codigo_barra']) : '';
            if ($codigoBarraVal === '') {
                continue;
            }
            $imagenUrlVal = isset($bc['imagen_url']) ? trim((string)$bc['imagen_url']) : null;
            $principalVal = !empty($bc['principal']) ? 1 : 0;

            // Buscamos si ya existía ese código de barra
            $stmt = $db->prepare(
                'SELECT id FROM productos_codigos_barra WHERE producto_id = :producto_id AND empresa_id = :empresa_id AND codigo_barra = :codigo_barra LIMIT 1'
            );
            $stmt->execute([
                'producto_id' => $id,
                'empresa_id' => $empresaId,
                'codigo_barra' => $codigoBarraVal,
            ]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                // Reactivamos y actualizamos
                $stmt = $db->prepare(
                    'UPDATE productos_codigos_barra SET principal = :principal, activo = 1, imagen_url = :imagen_url, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                $stmt->execute([
                    'principal' => $principalVal,
                    'imagen_url' => $imagenUrlVal,
                    'id' => $existingId
                ]);
            } else {
                // Creamos uno nuevo
                $this->productos->createBarcode($id, [
                    'empresa_id' => $empresaId,
                    'codigo_barra' => $codigoBarraVal,
                    'tipo_codigo' => $bc['tipo_codigo'] ?? 'BARRA',
                    'principal' => $principalVal,
                    'descripcion' => $principalVal ? 'Código de barra principal' : 'Código de barra adicional',
                    'imagen_url' => $imagenUrlVal,
                ]);
            }

            // Asociar la imagen (local o externa)
            $this->asociarImagenPorCodigoBarra($userId, $empresaId, $id, $codigoBarraVal, $imagenUrlVal);
        }

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

        $barcodeData = $data;
        $barcodeData['imagen_url'] = $data['imagen_url'] ?? null;

        $barcodeId = $this->guard(fn (): int => $this->productos->createBarcode($productoId, $barcodeData));

        $this->asociarImagenPorCodigoBarra($userId, $empresaId, $productoId, $data['codigo_barra'], $data['imagen_url'] ?? null);

        return ['id' => $barcodeId];
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

    private function asociarImagenPorCodigoBarra(int $userId, int $empresaId, int $productoId, string $codigoBarra, ?string $urlDirecta = null): void
    {
        $db = Database::connection();
        
        $stmt = $db->prepare(
            'SELECT id, imagen_url FROM productos_codigos_barra WHERE producto_id = :producto_id AND empresa_id = :empresa_id AND codigo_barra = :codigo_barra AND activo = 1 LIMIT 1'
        );
        $stmt->execute([
            'producto_id' => $productoId,
            'empresa_id' => $empresaId,
            'codigo_barra' => $codigoBarra,
        ]);
        $barcode = $stmt->fetch();
        if (!$barcode) {
            return;
        }
        $barcodeId = (int) $barcode['id'];
        $currentImagenUrl = $barcode['imagen_url'] ?? null;

        $sourceUrl = $urlDirecta ?: $currentImagenUrl;

        $stmt = $db->prepare(
            'SELECT 1 FROM productos_imagenes WHERE producto_id = :producto_id AND empresa_id = :empresa_id AND codigo_barra_id = :codigo_barra_id LIMIT 1'
        );
        $stmt->execute([
            'producto_id' => $productoId,
            'empresa_id' => $empresaId,
            'codigo_barra_id' => $barcodeId,
        ]);
        $hasImage = (bool) $stmt->fetchColumn();
        
        if ($hasImage && !$urlDirecta) {
            return;
        }

        $tempFile = null;
        $fotoNombre = '';

        if ($sourceUrl && (str_starts_with($sourceUrl, 'http://') || str_starts_with($sourceUrl, 'https://'))) {
            $tempFile = $this->descargarImagenExterna($sourceUrl);
            $fotoNombre = basename(parse_url($sourceUrl, PHP_URL_PATH) ?: 'foto.jpg');
        } else {
            $fotoNombre = $codigoBarra . '.jpg';
            $fotoPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'fotos' . DIRECTORY_SEPARATOR . $fotoNombre;
            if (file_exists($fotoPath)) {
                $tempFile = $fotoPath;
            }
        }

        if (!$tempFile) {
            return;
        }

        $date = new \DateTimeImmutable();
        $name = bin2hex(random_bytes(16)) . '.jpg';
        $relativeDir = sprintf('uploads/productos/empresa_%d/%s/%s', $empresaId, $date->format('Y'), $date->format('m'));
        $absoluteDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            error_log("No se pudo crear el directorio de almacenamiento para la foto: " . $absoluteDir);
            if ($sourceUrl && (str_starts_with($sourceUrl, 'http://') || str_starts_with($sourceUrl, 'https://'))) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
            return;
        }

        $targetPath = $absoluteDir . DIRECTORY_SEPARATOR . $name;
        if (!copy($tempFile, $targetPath)) {
            error_log("No se pudo copiar la foto de " . $tempFile . " a " . $targetPath);
            if ($sourceUrl && (str_starts_with($sourceUrl, 'http://') || str_starts_with($sourceUrl, 'https://'))) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
            return;
        }

        if ($sourceUrl && (str_starts_with($sourceUrl, 'http://') || str_starts_with($sourceUrl, 'https://'))) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        $size = filesize($targetPath);
        $hash = hash_file('sha256', $targetPath);
        $rutaRelativa = $relativeDir . '/' . $name;

        $imagenUrlParaGuardar = $sourceUrl ?: $rutaRelativa;
        $stmt = $db->prepare(
            'UPDATE productos_codigos_barra SET imagen_url = :imagen_url WHERE id = :id'
        );
        $stmt->execute([
            'imagen_url' => $imagenUrlParaGuardar,
            'id' => $barcodeId,
        ]);

        $uploadsRepo = new \Mypos\Repositories\UploadRepository($db);
        $archivoId = $uploadsRepo->insert([
            'empresa_id' => $empresaId,
            'sucursal_id' => null,
            'usuario_id' => $userId,
            'modulo' => 'PRODUCTOS',
            'entidad' => 'productos',
            'entidad_id' => $productoId,
            'nombre_original' => $fotoNombre,
            'nombre_storage' => $name,
            'ruta_relativa' => $rutaRelativa,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => $size,
            'hash_sha256' => $hash,
            'estado' => 'ACTIVO',
            'metadata_json' => json_encode(['producto_id' => $productoId, 'codigo_barra' => $codigoBarra]),
        ]);

        $db->prepare(
            'UPDATE productos_imagenes SET principal = 0 WHERE empresa_id = :empresa_id AND producto_id = :producto_id'
        )->execute(['empresa_id' => $empresaId, 'producto_id' => $productoId]);

        $stmt = $db->prepare(
            'INSERT INTO productos_imagenes (
                empresa_id, producto_id, producto_codigo_barra_id, codigo_barra_id, ruta,
                imagen_url, titulo, descripcion, principal, orden
             ) VALUES (
                :empresa_id, :producto_id, :producto_codigo_barra_id, :codigo_barra_id, :ruta, :imagen_url,
                :titulo, NULL, 1, 1
             )'
        );
        $stmt->execute([
            'empresa_id' => $empresaId,
            'producto_id' => $productoId,
            'producto_codigo_barra_id' => $barcodeId,
            'codigo_barra_id' => $barcodeId,
            'ruta' => $rutaRelativa,
            'imagen_url' => $rutaRelativa,
            'titulo' => 'Imagen asociada por código de barra: ' . $codigoBarra,
        ]);
    }

    private function descargarImagenExterna(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 200 && $data !== false) {
            $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'download_' . uniqid() . '.jpg';
            file_put_contents($tmpPath, $data);
            return $tmpPath;
        }

        return null;
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
