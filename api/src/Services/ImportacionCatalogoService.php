<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Repositories\ImportacionCatalogoRepository;
use Mypos\Repositories\ProductoRepository;
use PDO;
use PDOException;
use Throwable;

final class ImportacionCatalogoService
{
    private PDO $connection;
    private ImportacionCatalogoRepository $repository;
    private ProductoRepository $productos;

    public function __construct()
    {
        $this->connection = Database::connection();
        $this->repository = new ImportacionCatalogoRepository($this->connection);
        $this->productos = new ProductoRepository($this->connection);
    }

    public function crear(int $userId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->tenant($userId, $empresaId);

        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new HttpException('Error de validacion', 422, ['items' => ['La importacion requiere items']]);
        }

        if (count($items) > 1000) {
            throw new HttpException('Error de validacion', 422, ['items' => ['Maximo 1000 items por importacion en esta fase']]);
        }

        $type = strtoupper((string) ($data['tipo'] ?? 'PRODUCTOS'));
        if (!in_array($type, ['PRODUCTOS', 'PRECIOS_PROVEEDOR', 'CODIGOS_BARRA', 'ATRIBUTOS', 'MIXTO'], true)) {
            throw new HttpException('Error de validacion', 422, ['tipo' => ['Tipo de importacion invalido']]);
        }

        $this->connection->beginTransaction();
        try {
            $importId = $this->repository->createImport([
                'empresa_id' => $empresaId,
                'usuario_id' => $userId,
                'origen' => trim((string) ($data['origen'] ?? 'manual')),
                'tipo' => $type,
                'archivo_subido_id' => $data['archivo_subido_id'] ?? null,
                'total_items' => count($items),
                'metadata_json' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]);

            $line = 1;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new HttpException('Error de validacion', 422, ['items' => ['Cada item debe ser un objeto']]);
                }

