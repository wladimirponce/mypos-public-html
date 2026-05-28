<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\LibroRepository;

final class LibroService
{
    private const TIPOS_VENTA = ['BOLETA', 'FACTURA', 'GUIA_DESPACHO', 'NOTA_CREDITO'];
    private const ESTADOS_VENTA = ['BORRADOR', 'PENDIENTE_EMISION', 'EMITIDO_INTERNO', 'ENVIADO_SII', 'ACEPTADO_SII', 'RECHAZADO_SII', 'ANULADO'];
    private const ESTADOS_COMPRA = ['BORRADOR', 'CONFIRMADA', 'ANULADA', 'REVERSADA'];

    private LibroRepository $repository;

    public function __construct(?LibroRepository $repository = null)
    {
        $this->repository = $repository ?? new LibroRepository(Database::connection());
    }

    public function ventas(array $filters): array
    {
        [$empresaId, $sucursalId, $from, $to] = $this->filters($filters);
        $this->validateSalesFilters($filters);
        $items = array_map([$this, 'formatSale'], $this->repository->libroVentas($empresaId, $sucursalId, $from, $to, $filters));

        return [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'fecha_desde' => $from,
            'fecha_hasta' => $to,
            'items' => $items,
            'totales' => $this->formatTotals($this->repository->totalesVentas($empresaId, $sucursalId, $from, $to, $filters)),
        ];
    }

    public function compras(array $filters): array
    {
        [$empresaId, $sucursalId, $from, $to] = $this->filters($filters);
        $this->validatePurchaseFilters($filters);
        $items = array_map([$this, 'formatPurchase'], $this->repository->libroCompras($empresaId, $sucursalId, $from, $to, $filters));

        return [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'fecha_desde' => $from,
            'fecha_hasta' => $to,
            'items' => $items,
            'totales' => $this->formatTotals($this->repository->totalesCompras($empresaId, $sucursalId, $from, $to, $filters)),
        ];
    }

    public function resumenIva(array $filters): array
    {
        [$empresaId, $sucursalId, $from, $to] = $this->filters($filters);
        $sales = $this->formatTotals($this->repository->totalesVentas($empresaId, $sucursalId, $from, $to, []));
        $purchases = $this->formatTotals($this->repository->totalesCompras($empresaId, $sucursalId, $from, $to, []));
        $debit = (int) $sales['impuestos'];
        $credit = (int) $purchases['impuestos'];

        return [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'fecha_desde' => $from,
            'fecha_hasta' => $to,
            'ventas' => [
                'neto' => (int) $sales['neto'],
                'exento' => (int) $sales['exento'],
                'iva_debito' => $debit,
                'total' => (int) $sales['total'],
            ],
            'compras' => [
                'neto' => (int) $purchases['neto'],
                'exento' => (int) $purchases['exento'],
                'iva_credito' => $credit,
                'total' => (int) $purchases['total'],
            ],
            'resumen' => [
                'iva_debito' => $debit,
                'iva_credito' => $credit,
                'iva_a_pagar' => $debit - $credit,
            ],
        ];
    }

    public function ventasResumenTipoDocumento(array $filters): array
    {
        [$empresaId, $sucursalId, $from, $to] = $this->filters($filters);

        return array_map(static fn (array $row): array => [
            'tipo_documento' => (string) $row['tipo_documento'],
            'cantidad_documentos' => (int) $row['cantidad_documentos'],
            'neto' => (int) $row['neto'],
            'exento' => (int) $row['exento'],
            'impuestos' => (int) $row['impuestos'],
            'total' => (int) $row['total'],
        ], $this->repository->resumenVentasPorTipo($empresaId, $sucursalId, $from, $to));
    }

