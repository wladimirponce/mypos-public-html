<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\ProveedorRepository;

final class ProveedorService
{
    public function __construct(private ?ProveedorRepository $repository = null)
    {
        $this->repository ??= new ProveedorRepository(Database::connection());
    }

    public function crear(array $payload, ?int $userId = null): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $data = $this->data($payload);
        $this->validateRut($empresaId, $data['rut']);
        $id = $this->repository->create(['empresa_id' => $empresaId] + $data);
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'proveedores',
            'accion' => 'crear',
            'entidad' => 'proveedores',
            'entidad_id' => $id,
            'descripcion' => 'Proveedor creado',
            'datos_nuevos' => ['nombre' => $data['nombre'], 'rut' => $data['rut']],
        ]);

        return ['proveedor_id' => $id] + $this->ver($id, $empresaId);
    }

    public function listar(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $limit = $this->limit($filters['limit'] ?? 20);
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        return $this->repository->list($empresaId, $filters, $limit, $offset);
    }

    public function ver(int $id, int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $provider = $this->repository->find($empresaId, $id);
        if ($provider === null) {
            throw new HttpException('Proveedor no encontrado', 404);
        }

        return $provider;
    }

    public function actualizar(int $id, array $payload, ?int $userId = null): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $previous = $this->ver($id, $empresaId);
        $data = $this->data($payload);
        $this->validateRut($empresaId, $data['rut'], $id);
        $this->repository->update($empresaId, $id, $data);
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'proveedores',
            'accion' => 'actualizar',
            'entidad' => 'proveedores',
            'entidad_id' => $id,
            'descripcion' => 'Proveedor actualizado',
            'datos_anteriores' => ['nombre' => $previous['nombre'] ?? null, 'rut' => $previous['rut'] ?? null],
            'datos_nuevos' => ['nombre' => $data['nombre'], 'rut' => $data['rut']],
        ]);

        return $this->ver($id, $empresaId);
    }

    public function eliminar(int $id, int $empresaId, ?int $userId = null): array
    {
        $previous = $this->ver($id, $empresaId);
        $this->repository->softDelete($empresaId, $id);
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'proveedores',
            'accion' => 'eliminar',
            'entidad' => 'proveedores',
            'entidad_id' => $id,
            'descripcion' => 'Proveedor eliminado logicamente',
            'datos_anteriores' => ['activo' => (int) ($previous['activo'] ?? 1), 'deleted_at' => $previous['deleted_at'] ?? null],
            'datos_nuevos' => ['activo' => 0, 'deleted' => true],
        ]);

        return ['proveedor_id' => $id, 'activo' => 0, 'deleted' => true];
    }

    public function proveedoresPorProducto(int $empresaId, int $productoId): array
    {
        $this->validateProduct($empresaId, $productoId);

        return ['proveedores' => $this->repository->listProductProviders($empresaId, $productoId)];
    }

    public function productosPorProveedor(int $empresaId, int $proveedorId): array
    {
        $this->validateProvider($empresaId, $proveedorId);

        return ['productos' => $this->repository->listProviderProducts($empresaId, $proveedorId)];
    }

    public function asociarProducto(int $productoId, array $payload, ?int $userId = null): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $data = $this->providerProductData(['producto_id' => $productoId] + $payload);
        $this->validateProduct($empresaId, $productoId);
        $this->validateProvider($empresaId, $data['proveedor_id']);

        try {
            $id = $this->repository->createProviderProduct(['empresa_id' => $empresaId] + $data);
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new HttpException('Relacion proveedor-producto duplicada o codigo proveedor duplicado', 422);
            }

            throw $exception;
        }

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'proveedores',
            'accion' => 'asociar_producto',
            'entidad' => 'proveedor_productos',
            'entidad_id' => $id,
            'descripcion' => 'Producto asociado a proveedor',
            'datos_nuevos' => [
                'producto_id' => $productoId,
                'proveedor_id' => $data['proveedor_id'],
                'codigo_proveedor' => $data['codigo_proveedor'],
            ],
        ]);

        return ['id' => $id];
    }

    public function actualizarProductoProveedor(int $id, array $payload, ?int $userId = null): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $previous = $this->repository->findProviderProduct($empresaId, $id);

        if ($previous === null) {
            throw new HttpException('Relacion proveedor-producto no encontrada', 404);
        }

        $data = $this->providerProductData(['producto_id' => $previous['producto_id'], 'proveedor_id' => $previous['proveedor_id']] + $payload);
        $this->validateProduct($empresaId, $data['producto_id']);
        $this->validateProvider($empresaId, $data['proveedor_id']);

        try {
            if (!$this->repository->updateProviderProduct($empresaId, $id, $data)) {
                throw new HttpException('Relacion proveedor-producto no encontrada', 404);
            }
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new HttpException('Relacion proveedor-producto duplicada o codigo proveedor duplicado', 422);
            }

            throw $exception;
        }

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'proveedores',
            'accion' => 'actualizar_producto',
            'entidad' => 'proveedor_productos',
            'entidad_id' => $id,
            'descripcion' => 'Relacion proveedor-producto actualizada',
            'datos_anteriores' => [
                'codigo_proveedor' => $previous['codigo_proveedor'] ?? null,
                'proveedor_preferido' => $previous['proveedor_preferido'] ?? null,
            ],
            'datos_nuevos' => [
                'codigo_proveedor' => $data['codigo_proveedor'],
                'proveedor_preferido' => $data['proveedor_preferido'],
            ],
        ]);

        return ['id' => $id];
    }

    public function eliminarProductoProveedor(int $empresaId, int $id, ?int $userId = null): array
    {
        $previous = $this->repository->findProviderProduct($empresaId, $id);

        if ($previous === null) {
            throw new HttpException('Relacion proveedor-producto no encontrada', 404);
        }

        $this->repository->deleteProviderProduct($empresaId, $id);
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'proveedores',
            'accion' => 'eliminar_producto',
            'entidad' => 'proveedor_productos',
            'entidad_id' => $id,
            'descripcion' => 'Relacion proveedor-producto eliminada logicamente',
            'datos_anteriores' => ['activo' => (int) ($previous['activo'] ?? 1)],
            'datos_nuevos' => ['activo' => 0, 'deleted' => true],
        ]);

        return ['id' => $id, 'activo' => 0];
    }

    public function preciosPorProducto(int $empresaId, int $productoId): array
    {
        $this->validateProduct($empresaId, $productoId);

        return [
            'precios' => $this->repository->listProductPrices($empresaId, $productoId),
            'resumen' => $this->repository->productPriceSummary($empresaId, $productoId),
        ];
    }

    public function preciosPorProveedor(int $empresaId, int $proveedorId): array
    {
        $this->validateProvider($empresaId, $proveedorId);

        return ['precios' => $this->repository->listProviderPrices($empresaId, $proveedorId)];
    }

    public function registrarPrecioProducto(int $productoId, array $payload, ?int $userId = null): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $data = $this->providerPriceData(['producto_id' => $productoId, 'origen' => 'MANUAL'] + $payload);
        $this->validateProduct($empresaId, $productoId);
        $this->validateProvider($empresaId, $data['proveedor_id']);

        $relation = $this->repository->relationForProviderProduct($empresaId, $data['proveedor_id'], $productoId);
        if ($relation !== null) {
            $data['proveedor_producto_id'] = (int) $relation['id'];
            $data['unidad_compra'] = $data['unidad_compra'] ?? $relation['unidad_compra'] ?? 'UN';
            $data['factor_conversion'] = $data['factor_conversion'] ?? $relation['factor_conversion'] ?? 1;
        }

        $id = $this->repository->createProviderPrice(['empresa_id' => $empresaId] + $data);
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'proveedores',
            'accion' => 'registrar_precio',
            'entidad' => 'proveedor_precios',
            'entidad_id' => $id,
            'descripcion' => 'Precio proveedor registrado',
            'datos_nuevos' => [
                'producto_id' => $productoId,
                'proveedor_id' => $data['proveedor_id'],
                'precio_compra' => $data['precio_compra'],
                'origen' => $data['origen'],
            ],
        ]);

        return ['id' => $id];
    }

    public function registrarPrecioObservadoCompra(int $empresaId, int $proveedorId, int $compraId, array $item): void
    {
        $productoId = (int) ($item['producto_id'] ?? 0);
        $precio = (int) ($item['costo_unitario'] ?? 0);

        if ($empresaId <= 0 || $proveedorId <= 0 || $productoId <= 0 || $precio < 0) {
            return;
        }

        $relation = $this->repository->relationForProviderProduct($empresaId, $proveedorId, $productoId);
        $this->repository->createProviderPrice([
            'empresa_id' => $empresaId,
            'proveedor_id' => $proveedorId,
            'producto_id' => $productoId,
            'proveedor_producto_id' => $relation['id'] ?? null,
            'precio_compra' => $precio,
            'moneda' => $relation['moneda'] ?? 'CLP',
            'unidad_compra' => $relation['unidad_compra'] ?? 'UN',
            'factor_conversion' => $relation['factor_conversion'] ?? 1,
            'fecha_precio' => date('Y-m-d'),
            'origen' => 'COMPRA',
            'compra_id' => $compraId,
            'observacion' => 'Precio observado desde compra #' . $compraId,
            'activo' => 1,
        ]);
    }

    public function cargarListaPrecios(int $userId, int $proveedorId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $this->validateProvider($empresaId, $proveedorId);
        $items = $payload['items'] ?? null;

        if (!is_array($items) || $items === []) {
            throw new HttpException('La lista requiere items', 422);
        }

        if (count($items) > 1000) {
            throw new HttpException('Maximo 1000 items por lista', 422);
        }

        $id = $this->repository->createPriceListImport([
            'empresa_id' => $empresaId,
            'proveedor_id' => $proveedorId,
            'usuario_id' => $userId,
            'origen' => $payload['origen'] ?? 'manual',
            'total_items' => count($items),
            'metadata_json' => isset($payload['metadata']) ? json_encode($payload['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);

        $line = 1;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new HttpException('Cada item debe ser un objeto', 422);
            }

            $this->repository->insertPriceListItem($empresaId, $id, $line, $item);
            ++$line;
        }

        return ['id' => $id, 'total_items' => count($items)];
    }

    public function detalleListaPrecios(int $empresaId, int $proveedorId, int $id): array
    {
        $import = $this->repository->findPriceListImport($empresaId, $proveedorId, $id);

        if ($import === null) {
            throw new HttpException('Importacion de lista no encontrada', 404);
        }

        return ['importacion' => $import, 'items' => $this->decodeImportItems($this->repository->priceListItems($empresaId, $id))];
    }

    public function validarListaPrecios(int $empresaId, int $proveedorId, int $id): array
    {
        if ($this->repository->findPriceListImport($empresaId, $proveedorId, $id) === null) {
            throw new HttpException('Importacion de lista no encontrada', 404);
        }

        $items = $this->repository->priceListItems($empresaId, $id);
        $valid = 0;
        $errors = 0;

        foreach ($items as $item) {
            $result = $this->validarItemLista($empresaId, $proveedorId, $item);

            if ($result['estado'] === 'VALIDO') {
                ++$valid;
            } else {
                ++$errors;
            }

            $this->repository->updatePriceListItemValidation(
                (int) $item['id'],
                $empresaId,
                $result['producto_id'],
                $result['relacion_id'],
                $result['estado'],
                $result['errores']
            );
        }

        $this->repository->updatePriceListImportTotals($id, $empresaId, $valid, $errors);

        return ['id' => $id, 'total_items' => count($items), 'total_validos' => $valid, 'total_errores' => $errors, 'estado' => $errors > 0 ? 'CON_ERRORES' : 'VALIDADO'];
    }

    public function aplicarListaPrecios(int $empresaId, int $proveedorId, int $id): array
    {
        $import = $this->repository->findPriceListImport($empresaId, $proveedorId, $id);

        if ($import === null) {
            throw new HttpException('Importacion de lista no encontrada', 404);
        }

        if ($import['estado'] === 'APLICADO') {
            throw new HttpException('La lista ya fue aplicada', 409);
        }

        if ($import['estado'] !== 'VALIDADO') {
            throw new HttpException('La lista debe estar validada sin errores antes de aplicar', 409);
        }

        $applied = 0;
        foreach ($this->repository->priceListItems($empresaId, $id) as $item) {
            if ($item['estado'] !== 'VALIDO') {
                continue;
            }

            $this->repository->createProviderPrice([
                'empresa_id' => $empresaId,
                'proveedor_id' => $proveedorId,
                'producto_id' => (int) $item['producto_id_detectado'],
                'proveedor_producto_id' => $item['proveedor_producto_id_detectado'] ?? null,
                'precio_compra' => (int) $item['precio_compra'],
                'moneda' => 'CLP',
                'unidad_compra' => $item['unidad_compra'] ?? 'UN',
                'factor_conversion' => $item['factor_conversion'] ?? 1,
                'fecha_precio' => date('Y-m-d'),
                'origen' => 'IMPORTACION',
                'observacion' => 'Lista proveedor #' . $id,
                'activo' => 1,
            ]);
            $this->repository->markPriceListItemApplied((int) $item['id'], $empresaId);
            ++$applied;
        }

        $this->repository->markPriceListImportApplied($id, $empresaId);

        return ['id' => $id, 'estado' => 'APLICADO', 'precios_registrados' => $applied];
    }

    private function data(array $payload): array
    {
        $name = trim((string) ($payload['nombre'] ?? ''));
        if ($name === '') {
            throw new HttpException('nombre obligatorio', 422);
        }

        return [
            'rut' => $this->nullable($payload['rut'] ?? null),
            'nombre' => $name,
            'razon_social' => $this->nullable($payload['razon_social'] ?? null) ?? $name,
            'nombre_fantasia' => $name,
            'giro' => $this->nullable($payload['giro'] ?? null),
            'email' => $this->nullable($payload['email'] ?? null),
            'telefono' => $this->nullable($payload['telefono'] ?? null),
            'direccion' => $this->nullable($payload['direccion'] ?? null),
            'comuna' => $this->nullable($payload['comuna'] ?? null),
            'ciudad' => $this->nullable($payload['ciudad'] ?? null),
            'activo' => filter_var($payload['activo'] ?? true, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'observacion' => $this->nullable($payload['observacion'] ?? null),
        ];
    }

    private function validarItemLista(int $empresaId, int $proveedorId, array $item): array
    {
        $errors = [];
        $providerCode = $this->nullable($item['codigo_proveedor'] ?? null);
        $barcode = $this->nullable($item['codigo_barra'] ?? null);
        $internalCode = $this->nullable($item['codigo_interno'] ?? null);
        $price = $item['precio_compra'];
        $relation = $providerCode !== null ? $this->repository->relationByProviderCode($empresaId, $proveedorId, $providerCode) : null;
        $productId = $relation['producto_id'] ?? $this->repository->productByCodeOrBarcode($empresaId, $internalCode, $barcode);

        if ($providerCode === null && $barcode === null && $internalCode === null) {
            $errors[] = 'Debe informar codigo proveedor, codigo interno o codigo de barra';
        }

        if ($price === null || (int) $price < 0) {
            $errors[] = 'Precio compra obligatorio y no negativo';
        }

        if ($productId === null) {
            $errors[] = 'No se pudo conciliar el producto';
        }

        if ($item['factor_conversion'] !== null && (float) $item['factor_conversion'] <= 0) {
            $errors[] = 'Factor conversion debe ser mayor a 0';
        }

        return [
            'estado' => $errors === [] ? 'VALIDO' : 'ERROR',
            'producto_id' => $productId === null ? null : (int) $productId,
            'relacion_id' => isset($relation['id']) ? (int) $relation['id'] : null,
            'errores' => $errors,
        ];
    }

    private function decodeImportItems(array $items): array
    {
        foreach ($items as &$item) {
            $item['raw'] = json_decode((string) $item['raw_json'], true);
            $item['errores'] = $item['errores_json'] ? json_decode((string) $item['errores_json'], true) : [];
        }

        return $items;
    }

    private function validateRut(int $empresaId, ?string $rut, ?int $excludeId = null): void
    {
        if ($rut !== null && $this->repository->rutExists($empresaId, $rut, $excludeId)) {
            throw new HttpException('El RUT ya existe para esta empresa', 422);
        }
    }

    private function validateProduct(int $empresaId, int $productoId): void
    {
        if ($empresaId <= 0 || $productoId <= 0 || !$this->repository->productExists($empresaId, $productoId)) {
            throw new HttpException('Producto no encontrado', 404);
        }
    }

    private function validateProvider(int $empresaId, int $proveedorId): void
    {
        if ($empresaId <= 0 || $proveedorId <= 0 || !$this->repository->providerExists($empresaId, $proveedorId)) {
            throw new HttpException('Proveedor no encontrado', 404);
        }
    }

    private function providerProductData(array $payload): array
    {
        $providerId = $this->positiveInt($payload, 'proveedor_id');
        $productId = $this->positiveInt($payload, 'producto_id');
        $providerName = trim((string) ($payload['nombre_proveedor'] ?? ''));

        if ($providerName === '') {
            throw new HttpException('nombre_proveedor obligatorio', 422);
        }

        $factor = (float) ($payload['factor_conversion'] ?? 1);
        if ($factor <= 0) {
            throw new HttpException('factor_conversion debe ser mayor a 0', 422);
        }

        $lastCost = $payload['costo_ultimo'] ?? null;
        if ($lastCost !== null && $lastCost !== '' && (int) $lastCost < 0) {
            throw new HttpException('costo_ultimo no puede ser negativo', 422);
        }

        return [
            'proveedor_id' => $providerId,
            'producto_id' => $productId,
            'codigo_proveedor' => $this->nullable($payload['codigo_proveedor'] ?? null),
            'codigo_barra_proveedor' => $this->nullable($payload['codigo_barra_proveedor'] ?? null),
            'nombre_proveedor' => $providerName,
            'unidad_compra' => $this->nullable($payload['unidad_compra'] ?? null) ?? 'UN',
            'factor_conversion' => $factor,
            'costo_ultimo' => $lastCost === null || $lastCost === '' ? null : (int) $lastCost,
            'moneda' => $this->nullable($payload['moneda'] ?? null) ?? 'CLP',
            'proveedor_preferido' => filter_var($payload['proveedor_preferido'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'plazo_entrega_dias' => isset($payload['plazo_entrega_dias']) && $payload['plazo_entrega_dias'] !== '' ? max(0, (int) $payload['plazo_entrega_dias']) : null,
            'compra_minima' => isset($payload['compra_minima']) && $payload['compra_minima'] !== '' ? max(0, (float) $payload['compra_minima']) : null,
            'activo' => filter_var($payload['activo'] ?? true, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'observacion' => $this->nullable($payload['observacion'] ?? null),
        ];
    }

    private function providerPriceData(array $payload): array
    {
        $providerId = $this->positiveInt($payload, 'proveedor_id');
        $productId = $this->positiveInt($payload, 'producto_id');
        $price = $this->nonNegativeInt($payload, 'precio_compra');
        $factor = isset($payload['factor_conversion']) && $payload['factor_conversion'] !== '' ? (float) $payload['factor_conversion'] : null;
        $origin = strtoupper((string) ($payload['origen'] ?? 'MANUAL'));

        if ($factor !== null && $factor <= 0) {
            throw new HttpException('factor_conversion debe ser mayor a 0', 422);
        }

        if (!in_array($origin, ['MANUAL', 'IMPORTACION', 'COMPRA'], true)) {
            throw new HttpException('origen invalido', 422);
        }

        return [
            'proveedor_id' => $providerId,
            'producto_id' => $productId,
            'proveedor_producto_id' => isset($payload['proveedor_producto_id']) && (int) $payload['proveedor_producto_id'] > 0 ? (int) $payload['proveedor_producto_id'] : null,
            'precio_compra' => $price,
            'moneda' => $this->nullable($payload['moneda'] ?? null) ?? 'CLP',
            'unidad_compra' => $this->nullable($payload['unidad_compra'] ?? null) ?? 'UN',
            'factor_conversion' => $factor ?? 1,
            'fecha_precio' => $this->dateOrToday($payload['fecha_precio'] ?? null),
            'vigente_desde' => $this->dateOrNull($payload['vigente_desde'] ?? null),
            'vigente_hasta' => $this->dateOrNull($payload['vigente_hasta'] ?? null),
            'origen' => $origin,
            'compra_id' => isset($payload['compra_id']) && (int) $payload['compra_id'] > 0 ? (int) $payload['compra_id'] : null,
            'observacion' => $this->nullable($payload['observacion'] ?? null),
            'activo' => filter_var($payload['activo'] ?? true, FILTER_VALIDATE_BOOL) ? 1 : 0,
        ];
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);
        if ($value <= 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }
        return $value;
    }

    private function limit(mixed $value): int
    {
        $limit = (int) $value;
        if ($limit < 1 || $limit > 100) {
            throw new HttpException('limit debe ser un entero entre 1 y 100', 422);
        }
        return $limit;
    }

    private function nonNegativeInt(array $data, string $field): int
    {
        if (!isset($data[$field]) || !is_numeric($data[$field]) || (int) $data[$field] < 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} debe ser >= 0"]]);
        }

        return (int) $data[$field];
    }

    private function dateOrToday(mixed $value): string
    {
        return $this->dateOrNull($value) ?? date('Y-m-d');
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = (string) $value;
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $text);

        if (!$date || $date->format('Y-m-d') !== $text) {
            throw new HttpException('Fecha invalida', 422);
        }

        return $text;
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
