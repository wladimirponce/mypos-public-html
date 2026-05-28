<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\CompraRepository;
use Mypos\Repositories\StockRepository;
use Throwable;

final class CompraService
{
    private const TIPOS = ['FACTURA_COMPRA', 'GUIA_DESPACHO_COMPRA', 'BOLETA_COMPRA'];
    private const ESTADOS = ['BORRADOR', 'CONFIRMADA', 'ANULADA'];

    private CompraRepository $repository;

    public function __construct(?CompraRepository $repository = null)
    {
        $this->repository = $repository ?? new CompraRepository(Database::connection());
    }

    public function crear(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $sucursalId = $this->positiveInt($payload, 'sucursal_id');
        $estado = strtoupper((string) ($payload['estado'] ?? 'BORRADOR'));
        $items = $this->prepareItems($empresaId, $payload['items'] ?? []);

        $this->validateHeader($empresaId, $sucursalId, $payload, $estado);
        $totals = $this->totals($items);
        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();
            $purchaseId = $this->repository->insertPurchase([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'proveedor_id' => $payload['proveedor_id'] ?? null,
                'usuario_id' => $userId,
                'tipo_documento' => strtoupper((string) $payload['tipo_documento']),
                'folio' => $payload['folio'] ?? null,
                'fecha_documento' => $this->dateOrNull($payload['fecha_documento'] ?? null),
                'fecha_ingreso' => $this->dateOrNull($payload['fecha_ingreso'] ?? null),
                'estado' => $estado,
                'subtotal' => $totals['neto'],
                'impuesto_total' => $totals['iva'],
                'total' => $totals['total'],
                'observacion' => $payload['observacion'] ?? null,
            ]);

            foreach ($items as $index => $item) {
                $this->repository->insertDetail($this->detailPayload($empresaId, $purchaseId, $index + 1, $item));
            }

            if ($estado === 'CONFIRMADA') {
                $this->sumarStock($purchaseId, $empresaId, $sucursalId, $userId, $items, $connection);
            }

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'usuario_id' => $userId,
                'modulo' => 'compras',
                'accion' => 'crear',
                'entidad' => 'compras',
                'entidad_id' => $purchaseId,
                'descripcion' => 'Compra registrada',
                'datos_nuevos' => [
                    'estado' => $estado,
                    'tipo_documento' => strtoupper((string) $payload['tipo_documento']),
                    'proveedor_id' => $payload['proveedor_id'] ?? null,
                    'total' => $totals['total'],
                    'items' => count($items),
                    'stock_movido' => $estado === 'CONFIRMADA',
                ],
            ], $connection);

            $connection->commit();

            return ['compra_id' => $purchaseId, 'estado' => $estado, 'total' => $totals['total']];
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

        return ['compras' => $this->repository->list($empresaId, $filters)];
    }

    public function detalle(int $empresaId, int $id): array
    {
        $purchase = $this->repository->find($empresaId, $id);

        if ($purchase === null) {
            throw new HttpException('Compra no encontrada', 404);
        }

        return ['compra' => $purchase, 'detalles' => $this->repository->details($empresaId, $id)];
    }

    public function confirmar(int $userId, int $id, int $empresaId): array
    {
        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();
            $purchase = $this->repository->findForUpdate($empresaId, $id);

            if ($purchase === null) {
                throw new HttpException('Compra no encontrada', 404);
            }

            if ($purchase['estado'] !== 'BORRADOR') {
                throw new HttpException('La compra no está en estado BORRADOR', 422);
            }

            $details = $this->repository->details($empresaId, $id);
            $this->repository->markConfirmed($empresaId, $id);
            $this->sumarStock($id, $empresaId, (int) $purchase['sucursal_id'], $userId, $details, $connection);
            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) $purchase['sucursal_id'],
                'usuario_id' => $userId,
                'modulo' => 'compras',
                'accion' => 'confirmar',
                'entidad' => 'compras',
                'entidad_id' => $id,
                'descripcion' => 'Compra confirmada',
                'datos_anteriores' => ['estado' => (string) $purchase['estado']],
                'datos_nuevos' => ['estado' => 'CONFIRMADA', 'items' => count($details)],
            ], $connection);
            $connection->commit();

            return ['compra_id' => $id, 'estado' => 'CONFIRMADA'];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function anular(int $id, int $empresaId, ?string $reason): array
    {
        $purchase = $this->repository->findForUpdate($empresaId, $id);

        if ($purchase === null) {
            throw new HttpException('Compra no encontrada', 404);
        }

        if ($purchase['estado'] === 'CONFIRMADA') {
            throw new HttpException('La anulación de compras confirmadas se implementará con reversa de stock en una fase posterior', 422);
        }

        if ($purchase['estado'] !== 'BORRADOR') {
            throw new HttpException('La compra no puede ser anulada', 422);
        }

        $this->repository->markCancelled($empresaId, $id, $reason);
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $purchase['sucursal_id'],
            'modulo' => 'compras',
            'accion' => 'anular',
            'entidad' => 'compras',
            'entidad_id' => $id,
            'descripcion' => 'Compra borrador anulada',
            'datos_anteriores' => ['estado' => (string) $purchase['estado']],
            'datos_nuevos' => ['estado' => 'ANULADA', 'motivo' => $reason],
        ], $this->repository->connection());

        return ['compra_id' => $id, 'estado' => 'ANULADA'];
    }

    public function reversarCompra(int $userId, int $id, array $payload): array
    {
        return (new AnulacionService())->reversarCompra($userId, $id, $payload);
    }

    private function validateHeader(int $empresaId, int $sucursalId, array $payload, string $estado): void
    {
        if (!$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 422);
        }

        if (!$this->repository->proveedorExists($empresaId, isset($payload['proveedor_id']) ? (int) $payload['proveedor_id'] : null)) {
            throw new HttpException('Proveedor no encontrado', 422);
        }

        if (!in_array(strtoupper((string) ($payload['tipo_documento'] ?? '')), self::TIPOS, true)) {
            throw new HttpException('Tipo de documento inválido', 422);
        }

        if (!in_array($estado, self::ESTADOS, true) || $estado === 'ANULADA') {
            throw new HttpException('Estado inválido', 422);
        }
    }

    private function prepareItems(int $empresaId, mixed $items): array
    {
        if (!is_array($items) || $items === []) {
            throw new HttpException('La compra debe incluir items', 422);
        }

        $prepared = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new HttpException('Item inválido', 422);
            }

            $productId = $this->positiveInt($item, 'producto_id');
            $product = $this->repository->product($empresaId, $productId);

            if ($product === null) {
                throw new HttpException('Producto no existe', 422);
            }

            $quantity = $this->quantity($item['cantidad'] ?? null);
            $cost = $this->nonNegativeInt($item, 'costo_unitario');
            $net = isset($item['neto']) ? $this->nonNegativeInt($item, 'neto') : (int) round($quantity * $cost);
            $iva = isset($item['iva']) ? $this->nonNegativeInt($item, 'iva') : 0;
            $total = isset($item['total']) ? $this->nonNegativeInt($item, 'total') : $net + $iva;

            $prepared[] = [
                'producto_id' => $productId,
                'codigo_producto' => $product['codigo'] ?? $product['sku'] ?? null,
                'nombre_producto' => $product['nombre'],
                'controla_stock' => (int) $product['controla_stock'],
                'cantidad' => $this->formatQuantity($quantity),
                'costo_unitario' => $cost,
                'neto' => $net,
                'iva' => $iva,
                'total' => $total,
            ];
        }

        return $prepared;
    }

    private function sumarStock(int $purchaseId, int $empresaId, int $sucursalId, int $userId, array $items, $connection): void
    {
        $stockService = new StockService(new StockRepository($connection));

        foreach ($items as $item) {
            if ((int) ($item['controla_stock'] ?? 1) !== 1) {
                continue;
            }

            $stockService->sumarPorCompra([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'producto_id' => (int) $item['producto_id'],
                'usuario_id' => $userId,
                'tipo' => 'COMPRA',
                'referencia_tipo' => 'COMPRA',
                'referencia_id' => $purchaseId,
                'cantidad' => $item['cantidad'],
                'costo_unitario' => (int) $item['costo_unitario'],
                'observacion' => 'Compra #' . $purchaseId,
            ], $connection);
        }
    }

    private function detailPayload(int $empresaId, int $purchaseId, int $line, array $item): array
    {
        return [
            'empresa_id' => $empresaId,
            'compra_id' => $purchaseId,
            'producto_id' => $item['producto_id'],
            'linea' => $line,
            'codigo_producto' => $item['codigo_producto'],
            'codigo_barra_usado' => null,
            'nombre_producto' => $item['nombre_producto'],
            'cantidad' => $item['cantidad'],
            'costo_unitario' => $item['costo_unitario'],
            'neto' => $item['neto'],
            'iva' => $item['iva'],
            'impuesto_total' => $item['iva'],
            'total' => $item['total'],
        ];
    }

    private function totals(array $items): array
    {
        return [
            'neto' => array_sum(array_column($items, 'neto')),
            'iva' => array_sum(array_column($items, 'iva')),
            'total' => array_sum(array_column($items, 'total')),
        ];
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);

        if ($value <= 0) {
            throw new HttpException('Error de validación', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return $value;
    }

    private function nonNegativeInt(array $data, string $field): int
    {
        if (!isset($data[$field]) || !is_numeric($data[$field]) || (int) $data[$field] < 0) {
            throw new HttpException('Error de validación', 422, [$field => ["El campo {$field} debe ser >= 0"]]);
        }

        return (int) $data[$field];
    }

    private function quantity(mixed $value): float
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new HttpException('La cantidad debe ser mayor a 0', 422);
        }

        return round((float) $value, 3);
    }

    private function formatQuantity(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new HttpException('Fecha inválida', 422);
        }

        return (string) $value;
    }
}
