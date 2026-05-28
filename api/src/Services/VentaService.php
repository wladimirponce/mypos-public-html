<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\CreditoRepository;
use Mypos\Repositories\StockRepository;
use Mypos\Repositories\VentaRepository;
use Throwable;

final class VentaService
{
    private VentaRepository $repository;
    private DescuentoService $descuentos;
    private ImpuestoService $impuestos;
    private ComisionService $comisiones;

    public function __construct(?VentaRepository $repository = null)
    {
        $this->repository = $repository ?? new VentaRepository(Database::connection());
        $this->descuentos = new DescuentoService();
        $this->impuestos = new ImpuestoService();
        $this->comisiones = new ComisionService();
    }

    public function registrarVenta(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $sucursalId = $this->positiveInt($payload, 'sucursal_id');
        $tipoVenta = (string) ($payload['tipo_venta'] ?? 'BOLETA');
        $items = $payload['items'] ?? [];
        $payments = $payload['pagos'] ?? [];
        $cashOpening = $this->cashOpening($empresaId, $sucursalId, $payload);
        $paymentCondition = strtoupper(trim((string) ($payload['condicion_pago'] ?? 'CONTADO')));
        $clientId = isset($payload['cliente_id']) && (int) $payload['cliente_id'] > 0 ? (int) $payload['cliente_id'] : null;
        $deviceId = isset($payload['dispositivo_id']) && (int) $payload['dispositivo_id'] > 0 ? (int) $payload['dispositivo_id'] : null;
        $origin = strtoupper(trim((string) ($payload['origen'] ?? 'ONLINE')));
        $uuidOffline = isset($payload['uuid_offline']) && trim((string) $payload['uuid_offline']) !== ''
            ? trim((string) $payload['uuid_offline'])
            : null;

        if (!in_array($origin, ['ONLINE', 'OFFLINE'], true)) {
            throw new HttpException('origen invalido', 422);
        }

        if (!$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 422);
        }

        $config = (new ConfiguracionService())->efectiva($empresaId, $sucursalId);

        if ((bool) $config['exigir_caja_abierta_para_vender'] && $cashOpening === null) {
            $this->auditConfigBlock($empresaId, $sucursalId, $userId, 'exigir_caja_abierta_para_vender', 'La empresa exige caja abierta para vender');
            throw new HttpException('La empresa exige caja abierta para vender', 422);
        }

        if (!(bool) $config['permitir_venta_sin_cliente'] && $clientId === null) {
            $this->auditConfigBlock($empresaId, $sucursalId, $userId, 'permitir_venta_sin_cliente', 'La empresa exige cliente para registrar ventas');
            throw new HttpException('La empresa exige cliente para registrar ventas', 422);
        }

        if (!is_array($items) || $items === []) {
            throw new HttpException('La venta debe incluir items', 422);
        }

        if (!in_array($paymentCondition, ['CONTADO', 'CREDITO'], true)) {
            throw new HttpException('condicion_pago invalida', 422);
        }

        if ($paymentCondition === 'CONTADO' && (!is_array($payments) || $payments === [])) {
            throw new HttpException('La venta debe incluir pagos', 422);
        }

        $preparedItems = $this->prepareItems($empresaId, $items);
        $preparedPayments = is_array($payments) && $payments !== [] ? $this->preparePayments($payments) : [];
        $totals = $this->sumTotals($preparedItems);
        $paymentTotal = array_sum(array_column($preparedPayments, 'monto'));
        $creditBalance = $totals['total'] - $paymentTotal;

        if ($paymentCondition === 'CONTADO' && $paymentTotal !== $totals['total']) {
            throw new HttpException('La suma de pagos no coincide con el total de la venta', 422, [
                'pagos' => ['La suma de pagos debe ser igual al total'],
            ]);
        }

        if ($paymentCondition === 'CREDITO') {
            if (!(bool) $config['permitir_credito_clientes']) {
                $this->auditConfigBlock($empresaId, $sucursalId, $userId, 'permitir_credito_clientes', 'La venta a credito esta deshabilitada para esta empresa');
                throw new HttpException('La venta a credito esta deshabilitada para esta empresa', 422);
            }
            if ($clientId === null) {
                throw new HttpException('cliente_id obligatorio para venta a credito', 422);
            }
            if ($creditBalance <= 0) {
                throw new HttpException('La venta a credito debe dejar saldo pendiente', 422);
            }
            (new CreditoService(new CreditoRepository($this->repository->connection())))
                ->validarClienteParaVenta($empresaId, $clientId, $creditBalance);
        }

        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();