    public function comprasResumenProveedor(array $filters): array
    {
        [$empresaId, $sucursalId, $from, $to] = $this->filters($filters);
        $limit = $this->limit($filters['limit'] ?? 20);

        return array_map(static fn (array $row): array => [
            'proveedor_id' => $row['proveedor_id'] !== null ? (int) $row['proveedor_id'] : null,
            'proveedor_nombre' => (string) $row['proveedor_nombre'],
            'cantidad_documentos' => (int) $row['cantidad_documentos'],
            'neto' => (int) $row['neto'],
            'exento' => (int) $row['exento'],
            'impuestos' => (int) $row['impuestos'],
            'total' => (int) $row['total'],
        ], $this->repository->resumenComprasPorProveedor($empresaId, $sucursalId, $from, $to, $limit));
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
        if (isset($filters['sucursal_id']) && $filters['sucursal_id'] !== '') {
            $sucursalId = (int) $filters['sucursal_id'];
            if ($sucursalId <= 0 || !$this->repository->sucursalExists($empresaId, $sucursalId)) {
                throw new HttpException('Sucursal no encontrada', 422);
            }
        }

        return [$empresaId, $sucursalId, $from, $to];
    }

    private function validateSalesFilters(array $filters): void
    {
        if (!empty($filters['tipo_documento'])) {
            $this->allowed(strtoupper((string) $filters['tipo_documento']), self::TIPOS_VENTA, 'tipo_documento invalido');
        }

        if (!empty($filters['estado'])) {
            $this->allowed(strtoupper((string) $filters['estado']), self::ESTADOS_VENTA, 'estado invalido');
        }

        if (array_key_exists('incluir_anulados', $filters)) {
            $this->bool($filters['incluir_anulados'], 'incluir_anulados');
        }
    }

    private function validatePurchaseFilters(array $filters): void
    {
        if (!empty($filters['estado'])) {
            $this->allowed(strtoupper((string) $filters['estado']), self::ESTADOS_COMPRA, 'estado invalido');
        }

        if (array_key_exists('incluir_anuladas', $filters)) {
            $this->bool($filters['incluir_anuladas'], 'incluir_anuladas');
        }

        if (isset($filters['proveedor_id']) && $filters['proveedor_id'] !== '' && (int) $filters['proveedor_id'] <= 0) {
            throw new HttpException('proveedor_id invalido', 422);
        }
    }

    private function formatSale(array $row): array
    {
        return [
            'documento_emitido_id' => (int) $row['documento_emitido_id'],
            'fecha_emision' => (string) $row['fecha_emision'],
            'tipo_documento' => (string) $row['tipo_documento'],
            'folio' => $row['folio'],
            'estado' => (string) $row['estado'],
            'rut_receptor' => $row['rut_receptor'],
            'razon_social_receptor' => $row['razon_social_receptor'],
            'neto' => (int) $row['neto'],
            'exento' => (int) $row['exento'],
            'impuestos' => (int) $row['impuestos'],
            'total' => (int) $row['total'],
            'venta_id' => $row['venta_id'] !== null ? (int) $row['venta_id'] : null,
        ];
    }

    private function formatPurchase(array $row): array
    {
        return [
            'compra_id' => (int) $row['compra_id'],
            'fecha_documento' => (string) $row['fecha_documento'],
            'tipo_documento' => (string) $row['tipo_documento'],
            'folio' => $row['folio'],
            'proveedor_id' => $row['proveedor_id'] !== null ? (int) $row['proveedor_id'] : null,
            'proveedor_nombre' => (string) $row['proveedor_nombre'],
            'neto' => (int) $row['neto'],
            'exento' => (int) $row['exento'],
            'impuestos' => (int) $row['impuestos'],
            'total' => (int) $row['total'],
            'estado' => (string) $row['estado'],
            'documento_ia_id' => $row['documento_ia_id'] !== null ? (int) $row['documento_ia_id'] : null,
        ];
    }

    private function formatTotals(array $row): array
    {
        return [
            'cantidad_documentos' => (int) ($row['cantidad_documentos'] ?? 0),
            'neto' => (int) ($row['neto'] ?? 0),
            'exento' => (int) ($row['exento'] ?? 0),
            'impuestos' => (int) ($row['impuestos'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
        ];
    }

    private function date(string $value, string $field): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new HttpException($field . ' invalida', 422);
        }

        return $value;
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

    private function allowed(string $value, array $allowed, string $message): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new HttpException($message, 422);
        }
    }

    private function bool(mixed $value, string $field): bool
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw new HttpException($field . ' debe ser true/false o 1/0', 422);
        }

        return $parsed;
    }
}
