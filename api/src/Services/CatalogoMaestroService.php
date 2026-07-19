<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Repositories\CatalogoMaestroRepository;
use Mypos\Repositories\ProductoAtributoRepository;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class CatalogoMaestroService
{
    private PDO $db;
    private CatalogoMaestroRepository $repository;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->repository = new CatalogoMaestroRepository($this->db);
    }

    public function importarPaquete(string $packagePath, string $catalogCode = 'FARMA_CL_1'): array
    {
        [$directory, $cleanup] = $this->materializePackage($packagePath);
        try {
            $productsFile = $directory . DIRECTORY_SEPARATOR . 'productos.csv';
            $barcodesFile = $directory . DIRECTORY_SEPARATOR . 'productos_codigos_barra.csv';
            if (!is_file($productsFile) || !is_file($barcodesFile)) {
                throw new RuntimeException('El paquete requiere productos.csv y productos_codigos_barra.csv');
            }

            $manifest = $this->readJson($directory . DIRECTORY_SEPARATOR . 'manifest.json');
            $catalogId = $this->repository->upsertCatalogo([
                'codigo' => $catalogCode,
                'nombre' => 'Catalogo maestro farmacia Chile',
                'perfil' => 'FARMACIA',
                'version' => (string) ($manifest['version'] ?? '1.0'),
                'estado' => 'ACTIVO',
                'descripcion' => 'Catalogo compartido de referencia para farmacias y perfumeria.',
            ]);
            $sourceHash = hash_file('sha256', $productsFile) . hash_file('sha256', $barcodesFile);
            $sourceHash = hash('sha256', $sourceHash);
            $importId = $this->repository->createImport($catalogId, basename($packagePath), $sourceHash, $manifest);
            $metrics = [
                'estado' => 'APLICADA',
                'productos_procesados' => 0,
                'productos_creados' => 0,
                'productos_actualizados' => 0,
                'codigos_procesados' => 0,
                'codigos_creados' => 0,
                'codigos_actualizados' => 0,
                'errores' => 0,
            ];

            $this->db->beginTransaction();
            try {
                $productIds = $this->repository->productExternalIds($catalogId);
                foreach ($this->csvRows($productsFile) as $row) {
                    ++$metrics['productos_procesados'];
                    $externalId = $this->required($row, 'producto_externo_id');
                    $name = $this->required($row, 'nombre');
                    $existing = $productIds[$externalId] ?? null;
                    $data = $this->productData($row, $externalId, $name);
                    $productIds[$externalId] = $this->repository->upsertProduct($catalogId, $data);
                    $existing === null ? ++$metrics['productos_creados'] : ++$metrics['productos_actualizados'];
                }

                $barcodeProducts = $this->repository->barcodeProductMap($catalogId);
                foreach ($this->csvRows($barcodesFile) as $row) {
                    ++$metrics['codigos_procesados'];
                    $externalId = $this->required($row, 'producto_externo_id');
                    $barcode = $this->required($row, 'codigo_barra');
                    $productId = $productIds[$externalId] ?? null;
                    if ($productId === null) {
                        ++$metrics['errores'];
                        continue;
                    }
                    $existingProduct = $barcodeProducts[$barcode] ?? null;
                    if ($existingProduct !== null && $existingProduct !== $productId) {
                        ++$metrics['errores'];
                        continue;
                    }
                    $this->repository->upsertBarcode($catalogId, $productId, [
                        'codigo_barra' => $barcode,
                        'tipo_codigo' => $this->text($row['tipo_codigo'] ?? null) ?? 'OTRO',
                        'principal' => $this->boolInt($row['principal'] ?? 0),
                        'activo' => $this->boolInt($row['activo'] ?? 1),
                        'descripcion' => $this->text($row['descripcion'] ?? null),
                        'source_hash' => hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    ]);
                    $barcodeProducts[$barcode] = $productId;
                    $existingProduct === null ? ++$metrics['codigos_creados'] : ++$metrics['codigos_actualizados'];
                }

                if ($metrics['errores'] > 0) {
                    $metrics['estado'] = 'CON_ERRORES';
                }
                $this->repository->finishImport($importId, $metrics);
                $this->db->commit();
            } catch (Throwable $exception) {
                $this->db->rollBack();
                $metrics['estado'] = 'CON_ERRORES';
                $metrics['errores'] = max(1, $metrics['errores']);
                $this->repository->finishImport($importId, $metrics);
                throw $exception;
            }

            return ['catalogo_id' => $catalogId, 'importacion_id' => $importId, ...$metrics];
        } finally {
            if ($cleanup !== null) {
                $this->removeDirectory($cleanup);
            }
        }
    }

    public function buscar(string $catalogCode, string $query, int $limit = 50): array
    {
        $catalogId = $this->catalogId($catalogCode);
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw new HttpException('La busqueda requiere al menos 2 caracteres', 422);
        }
        $productos = array_map(
            fn (array $row): array => $this->exposeMetadata($row),
            $this->repository->search($catalogId, $query, max(1, min($limit, 100)))
        );
        return ['productos' => $productos];
    }

    public function buscarCodigo(string $catalogCode, string $barcode): array
    {
        $catalogId = $this->catalogId($catalogCode);
        $product = $this->repository->findByBarcode($catalogId, trim($barcode));
        if ($product === null) {
            throw new HttpException('Producto no encontrado en el catalogo maestro', 404);
        }
        return ['producto' => $this->exposeMetadata($product)];
    }

    public function metricas(string $catalogCode): array
    {
        return ['metricas' => $this->repository->metrics($this->catalogId($catalogCode))];
    }

    public function vinculo(int $userId, int $catalogProductId, int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('Error de validacion', 422, ['empresa_id' => ['La empresa_id es obligatoria']]);
        }
        (new TenantMiddleware())->handle($userId, $empresaId);
        return ['vinculo' => $this->repository->findCompanyLink($empresaId, $catalogProductId)];
    }

    public function incorporar(int $userId, int $catalogProductId, array $data): array
    {
        $empresaId = (int) ($data['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new HttpException('Error de validacion', 422, ['empresa_id' => ['La empresa_id es obligatoria']]);
        }
        (new TenantMiddleware())->handle($userId, $empresaId);

        $catalogCode = (string) ($data['catalogo'] ?? 'FARMA_CL_1');
        $catalogId = $this->catalogId($catalogCode);
        $master = $this->repository->findProduct($catalogId, $catalogProductId);
        if ($master === null) {
            throw new HttpException('Producto no encontrado en el catalogo maestro', 404);
        }

        $existing = $this->repository->findCompanyLink($empresaId, $catalogProductId);
        if ($existing !== null) {
            return ['creado' => false, 'vinculo' => $existing];
        }

        $code = trim((string) ($data['codigo'] ?? $master['codigo_referencia'] ?? $master['producto_externo_id']));
        if ($code === '') {
            throw new HttpException('Error de validacion', 422, ['codigo' => ['El codigo privado es obligatorio']]);
        }
        $cost = $this->nonNegativeInteger($data, 'precio_costo');
        $price = $this->nonNegativeInteger($data, 'precio_venta');
        $barcodes = $this->selectedBarcodes($catalogId, $catalogProductId, $data);

        $payload = [
            'empresa_id' => $empresaId,
            'rubro_id' => $data['rubro_id'] ?? null,
            'centro_costo_id' => $data['centro_costo_id'] ?? null,
            'codigo' => $code,
            'nombre' => trim((string) ($data['nombre'] ?? $master['nombre'])),
            'descripcion' => $data['descripcion'] ?? $master['descripcion'],
            'unidad_medida' => $data['unidad_medida'] ?? $master['unidad_medida'] ?? 'UN',
            'precio_costo' => $cost,
            'precio_venta' => $price,
            'controla_stock' => (int) ($data['controla_stock'] ?? 1),
            'stock_minimo' => $data['stock_minimo'] ?? '0.000',
            'permite_descuento' => (int) ($data['permite_descuento'] ?? 1),
            'permite_comision' => (int) ($data['permite_comision'] ?? 1),
            'activo' => 1,
            'codigos_barra' => array_map(static fn (array $barcode): array => [
                'codigo_barra' => $barcode['codigo_barra'],
                'tipo_codigo' => $barcode['tipo_codigo'],
                'principal' => (int) $barcode['principal'],
            ], $barcodes),
        ];

        $this->db->beginTransaction();
        try {
            $productId = (int) (new ProductoService())->createProducto($userId, $payload)['id'];
            $attributesCopied = $this->copyKnownAttributes($empresaId, $productId, $master);
            $linkId = $this->repository->createCompanyLink(
                $empresaId,
                $catalogId,
                $catalogProductId,
                $productId,
                $userId
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return [
            'creado' => true,
            'vinculo' => [
                'id' => $linkId,
                'empresa_id' => $empresaId,
                'catalogo_id' => $catalogId,
                'catalogo_producto_id' => $catalogProductId,
                'producto_id' => $productId,
                'codigos_barra_copiados' => count($barcodes),
                'atributos_copiados' => $attributesCopied,
            ],
        ];
    }

    private function productData(array $row, string $externalId, string $name): array
    {
        $normalized = [
            'producto_externo_id' => $externalId,
            'codigo_referencia' => $this->text($row['codigo_interno'] ?? null),
            'nombre' => $name,
            'descripcion' => $this->text($row['descripcion'] ?? null),
            'unidad_medida' => $this->text($row['unidad_medida'] ?? null),
            'categoria' => $this->text($row['categoria'] ?? null),
            'familia' => $this->text($row['familia'] ?? null),
            'laboratorio' => $this->text($row['laboratorio'] ?? null),
            'principio_activo' => $this->text($row['principio_activo'] ?? null),
            'dosis' => $this->text($row['dosis'] ?? null),
            'titular' => $this->text($row['titular'] ?? null),
            'presentacion' => $this->text($row['presentacion'] ?? null),
            'bioequivalente' => $this->text($row['bioequivalente'] ?? null),
            'cenabast' => $this->boolInt($row['cenabast'] ?? 0),
            'estado_revision' => 'VALIDO',
            'metadata_json' => $this->metadataJson($row),
        ];
        $normalized['source_hash'] = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $normalized;
    }

    private function exposeMetadata(array $row): array
    {
        $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
        unset($row['metadata_json']);
        $precio = is_array($metadata) ? ($metadata['precio_referencia'] ?? null) : null;
        $row['precio_referencia'] = is_numeric($precio) ? (int) $precio : null;
        return $row;
    }

    private function metadataJson(array $row): ?string
    {
        $metadata = [];
        $precioReferencia = $this->text($row['precio_referencia'] ?? null);
        if ($precioReferencia !== null && ctype_digit($precioReferencia) && (int) $precioReferencia > 0) {
            $metadata['precio_referencia'] = (int) $precioReferencia;
        }
        return $metadata === []
            ? null
            : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function csvRows(string $path): iterable
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir {$path}");
        }
        try {
            $headers = fgetcsv($handle);
            if (!is_array($headers)) {
                throw new RuntimeException("CSV sin encabezados: {$path}");
            }
            $headers = array_map(static fn ($value): string => preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $value)) ?? trim((string) $value), $headers);
            while (($values = fgetcsv($handle)) !== false) {
                if (count($values) !== count($headers)) {
                    throw new RuntimeException("Fila con cantidad de columnas invalida en {$path}");
                }
                yield array_combine($headers, $values);
            }
        } finally {
            fclose($handle);
        }
    }

    private function materializePackage(string $path): array
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException('Paquete de importacion no encontrado');
        }
        if (is_dir($resolved)) {
            return [$resolved, null];
        }
        if (strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'zip' || !class_exists(ZipArchive::class)) {
            throw new RuntimeException('El paquete debe ser una carpeta o ZIP y ZipArchive debe estar disponible');
        }
        $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mypos_catalogo_' . bin2hex(random_bytes(8));
        mkdir($target, 0700, true);
        $zip = new ZipArchive();
        if ($zip->open($resolved) !== true) {
            throw new RuntimeException('No se pudo abrir el ZIP');
        }
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = (string) $zip->getNameIndex($index);
            if ($name !== basename($name) || str_contains($name, '..')) {
                $zip->close();
                $this->removeDirectory($target);
                throw new RuntimeException('El ZIP contiene rutas no permitidas');
            }
        }
        $zip->extractTo($target);
        $zip->close();
        return [$target, $target];
    }

    private function catalogId(string $code): int
    {
        $id = $this->repository->catalogId(strtoupper(trim($code)));
        if ($id === null) {
            throw new HttpException('Catalogo maestro no encontrado', 404);
        }
        return $id;
    }

    private function required(array $row, string $field): string
    {
        $value = $this->text($row[$field] ?? null);
        if ($value === null) {
            throw new RuntimeException("Campo obligatorio vacio: {$field}");
        }
        return $value;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function boolInt(mixed $value): int
    {
        return in_array(strtoupper(trim((string) $value)), ['1', 'SI', 'TRUE', 'YES'], true) ? 1 : 0;
    }

    private function nonNegativeInteger(array $data, string $field): int
    {
        $value = $data[$field] ?? null;
        if (!is_int($value) && !ctype_digit((string) $value) || (int) $value < 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} debe ser un entero >= 0"]]);
        }
        return (int) $value;
    }

    private function copyKnownAttributes(int $empresaId, int $productId, array $master): int
    {
        $repository = new ProductoAtributoRepository($this->db);
        $definitions = $repository->listDefinitions($empresaId);
        $source = [
            'principio_activo' => $master['principio_activo'],
            'laboratorio' => $master['laboratorio'],
            'dosis' => $master['dosis'],
            'titular' => $master['titular'],
            'presentacion' => $master['presentacion'],
            'bioequivalente' => $master['bioequivalente'],
            'cenabast' => (int) $master['cenabast'],
        ];
        $values = [];
        foreach ($definitions as $definition) {
            $code = (string) $definition['codigo'];
            if (!array_key_exists($code, $source) || $source[$code] === null || $source[$code] === '') {
                continue;
            }
            $normalized = match ($definition['tipo_dato']) {
                'BOOLEANO' => ['valor_booleano' => $this->boolInt($source[$code])],
                'TEXTO', 'OPCION' => ['valor_texto' => (string) $source[$code]],
                default => null,
            };
            if ($normalized === null) {
                continue;
            }
            $values[(int) $definition['id']] = $normalized;
        }
        $repository->replaceValues($productId, $empresaId, $values);
        return count($values);
    }

    private function selectedBarcodes(int $catalogId, int $catalogProductId, array $data): array
    {
        if (($data['incluir_codigos_barra'] ?? true) === false) {
            return [];
        }
        $available = $this->repository->productBarcodes($catalogId, $catalogProductId);
        if (!array_key_exists('codigos_barra', $data)) {
            return $available;
        }
        if (!is_array($data['codigos_barra'])) {
            throw new HttpException('Error de validacion', 422, ['codigos_barra' => ['Debe ser un arreglo']]);
        }
        $requested = array_values(array_unique(array_map('strval', $data['codigos_barra'])));
        $selected = array_values(array_filter(
            $available,
            static fn (array $barcode): bool => in_array((string) $barcode['codigo_barra'], $requested, true)
        ));
        if (count($selected) !== count($requested)) {
            throw new HttpException('Error de validacion', 422, [
                'codigos_barra' => ['Uno o mas codigos no pertenecen al producto maestro'],
            ]);
        }
        return $selected;
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function removeDirectory(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
