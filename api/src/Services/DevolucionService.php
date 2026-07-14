<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\CajaRepository;
use Mypos\Repositories\DevolucionRepository;
use Mypos\Repositories\EgresoRepository;
use Mypos\Repositories\StockRepository;
use Mypos\Repositories\ValeCreditoRepository;
use Throwable;

/**
 * Devoluciones parciales de venta (Fase 1 del plan de capacidades).
 *
 * El "cambio de producto" se compone con piezas existentes:
 *   devolución (aquí) → vale de crédito → nueva venta pagada con VALE en el POS.
 * Así la diferencia a cobrar o devolver la resuelve el flujo normal de venta.
 *
 * Reembolso:
 *  - VALE: emite un vale de crédito por el total devuelto (default).
 *  - EFECTIVO: registra un egreso de caja (requiere caja abierta del cajero).
 *
 * Stock: destino STOCK reingresa las unidades (movimiento DEVOLUCION);
 * destino MERMA no genera movimiento (el producto vuelve dañado/vencido y
 * se descarta: el neto de inventario es cero).
 *
 * NC electrónica: si la venta tiene documento tributario emitido, la
 * devolución queda nc_estado = PENDIENTE con los montos por línea para emitir
 * la NC 61 referenciada (automatización en fase posterior).
 */
final class DevolucionService
{
    private DevolucionRepository $repository;

    public function __construct(?DevolucionRepository $repository = null)
    {
        $this->repository = $repository ?? new DevolucionRepository(Database::connection());
    }

    /** Detalle de la venta con cantidades ya devueltas, para armar la devolución en la UI. */
    public function resumenVenta(int $empresaId, int $ventaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $connection = $this->repository->connection();
        $statement = $connection->prepare(
            'SELECT id, sucursal_id, cliente_id, estado, fecha_venta, total, condicion_pago
             FROM ventas WHERE id = :id AND empresa_id = :empresa_id LIMIT 1'
        );
        $statement->execute(['id' => $ventaId, 'empresa_id' => $empresaId]);
        $venta = $statement->fetch();
        if (!is_array($venta)) {
            throw new HttpException('Venta no encontrada', 404);
        }

        $detalles = array_map(static function (array $detalle): array {
            $cantidad = (float) $detalle['cantidad'];
            $devuelta = (float) $detalle['cantidad_devuelta'];

            return [
                'venta_detalle_id' => (int) $detalle['id'],
                'producto_id' => (int) $detalle['producto_id'],
                'nombre_producto' => (string) $detalle['nombre_producto'],
                'cantidad' => $cantidad,
                'cantidad_devuelta' => $devuelta,
                'cantidad_disponible' => max(0, round($cantidad - $devuelta, 3)),
                'precio_unitario' => (int) $detalle['precio_unitario'],
                'total' => (int) $detalle['total'],
                'controla_stock' => (int) $detalle['controla_stock'],
            ];
        }, $this->repository->saleDetails($empresaId, $ventaId));

        return [
            'venta' => [
                'id' => (int) $venta['id'],
                'sucursal_id' => (int) $venta['sucursal_id'],
                'cliente_id' => $venta['cliente_id'] !== null ? (int) $venta['cliente_id'] : null,
                'estado' => (string) $venta['estado'],
                'fecha_venta' => (string) $venta['fecha_venta'],
                'total' => (int) $venta['total'],
                'condicion_pago' => (string) $venta['condicion_pago'],
            ],
            'detalles' => $detalles,
            'devoluciones_previas' => $this->repository->listar($empresaId, ['venta_id' => $ventaId]),
        ];
    }

