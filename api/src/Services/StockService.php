<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\StockRepository;
use PDO;
use Throwable;

final class StockService
{
    private const TIPOS_VALIDOS = [
        'VENTA',
        'COMPRA',
        'AJUSTE',
        'DEVOLUCION',
        'TRASPASO_ENTRADA',
        'TRASPASO_SALIDA',
        'ANULACION_VENTA',
        'REVERSA_COMPRA',
        'MERMA',
    ];

    private const TIPOS_UBICACION = [
        'SUCURSAL_VENTA',
        'BODEGA',
        'TRANSITO',
        'MERMA',
        'VIRTUAL',
    ];

    private StockRepository $repository;

    public function __construct(?StockRepository $repository = null)
    {
        $this->repository = $repository ?? new StockRepository(Database::connection());
    }

    public function obtenerStockProducto(int $empresaId, int $sucursalId, int $productoId): array
    {
        $this->validarBase($empresaId, $sucursalId, $productoId);
        $this->repository->ensureStockRow($empresaId, $sucursalId, $productoId);

        $stock = $this->repository->getStockProduct($empresaId, $sucursalId, $productoId);

        if ($stock === null) {
            throw new HttpException('Stock no encontrado', 404);
        }

        return $stock;
    }

    public function listarStock(int $empresaId, int $sucursalId, ?string $q = null): array
    {
        if ($empresaId <= 0 || $sucursalId <= 0) {
            throw new HttpException('Error de validación', 422, [
                'empresa_id' => ['La empresa_id es obligatoria'],
                'sucursal_id' => ['La sucursal_id es obligatoria'],
            ]);
        }

        if (!$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 404);
        }

