<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\LoteRepository;
use Mypos\Repositories\StockRepository;
use PDO;

final class LoteService
{
    private LoteRepository $repository;

    public function __construct(?LoteRepository $repository = null)
    {
        $this->repository = $repository ?? new LoteRepository(Database::connection());
    }

    /**
     * Registra la entrada de un lote al sistema.
     * Crea o reutiliza el lote en stock_lotes y llama a StockService::sumarPorCompra()
     * con lote_id para que el movimiento quede trazado en stock_lotes_ubicacion.
     */
    public function registrarEntradaLote(int $usuarioId, array $data, ?PDO $externalConnection = null): array
    {
        $connection  = $externalConnection ?? $this->repository->connection();
        $loteRepo    = $externalConnection !== null ? new LoteRepository($externalConnection) : $this->repository;
        $stockService = new StockService(new StockRepository($connection));

        $empresaId  = $this->positiveInt($data, 'empresa_id');
        $productoId = $this->positiveInt($data, 'producto_id');
        $sucursalId = $this->positiveInt($data, 'sucursal_id');
        $numeroLote = trim((string) ($data['numero_lote'] ?? ''));
        $cantidad   = $this->quantity($data['cantidad'] ?? null);

        if ($numeroLote === '') {
            throw new HttpException('Error de validacion', 422, ['numero_lote' => ['El numero_lote es obligatorio']]);
        }

        if ($cantidad <= 0) {
            throw new HttpException('La cantidad debe ser mayor a 0', 422);
        }

        $lote = $loteRepo->findLote($empresaId, $productoId, $numeroLote);

        if ($lote === null) {
            $loteId = $loteRepo->insertLote([
                'empresa_id'        => $empresaId,
                'producto_id'       => $productoId,
                'numero_lote'       => $numeroLote,
                'fecha_vencimiento' => $this->dateOrNull($data['fecha_vencimiento'] ?? null),
                'fecha_fabricacion' => $this->dateOrNull($data['fecha_fabricacion'] ?? null),
                'proveedor_id'      => isset($data['proveedor_id']) && (int) $data['proveedor_id'] > 0
                    ? (int) $data['proveedor_id'] : null,
                'compra_id'         => isset($data['compra_id']) && (int) $data['compra_id'] > 0
                    ? (int) $data['compra_id'] : null,
                'cantidad_original' => number_format($cantidad, 3, '.', ''),
                'observacion'       => $data['observacion'] ?? null,
            ]);
        } else {
            $loteId = (int) $lote['id'];
        }

        $movimiento = $stockService->sumarPorCompra([
            'empresa_id'      => $empresaId,
            'sucursal_id'     => $sucursalId,
            'producto_id'     => $productoId,
            'usuario_id'      => $usuarioId,
            'lote_id'         => $loteId,
            'cantidad'        => $cantidad,
            'costo_unitario'  => (int) ($data['costo_unitario'] ?? 0),
            'referencia_tipo' => $data['referencia_tipo'] ?? 'LOTE_ENTRADA',
            'referencia_id'   => $data['referencia_id'] ?? $loteId,
            'observacion'     => $data['observacion'] ?? 'Entrada lote ' . $numeroLote,
        ], $connection);

        AuditoriaService::registrarEvento([
            'empresa_id'  => $empresaId,
            'sucursal_id' => $sucursalId,
            'usuario_id'  => $usuarioId,
            'modulo'      => 'lotes',
            'accion'      => 'entrada',
            'entidad'     => 'stock_lotes',
            'entidad_id'  => $loteId,
            'descripcion' => 'Entrada de lote registrada',
            'datos_nuevos' => [
                'lote_id'           => $loteId,
                'numero_lote'       => $numeroLote,
                'producto_id'       => $productoId,
                'cantidad'          => number_format($cantidad, 3, '.', ''),
                'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
            ],
        ], $connection);

        return [
            'lote_id'           => $loteId,
            'numero_lote'       => $numeroLote,
            'producto_id'       => $productoId,
            'cantidad'          => number_format($cantidad, 3, '.', ''),
            'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
            'movimiento_id'     => $movimiento['movimiento_id'],
        ];
    }