            $saleId = $this->repository->insertSale([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'caja_id' => $cashOpening['caja_id'] ?? ($payload['caja_id'] ?? null),
                'apertura_id' => $cashOpening['id'] ?? ($payload['apertura_id'] ?? null),
                'caja_apertura_id' => $cashOpening['id'] ?? null,
                'usuario_id' => $userId,
                'cliente_id' => $clientId,
                'tipo_venta' => $tipoVenta,
                'condicion_pago' => $paymentCondition,
                'subtotal' => $totals['subtotal'],
                'descuento_total' => $totals['descuento_total'],
                'impuesto_total' => $totals['impuesto_total'],
                'total' => $totals['total'],
                'margen_total' => $totals['margen_total'],
                'comision_total' => $totals['comision_total'],
                'uuid_offline' => $uuidOffline,
                'dispositivo_id' => $deviceId,
                'sync_evento_id' => isset($payload['sync_evento_id']) && (int) $payload['sync_evento_id'] > 0
                    ? (int) $payload['sync_evento_id']
                    : null,
                'origen' => $origin,
                'sync_estado' => strtoupper((string) ($payload['sync_estado'] ?? 'SYNC_OK')),
                'created_offline_at' => $payload['created_offline_at'] ?? null,
            ]);

            foreach ($preparedItems as $line => $item) {
                $detailId = $this->repository->insertSaleDetail([
                    'empresa_id' => $empresaId,
                    'venta_id' => $saleId,
                    'producto_id' => $item['producto_id'],
                    'linea' => $line + 1,
                    'codigo_producto' => $item['codigo_producto'],
                    'codigo_barra_usado' => $item['codigo_barra_usado'],
                    'nombre_producto' => $item['nombre_producto'],
                    'cantidad' => $item['cantidad_formatted'],
                    'precio_unitario' => $item['precio_unitario'],
                    'costo_unitario' => $item['costo_unitario'],
                    'descuento_tipo' => $item['descuento_tipo'],
                    'descuento_valor' => $item['descuento_valor'],
                    'subtotal' => $item['subtotal'],
                    'descuento_total' => $item['descuento_total'],
                    'neto' => $item['neto'],
                    'impuestos_total' => $item['impuestos_total'],
                    'exento' => $item['exento'],
                    'impuesto_total' => $item['impuestos_total'],
                    'total' => $item['total'],
                    'margen_total' => $item['margen_estimado'],
                    'margen_estimado' => $item['margen_estimado'],
                    'comision_total' => $item['comision_vendedor'],
                    'comision_vendedor' => $item['comision_vendedor'],
                ]);

                foreach ($item['impuestos_detalle'] as $tax) {
                    $tax['empresa_id'] = $empresaId;
                    $tax['venta_id'] = $saleId;
                    $tax['venta_detalle_id'] = $detailId;
                    $this->repository->insertSaleDetailTax($tax);
                }

                if ((int) $item['controla_stock'] === 1) {
                    $stockService = new StockService(new StockRepository($connection));
                    $stockService->descontarPorVenta([
                        'empresa_id' => $empresaId,
                        'sucursal_id' => $sucursalId,
                        'producto_id' => $item['producto_id'],
                        'usuario_id' => $userId,
                        'tipo' => 'VENTA',
                        'referencia_tipo' => 'VENTA',
                        'referencia_id' => $saleId,
                        'cantidad' => $item['cantidad_formatted'],
                        'costo_unitario' => $item['costo_unitario'],
                        'observacion' => 'Venta #' . $saleId,
                        'dispositivo_id' => $deviceId,
                    ], $connection);
                }
            }

            foreach ($preparedPayments as $payment) {
                $this->repository->insertPayment([
                    'empresa_id' => $empresaId,
                    'venta_id' => $saleId,
                    'metodo_pago_id' => $payment['metodo_pago_id'],
                    'metodo_pago_codigo' => $payment['metodo_pago_codigo'],
                    'monto' => $payment['monto'],
                    'referencia' => $payment['referencia'] ?? null,
                ]);
            }

