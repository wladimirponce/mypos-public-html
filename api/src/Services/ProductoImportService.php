<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Repositories\ProductoRepository;
use PDO;
use Throwable;

final class ProductoImportService
{
    private PDO $connection;
    private ProductoRepository $productos;
    private ImportParserService $parser;
    private StockService $stock;

    private const EXPECTED_FIELDS = [
        'codigo_interno', 'nombre', 'precio_costo', 'precio_venta',
        'rubro', 'stock_minimo', 'stock_inicial', 'activo',
    ];

    public function __construct()
    {
        $this->connection = Database::connection();
        $this->productos = new ProductoRepository($this->connection);
        $this->parser = new ImportParserService();
        $this->stock = new StockService();
    }

    public function upload(int $userId, int $empresaId, string $filePath, string $mime): array
    {
        $this->tenant($userId, $empresaId);

        $items = str_ends_with(strtolower($filePath), '.xlsx') || str_contains($mime, 'spreadsheet')
            ? $this->parser->parseXlsx($filePath, self::EXPECTED_FIELDS)
            : $this->parser->parseCsv($filePath, self::EXPECTED_FIELDS);

        if ($items === []) {
            throw new HttpException('El archivo no contiene filas de datos', 422);
        }

        if (count($items) > 5000) {
            throw new HttpException('Máximo 5000 filas por importación', 422);
        }

        // Se leen antes de abrir la transaccion: describen el archivo, no la fila.
        $columnasIgnoradas = $this->parser->columnasIgnoradas();

        $this->connection->beginTransaction();
        try {
            $importId = $this->createImport($empresaId, $userId, count($items), $columnasIgnoradas);

            $rubros = $this->loadRubros($empresaId);
            $codigosExistentes = $this->loadCodigos($empresaId);

            $filas_ok = 0;
            $filas_error = 0;
            $filas_nuevas = 0;
            $filas_actualizar = 0;
            $seenCodes = [];

            foreach ($items as $linea => $raw) {
                $codigo  = trim((string) ($raw['codigo_interno'] ?? ''));
                $nombre  = trim((string) ($raw['nombre'] ?? ''));
                $pVenta  = (int) round((float) ($raw['precio_venta'] ?? 0));
                $pCosto  = (int) round((float) ($raw['precio_costo'] ?? 0));
                $rubroNombre = trim((string) ($raw['rubro'] ?? ''));
                $stockMin = max(0.0, (float) ($raw['stock_minimo'] ?? 0));
                // NULL (columna ausente) y 0 significan cosas distintas: sin
                // columna no se toca el stock; con un 0 explicito tampoco hay
                // movimiento, pero el usuario lo declaro.
                $stockInicial = $raw['stock_inicial'] === null || $raw['stock_inicial'] === ''
                    ? null
                    : max(0.0, (float) $raw['stock_inicial']);
                $activoRaw = trim((string) ($raw['activo'] ?? '1'));
                $activo = in_array(strtolower($activoRaw), ['0', 'false', 'no', 'n', 'inactivo'], true) ? 0 : 1;

                $errors = [];
                if ($codigo === '') {
                    $errors[] = 'Código obligatorio';
                }
                if ($nombre === '') {
                    $errors[] = 'Nombre obligatorio';
                }
                if ($pVenta <= 0) {
                    $errors[] = 'Precio venta debe ser mayor a 0';
                }
                if ($pCosto < 0) {
                    $errors[] = 'Precio costo no puede ser negativo';
                }
                if ($codigo !== '' && isset($seenCodes[$codigo])) {
                    $errors[] = 'Código duplicado en el archivo';
                }

                $rubroId = null;
                if ($rubroNombre !== '' && $errors === []) {
                    $normalized = mb_strtolower(trim($rubroNombre), 'UTF-8');
                    $rubroId = $rubros[$normalized] ?? null;
                }

                $productoId = $codigo !== '' ? ($codigosExistentes[$codigo] ?? null) : null;
                $accion = $productoId !== null ? 'ACTUALIZAR' : 'CREAR';
                $estado = $errors === [] ? 'OK' : 'ERROR';

                if ($estado === 'OK') {
                    ++$filas_ok;
                    if ($accion === 'CREAR') {
                        ++$filas_nuevas;
                    } else {
                        ++$filas_actualizar;
                    }
                } else {
                    ++$filas_error;
                }

                if ($codigo !== '') {
                    $seenCodes[$codigo] = true;
                }

                $this->insertItem($importId, $empresaId, $linea + 1, [
                    'codigo'      => $codigo,
                    'nombre'      => $nombre,
                    'precio_costo' => $pCosto,
                    'precio_venta' => $pVenta,
                    'rubro'       => $rubroNombre !== '' ? $rubroNombre : null,
                    'rubro_id'    => $rubroId,
                    'stock_minimo' => $stockMin,
                    'stock_inicial' => $stockInicial,
                    'activo'      => $activo,
                    'producto_id' => $productoId,
                    'accion'      => $estado === 'ERROR' ? null : $accion,
                    'estado'      => $estado,
                    'errores_json' => $errors !== [] ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
                    'raw_json'    => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $this->updateImportTotals($importId, $empresaId, $filas_ok, $filas_nuevas, $filas_actualizar, $filas_error);
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return [
            'id'              => $importId,
            'total_filas'     => count($items),
            'filas_ok'        => $filas_ok,
            'filas_nuevas'    => $filas_nuevas,
            'filas_actualizar' => $filas_actualizar,
            'filas_error'     => $filas_error,
            'estado'          => $filas_error > 0 ? 'PENDIENTE' : 'VALIDADO',
            'columnas_ignoradas' => $columnasIgnoradas,
        ];
    }

    public function show(int $userId, int $empresaId, int $id): array
    {
        $this->tenant($userId, $empresaId);
        $import = $this->findImport($empresaId, $id);

        if ($import === null) {
            throw new HttpException('Importación no encontrada', 404);
        }

        $items = $this->getItems($empresaId, $id);

        $import['columnas_ignoradas'] = $import['columnas_ignoradas_json']
            ? (json_decode((string) $import['columnas_ignoradas_json'], true) ?: [])
            : [];
        unset($import['columnas_ignoradas_json']);

        return [
            'importacion' => $import,
            'items'       => array_map(function (array $item): array {
                $item['errores'] = $item['errores_json'] ? json_decode($item['errores_json'], true) : [];
                unset($item['errores_json']);
                return $item;
            }, $items),
        ];
    }

    public function aplicar(int $userId, int $empresaId, int $id, ?int $sucursalId = null): array
    {
        $this->tenant($userId, $empresaId);
        $import = $this->findImport($empresaId, $id);

        if ($import === null) {
            throw new HttpException('Importación no encontrada', 404);
        }

        if ($import['estado'] === 'APLICADO') {
            throw new HttpException('La importación ya fue aplicada', 409);
        }

        $items = $this->getValidItems($empresaId, $id);
        $creados = 0;
        $actualizados = 0;
        $rubrosCreados = 0;
        $stockCargado = 0;

        // Solo se resuelve la sucursal si el archivo trae existencias, para no
        // fallar una importacion de solo precios en una empresa sin sucursales.
        $necesitaStock = false;
        foreach ($items as $item) {
            if ($item['stock_inicial'] !== null && (float) $item['stock_inicial'] > 0) {
                $necesitaStock = true;
                break;
            }
        }

        $sucursalDestino = $necesitaStock
            ? $this->resolveSucursalDestino($empresaId, $sucursalId ?? (int) ($import['sucursal_id'] ?? 0))
            : 0;

        $this->connection->beginTransaction();
        try {
            // Los rubros que el archivo nombra y la empresa todavia no tiene se
            // crean aqui. Antes la importacion solo sabia BUSCAR el rubro por
            // nombre y, si no existia, dejaba el producto en `rubro_id = null`
            // sin avisar; como tampoco habia pantalla para crear rubros, el
            // catalogo entero terminaba en "Sin rubro".
            $rubros = $this->loadRubros($empresaId);
            foreach ($items as $index => $item) {
                $nombreRubro = trim((string) ($item['rubro'] ?? ''));
                if ($nombreRubro === '' || !empty($item['rubro_id'])) {
                    continue;
                }

                $normalized = mb_strtolower($nombreRubro, 'UTF-8');
                if (!isset($rubros[$normalized])) {
                    $rubros[$normalized] = $this->createRubro($empresaId, $nombreRubro);
                    ++$rubrosCreados;
                }

                $items[$index]['rubro_id'] = $rubros[$normalized];
            }

            foreach ($items as $item) {
                $productoId = 0;

                if ($item['accion'] === 'CREAR') {
                    $productoId = $this->productos->create([
                        'empresa_id'   => $empresaId,
                        'rubro_id'     => $item['rubro_id'] ?: null,
                        'codigo'       => $item['codigo'],
                        'nombre'       => $item['nombre'],
                        'precio_costo' => (int) $item['precio_costo'],
                        'precio_venta' => (int) $item['precio_venta'],
                        'stock_minimo' => number_format((float) $item['stock_minimo'], 3, '.', ''),
                        'controla_stock' => 1,
                        'permite_descuento' => 1,
                        'permite_comision' => 1,
                        'activo'       => (int) $item['activo'],
                    ]);
                    ++$creados;
                } elseif ($item['accion'] === 'ACTUALIZAR' && $item['producto_id']) {
                    $existing = $this->productos->find((int) $item['producto_id'], $empresaId);
                    if ($existing !== null) {
                        $this->productos->update((int) $item['producto_id'], $empresaId, [
                            'empresa_id'     => $empresaId,
                            'rubro_id'       => $item['rubro_id'] ?: ($existing['rubro_id'] ?? null),
                            'centro_costo_id' => $existing['centro_costo_id'] ?? null,
                            'codigo'         => $item['codigo'],
                            'nombre'         => $item['nombre'],
                            'descripcion'    => $existing['descripcion'] ?? null,
                            'unidad_medida'  => $existing['unidad_medida'] ?? 'UN',
                            'precio_costo'   => (int) $item['precio_costo'] ?: (int) ($existing['precio_costo'] ?? 0),
                            'precio_venta'   => (int) $item['precio_venta'],
                            'stock_minimo'   => number_format((float) $item['stock_minimo'], 3, '.', ''),
                            'controla_stock' => $existing['controla_stock'] ?? 1,
                            'permite_descuento' => $existing['permite_descuento'] ?? 1,
                            'permite_comision' => $existing['permite_comision'] ?? 1,
                            'activo'         => (int) $item['activo'],
                        ]);
                        $productoId = (int) $item['producto_id'];
                        ++$actualizados;
                    }
                }

                // Carga de existencias. Se hace como AJUSTE dentro de la misma
                // transaccion que crea el producto: si el movimiento falla, no
                // queda un catalogo a medias con stock inconsistente.
                $stockInicial = $item['stock_inicial'] === null ? null : (float) $item['stock_inicial'];
                if ($productoId > 0 && $stockInicial !== null && $stockInicial > 0 && $sucursalDestino > 0) {
                    $this->stock->ajustarStock([
                        'empresa_id'  => $empresaId,
                        'sucursal_id' => $sucursalDestino,
                        'producto_id' => $productoId,
                        'cantidad'    => $stockInicial,
                        'usuario_id'  => $userId,
                        'motivo'      => 'Carga inicial por importacion de catalogo #' . $id,
                    ], $this->connection);
                    ++$stockCargado;
                }
            }

            $this->markApplied($id, $empresaId);
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return [
            'id'          => $id,
            'estado'      => 'APLICADO',
            'creados'     => $creados,
            'actualizados' => $actualizados,
            'rubros_creados' => $rubrosCreados,
            'stock_cargado' => $stockCargado,
            'sucursal_id' => $sucursalDestino > 0 ? $sucursalDestino : null,
        ];
    }

    /**
     * Sucursal a la que se cargan las existencias.
     *
     * Si el usuario eligio una, se valida que sea de la empresa. Si no, se usa
     * la primera sucursal activa, que en una empresa recien creada es la Casa
     * Matriz que genera el registro.
     */
    private function resolveSucursalDestino(int $empresaId, int $sucursalId): int
    {
        if ($sucursalId > 0) {
            $statement = $this->connection->prepare(
                'SELECT id FROM sucursales WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1'
            );
            $statement->execute(['id' => $sucursalId, 'empresa_id' => $empresaId]);
            $found = (int) $statement->fetchColumn();

            if ($found === 0) {
                throw new HttpException('La sucursal indicada no existe o no pertenece a la empresa', 422);
            }

            return $found;
        }

        $statement = $this->connection->prepare(
            'SELECT id FROM sucursales WHERE empresa_id = :empresa_id AND activo = 1 ORDER BY id LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $primera = (int) $statement->fetchColumn();

        if ($primera === 0) {
            throw new HttpException(
                'El archivo trae existencias pero la empresa no tiene ninguna sucursal activa donde cargarlas',
                422
            );
        }

        return $primera;
    }

    /**
     * Crea un rubro nombrado por el archivo importado y devuelve su id.
     *
     * `uq_rubros_empresa_nombre` es case-insensitive por la collation
     * utf8mb4_unicode_ci, asi que si el rubro ya existia con otra
     * capitalizacion el INSERT IGNORE no hace nada y hay que recuperar el id
     * existente en vez de confiar en lastInsertId().
     */
    private function createRubro(int $empresaId, string $nombre): int
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO rubros (empresa_id, nombre, activo) VALUES (:empresa_id, :nombre, 1)'
        );
        $statement->execute(['empresa_id' => $empresaId, 'nombre' => $nombre]);

        $id = (int) $this->connection->lastInsertId();
        if ($statement->rowCount() > 0 && $id > 0) {
            return $id;
        }

        $lookup = $this->connection->prepare(
            'SELECT id FROM rubros WHERE empresa_id = :empresa_id AND nombre = :nombre LIMIT 1'
        );
        $lookup->execute(['empresa_id' => $empresaId, 'nombre' => $nombre]);

        return (int) $lookup->fetchColumn();
    }

    private function loadRubros(int $empresaId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, nombre FROM rubros WHERE empresa_id = :empresa_id AND activo = 1'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[mb_strtolower(trim($row['nombre']), 'UTF-8')] = (int) $row['id'];
        }

        return $map;
    }

    private function loadCodigos(int $empresaId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, codigo FROM productos WHERE empresa_id = :empresa_id'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['codigo']] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * @param array<int, string> $columnasIgnoradas
     */
    private function createImport(int $empresaId, int $userId, int $total, array $columnasIgnoradas = []): int
    {
        $this->connection->prepare(
            'INSERT INTO importaciones_maestro (empresa_id, usuario_id, total_filas, columnas_ignoradas_json)
             VALUES (:empresa_id, :usuario_id, :total_filas, :columnas_ignoradas_json)'
        )->execute([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'total_filas' => $total,
            'columnas_ignoradas_json' => $columnasIgnoradas === []
                ? null
                : json_encode(array_values($columnasIgnoradas), JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function insertItem(int $importId, int $empresaId, int $linea, array $d): void
    {
        $this->connection->prepare(
            'INSERT INTO importaciones_maestro_items
                (empresa_id, importacion_id, linea, codigo, nombre, precio_costo, precio_venta,
                 rubro, rubro_id, stock_minimo, stock_inicial, activo, producto_id, accion, estado, errores_json, raw_json)
             VALUES
                (:empresa_id, :importacion_id, :linea, :codigo, :nombre, :precio_costo, :precio_venta,
                 :rubro, :rubro_id, :stock_minimo, :stock_inicial, :activo, :producto_id, :accion, :estado, :errores_json, :raw_json)'
        )->execute([
            'empresa_id'    => $empresaId,
            'importacion_id' => $importId,
            'linea'         => $linea,
            'codigo'        => $d['codigo'],
            'nombre'        => $d['nombre'],
            'precio_costo'  => $d['precio_costo'],
            'precio_venta'  => $d['precio_venta'],
            'rubro'         => $d['rubro'],
            'rubro_id'      => $d['rubro_id'],
            'stock_minimo'  => $d['stock_minimo'],
            'stock_inicial' => $d['stock_inicial'],
            'activo'        => $d['activo'],
            'producto_id'   => $d['producto_id'],
            'accion'        => $d['accion'],
            'estado'        => $d['estado'],
            'errores_json'  => $d['errores_json'],
            'raw_json'      => $d['raw_json'],
        ]);
    }

    private function updateImportTotals(
        int $importId, int $empresaId,
        int $ok, int $nuevas, int $actualizar, int $error
    ): void {
        $estado = $error === 0 ? 'VALIDADO' : 'PENDIENTE';
        $this->connection->prepare(
            'UPDATE importaciones_maestro
             SET filas_ok = :ok, filas_nuevas = :nuevas, filas_actualizar = :actualizar,
                 filas_error = :error, estado = :estado
             WHERE id = :id AND empresa_id = :empresa_id'
        )->execute([
            'ok' => $ok, 'nuevas' => $nuevas, 'actualizar' => $actualizar,
            'error' => $error, 'estado' => $estado,
            'id' => $importId, 'empresa_id' => $empresaId,
        ]);
    }

    private function markApplied(int $id, int $empresaId): void
    {
        $this->connection->prepare(
            "UPDATE importaciones_maestro SET estado = 'APLICADO', applied_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id"
        )->execute(['id' => $id, 'empresa_id' => $empresaId]);
    }

    private function findImport(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM importaciones_maestro WHERE id = :id AND empresa_id = :empresa_id LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function getItems(int $empresaId, int $id): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, linea, codigo, nombre, precio_costo, precio_venta, rubro, rubro_id,
                    stock_minimo, stock_inicial, activo, producto_id, accion, estado, errores_json
             FROM importaciones_maestro_items
             WHERE empresa_id = :empresa_id AND importacion_id = :id
             ORDER BY linea'
        );
        $statement->execute(['empresa_id' => $empresaId, 'id' => $id]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getValidItems(int $empresaId, int $id): array
    {
        $statement = $this->connection->prepare(
            "SELECT id, linea, codigo, nombre, precio_costo, precio_venta, rubro, rubro_id,
                    stock_minimo, stock_inicial, activo, producto_id, accion
             FROM importaciones_maestro_items
             WHERE empresa_id = :empresa_id AND importacion_id = :id AND estado = 'OK'
             ORDER BY linea"
        );
        $statement->execute(['empresa_id' => $empresaId, 'id' => $id]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function tenant(int $userId, int $empresaId): void
    {
        (new TenantMiddleware())->handle($userId, $empresaId);
    }
}
