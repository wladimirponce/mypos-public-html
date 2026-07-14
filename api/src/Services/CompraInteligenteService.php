<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\CompraInteligenteRepository;

/**
 * Servicio de compras inteligentes v1/v2.
 *
 * Responsabilidades:
 * - Validar los parámetros de entrada.
 * - Delegar al repositorio la obtención de sugerencias.
 * - Enriquecer con consumo promedio, traslados posibles, alertas de precio y ranking.
 * - Agrupar las sugerencias por proveedor.
 * - Orquestar la creación de compras borrador a partir de una selección.
 */
final class CompraInteligenteService
{
    private CompraInteligenteRepository $repository;

    public function __construct(?CompraInteligenteRepository $repository = null)
    {
        $this->repository = $repository ?? new CompraInteligenteRepository(Database::connection());
    }

    /**
     * Genera sugerencias de compra para la empresa.
     *
     * @param int      $empresaId    Empresa evaluada
     * @param int|null $ubicacionId  Filtrar a una ubicación específica (opcional)
     * @param int      $diasConsumo  Ventana de días para calcular consumo promedio (default 60)
     * @param float    $umbralAlza   Porcentaje (0.0-1.0) que activa la alerta de alza de costo (default 0.05)
     */
    public function sugerencias(int $empresaId, ?int $ubicacionId, int $diasConsumo = 60, float $umbralAlza = 0.05): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id inválido', 422);
        }

        if ($ubicacionId !== null && $ubicacionId > 0) {
            if (!$this->repository->ubicacionExists($empresaId, $ubicacionId)) {
                throw new HttpException('Ubicación no encontrada o inactiva', 404);
            }
        }

        $diasConsumo = max(7, min(365, $diasConsumo));
        $umbralAlza  = max(0.0, min(1.0, $umbralAlza));

        $rows     = $this->repository->obtenerSugerencias($empresaId, $ubicacionId);
        $enriched = array_map(
            fn (array $row) => $this->enrichRowV2($row, $diasConsumo, $umbralAlza),
            $rows
        );

        return [
            'sugerencias'            => $enriched,
            'agrupado_por_proveedor' => $this->agruparPorProveedor($enriched),
            'total'                  => count($enriched),
            'parametros'             => [
                'dias_consumo'  => $diasConsumo,
                'umbral_alza'   => $umbralAlza,
            ],
        ];
    }

    /**
     * Genera una o más compras en estado BORRADOR a partir de una selección
     * de ítems sugeridos, agrupados por proveedor.
     *
     * Payload esperado:
     * {
     *   "empresa_id": 1,
     *   "sucursal_id": 1,   <- sucursal que recibirá el stock
     *   "grupos": [
     *     {
     *       "proveedor_id": 5,
     *       "items": [
     *         { "producto_id": 12, "cantidad": 20, "costo_unitario": 1000 },
     *         ...
     *       ]
     *     },
     *     ...
     *   ]
     * }
     *
     * @param int   $userId  ID del usuario que genera los borradores
     * @param array $payload Payload descrito arriba
     * @return array{ borradores: list<array<string, mixed>>, total_compras: int }
     */
    public function generarBorradores(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $sucursalId = $this->positiveInt($payload, 'sucursal_id');

        if (!$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada o inactiva', 422);
        }

        $grupos = $payload['grupos'] ?? [];

        if (!is_array($grupos) || $grupos === []) {
            throw new HttpException('Debe enviar al menos un grupo de ítems', 422);
        }

        $compraService = new CompraService();
        $borradores = [];

        foreach ($grupos as $idx => $grupo) {
            if (!is_array($grupo)) {
                throw new HttpException("El grupo #{$idx} es inválido", 422);
            }

            $proveedorId = isset($grupo['proveedor_id']) && (int) $grupo['proveedor_id'] > 0
                ? (int) $grupo['proveedor_id']
                : null;

            $items = $grupo['items'] ?? [];

            if (!is_array($items) || $items === []) {
                throw new HttpException("El grupo #{$idx} no tiene ítems", 422);
            }

            // Validar y normalizar ítems
            $itemsNormalizados = $this->normalizarItems($items, $idx);

            $result = $compraService->crear($userId, [
                'empresa_id'     => $empresaId,
                'sucursal_id'    => $sucursalId,
                'proveedor_id'   => $proveedorId,
                'tipo_documento' => 'FACTURA_COMPRA',
                'estado'         => 'BORRADOR',
                'observacion'    => 'Borrador generado por compras inteligentes',
                'items'          => $itemsNormalizados,
            ]);

            $borradores[] = [
                'compra_id'   => $result['compra_id'],
                'estado'      => $result['estado'],
                'total'       => $result['total'],
                'proveedor_id' => $proveedorId,
                'items'       => count($itemsNormalizados),
            ];
        }

        return [
            'borradores'    => $borradores,
            'total_compras' => count($borradores),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Enriquecimiento base (Sprint 11): tipos, disponible, deficit, costo estimado.
     */
    private function enrichRow(array $row): array
    {
        $row['cantidad_actual']   = (float) ($row['cantidad_actual'] ?? 0);
        $row['reservado']         = (float) ($row['reservado'] ?? 0);
        $row['stock_minimo']      = (float) ($row['stock_minimo'] ?? 0);
        $row['stock_maximo']      = (float) ($row['stock_maximo'] ?? 0);
        $row['punto_reorden']     = (float) ($row['punto_reorden'] ?? 0);
        $row['cantidad_sugerida'] = (float) ($row['cantidad_sugerida'] ?? 1);
        $row['costo_actual']      = (int)   ($row['costo_actual'] ?? 0);
        $row['precio_vigente']    = isset($row['precio_vigente']) ? (int) $row['precio_vigente'] : null;

        $row['disponible'] = max(0, $row['cantidad_actual'] - $row['reservado']);
        $row['deficit']    = max(0, $row['punto_reorden']  - $row['cantidad_actual']);

        if (empty($row['proveedor_id'])) {
            $row['proveedor_id']           = null;
            $row['proveedor_nombre']       = null;
            $row['proveedor_rut']          = null;
            $row['proveedor_producto_id']  = null;
            $row['codigo_proveedor']       = null;
            $row['nombre_en_proveedor']    = null;
            $row['unidad_compra']          = null;
            $row['factor_conversion']      = null;
            $row['proveedor_preferido']    = null;
            $row['plazo_entrega_dias']     = null;
            $row['compra_minima']          = null;
            $row['precio_vigente']         = null;
            $row['precio_moneda']          = null;
            $row['precio_fecha']           = null;
            $row['precio_origen']          = null;
        }

        $costo = $row['precio_vigente'] ?? $row['costo_actual'];
        $row['costo_estimado_total'] = (int) round($row['cantidad_sugerida'] * $costo);

        return $row;
    }

    /**
     * Enriquecimiento v2 (Sprint 12): añade consumo, cobertura, traslados,
     * alertas de alza y ranking de proveedores.
     */
    private function enrichRowV2(array $row, int $diasConsumo, float $umbralAlza): array
    {
        // Base v1
        $row = $this->enrichRow($row);

        $empresaId   = (int) $row['empresa_id'];
        $productoId  = (int) $row['producto_id'];
        $ubicacionId = (int) $row['ubicacion_id'];

        // --- Consumo promedio ---
        $consumoDiario = $this->repository->consumoPromedio($empresaId, $productoId, $ubicacionId, $diasConsumo);
        $row['consumo_diario_promedio'] = round($consumoDiario, 4);
        $row['dias_ventana_consumo']    = $diasConsumo;

        if ($consumoDiario > 0) {
            $row['dias_cobertura_actual']   = round($row['cantidad_actual'] / $consumoDiario, 1);
            $row['dias_cobertura_sugerida'] = round(($row['cantidad_actual'] + $row['cantidad_sugerida']) / $consumoDiario, 1);
        } else {
            $row['dias_cobertura_actual']   = null;
            $row['dias_cobertura_sugerida'] = null;
        }

        // --- Punto de reorden sugerido (P6): consumo × (lead time + seguridad) ---
        // Usa el plazo de entrega del proveedor sugerido; si no hay dato, asume 3
        // días. El colchón de seguridad cubre variabilidad de demanda/entrega.
        $leadTime = isset($row['plazo_entrega_dias']) && (int) $row['plazo_entrega_dias'] > 0
            ? (int) $row['plazo_entrega_dias']
            : 3;
        $diasSeguridad = max(2, (int) ceil($leadTime * 0.5));
        $row['lead_time_dias']         = $leadTime;
        $row['dias_inventario_optimo'] = $leadTime + $diasSeguridad;
        $row['punto_reorden_sugerido'] = $consumoDiario > 0
            ? (int) ceil($consumoDiario * ($leadTime + $diasSeguridad))
            : null;
        // Quiebre inminente: el stock actual se agota antes de que llegue la
        // reposición (cobertura actual <= plazo de entrega).
        $row['quiebre_inminente'] = $consumoDiario > 0
            && ($row['cantidad_actual'] / $consumoDiario) <= $leadTime;

        // --- Traslados posibles ---
        $necesidad = (float) $row['deficit'];
        $traslados = $necesidad > 0
            ? $this->repository->ubicacionesConExcedente($empresaId, $productoId, $ubicacionId, $necesidad)
            : [];
        $row['traslados_posibles'] = $traslados;

        // --- Acción recomendada ---
        if ($traslados !== []) {
            $row['accion_recomendada'] = 'TRASLADAR';
        } elseif ($consumoDiario === 0.0) {
            $row['accion_recomendada'] = 'EVALUAR';
        } else {
            $row['accion_recomendada'] = 'COMPRAR';
        }

        // --- Alerta de alza de costo (contra el mismo proveedor sugerido) ---
        $proveedorId = isset($row['proveedor_id']) && (int) $row['proveedor_id'] > 0
            ? (int) $row['proveedor_id']
            : null;
        $row['alerta_alza_precio'] = $this->calcularAlertaAlza(
            $empresaId, $productoId, $proveedorId, $row['precio_vigente'], $umbralAlza
        );

        // --- Ranking de proveedores ---
        $row['ranking_proveedores'] = $this->repository->rankingProveedores($empresaId, $productoId);

        return $row;
    }

    /**
     * Detecta si el precio vigente del proveedor sugerido supera el precio
     * anterior del mismo proveedor más allá del umbral dado.
     *
     * @return array{detectada: bool, porcentaje_alza: float|null, precio_anterior: int|null}
     */
    private function calcularAlertaAlza(int $empresaId, int $productoId, ?int $proveedorId, ?int $precioVigente, float $umbral): array
    {
        if ($precioVigente === null || $precioVigente <= 0 || $proveedorId === null) {
            return ['detectada' => false, 'porcentaje_alza' => null, 'precio_anterior' => null];
        }

        // Tomamos los 2 últimos precios del MISMO proveedor; si el más reciente
        // (vigente) > penúltimo * (1 + umbral) = alza.
        $historial = $this->repository->historialPreciosProveedor($empresaId, $productoId, $proveedorId, 2);

        if (count($historial) < 2) {
            return ['detectada' => false, 'porcentaje_alza' => null, 'precio_anterior' => null];
        }

        $precioAnterior = (int) $historial[1]['precio_compra'];

        if ($precioAnterior <= 0) {
            return ['detectada' => false, 'porcentaje_alza' => null, 'precio_anterior' => null];
        }

        $porcentaje = ($precioVigente - $precioAnterior) / $precioAnterior;

        return [
            'detectada'       => $porcentaje >= $umbral,
            'porcentaje_alza' => round($porcentaje * 100, 2),
            'precio_anterior' => $precioAnterior,
        ];
    }

    /**
     * Agrupa las sugerencias por proveedor para facilitar la creación de
     * compras multi-ítem por proveedor en el frontend.
     *
     * @param list<array<string, mixed>> $sugerencias
     * @return list<array<string, mixed>>
     */
    private function agruparPorProveedor(array $sugerencias): array
    {
        $grupos = [];

        foreach ($sugerencias as $s) {
            $key = $s['proveedor_id'] !== null ? (string) $s['proveedor_id'] : '__sin_proveedor__';

            if (!isset($grupos[$key])) {
                $grupos[$key] = [
                    'proveedor_id'          => $s['proveedor_id'],
                    'proveedor_nombre'      => $s['proveedor_nombre'],
                    'proveedor_rut'         => $s['proveedor_rut'],
                    'items'                 => [],
                    'total_costo_estimado'  => 0,
                    'total_items_trasladar' => 0,
                    'total_items_evaluar'   => 0,
                ];
            }

            $accion = $s['accion_recomendada'] ?? 'COMPRAR';
            if ($accion === 'TRASLADAR') {
                $grupos[$key]['total_items_trasladar']++;
            } elseif ($accion === 'EVALUAR') {
                $grupos[$key]['total_items_evaluar']++;
            }

            $grupos[$key]['items'][] = [
                'stock_ubicacion_id'      => $s['stock_ubicacion_id'],
                'producto_id'             => $s['producto_id'],
                'sku'                     => $s['sku'],
                'producto_nombre'         => $s['producto_nombre'],
                'ubicacion_id'            => $s['ubicacion_id'],
                'ubicacion_nombre'        => $s['ubicacion_nombre'],
                'cantidad_actual'         => $s['cantidad_actual'],
                'punto_reorden'           => $s['punto_reorden'],
                'stock_maximo'            => $s['stock_maximo'],
                'deficit'                 => $s['deficit'],
                'cantidad_sugerida'       => $s['cantidad_sugerida'],
                'unidad_compra'           => $s['unidad_compra'] ?? $s['unidad_medida'],
                'precio_vigente'          => $s['precio_vigente'],
                'costo_actual'            => $s['costo_actual'],
                'costo_estimado_total'    => $s['costo_estimado_total'],
                // v2
                'consumo_diario_promedio' => $s['consumo_diario_promedio'] ?? null,
                'dias_cobertura_actual'   => $s['dias_cobertura_actual']   ?? null,
                'accion_recomendada'      => $accion,
                'traslados_posibles'      => $s['traslados_posibles']      ?? [],
                'alerta_alza_precio'      => $s['alerta_alza_precio']      ?? null,
            ];

            $grupos[$key]['total_costo_estimado'] += $s['costo_estimado_total'];
        }

        return array_values($grupos);
    }

    /**
     * Normaliza los ítems de un grupo para pasarlos a CompraService::crear().
     */
    private function normalizarItems(array $items, int $grupoIdx): array
    {
        $normalized = [];

        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                throw new HttpException("Item #{$i} del grupo #{$grupoIdx} es inválido", 422);
            }

            $productoId = isset($item['producto_id']) && (int) $item['producto_id'] > 0
                ? (int) $item['producto_id']
                : null;

            if ($productoId === null) {
                throw new HttpException("Item #{$i} del grupo #{$grupoIdx}: producto_id requerido", 422);
            }

            $cantidad = is_numeric($item['cantidad'] ?? null) && (float) $item['cantidad'] > 0
                ? (float) $item['cantidad']
                : null;

            if ($cantidad === null) {
                throw new HttpException("Item #{$i} del grupo #{$grupoIdx}: cantidad inválida", 422);
            }

            $costo = isset($item['costo_unitario']) && is_numeric($item['costo_unitario'])
                ? (int) $item['costo_unitario']
                : 0;

            $normalized[] = [
                'producto_id'    => $productoId,
                'cantidad'       => $cantidad,
                'costo_unitario' => $costo,
                'neto'           => (int) round($cantidad * $costo),
                'iva'            => 0,
                'total'          => (int) round($cantidad * $costo),
            ];
        }

        return $normalized;
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);

        if ($value <= 0) {
            throw new HttpException(
                'Error de validación',
                422,
                [$field => ["El campo {$field} es obligatorio"]]
            );
        }

        return $value;
    }
}