            $creditId = null;
            if ($paymentCondition === 'CREDITO') {
                $creditService = new CreditoService(new CreditoRepository($connection));
                $creditId = $creditService->crearDesdeVenta([
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'cliente_id' => $clientId,
                    'venta_id' => $saleId,
                    'monto_original' => $creditBalance,
                    'fecha_vencimiento' => $payload['fecha_vencimiento'] ?? null,
                    'observacion' => $payload['observacion_credito'] ?? null,
                    'created_by_usuario_id' => $userId,
                ]);
                $this->repository->updateSaleCredit($empresaId, $saleId, $creditId);
            }

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'usuario_id' => $userId,
                'modulo' => 'ventas',
                'accion' => 'crear',
                'entidad' => 'ventas',
                'entidad_id' => $saleId,
                'descripcion' => 'Venta registrada',
                'datos_nuevos' => [
                    'venta_id' => $saleId,
                    'tipo_venta' => $tipoVenta,
                    'condicion_pago' => $paymentCondition,
                    'cliente_id' => $clientId,
                    'caja_apertura_id' => $cashOpening['id'] ?? null,
                    'credito_cliente_id' => $creditId,
                    'total' => $totals['total'],
                    'items' => count($preparedItems),
                    'origen' => $origin,
                    'uuid_offline' => $uuidOffline,
                    'dispositivo_id' => $deviceId,
                ],
            ], $connection);

            $connection->commit();

            $response = [
                'venta_id' => $saleId,
                'total' => $totals['total'],
                'items' => count($preparedItems),
                'condicion_pago' => $paymentCondition,
                'origen' => $origin,
                'uuid_offline' => $uuidOffline,
            ];

            if ($creditId !== null) {
                $response['credito_cliente_id'] = $creditId;
                $response['saldo_credito'] = $creditBalance;
            }

            return $response;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function anularVenta(int $userId, int $saleId, array $payload): array
    {
        return (new AnulacionService())->anularVenta($userId, $saleId, $payload);
    }

    private function prepareItems(int $empresaId, array $items): array
    {
        $prepared = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new HttpException('Item inválido', 422);
            }

            $quantity = $this->quantity($item['cantidad'] ?? null);
            $product = isset($item['producto_id'])
                ? $this->repository->findProductById($empresaId, (int) $item['producto_id'])
                : $this->repository->findProductByCode($empresaId, (string) ($item['codigo'] ?? ''));

            if ($product === null) {
                throw new HttpException('Producto no existe', 422);
            }

            $unitPrice = (int) $product['precio_venta'];
            $unitCost = (int) ($product['precio_costo'] ?? $product['costo_actual'] ?? 0);
            $subtotal = (int) round($unitPrice * $quantity);
            $discount = $this->descuentos->calcularMejorDescuento($this->repository->activeDiscounts($empresaId, (int) $product['id']), $subtotal);
            $baseAfterDiscount = $subtotal - $discount['total'];
            $taxes = $this->impuestos->calcular($this->repository->activeTaxes($empresaId, (int) $product['id']), $baseAfterDiscount);
            $total = $baseAfterDiscount;
            $margin = $total - (int) round($unitCost * $quantity);
            $commission = $this->comisiones->calcular($this->repository->activeCommissions($empresaId, (int) $product['id']), $total, $quantity, $margin);

            $prepared[] = [
                'producto_id' => (int) $product['id'],
                'codigo_producto' => (string) ($product['codigo'] ?? $product['sku'] ?? ''),
                'codigo_barra_usado' => $product['codigo_barra_usado'] ?? ($item['codigo'] ?? null),
                'nombre_producto' => (string) $product['nombre'],
                'cantidad' => $quantity,
                'cantidad_formatted' => $this->formatQuantity($quantity),
                'precio_unitario' => $unitPrice,
                'costo_unitario' => $unitCost,
                'descuento_tipo' => $discount['tipo'],
                'descuento_valor' => $discount['valor'],
                'subtotal' => $subtotal,
                'descuento_total' => $discount['total'],
                'neto' => $taxes['neto'],
                'impuestos_total' => $taxes['impuestos_total'],
                'exento' => $taxes['exento'],
                'total' => $total,
                'margen_estimado' => $margin,
                'comision_vendedor' => $commission,
                'impuestos_detalle' => $taxes['detalle'],
                'controla_stock' => (int) $product['controla_stock'],
            ];
        }

        return $prepared;
    }

    private function cashOpening(int $empresaId, int $sucursalId, array $payload): ?array
    {
        $openingId = (int) ($payload['caja_apertura_id'] ?? 0);

        if ($openingId <= 0) {
            return null;
        }

        $opening = $this->repository->openCashOpening($empresaId, $sucursalId, $openingId);

        if ($opening === null) {
            throw new HttpException('Caja apertura no existe o no esta abierta', 422);
        }

        return $opening;
    }

    private function preparePayments(array $payments): array
    {
        $prepared = [];

        foreach ($payments as $payment) {
            $code = strtoupper(trim((string) ($payment['metodo_pago_codigo'] ?? '')));
            $amount = (int) ($payment['monto'] ?? -1);

            if ($code === '' || $amount < 0) {
                throw new HttpException('Pago inválido', 422);
            }

            $method = $this->repository->activePaymentMethod($code);

            if ($method === null) {
                throw new HttpException('Método de pago no existe o está inactivo', 422);
            }

            $prepared[] = [
                'metodo_pago_id' => (int) $method['id'],
                'metodo_pago_codigo' => $code,
                'monto' => $amount,
                'referencia' => $payment['referencia'] ?? null,
            ];
        }

        return $prepared;
    }

    private function sumTotals(array $items): array
    {
        return [
            'subtotal' => array_sum(array_column($items, 'subtotal')),
            'descuento_total' => array_sum(array_column($items, 'descuento_total')),
            'impuesto_total' => array_sum(array_column($items, 'impuestos_total')),
            'total' => array_sum(array_column($items, 'total')),
            'margen_total' => array_sum(array_column($items, 'margen_estimado')),
            'comision_total' => array_sum(array_column($items, 'comision_vendedor')),
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

    private function auditConfigBlock(int $empresaId, int $sucursalId, int $userId, string $field, string $message): void
    {
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'usuario_id' => $userId,
            'modulo' => 'configuracion',
            'accion' => 'bloqueo_operacion',
            'entidad' => 'empresa_configuracion_operativa',
            'descripcion' => $message,
            'metadata' => [
                'campo' => $field,
                'operacion' => 'venta',
            ],
            'severidad' => 'WARNING',
            'resultado' => 'ERROR',
        ]);
    }
}
