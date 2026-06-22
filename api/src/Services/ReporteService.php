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
