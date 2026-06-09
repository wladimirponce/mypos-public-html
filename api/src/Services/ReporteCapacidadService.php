<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\ReporteCapacidadRepository;

/**
 * Reportes activados por capacidades del sistema multi-rubro.
 *
 * Cada metodo verifica que la empresa tenga la capacidad requerida antes
 * de ejecutar la consulta. Si no la tiene, lanza 403 con indicacion de
 * que capacidad habilitar.
 */
final class ReporteCapacidadService
{
    private ReporteCapacidadRepository $repository;
    private PerfilNegocioService $perfilService;

    public function __construct(?ReporteCapacidadRepository $repository = null)
    {
        $this->repository   = $repository ?? new ReporteCapacidadRepository(Database::connection());
        $this->perfilService = new PerfilNegocioService();
    }

    // =========================================================================
    // MERMA_OPERATIVA
    // =========================================================================

    public function reporteMerma(array $filters): array
    {
        [$empresaId, $sucursalId, $desde, $hasta] = $this->dateFilters($filters);
        $this->requireCapacidad($empresaId, 'MERMA_OPERATIVA');

        $detalle = $this->repository->mermaByPeriod($empresaId, $sucursalId, $desde, $hasta);
        $resumen = $this->repository->mermaTotal($empresaId, $sucursalId, $desde, $hasta);

        return [
            'empresa_id'   => $empresaId,
            'sucursal_id'  => $sucursalId,
            'fecha_desde'  => $desde,
            'fecha_hasta'  => $hasta,
            'resumen' => [
                'total_unidades'    => $this->fmt($resumen['total_unidades'] ?? 0),
                'total_costo'       => (int) ($resumen['total_costo'] ?? 0),
                'total_movimientos' => (int) ($resumen['total_movimientos'] ?? 0),
            ],
            'detalle' => array_map(fn (array $r): array => [
                'producto_id'        => (int) $r['producto_id'],
                'producto_codigo'    => $r['producto_codigo'],
                'producto_nombre'    => $r['producto_nombre'],
                'merma_esperada_pct' => $r['merma_esperada_pct'] !== null
                    ? (float) $r['merma_esperada_pct'] : null,
                'unidades_merma'     => $this->fmt($r['unidades_merma']),
                'costo_merma'        => (int) ($r['costo_merma'] ?? 0),
                'num_movimientos'    => (int) $r['num_movimientos'],
            ], $detalle),
        ];
    }

    // =========================================================================
    // LOTES_VENCIMIENTO
    // =========================================================================

    public function reporteLotesPorVencer(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $dias      = isset($filters['dias']) && (int) $filters['dias'] > 0 ? (int) $filters['dias'] : 30;
        $this->requireCapacidad($empresaId, 'LOTES_VENCIMIENTO');

        $rows = $this->repository->lotesPorVencer($empresaId, $dias);

        // Agrupar por lote para consolidar ubicaciones en sub-array
        $lotes = [];
        foreach ($rows as $row) {
            $loteId = (int) $row['lote_id'];
            if (!isset($lotes[$loteId])) {
                $lotes[$loteId] = [
                    'lote_id'           => $loteId,
                    'numero_lote'       => $row['numero_lote'],
                    'fecha_vencimiento' => $row['fecha_vencimiento'],
                    'dias_para_vencer'  => (int) $row['dias_para_vencer'],
                    'producto_id'       => (int) $row['producto_id'],
                    'producto_codigo'   => $row['producto_codigo'],
                    'producto_nombre'   => $row['producto_nombre'],
                    'ubicaciones'       => [],
                    'stock_total'       => 0.0,
                ];
            }
            $cant = (float) $row['stock_en_ubicacion'];
            $lotes[$loteId]['ubicaciones'][] = [
                'ubicacion_id'   => (int) $row['ubicacion_id'],
                'ubicacion_nombre' => $row['ubicacion_nombre'],
                'sucursal_id'    => $row['sucursal_id'] !== null ? (int) $row['sucursal_id'] : null,
                'cantidad'       => $cant,
            ];
            $lotes[$loteId]['stock_total'] += $cant;
        }

        return [
            'empresa_id'  => $empresaId,
            'dias_alerta' => $dias,
            'total_lotes' => count($lotes),
            'lotes'       => array_values($lotes),
        ];
    }

    public function reporteLotesVencidos(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->requireCapacidad($empresaId, 'LOTES_VENCIMIENTO');

        $rows = $this->repository->lotesVencidosConStock($empresaId);

        return [
            'empresa_id'  => $empresaId,
            'total_lotes' => count($rows),
            'advertencia' => count($rows) > 0
                ? 'Existen ' . count($rows) . ' lotes vencidos con stock. Registre una merma para regularizarlos.'
                : null,
            'lotes' => array_map(fn (array $r): array => [
                'lote_id'          => (int) $r['lote_id'],
                'numero_lote'      => $r['numero_lote'],
                'fecha_vencimiento'=> $r['fecha_vencimiento'],
                'dias_vencido'     => (int) $r['dias_vencido'],
                'producto_id'      => (int) $r['producto_id'],
                'producto_codigo'  => $r['producto_codigo'],
                'producto_nombre'  => $r['producto_nombre'],
                'stock_total'      => $this->fmt($r['stock_total']),
            ], $rows),
        ];
    }