                $this->repository->insertItem($importId, $empresaId, $line, $item);
                ++$line;
            }

            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        return ['id' => $importId, 'total_items' => count($items)];
    }

    public function listar(int $userId, int $empresaId): array
    {
        $this->tenant($userId, $empresaId);

        return ['importaciones' => $this->repository->listImports($empresaId)];
    }

    public function detalle(int $userId, int $empresaId, int $id): array
    {
        $this->tenant($userId, $empresaId);
        $import = $this->repository->findImport($empresaId, $id);

        if ($import === null) {
            throw new HttpException('Importacion no encontrada', 404);
        }

        return [
            'importacion' => $import,
            'items' => $this->decodeRows($this->repository->items($empresaId, $id)),
        ];
    }

    public function validar(int $userId, int $empresaId, int $id): array
    {
        $this->tenant($userId, $empresaId);

        if ($this->repository->findImport($empresaId, $id) === null) {
            throw new HttpException('Importacion no encontrada', 404);
        }

        $items = $this->repository->allItemsForValidation($empresaId, $id);
        $seenCodes = [];
        $seenBarcodes = [];
        $valid = 0;
        $errors = 0;

        foreach ($items as $item) {
            $result = $this->validateItem($empresaId, $item, $seenCodes, $seenBarcodes);

            if ($result['status'] === 'ERROR') {
                ++$errors;
            } else {
                ++$valid;
            }

            $this->repository->updateItemValidation(
                (int) $item['id'],
                $empresaId,
                $result['producto_id'],
                $result['action'],
                $result['status'],
                $result['errors']
            );
        }

        $this->repository->updateImportTotals($id, $empresaId, $valid, $errors);

        return [
            'id' => $id,
            'total_items' => count($items),
            'total_validos' => $valid,
            'total_errores' => $errors,
            'estado' => $errors > 0 ? 'CON_ERRORES' : 'VALIDADO',
        ];
    }

    public function aplicar(int $userId, int $empresaId, int $id): array
    {
        $this->tenant($userId, $empresaId);
        $import = $this->repository->findImport($empresaId, $id);

        if ($import === null) {
            throw new HttpException('Importacion no encontrada', 404);
        }

        if ($import['estado'] === 'APLICADO') {
            throw new HttpException('La importacion ya fue aplicada', 409);
        }

        if ($import['estado'] !== 'VALIDADO') {
            throw new HttpException('La importacion debe estar validada sin errores antes de aplicar', 409);
        }

        $items = $this->repository->validItemsForApply($empresaId, $id);
        $created = 0;
        $updated = 0;
        $associatedBarcodes = 0;

        $this->connection->beginTransaction();
        try {
            foreach ($items as $item) {
                $action = (string) $item['accion_sugerida'];

                if ($action === 'CREAR') {
                    $productId = $this->productos->create($this->productDataForCreate($empresaId, $item));
                    ++$created;
                    $associatedBarcodes += $this->createBarcodeIfMissing($empresaId, $productId, $item);
                } elseif ($action === 'ACTUALIZAR') {
                    $productId = (int) $item['producto_id_detectado'];
                    $product = $this->productos->find($productId, $empresaId);

                    if ($product === null) {
                        throw new HttpException('Producto detectado no encontrado al aplicar importacion', 409);
                    }

                    $this->productos->update($productId, $empresaId, $this->productDataForUpdate($empresaId, $product, $item));
                    ++$updated;
                    $associatedBarcodes += $this->createBarcodeIfMissing($empresaId, $productId, $item);
                } elseif ($action === 'ASOCIAR_CODIGO') {
                    $productId = (int) $item['producto_id_detectado'];
                    $associatedBarcodes += $this->createBarcodeIfMissing($empresaId, $productId, $item, true);
                }

                $this->repository->markItemApplied((int) $item['id'], $empresaId);
            }

            $this->repository->markImportApplied($id, $empresaId);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();

            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                throw new HttpException('Conflicto de datos al aplicar importacion', 409);
            }

            throw $exception;
        }

        return [
            'id' => $id,
            'estado' => 'APLICADO',
            'productos_creados' => $created,
            'productos_actualizados' => $updated,
            'codigos_barra_asociados' => $associatedBarcodes,
        ];
    }

    private function validateItem(int $empresaId, array $item, array &$seenCodes, array &$seenBarcodes): array
    {
        $errors = [];
        $code = trim((string) ($item['codigo_interno'] ?? ''));
        $barcode = trim((string) ($item['codigo_barra'] ?? ''));
        $name = trim((string) ($item['nombre'] ?? ''));
        $purchasePrice = $item['precio_compra'];
        $salePrice = $item['precio_venta'];

        if ($code === '' && $barcode === '') {
            $errors[] = 'Debe informar codigo interno o codigo de barra';
        }

        if ($name === '') {
            $errors[] = 'Debe informar nombre o descripcion';
        }

        if ($purchasePrice !== null && (int) $purchasePrice < 0) {
            $errors[] = 'Precio compra no puede ser negativo';
        }

        if ($salePrice !== null && (int) $salePrice < 0) {
            $errors[] = 'Precio venta no puede ser negativo';
        }

        if ($code !== '') {
            if (isset($seenCodes[$code])) {
                $errors[] = 'Codigo interno duplicado dentro de la importacion';
            }
            $seenCodes[$code] = true;
        }

        if ($barcode !== '') {
            if (isset($seenBarcodes[$barcode])) {
                $errors[] = 'Codigo de barra duplicado dentro de la importacion';
            }
            $seenBarcodes[$barcode] = true;
        }

        $productByCode = $code !== '' ? $this->repository->productByInternalCode($empresaId, $code) : null;
        $productByBarcode = $barcode !== '' ? $this->repository->productByBarcode($empresaId, $barcode) : null;

        if ($productByCode !== null && $productByBarcode !== null && $productByCode !== $productByBarcode) {
            $errors[] = 'Codigo interno y codigo de barra apuntan a productos distintos';
        }

        if ($errors !== []) {
            return ['status' => 'ERROR', 'action' => 'ERROR', 'producto_id' => $productByCode ?? $productByBarcode, 'errors' => $errors];
        }

        if ($productByCode !== null && $barcode !== '' && $productByBarcode === null) {
            return ['status' => 'VALIDO', 'action' => 'ASOCIAR_CODIGO', 'producto_id' => $productByCode, 'errors' => []];
        }

        if ($productByCode !== null || $productByBarcode !== null) {
            return ['status' => 'VALIDO', 'action' => 'ACTUALIZAR', 'producto_id' => $productByCode ?? $productByBarcode, 'errors' => []];
        }

        return ['status' => 'VALIDO', 'action' => 'CREAR', 'producto_id' => null, 'errors' => []];
    }

    private function productDataForCreate(int $empresaId, array $item): array
    {
        $code = $this->text($item['codigo_interno'] ?? null) ?? $this->text($item['codigo_barra'] ?? null);

        return [
            'empresa_id' => $empresaId,
            'codigo' => $code,
            'nombre' => $this->text($item['nombre'] ?? null),
            'descripcion' => $this->text($item['descripcion'] ?? null),
            'unidad_medida' => 'UN',
            'precio_costo' => (int) ($item['precio_compra'] ?? 0),
            'precio_venta' => (int) ($item['precio_venta'] ?? 0),
            'controla_stock' => 1,
            'stock_minimo' => '0.000',
            'permite_descuento' => 1,
            'permite_comision' => 1,
            'activo' => 1,
        ];
    }

    private function productDataForUpdate(int $empresaId, array $product, array $item): array
    {
        return [
            'empresa_id' => $empresaId,
            'rubro_id' => $product['rubro_id'] ?? null,
            'centro_costo_id' => $product['centro_costo_id'] ?? null,
            'codigo' => $product['codigo'],
            'nombre' => $this->text($item['nombre'] ?? null) ?? $product['nombre'],
            'descripcion' => $this->text($item['descripcion'] ?? null) ?? $product['descripcion'] ?? null,
            'unidad_medida' => $product['unidad_medida'] ?? 'UN',
            'precio_costo' => $item['precio_compra'] ?? $product['precio_costo'] ?? $product['costo_actual'] ?? 0,
            'precio_venta' => $item['precio_venta'] ?? $product['precio_venta'] ?? 0,
            'controla_stock' => $product['controla_stock'] ?? 1,
            'stock_minimo' => $product['stock_minimo'] ?? '0.000',
            'permite_descuento' => $product['permite_descuento'] ?? 1,
            'permite_comision' => $product['permite_comision'] ?? 1,
            'activo' => $product['activo'] ?? 1,
        ];
    }

    private function createBarcodeIfMissing(int $empresaId, int $productId, array $item, bool $required = false): int
    {
        $barcode = $this->text($item['codigo_barra'] ?? null);

        if ($barcode === null) {
            if ($required) {
                throw new HttpException('La asociacion de codigo requiere codigo de barra', 409);
            }

            return 0;
        }

        $existingProductId = $this->repository->productByBarcode($empresaId, $barcode);
        if ($existingProductId !== null) {
            if ($existingProductId !== $productId) {
                throw new HttpException('Codigo de barra ya asociado a otro producto', 409);
            }

            return 0;
        }

        $this->productos->createBarcode($productId, [
            'empresa_id' => $empresaId,
            'codigo_barra' => $barcode,
            'tipo_codigo' => 'BARRA',
            'descripcion' => 'Importacion catalogo',
            'principal' => 1,
        ]);

        return 1;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function decodeRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['raw'] = json_decode((string) $row['raw_json'], true);
            $row['errores'] = $row['errores_json'] ? json_decode((string) $row['errores_json'], true) : [];
        }

        return $rows;
    }

    private function empresaId(array $data): int
    {
        $empresaId = (int) ($data['empresa_id'] ?? 0);

        if ($empresaId <= 0) {
            throw new HttpException('Error de validacion', 422, ['empresa_id' => ['La empresa_id es obligatoria']]);
        }

        return $empresaId;
    }

    private function tenant(int $userId, int $empresaId): void
    {
        (new TenantMiddleware())->handle($userId, $empresaId);
    }
}
