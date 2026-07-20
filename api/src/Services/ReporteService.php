<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\ReporteRepository;

final class ReporteService
{
    private ReporteRepository $repository;

    public function __construct(?ReporteRepository $repository = null)
    {
        $this->repository = $repository ?? new ReporteRepository(Database::connection());
    }

    public function calidadDatos(array $filters): array
    {
        $empresaId = (int) ($filters['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $metricas = $this->repository->calidadDatos($empresaId);
        $total = max(1, $metricas['productos_activos']);
        $definiciones = [
            ['codigo' => 'SIN_COSTO', 'campo' => 'productos_sin_costo', 'titulo' => 'Productos sin costo', 'impacto' => 'Impide calcular margen y capital correctamente.', 'url' => '/app/productos'],
            ['codigo' => 'SIN_PROVEEDOR', 'campo' => 'productos_sin_proveedor', 'titulo' => 'Productos sin proveedor', 'impacto' => 'Reduce la calidad de las recomendaciones de compra.', 'url' => '/app/proveedores'],
            ['codigo' => 'SIN_PARAMETROS_STOCK', 'campo' => 'productos_sin_parametros_stock', 'titulo' => 'Productos sin parámetros de reposición', 'impacto' => 'No se puede anticipar el quiebre con reglas confiables.', 'url' => '/app/stock'],
            ['codigo' => 'SIN_RUBRO', 'campo' => 'productos_sin_rubro', 'titulo' => 'Productos sin rubro', 'impacto' => 'Dificulta comparar rotación y margen por categoría.', 'url' => '/app/productos'],
            ['codigo' => 'SIN_LEAD_TIME', 'campo' => 'relaciones_sin_plazo_entrega', 'titulo' => 'Proveedores sin plazo de entrega', 'impacto' => 'La fecha de quiebre usa un plazo supuesto.', 'url' => '/app/proveedores'],
            ['codigo' => 'VENTA_SIN_COSTO', 'campo' => 'ventas_sin_costo_historico', 'titulo' => 'Ventas recientes sin costo histórico', 'impacto' => 'El margen histórico queda incompleto.', 'url' => '/app/reportes/productos'],
            ['codigo' => 'LOTE_SIN_VENCIMIENTO', 'campo' => 'productos_lote_sin_vencimiento', 'titulo' => 'Productos con lote sin vencimiento', 'impacto' => 'No se pueden anticipar pérdidas por caducidad.', 'url' => '/app/farmacia'],
        ];

        $problemas = [];
        $afectados = 0;
        foreach ($definiciones as $definicion) {
            $cantidad = (int) ($metricas[$definicion['campo']] ?? 0);
            $afectados += min($total, $cantidad);
            $problemas[] = [
                'codigo' => $definicion['codigo'],
                'titulo' => $definicion['titulo'],
                'cantidad' => $cantidad,
                'impacto' => $definicion['impacto'],
                'correccion_url' => $definicion['url'],
                'estado' => $cantidad === 0 ? 'OK' : ($cantidad / $total >= 0.25 ? 'CRITICO' : 'ADVERTENCIA'),
            ];
        }

        $puntaje = (int) round(max(0, 100 - ($afectados / ($total * count($definiciones))) * 100));
        return [
            'empresa_id' => $empresaId,
            'puntaje' => $puntaje,
            'nivel' => $puntaje >= 90 ? 'LISTO' : ($puntaje >= 70 ? 'MEJORABLE' : 'INSUFICIENTE'),
            'productos_activos' => $metricas['productos_activos'],
            'problemas' => $problemas,
            'generado_at' => date(DATE_ATOM),
        ];
    }

    public function analiticaAvanzada(array $filters): array
    {
        $empresaId = (int) ($filters['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $hasta = $this->date((string) ($filters['hasta'] ?? date('Y-m-d')), 'hasta');
        $desdeDefault = date('Y-m-d', strtotime($hasta . ' -29 days'));
        $desde = $this->date((string) ($filters['desde'] ?? $desdeDefault), 'desde');
        if ($desde > $hasta) {
            throw new HttpException('Rango de fechas invalido', 422);
        }
        return [
            'empresa_id' => $empresaId,
            'desde' => $desde,
            'hasta' => $hasta,
        ] + $this->repository->analiticaAvanzada($empresaId, $desde, $hasta);
    }

    public function resumenVentas(array $filters): array
    {
        [$empresaId, $sucursalId, $from, $to] = $this->filters($filters);
        $summary = $this->repository->resumenVentas($empresaId, $sucursalId, $from, $to);
        $days = $this->daysInfo(
            (int) $summary['dias_cerrados'],
            $this->repository->diasPendientesDeCierre($empresaId, $sucursalId, $from, $to, $this->today())
        );
        $totalVentas = (int) ($summary['total_ventas'] ?? 0);
        $totalImpuestos = (int) ($summary['total_impuestos'] ?? 0);
        $cantidadVentas = (int) ($summary['cantidad_ventas'] ?? 0);

        $data = [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'fecha_desde' => $from,
            'fecha_hasta' => $to,
            'total_ventas' => $totalVentas,
            'total_neto' => $totalVentas - $totalImpuestos,
            'total_impuestos' => $totalImpuestos,
            'total_descuentos' => (int) ($summary['total_descuentos'] ?? 0),
            'total_margen_estimado' => (int) ($summary['total_margen_estimado'] ?? 0),
            'cantidad_ventas' => $cantidadVentas,
            'ticket_promedio' => $cantidadVentas > 0 ? (int) round($totalVentas / $cantidadVentas) : 0,
            'cantidad_productos' => (string) ($summary['cantidad_productos'] ?? '0.000'),
            'dias_cerrados' => $days['dias_cerrados'],
            'dias_parciales' => $days['dias_parciales'],
            'parcial' => $days['parcial'],
            'advertencia' => null,
        ];

        if ($data['parcial']) {
            $n = (int) $data['dias_parciales'];
            $data['advertencia'] = $n === 1
                ? 'Hay 1 día con ventas sin cerrar; ciérralo para fijar los totales.'
                : "Hay {$n} días con ventas sin cerrar; ciérralos para fijar los totales.";
        }

        return $data;
    }

    public function ventasPorDia(array $filters): array
    {
        [$empresaId, $sucursalId, $from, $to] = $this->filters($filters);
        $sales = [];
        $products = [];

        foreach ($this->repository->productosPorDia($empresaId, $sucursalId, $from, $to) as $row) {
            $products[(string) $row['fecha']] = (string) $row['cantidad_productos'];
        }

        foreach ($this->repository->ventasPorDia($empresaId, $sucursalId, $from, $to) as $row) {
            $isClosed = (string) ($row['estado'] ?? '') === 'CERRADO';
            $sales[(string) $row['fecha']] = [
                'fecha' => (string) $row['fecha'],
                'total_ventas' => (int) $row['total_ventas'],
                'cantidad_ventas' => (int) $row['cantidad_ventas'],
                'cantidad_productos' => $products[(string) $row['fecha']] ?? '0.000',
                'estado' => $isClosed ? 'CERRADO' : 'PARCIAL',
                'parcial' => !$isClosed,
            ];
        }

        $rows = [];
        foreach ($this->dateRange($from, $to) as $date) {
            $rows[] = $sales[$date] ?? [
                'fecha' => $date,
                'total_ventas' => 0,
                'cantidad_ventas' => 0,
                'cantidad_productos' => '0.000',
                'estado' => 'SIN_CIERRE',
                'parcial' => true,
            ];
        }

        return $rows;
    }

    public function ventasPorMetodoPago(array $filters): array
    {
        [$empresaId, $sucursalId, $from, $to] = $this->filters($filters);

        return array_map(static fn (array $row): array => [
            'metodo_pago_id' => (int) $row['metodo_pago_id'],
            'codigo' => (string) $row['codigo'],
            'nombre' => (string) $row['nombre'],
            'total' => (int) $row['total'],
            'cantidad_operaciones' => (int) $row['cantidad_operaciones'],
        ], $this->repository->ventasPorMetodoPago($empresaId, $sucursalId, $from, $to));
    }

    public function ventasPorProducto(array $filters): array
    {
        [$empresaId, $sucursalId, $from, $to] = $this->filters($filters);
        $limit = $this->limit($filters['limit'] ?? 20);
        $order = strtolower((string) ($filters['orden'] ?? 'total'));

        if (!in_array($order, ['total', 'cantidad'], true)) {
            throw new HttpException('orden invalido', 422);
        }

        return array_map(static fn (array $row): array => [
            'producto_id' => (int) $row['producto_id'],
            'codigo' => $row['codigo'],
            'nombre' => (string) $row['nombre'],
            'cantidad_vendida' => (string) $row['cantidad_vendida'],
            'total_vendido' => (int) $row['total_vendido'],
            'margen_estimado' => (int) $row['margen_estimado'],
        ], $this->repository->ventasPorProducto($empresaId, $sucursalId, $from, $to, $limit, $order));
    }

    public function ventasPorRubro(array $filters): array
    {
        [$empresaId, $sucursalId, $from, $to] = $this->filters($filters);

        return array_map(static fn (array $row): array => [
            'rubro_id' => $row['rubro_id'] !== null ? (int) $row['rubro_id'] : null,
            'rubro' => (string) $row['rubro'],
            'cantidad_vendida' => (string) $row['cantidad_vendida'],
            'total_vendido' => (int) $row['total_vendido'],
            'margen_estimado' => (int) $row['margen_estimado'],
        ], $this->repository->ventasPorRubro($empresaId, $sucursalId, $from, $to));
    }

    public function ventasPorUsuario(array $filters, int $limit = 100): array
    {
        [$empresaId, $sucursalId, $from, $to] = $this->filters($filters);

        return array_map(static fn (array $row): array => [
            'usuario_id' => (int) $row['usuario_id'],
            'usuario' => (string) $row['usuario'],
            'cantidad_ventas' => (int) $row['cantidad_ventas'],
            'total_vendido' => (int) $row['total_vendido'],
            'margen_estimado' => (int) $row['margen_estimado'],
        ], $this->repository->ventasPorUsuario($empresaId, $sucursalId, $from, $to, $limit));
    }

    public function dashboard(array $filters): array
    {
        [$empresaId, $sucursalId, $from, $to] = $this->filters($filters);

        $summary = $this->resumenVentas($filters);
        $quantity = (int) $summary['cantidad_ventas'];
        $totalVentas = (int) $summary['total_ventas'];

        $comparativo = $this->comparativoPeriodoAnterior($empresaId, $sucursalId, $from, $to);

        $stockBajo = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'codigo' => (string) $row['codigo'],
            'nombre' => (string) $row['nombre'],
            'rubro' => (string) $row['rubro'],
            'stock_actual' => (float) $row['stock_actual'],
            'stock_minimo' => (float) $row['stock_minimo'],
        ], $this->repository->stockBajo($empresaId, $sucursalId));

        return [
            'resumen' => [
                'total_ventas' => $totalVentas,
                'cantidad_ventas' => $quantity,
                'ticket_promedio' => $quantity > 0 ? (int) round($totalVentas / $quantity) : 0,
                'total_margen_estimado' => (int) $summary['total_margen_estimado'],
                'cantidad_productos' => (string) $summary['cantidad_productos'],
                'dias_parciales' => (int) $summary['dias_parciales'],
                'parcial' => (bool) $summary['parcial'],
                'advertencia' => $summary['advertencia'],
            ],
            'comparativo' => $comparativo,
            'metodos_pago' => $this->ventasPorMetodoPago($filters),
            'top_productos' => $this->ventasPorProducto(['limit' => 5, 'orden' => 'total'] + $filters),
            'top_usuarios' => $this->ventasPorUsuario($filters, 5),
            'rubros' => $this->ventasPorRubro($filters),
            'ventas_por_dia' => $this->ventasPorDia($filters),
            'stock_bajo' => $stockBajo,
        ];
    }

    /**
     * Salud financiera del inventario (P8/P9): capital inmovilizado y stock
     * muerto, con un resumen en lenguaje de dueño (sin balances ni tecnicismos).
     */
    public function saludFinanciera(array $filters): array
    {
        $empresaId = (int) ($filters['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $dias  = isset($filters['dias']) && is_numeric($filters['dias']) ? max(1, (int) $filters['dias']) : 90;
        $limit = isset($filters['limit']) && is_numeric($filters['limit']) ? max(1, (int) $filters['limit']) : 20;

        $resumen = $this->repository->capitalInmovilizadoResumen($empresaId, $dias);
        $muertosRaw = $this->repository->stockMuertoTop($empresaId, $dias, $limit);

        $hoy = new DateTimeImmutable('today');
        $muertos = array_map(function (array $r) use ($hoy): array {
            $diasSinVenta = null;
            if (!empty($r['ultima_venta'])) {
                $diasSinVenta = (int) $hoy->diff(new DateTimeImmutable((string) $r['ultima_venta']))->days;
            }
            return [
                'producto_id'    => (int) $r['id'],
                'codigo'         => (string) $r['codigo'],
                'nombre'         => (string) $r['nombre'],
                'stock'          => (float) $r['stock'],
                'costo'          => (int) round((float) $r['costo']),
                'valor'          => (int) round((float) $r['valor']),
                'ultima_venta'   => $r['ultima_venta'] ?? null,
                'dias_sin_venta' => $diasSinVenta, // null = nunca se ha vendido
            ];
        }, $muertosRaw);

        $valorTotal  = $resumen['valor_inventario_total'];
        $valorMuerto = $resumen['valor_stock_muerto'];
        $pctMuerto   = $valorTotal > 0 ? round($valorMuerto / $valorTotal * 100, 1) : 0.0;

        // Lenguaje de dueño (P9): frases directas, sin jerga contable.
        $fmt = static fn (int $n): string => '$' . number_format($n, 0, ',', '.');
        $mensajes = [
            sprintf('Tienes %s en mercadería guardada en tus bodegas y estantes.', $fmt($valorTotal)),
        ];
        if ($valorMuerto > 0) {
            $mensajes[] = sprintf(
                'De ese total, %s (%s%%) está "durmiendo": %d producto(s) sin una sola venta en los últimos %d días. Ese dinero podría usarse para pagar cuentas o reponer lo que sí rota.',
                $fmt($valorMuerto),
                rtrim(rtrim(number_format($pctMuerto, 1, ',', '.'), '0'), ','),
                $resumen['items_muertos'],
                $dias
            );
        } else {
            $mensajes[] = 'Buenas noticias: no hay stock muerto en el período analizado.';
        }

        return [
            'dias_sin_venta_umbral'  => $dias,
            'valor_inventario_total' => $valorTotal,
            'valor_stock_muerto'     => $valorMuerto,
            'pct_stock_muerto'       => $pctMuerto,
            'items_total'            => $resumen['items_total'],
            'items_muertos'          => $resumen['items_muertos'],
            'mensajes'               => $mensajes,
            'stock_muerto'           => $muertos,
        ];
    }

    private function comparativoPeriodoAnterior(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        $days = (int) $start->diff($end)->days + 1;

        $prevTo = $start->sub(new DateInterval('P1D'))->format('Y-m-d');
        $prevFrom = $start->sub(new DateInterval("P{$days}D"))->format('Y-m-d');

        $prev = $this->repository->resumenVentas($empresaId, $sucursalId, $prevFrom, $prevTo);
        $prevQty = (int) ($prev['cantidad_ventas'] ?? 0);
        $prevTotal = (int) ($prev['total_ventas'] ?? 0);

        return [
            'total_ventas' => $prevTotal,
            'cantidad_ventas' => $prevQty,
            'ticket_promedio' => $prevQty > 0 ? (int) round($prevTotal / $prevQty) : 0,
            'total_margen_estimado' => (int) ($prev['total_margen_estimado'] ?? 0),
            'fecha_desde' => $prevFrom,
            'fecha_hasta' => $prevTo,
        ];
    }

    private function filters(array $filters): array
    {
        $empresaId = (int) ($filters['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $from = $this->date((string) ($filters['fecha_desde'] ?? ''), 'fecha_desde');
        $to = $this->date((string) ($filters['fecha_hasta'] ?? ''), 'fecha_hasta');

        if ($from > $to) {
            throw new HttpException('fecha_desde no puede ser mayor que fecha_hasta', 422);
        }

        $sucursalId = null;
        if (!empty($filters['sucursal_id'])) {
            $sucursalId = (int) $filters['sucursal_id'];
            if ($sucursalId <= 0 || !$this->repository->sucursalExists($empresaId, $sucursalId)) {
                throw new HttpException('Sucursal no encontrada', 422);
            }
        }

        return [$empresaId, $sucursalId, $from, $to];
    }

    private function date(string $value, string $field): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new HttpException($field . ' invalida', 422);
        }

        return $value;
    }

    private function daysInfo(int $closedDays, int $pendingDays): array
    {
        return [
            'dias_cerrados' => $closedDays,
            'dias_parciales' => $pendingDays,
            'parcial' => $pendingDays > 0,
        ];
    }

    private function today(): string
    {
        return (new DateTimeImmutable('now', new \DateTimeZone('America/Santiago')))->format('Y-m-d');
    }

    private function dateRange(string $from, string $to): array
    {
        $start = new DateTimeImmutable($from);
        $end = (new DateTimeImmutable($to))->add(new DateInterval('P1D'));
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);
        $dates = [];

        foreach ($period as $date) {
            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }

    private function limit(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new HttpException('limit debe ser un entero entre 1 y 100', 422);
        }

        $limit = (int) $value;
        if ($limit < 1 || $limit > 100) {
            throw new HttpException('limit debe ser un entero entre 1 y 100', 422);
        }

        return $limit;
    }
}
