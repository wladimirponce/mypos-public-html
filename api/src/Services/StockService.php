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

            $repository->ensureStockRow($empresaId, $sucursalId, $productoId);
            $stock = $repository->lockStockRow($empresaId, $sucursalId, $productoId);
            $stockAnterior = (float) $stock['cantidad'];
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

            $repository->updateQuantity((int) $stock['id'], $stockNuevoFormatted);
            $movementId = $repository->insertMovement([
                'uuid' => $data['uuid'] ?? null,
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'dispositivo_id' => $data['dispositivo_id'] ?? null,
                'producto_id' => $productoId,
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
            'VENTA', 'TRASPASO_SALIDA', 'REVERSA_COMPRA' => -abs($cantidad),
            'COMPRA', 'DEVOLUCION', 'TRASPASO_ENTRADA', 'ANULACION_VENTA' => abs($cantidad),
            'AJUSTE' => $cantidad,
        };
    }

    private function formatQuantity(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