    public function registrar(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $ventaId = $this->positiveInt($payload, 'venta_id');
        $motivo = trim((string) ($payload['motivo'] ?? ''));
        if (strlen($motivo) < 5) {
            throw new HttpException('El motivo es obligatorio (minimo 5 caracteres)', 422);
        }

        $destino = strtoupper((string) ($payload['destino'] ?? 'STOCK'));
        if (!in_array($destino, ['STOCK', 'MERMA'], true)) {
            throw new HttpException('destino invalido (STOCK o MERMA)', 422);
        }

        $tipoReembolso = strtoupper((string) ($payload['tipo_reembolso'] ?? 'VALE'));
        if (!in_array($tipoReembolso, ['VALE', 'EFECTIVO'], true)) {
            throw new HttpException('tipo_reembolso invalido (VALE o EFECTIVO)', 422);
        }

        $items = $payload['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new HttpException('La devolucion debe incluir items', 422);
        }

        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();

            $venta = $this->repository->findSaleForUpdate($empresaId, $ventaId);
            if ($venta === null) {
                throw new HttpException('Venta no encontrada', 404);
            }
            if ((string) $venta['estado'] === 'ANULADA' || $venta['anulada_at'] !== null) {
                throw new HttpException('No se puede devolver una venta anulada', 422);
            }

            $sucursalId = (int) $venta['sucursal_id'];

            // Indexar detalles con lo ya devuelto para validar disponibles.
            $detalles = [];
            foreach ($this->repository->saleDetails($empresaId, $ventaId) as $detalle) {
                $detalles[(int) $detalle['id']] = $detalle;
            }

            $lineas = [];
            $montoTotal = 0;
            foreach ($items as $item) {
                $detalleId = (int) ($item['venta_detalle_id'] ?? 0);
                $cantidad = round((float) ($item['cantidad'] ?? 0), 3);
                if ($detalleId <= 0 || !isset($detalles[$detalleId])) {
                    throw new HttpException('venta_detalle_id invalido en items', 422);
                }
                if ($cantidad <= 0) {
                    throw new HttpException('La cantidad a devolver debe ser mayor que cero', 422);
                }

                $detalle = $detalles[$detalleId];
                $disponible = round((float) $detalle['cantidad'] - (float) $detalle['cantidad_devuelta'], 3);
                if ($cantidad > $disponible) {
                    throw new HttpException(
                        "La linea \"{$detalle['nombre_producto']}\" solo tiene {$disponible} unidades disponibles para devolver",
                        422
                    );
                }

                // Valor devuelto proporcional al total efectivamente cobrado por la
                // línea (incluye descuentos), no al precio de lista.
                $valorUnitario = (float) $detalle['total'] / max(0.001, (float) $detalle['cantidad']);
                $totalLinea = (int) round($valorUnitario * $cantidad);
                $montoTotal += $totalLinea;

                $lineas[] = [
                    'detalle' => $detalle,
                    'venta_detalle_id' => $detalleId,
                    'cantidad' => $cantidad,
                    'precio_unitario' => (int) round($valorUnitario),
                    'total_linea' => $totalLinea,
                ];
            }

            if ($montoTotal <= 0) {
                throw new HttpException('El monto a devolver debe ser mayor que cero', 422);
            }

            $tieneDocumento = $this->repository->saleHasEmittedDocument($empresaId, $ventaId);
            $openingId = isset($payload['caja_apertura_id']) && (int) $payload['caja_apertura_id'] > 0
                ? (int) $payload['caja_apertura_id']
                : null;

            $devolucionId = $this->repository->insertDevolucion([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'venta_id' => $ventaId,
                'usuario_id' => $userId,
                'caja_apertura_id' => $tipoReembolso === 'EFECTIVO' ? $openingId : null,
                'tipo_reembolso' => $tipoReembolso,
                'vale_id' => null,
                'egreso_id' => null,
                'monto_total' => $montoTotal,
                'motivo' => $motivo,
                'destino' => $destino,
                'nc_estado' => $tieneDocumento ? 'PENDIENTE' : 'NO_APLICA',
                'metadata_json' => json_encode([
                    'items' => count($lineas),
                    'nc_requerida' => $tieneDocumento,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            // ── Stock: reingreso solo si destino STOCK y el producto controla stock ─
            $stockService = new StockService(new StockRepository($connection));
            foreach ($lineas as $linea) {
                $movimientoId = null;
                if ($destino === 'STOCK' && (int) $linea['detalle']['controla_stock'] === 1) {
                    $movimiento = $stockService->registrarMovimiento([
                        'empresa_id' => $empresaId,
                        'sucursal_id' => $sucursalId,
                        'producto_id' => (int) $linea['detalle']['producto_id'],
                        'usuario_id' => $userId,
                        'tipo' => 'DEVOLUCION',
                        'referencia_tipo' => 'DEVOLUCION',
                        'referencia_id' => $devolucionId,
                        'cantidad' => number_format($linea['cantidad'], 3, '.', ''),
                        'costo_unitario' => (int) ($linea['detalle']['costo_unitario'] ?? 0),
                        'observacion' => 'Devolucion venta #' . $ventaId,
                    ], $connection);
                    $movimientoId = (int) $movimiento['movimiento_id'];
                }

                $this->repository->insertDetalle([
                    'empresa_id' => $empresaId,
                    'devolucion_id' => $devolucionId,
                    'venta_detalle_id' => $linea['venta_detalle_id'],
                    'producto_id' => (int) $linea['detalle']['producto_id'],
                    'cantidad' => number_format($linea['cantidad'], 3, '.', ''),
                    'precio_unitario' => $linea['precio_unitario'],
                    'total_linea' => $linea['total_linea'],
                    'stock_movimiento_id' => $movimientoId,
                ]);
            }

            // ── Reembolso ────────────────────────────────────────────────────────
            $valeId = null;
            $valeCodigo = null;
            $egresoId = null;

            if ($tipoReembolso === 'VALE') {
                $valeService = new ValeCreditoService(new ValeCreditoRepository($connection));
                $vale = $valeService->emitir($userId, [
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'cliente_id' => $venta['cliente_id'] !== null ? (int) $venta['cliente_id'] : null,
                    'monto' => $montoTotal,
                    'origen' => 'CAMBIO',
                    'referencia_tipo' => 'DEVOLUCION',
                    'referencia_id' => $devolucionId,
                    'observacion' => 'Devolucion venta #' . $ventaId,
                ], false);
                $valeId = (int) $vale['vale_id'];
                $valeCodigo = (string) $vale['codigo'];
            } else {
                // EFECTIVO: egreso de caja dentro de esta transacción (no se usa
                // EgresoService::create porque abriría una transacción anidada).
                if ($openingId === null) {
                    throw new HttpException('caja_apertura_id obligatorio para reembolso en efectivo', 422);
                }
                $cajas = new CajaRepository($connection);
                $opening = $cajas->findOpeningForUpdate($empresaId, $openingId);
                if ($opening === null || (string) $opening['estado'] !== 'ABIERTA' || (int) $opening['sucursal_id'] !== $sucursalId) {
                    throw new HttpException('Para reembolsar en efectivo debe existir una caja abierta en la sucursal de la venta', 422);
                }

                $egresos = new EgresoRepository($connection);
                $egresoId = $egresos->insert([
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'caja_apertura_id' => $openingId,
                    'usuario_id' => $userId,
                    'fecha_egreso' => date('Y-m-d'),
                    'quien_recibe' => 'Cliente (devolucion)',
                    'motivo' => 'Devolucion venta #' . $ventaId,
                    'descripcion' => $motivo,
                    'monto' => $montoTotal,
                ]);
                $movementId = $cajas->insertMovement([
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'caja_apertura_id' => $openingId,
                    'usuario_id' => $userId,
                    'tipo' => 'RETIRO',
                    'concepto' => 'Egreso: Devolucion venta #' . $ventaId,
                    'monto' => $montoTotal,
                    'observacion' => 'Recibe: Cliente (devolucion)',
                    'referencia_tipo' => 'EGRESO',
                    'referencia_id' => $egresoId,
                ]);
                $egresos->linkMovement($egresoId, $movementId);
            }

            $this->repository->updateReembolso($devolucionId, $valeId, $egresoId);

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'usuario_id' => $userId,
                'modulo' => 'devoluciones',
                'accion' => 'crear',
                'entidad' => 'devoluciones',
                'entidad_id' => $devolucionId,
                'descripcion' => 'Devolucion registrada',
                'datos_nuevos' => [
                    'venta_id' => $ventaId,
                    'monto_total' => $montoTotal,
                    'tipo_reembolso' => $tipoReembolso,
                    'destino' => $destino,
                    'vale_id' => $valeId,
                    'egreso_id' => $egresoId,
                    'nc_estado' => $tieneDocumento ? 'PENDIENTE' : 'NO_APLICA',
                    'items' => count($lineas),
                ],
            ], $connection);

            $connection->commit();

            return [
                'devolucion_id' => $devolucionId,
                'venta_id' => $ventaId,
                'monto_total' => $montoTotal,
                'tipo_reembolso' => $tipoReembolso,
                'vale_id' => $valeId,
                'vale_codigo' => $valeCodigo,
                'egreso_id' => $egresoId,
                'nc_estado' => $tieneDocumento ? 'PENDIENTE' : 'NO_APLICA',
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function listar(int $empresaId, array $filters): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        return ['devoluciones' => $this->repository->listar($empresaId, $filters)];
    }

    public function detalle(int $empresaId, int $id): array
    {
        $devolucion = $this->repository->find($empresaId, $id);
        if ($devolucion === null) {
            throw new HttpException('Devolucion no encontrada', 404);
        }

        return [
            'devolucion' => $devolucion,
            'detalles' => $this->repository->detalles($empresaId, $id),
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
}