        return ['stock' => $this->repository->listStock($empresaId, $sucursalId, $q)];
    }

    public function listarStockUbicacion(int $empresaId, int $ubicacionId, ?string $q = null): array
    {
        if ($empresaId <= 0 || $ubicacionId <= 0) {
            throw new HttpException('Error de validacion', 422);
        }

        if ($this->repository->findLocation($empresaId, $ubicacionId) === null) {
            throw new HttpException('Ubicacion no encontrada', 404);
        }

        return ['stock' => $this->repository->listStockByLocation($empresaId, $ubicacionId, $q)];
    }

    /**
     * Verifica el invariante de integridad de stock para la empresa:
     * stock_sucursal.cantidad == SUM(stock_ubicacion de la sucursal).
     * Solo lectura; util para endpoint de monitoreo y cron de reconciliacion.
     */
    public function verificarIntegridad(int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('Error de validacion', 422, ['empresa_id' => ['La empresa_id es obligatoria']]);
        }

        $descuadres = $this->repository->findStockDiscrepancies($empresaId);

        return [
            'integro' => $descuadres === [],
            'total_descuadres' => count($descuadres),
            'descuadres' => $descuadres,
        ];
    }

    public function listarMovimientos(int $empresaId, int $sucursalId, ?int $productoId = null): array
    {
        if ($empresaId <= 0 || $sucursalId <= 0) {
            throw new HttpException('Error de validación', 422);
        }

        if (!$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 404);
        }

        return ['movimientos' => $this->repository->listMovements($empresaId, $sucursalId, $productoId)];
    }

    public function listarUbicaciones(int $empresaId, ?string $tipo = null, ?int $sucursalId = null, bool $includeInactive = false): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('Error de validacion', 422, ['empresa_id' => ['La empresa_id es obligatoria']]);
        }

        if ($tipo !== null && trim($tipo) !== '' && !in_array(strtoupper(trim($tipo)), self::TIPOS_UBICACION, true)) {
            throw new HttpException('Tipo de ubicacion invalido', 422);
        }

        return ['ubicaciones' => $this->repository->listLocations($empresaId, $tipo, $sucursalId, $includeInactive)];
    }

    public function crearUbicacion(array $data): array
    {
        $this->validateLocation($data);
        $this->validateLocationSucursal($data);

        return ['id' => $this->repository->createLocation($data)];
    }

    public function actualizarUbicacion(int $id, array $data): array
    {
        $empresaId = $this->positiveInt($data, 'empresa_id');
        $this->validateLocation($data);
        $this->validateLocationSucursal($data);

        if (!$this->repository->updateLocation($id, $empresaId, $data)) {
            throw new HttpException('Ubicacion no encontrada', 404);
        }

        return ['id' => $id];
    }

    public function desactivarUbicacion(int $empresaId, int $id): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('Error de validacion', 422, ['empresa_id' => ['La empresa_id es obligatoria']]);
        }

        if (!$this->repository->deactivateLocation($id, $empresaId)) {
            throw new HttpException('Ubicacion no encontrada', 404);
        }

        return ['id' => $id, 'activo' => 0];
    }

    public function registrarMovimiento(array $data, ?PDO $externalConnection = null): array
    {
        $connection = $externalConnection ?? $this->repository->connection();
        $ownsTransaction = !$connection->inTransaction();

        if ($externalConnection !== null) {
            $repository = new StockRepository($externalConnection);
        } else {
            $repository = $this->repository;
        }

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $empresaId = $this->positiveInt($data, 'empresa_id');
            $sucursalId = $this->positiveInt($data, 'sucursal_id');
            $productoId = $this->positiveInt($data, 'producto_id');
            $tipo = strtoupper((string) ($data['tipo'] ?? $data['tipo_movimiento'] ?? ''));
            $cantidad = $this->quantity($data['cantidad'] ?? null);

            if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
                throw new HttpException('Tipo de movimiento inválido', 422);
            }

            if ($cantidad === 0.0) {
                throw new HttpException('La cantidad no puede ser 0', 422);
            }

            $this->validarBase($empresaId, $sucursalId, $productoId, $repository);
            $producto = $repository->productoStockData($empresaId, $productoId);

            if ((int) $producto['controla_stock'] !== 1) {
                throw new HttpException('El producto no controla stock', 422);
            }

            // stock_ubicacion es la unica fuente de verdad. Toda operacion debe
            // resolver una ubicacion concreta; stock_sucursal se deriva luego.
            $ubicacion = $this->resolveLocation($repository, $empresaId, $sucursalId, $tipo, $data);
            $ubicacionId = (int) $ubicacion['id'];

            $repository->ensureLocationStockRow($empresaId, $ubicacionId, $productoId);
            $locationStock = $repository->lockLocationStockRow($empresaId, $ubicacionId, $productoId);
            $stockAnterior = (float) $locationStock['cantidad'];
            $delta = $this->delta($tipo, $cantidad);
            $stockNuevo = $stockAnterior + $delta;
            $config = (new ConfiguracionService())->efectiva($empresaId, $sucursalId);

            if ($stockNuevo < 0 && !(bool) $config['permitir_stock_negativo']) {
                AuditoriaService::registrarEvento([
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'usuario_id' => isset($data['usuario_id']) ? (int) $data['usuario_id'] : null,
                    'modulo' => 'configuracion',
                    'accion' => 'bloqueo_operacion',
                    'entidad' => 'empresa_configuracion_operativa',
                    'descripcion' => 'Movimiento de stock bloqueado por configuracion de stock negativo',
                    'metadata' => [
                        'campo' => 'permitir_stock_negativo',
                        'producto_id' => $productoId,
                        'tipo_movimiento' => $tipo,
                        'stock_anterior' => $this->formatQuantity($stockAnterior),
                        'stock_proyectado' => $this->formatQuantity($stockNuevo),
                    ],
                    'severidad' => 'WARNING',
                    'resultado' => 'ERROR',
                ], $connection);
                throw new HttpException('Stock insuficiente', 422);
            }

            if ($stockNuevo < 0) {
                AuditoriaService::registrarEvento([
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'usuario_id' => isset($data['usuario_id']) ? (int) $data['usuario_id'] : null,
                    'modulo' => 'stock',
                    'accion' => 'stock_negativo_permitido',
                    'entidad' => 'stock_sucursal',
                    'descripcion' => 'Movimiento permitido deja stock negativo por configuracion',
                    'metadata' => [
                        'producto_id' => $productoId,
                        'tipo_movimiento' => $tipo,
                        'stock_anterior' => $this->formatQuantity($stockAnterior),
                        'stock_nuevo' => $this->formatQuantity($stockNuevo),
                    ],
                    'severidad' => 'WARNING',
                    'resultado' => 'OK',
                ], $connection);
            }

            $stockAnteriorFormatted = $this->formatQuantity($stockAnterior);
            $stockNuevoFormatted = $this->formatQuantity($stockNuevo);
            $deltaFormatted = $this->formatQuantity($delta);

            // 1) Escritura autoritativa: el saldo vive en stock_ubicacion.
            $repository->updateLocationQuantity((int) $locationStock['id'], $stockNuevoFormatted);

            // 2) Saldo derivado: recalcula stock_sucursal (vista de compatibilidad)
            //    para la sucursal duenha de la ubicacion. Una ubicacion sin
            //    sucursal (bodega pura) no agrega a ninguna vista legacy.
            $rollupSucursalId = (int) ($ubicacion['sucursal_id'] ?? 0);
            if ($rollupSucursalId > 0) {
                $repository->ensureStockRow($empresaId, $rollupSucursalId, $productoId);
                $repository->recalcSucursalStock($empresaId, $rollupSucursalId, $productoId);
            }

            // 3) Detalle por lote: si el payload trae lote_id, actualiza stock_lotes_ubicacion.
            //    La fuente de verdad sigue siendo stock_ubicacion; stock_lotes_ubicacion es el
            //    desglose auditado por lote bajo esa misma ubicacion.
            $loteId = isset($data['lote_id']) && (int) $data['lote_id'] > 0 ? (int) $data['lote_id'] : null;
            if ($loteId !== null) {
                $loteRepo = new \Mypos\Repositories\LoteRepository($connection);
                $loteRepo->ensureLoteUbicacion($empresaId, $loteId, $ubicacionId, $productoId);
                $loteRow = $loteRepo->lockLoteUbicacion($empresaId, $loteId, $ubicacionId);
                $nuevaCantidadLote = round((float) $loteRow['cantidad'] + $delta, 3);
                $loteRepo->updateLoteUbicacionCantidad(
                    (int) $loteRow['id'],
                    number_format($nuevaCantidadLote, 3, '.', '')
                );
            }

            $movementId = $repository->insertMovement([
                'uuid' => $data['uuid'] ?? null,
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'ubicacion_id' => $ubicacionId,
                'ubicacion_origen_id' => $data['ubicacion_origen_id'] ?? null,
                'ubicacion_destino_id' => $data['ubicacion_destino_id'] ?? null,
                'dispositivo_id' => $data['dispositivo_id'] ?? null,
                'producto_id' => $productoId,
                'lote_id' => $loteId,
                'usuario_id' => $data['usuario_id'] ?? null,
                'tipo_movimiento' => $tipo,
                'referencia_tipo' => $data['referencia_tipo'] ?? null,
                'referencia_id' => $data['referencia_id'] ?? null,
                'cantidad' => $deltaFormatted,
                'stock_anterior' => $stockAnteriorFormatted,
                'stock_nuevo' => $stockNuevoFormatted,
                'costo_unitario' => $data['costo_unitario'] ?? 0,
                'observacion' => $data['observacion'] ?? null,
            ]);

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'usuario_id' => isset($data['usuario_id']) ? (int) $data['usuario_id'] : null,
                'dispositivo_id' => $data['dispositivo_id'] ?? null,
                'modulo' => 'stock',
                'accion' => 'movimiento',
                'entidad' => 'stock_movimientos',
                'entidad_id' => $movementId,
                'descripcion' => 'Movimiento de stock registrado',
                'datos_anteriores' => [
                    'producto_id' => $productoId,
                    'cantidad' => $stockAnteriorFormatted,
                ],
                'datos_nuevos' => [
                    'producto_id' => $productoId,
                    'cantidad' => $stockNuevoFormatted,
                ],
                'metadata' => [
                    'tipo_movimiento' => $tipo,
                    'cantidad' => $deltaFormatted,
                    'referencia_tipo' => $data['referencia_tipo'] ?? null,
                    'referencia_id' => $data['referencia_id'] ?? null,
                ],
            ], $connection);

            if ($ownsTransaction) {
                $connection->commit();
            }

            return [
                'movimiento_id' => $movementId,
                'producto_id' => $productoId,
                'tipo' => $tipo,
                'cantidad' => $deltaFormatted,
                'stock_anterior' => $stockAnteriorFormatted,
                'stock_nuevo' => $stockNuevoFormatted,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function descontarPorVenta(array $data, ?PDO $connection = null): array
    {
        $data['tipo'] = 'VENTA';

        return $this->registrarMovimiento($data, $connection);
    }

    public function trasladar(array $data, ?PDO $externalConnection = null): array
    {
        $connection = $externalConnection ?? $this->repository->connection();
        $repository = $externalConnection === null ? $this->repository : new StockRepository($externalConnection);
        $ownsTransaction = !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $empresaId = $this->positiveInt($data, 'empresa_id');
            $productoId = $this->positiveInt($data, 'producto_id');
            $origenId = $this->positiveInt($data, 'ubicacion_origen_id');
            $destinoId = $this->positiveInt($data, 'ubicacion_destino_id');
            $cantidad = $this->quantity($data['cantidad'] ?? null);

            if ($cantidad <= 0) {
                throw new HttpException('La cantidad de traslado debe ser mayor a 0', 422);
            }

            if ($origenId === $destinoId) {
                throw new HttpException('Origen y destino deben ser distintos', 422);
            }

            $origen = $repository->findLocation($empresaId, $origenId);
            $destino = $repository->findLocation($empresaId, $destinoId);

            if ($origen === null || $destino === null || (int) $origen['activo'] !== 1 || (int) $destino['activo'] !== 1) {
                throw new HttpException('Ubicacion no encontrada o inactiva', 404);
            }

            if ($repository->productoStockData($empresaId, $productoId) === null) {
                throw new HttpException('Producto no encontrado', 404);
            }

            // El traslado se compone de dos movimientos que pasan por la unica via
            // registrarMovimiento(): asi heredan auditoria, guarda de stock negativo
            // y el recalculo derivado de stock_sucursal por cada sucursal afectada.
            $origenSucursal = (int) ($origen['sucursal_id'] ?? 0) ?: (int) ($data['sucursal_id'] ?? 0);
            $destinoSucursal = (int) ($destino['sucursal_id'] ?? 0) ?: (int) ($data['sucursal_id'] ?? 0);

            if ($origenSucursal <= 0 || $destinoSucursal <= 0) {
                throw new HttpException('El traslado requiere sucursal_id cuando las ubicaciones no tienen sucursal asociada', 422);
            }

            $transferId = $repository->createTransfer([
                'empresa_id' => $empresaId,
                'producto_id' => $productoId,
                'ubicacion_origen_id' => $origenId,
                'ubicacion_destino_id' => $destinoId,
                'cantidad' => $this->formatQuantity($cantidad),
                'usuario_id' => $data['usuario_id'] ?? null,
                'observacion' => $data['observacion'] ?? null,
            ]);

            $salida = $this->registrarMovimiento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $origenSucursal,
                'ubicacion_id' => $origenId,
                'ubicacion_origen_id' => $origenId,
                'ubicacion_destino_id' => $destinoId,
                'producto_id' => $productoId,
                'usuario_id' => $data['usuario_id'] ?? null,
                'tipo' => 'TRASPASO_SALIDA',
                'referencia_tipo' => 'STOCK_TRASLADO',
                'referencia_id' => $transferId,
                'cantidad' => $cantidad,
                'observacion' => $data['observacion'] ?? null,
            ], $connection);

            $entrada = $this->registrarMovimiento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $destinoSucursal,
                'ubicacion_id' => $destinoId,
                'ubicacion_origen_id' => $origenId,
                'ubicacion_destino_id' => $destinoId,
                'producto_id' => $productoId,
                'usuario_id' => $data['usuario_id'] ?? null,
                'tipo' => 'TRASPASO_ENTRADA',
                'referencia_tipo' => 'STOCK_TRASLADO',
                'referencia_id' => $transferId,
                'cantidad' => $cantidad,
                'observacion' => $data['observacion'] ?? null,
            ], $connection);

            $outMovementId = (int) $salida['movimiento_id'];
            $inMovementId = (int) $entrada['movimiento_id'];
            $repository->completeTransfer($transferId, $outMovementId, $inMovementId);

            if ($ownsTransaction) {
                $connection->commit();
            }

            return [
                'traslado_id' => $transferId,
                'movimiento_salida_id' => $outMovementId,
                'movimiento_entrada_id' => $inMovementId,
                'producto_id' => $productoId,
                'ubicacion_origen_id' => $origenId,
                'ubicacion_destino_id' => $destinoId,
                'cantidad' => $this->formatQuantity($cantidad),
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function sumarPorCompra(array $data, ?PDO $connection = null): array
    {
        $data['tipo'] = 'COMPRA';

        return $this->registrarMovimiento($data, $connection);
    }

    public function ajustarStock(array $data, ?PDO $connection = null): array
    {
        $data['tipo'] = 'AJUSTE';

        return $this->registrarMovimiento($data, $connection);
    }

    /**
     * Registra una merma operativa: perdida de stock por deterioro, vencimiento,
     * rotura u otro evento no comercial. Siempre descuenta (delta negativo).
     * Solo disponible cuando la empresa tiene la capacidad MERMA_OPERATIVA activa.
     */
    public function registrarMerma(array $data, ?PDO $connection = null): array
    {
        $data['tipo'] = 'MERMA';
        // Normalizamos: la merma siempre descuenta aunque el frontend mande positivo.
        if (isset($data['cantidad'])) {
            $data['cantidad'] = abs((float) $data['cantidad']);
        }

        return $this->registrarMovimiento($data, $connection);
    }

    public function revertirMovimiento(int $empresaId, int $movimientoId, ?int $usuarioId = null, ?PDO $connection = null): array
    {
        $repository = $connection === null ? $this->repository : new StockRepository($connection);
        $original = $repository->findMovement($empresaId, $movimientoId);

        if ($original === null) {
            throw new HttpException('Movimiento original no encontrado', 404);
        }

        return $this->registrarMovimiento([
            'empresa_id' => (int) $original['empresa_id'],
            'sucursal_id' => (int) $original['sucursal_id'],
            'producto_id' => (int) $original['producto_id'],
            'usuario_id' => $usuarioId,
            'dispositivo_id' => $original['dispositivo_id'] ?? null,
            'tipo' => 'AJUSTE',
            'referencia_tipo' => 'STOCK_MOVIMIENTO_REVERSA',
            'referencia_id' => $movimientoId,
            'cantidad' => -1 * (float) $original['cantidad'],
            'costo_unitario' => $original['costo_unitario'] ?? 0,
            'observacion' => 'Reversa de movimiento ' . $movimientoId,
        ], $connection);
    }

    private function validarBase(int $empresaId, int $sucursalId, int $productoId, ?StockRepository $repository = null): void
    {
        $repository ??= $this->repository;

        if ($empresaId <= 0 || $sucursalId <= 0 || $productoId <= 0) {
            throw new HttpException('Error de validación', 422);
        }

        if (!$repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 404);
        }

        if ($repository->productoStockData($empresaId, $productoId) === null) {
            throw new HttpException('Producto no encontrado', 404);
        }
    }

    private function validateLocation(array $data): void
    {
        $this->positiveInt($data, 'empresa_id');

        if (trim((string) ($data['codigo'] ?? '')) === '') {
            throw new HttpException('Error de validacion', 422, ['codigo' => ['El codigo es obligatorio']]);
        }

        if (trim((string) ($data['nombre'] ?? '')) === '') {
            throw new HttpException('Error de validacion', 422, ['nombre' => ['El nombre es obligatorio']]);
        }

        $tipo = strtoupper((string) ($data['tipo'] ?? 'BODEGA'));
        if (!in_array($tipo, self::TIPOS_UBICACION, true)) {
            throw new HttpException('Tipo de ubicacion invalido', 422);
        }
    }

    private function validateLocationSucursal(array $data): void
    {
        $empresaId = (int) $data['empresa_id'];
        $sucursalId = (int) ($data['sucursal_id'] ?? 0);

        if ($sucursalId > 0 && !$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 404);
        }

        $tipo = strtoupper((string) ($data['tipo'] ?? 'BODEGA'));
        if ($tipo === 'SUCURSAL_VENTA' && $sucursalId <= 0) {
            throw new HttpException('La ubicacion de venta requiere sucursal_id', 422);
        }
    }

    /**
     * Resuelve la ubicacion concreta sobre la que se aplica el movimiento.
     *
     * Es obligatoria: si el payload no trae ubicacion_id se usa la SUCURSAL_VENTA
     * principal de la sucursal. Si no existe ninguna, falla en vez de omitir
     * silenciosamente la escritura en stock_ubicacion (lo que descuadraba el saldo).
     * Para ventas, exige que la ubicacion permita venta (no se vende desde bodega).
     *
     * @return array{id:int,sucursal_id:?int,permite_venta:int,activo:int,tipo:string}
     */
    private function resolveLocation(StockRepository $repository, int $empresaId, int $sucursalId, string $tipo, array $data): array
    {
        $ubicacionId = (int) ($data['ubicacion_id'] ?? 0);

        if ($ubicacionId > 0) {
            $location = $repository->findLocation($empresaId, $ubicacionId);
            if ($location === null || (int) $location['activo'] !== 1) {
                throw new HttpException('Ubicacion no encontrada o inactiva', 404);
            }
        } else {
            $location = $repository->defaultLocationForSucursal($empresaId, $sucursalId);
            if ($location === null) {
                throw new HttpException(
                    'La sucursal no tiene una ubicacion de venta configurada; indique ubicacion_id',
                    422
                );
            }
        }

        if ($tipo === 'VENTA' && (int) ($location['permite_venta'] ?? 0) !== 1) {
            throw new HttpException('La ubicacion no permite ventas', 422);
        }

        return $location;
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);

        if ($value <= 0) {
            throw new HttpException('Error de validación', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return $value;
    }

    private function quantity(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new HttpException('La cantidad debe ser numérica', 422);
        }

        return round((float) $value, 3);
    }

    private function delta(string $tipo, float $cantidad): float
    {
        return match ($tipo) {
            'VENTA', 'TRASPASO_SALIDA', 'REVERSA_COMPRA', 'MERMA' => -abs($cantidad),
            'COMPRA', 'DEVOLUCION', 'TRASPASO_ENTRADA', 'ANULACION_VENTA' => abs($cantidad),
            'AJUSTE' => $cantidad,
        };
    }

    private function formatQuantity(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