    public function stockPorLote(int $empresaId, int $productoId, ?int $ubicacionId): array
    {
        if ($empresaId <= 0 || $productoId <= 0) {
            throw new HttpException('empresa_id y producto_id son obligatorios', 422);
        }

        return ['lotes' => $this->repository->stockPorLote($empresaId, $productoId, $ubicacionId)];
    }

    public function alertasVencimiento(int $empresaId, int $diasAlerta): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id es obligatorio', 422);
        }

        if ($diasAlerta < 0 || $diasAlerta > 365) {
            $diasAlerta = 30;
        }

        $alertas = array_map(
            fn (array $row) => $this->conSugerenciaDescuento($row),
            $this->repository->alertasVencimiento($empresaId, $diasAlerta)
        );

        return [
            'dias_alerta' => $diasAlerta,
            'alertas'     => $alertas,
        ];
    }

    /**
     * Descuento sugerido (P4) según cercanía al vencimiento. Cuanto más cerca,
     * mayor el descuento para liquidar el stock antes de que se pierda (merma).
     */
    public static function descuentoSugeridoPct(int $diasParaVencer): int
    {
        if ($diasParaVencer <= 3)  return 40; // incluye vencidos (dias negativos)
        if ($diasParaVencer <= 7)  return 30;
        if ($diasParaVencer <= 15) return 20;
        if ($diasParaVencer <= 30) return 10;
        return 0;
    }

    /** Enriquece una fila de alerta de vencimiento con la promo sugerida. */
    private function conSugerenciaDescuento(array $row): array
    {
        $dias   = (int) ($row['dias_para_vencer'] ?? 0);
        $precio = (int) ($row['precio_venta'] ?? 0);
        $pct    = self::descuentoSugeridoPct($dias);

        $row['descuento_sugerido_pct'] = $pct;
        $row['precio_promo_sugerido']  = ($pct > 0 && $precio > 0)
            ? (int) round($precio * (1 - $pct / 100))
            : null;

        return $row;
    }

    /**
     * Calcula asignaciones FEFO (First Expired First Out) para la cantidad solicitada.
     * Retorna array de [{lote_id, numero_lote, fecha_vencimiento, cantidad}] en orden FEFO.
     * Si no hay lotes suficientes, retorna lo disponible (el frontend decide si bloquear).
     *
     * @return array<int,array{lote_id:int,numero_lote:string,fecha_vencimiento:?string,cantidad:float}>
     */
    public function resolverFEFO(int $empresaId, int $ubicacionId, int $productoId, float $cantidadNecesaria): array
    {
        if ($cantidadNecesaria <= 0 || $empresaId <= 0 || $ubicacionId <= 0 || $productoId <= 0) {
            return [];
        }

        $lotes      = $this->repository->lotesFefo($empresaId, $productoId, $ubicacionId);
        $asignaciones = [];
        $restante   = $cantidadNecesaria;

        foreach ($lotes as $lote) {
            if ($restante <= 0) {
                break;
            }

            $disponible = (float) $lote['cantidad'];
            $asignar    = min($disponible, $restante);

            $asignaciones[] = [
                'lote_id'           => (int) $lote['lote_id'],
                'numero_lote'       => $lote['numero_lote'],
                'fecha_vencimiento' => $lote['fecha_vencimiento'],
                'cantidad'          => round($asignar, 3),
            ];
            $restante -= $asignar;
        }

        return $asignaciones;
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);

        if ($value <= 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return $value;
    }

    private function quantity(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new HttpException('La cantidad debe ser numerica', 422);
        }

        return round((float) $value, 3);
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $date = date_create((string) $value);

        return $date !== false ? date_format($date, 'Y-m-d') : null;
    }
}