    // =========================================================================
    // VARIANTES
    // =========================================================================

    public function reporteStockVariantes(array $filters): array
    {
        $empresaId     = $this->positiveInt($filters, 'empresa_id');
        $productoPadre = isset($filters['producto_padre_id']) && (int) $filters['producto_padre_id'] > 0
            ? (int) $filters['producto_padre_id'] : null;
        $this->requireCapacidad($empresaId, 'VARIANTES');

        $rows = $this->repository->stockPorVariante($empresaId, $productoPadre);

        // Agrupar por producto padre
        $padres = [];
        foreach ($rows as $row) {
            $padreId = (int) $row['producto_padre_id'];
            if (!isset($padres[$padreId])) {
                $padres[$padreId] = [
                    'producto_padre_id' => $padreId,
                    'padre_codigo'      => $row['padre_codigo'],
                    'padre_nombre'      => $row['padre_nombre'],
                    'variantes'         => [],
                    'stock_total_padre' => 0.0,
                ];
            }
            $stock = (float) $row['stock_total'];
            $padres[$padreId]['variantes'][] = [
                'variante_id'    => (int) $row['variante_id'],
                'codigo_variante'=> $row['codigo_variante'],
                'producto_id'    => (int) $row['producto_id'],
                'codigo'         => $row['codigo'],
                'nombre'         => $row['variante_nombre'],
                'precio_venta'   => (int) $row['precio_venta'],
                'stock_total'    => $stock,
            ];
            $padres[$padreId]['stock_total_padre'] += $stock;
        }

        return [
            'empresa_id'       => $empresaId,
            'producto_padre_id'=> $productoPadre,
            'total_productos'  => count($padres),
            'productos'        => array_values($padres),
        ];
    }

    public function reporteVentasVariantes(array $filters): array
    {
        [$empresaId, $sucursalId, $desde, $hasta] = $this->dateFilters($filters);
        $this->requireCapacidad($empresaId, 'VARIANTES');

        $rows = $this->repository->ventasPorVariante($empresaId, $sucursalId, $desde, $hasta);

        return [
            'empresa_id'  => $empresaId,
            'sucursal_id' => $sucursalId,
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'total_filas' => count($rows),
            'variantes'   => array_map(fn (array $r): array => [
                'producto_padre_id' => (int) $r['producto_padre_id'],
                'padre_codigo'      => $r['padre_codigo'],
                'padre_nombre'      => $r['padre_nombre'],
                'codigo_variante'   => $r['codigo_variante'],
                'producto_id'       => (int) $r['producto_id'],
                'variante_codigo'   => $r['variante_codigo'],
                'variante_nombre'   => $r['variante_nombre'],
                'total_unidades'    => $this->fmt($r['total_unidades']),
                'total_pesos'       => (int) ($r['total_pesos'] ?? 0),
                'num_ventas'        => (int) $r['num_ventas'],
            ], $rows),
        ];
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    private function requireCapacidad(int $empresaId, string $codigo): void
    {
        if (!$this->perfilService->tieneCapacidad($empresaId, $codigo)) {
            throw new HttpException(
                'La empresa no tiene habilitada la capacidad ' . $codigo
                . '. Active el perfil de negocio correspondiente en /api/v1/perfil-negocio/activar.',
                403
            );
        }
    }

    /**
     * Extrae empresa_id, sucursal_id, fecha_desde, fecha_hasta de los filtros.
     * Defaults: hoy - 30 dias / hoy.
     *
     * @return array{0:int,1:int|null,2:string,3:string}
     */
    private function dateFilters(array $filters): array
    {
        $empresaId  = $this->positiveInt($filters, 'empresa_id');
        $sucursalId = isset($filters['sucursal_id']) && (int) $filters['sucursal_id'] > 0
            ? (int) $filters['sucursal_id'] : null;
        $desde = $this->parseDate($filters['fecha_desde'] ?? null, date('Y-m-d', strtotime('-30 days')));
        $hasta = $this->parseDate($filters['fecha_hasta'] ?? null, date('Y-m-d'));

        return [$empresaId, $sucursalId, $desde, $hasta];
    }

    private function positiveInt(array $filters, string $field): int
    {
        $value = (int) ($filters[$field] ?? 0);

        if ($value <= 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return $value;
    }

    private function parseDate(mixed $value, string $default): string
    {
        if ($value === null || trim((string) $value) === '') {
            return $default;
        }

        $d = date_create((string) $value);

        return $d !== false ? date_format($d, 'Y-m-d') : $default;
    }

    private function fmt(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
