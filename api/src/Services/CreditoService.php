<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\CreditoRepository;
use Throwable;

final class CreditoService
{
    private const ESTADOS = ['PENDIENTE', 'PARCIAL', 'PAGADO', 'ANULADO'];

    public function __construct(private ?CreditoRepository $repository = null)
    {
        $this->repository ??= new CreditoRepository(Database::connection());
    }

    public function validarClienteParaVenta(int $empresaId, int $clienteId, int $saldoNuevo): array
    {
        $client = $this->repository->clienteCredito($empresaId, $clienteId);
        if ($client === null || (int) $client['activo'] !== 1) {
            throw new HttpException('Cliente no encontrado', 422);
        }
        if ((int) $client['permite_credito'] !== 1) {
            throw new HttpException('El cliente no tiene credito habilitado', 422);
        }
        $limit = (int) $client['limite_credito'];
        if ($limit > 0 && ($this->repository->deudaPendienteCliente($empresaId, $clienteId) + $saldoNuevo) > $limit) {
            throw new HttpException('El limite de credito del cliente fue excedido', 422);
        }
        return $client;
    }

    public function crearDesdeVenta(array $data): int
    {
        if ((int) $data['monto_original'] <= 0) {
            throw new HttpException('El saldo de credito debe ser mayor que cero', 422);
        }
        $creditoId = $this->repository->crearCredito([
            'empresa_id' => (int) $data['empresa_id'],
            'sucursal_id' => (int) $data['sucursal_id'],
            'cliente_id' => (int) $data['cliente_id'],
            'venta_id' => (int) $data['venta_id'],
            'monto_original' => (int) $data['monto_original'],
            'saldo_pendiente' => (int) $data['monto_original'],
            'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'created_by_usuario_id' => (int) $data['created_by_usuario_id'],
        ]);

        AuditoriaService::registrarEvento([
            'empresa_id' => (int) $data['empresa_id'],
            'sucursal_id' => (int) $data['sucursal_id'],
            'usuario_id' => (int) $data['created_by_usuario_id'],
            'modulo' => 'creditos',
            'accion' => 'crear',
            'entidad' => 'creditos_clientes',
            'entidad_id' => $creditoId,
            'descripcion' => 'Credito de cliente creado desde venta',
            'datos_nuevos' => [
                'cliente_id' => (int) $data['cliente_id'],
                'venta_id' => (int) $data['venta_id'],
                'monto_original' => (int) $data['monto_original'],
                'saldo_pendiente' => (int) $data['monto_original'],
                'estado' => 'PENDIENTE',
            ],
        ], $this->repository->connection());

        return $creditoId;
    }

    public function listar(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateFilters($filters);
        return $this->repository->list($empresaId, $filters);
    }

    public function detalle(int $id, int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $credit = $this->repository->find($empresaId, $id);
        if ($credit === null) {
            throw new HttpException('Credito no encontrado', 404);
        }
        return [
            'credito' => $credit,
            'cliente' => $this->repository->detalleCliente($empresaId, (int) $credit['cliente_id']),
            'venta' => $this->repository->venta($empresaId, (int) $credit['venta_id']),
            'pagos' => $this->repository->pagos($empresaId, $id),
        ];
    }

    public function pagar(int $userId, int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $amount = $this->positiveMoney($payload['monto'] ?? 0, 'monto');
        $methodId = $this->positiveInt($payload, 'metodo_pago_id');
        $openingId = isset($payload['caja_apertura_id']) && $payload['caja_apertura_id'] !== ''
            ? (int) $payload['caja_apertura_id']
            : null;
        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();
            $credit = $this->repository->findForUpdate($empresaId, $id);
            if ($credit === null) {
                throw new HttpException('Credito no encontrado', 404);
            }
            if (!in_array((string) $credit['estado'], ['PENDIENTE', 'PARCIAL'], true)) {
                throw new HttpException('El credito no acepta pagos en su estado actual', 422);
            }
            if ($amount > (int) $credit['saldo_pendiente']) {
                throw new HttpException('El pago no puede superar el saldo pendiente', 422);
            }
            if ($this->repository->metodoPagoActivo($methodId) === null) {
                throw new HttpException('Metodo de pago no existe o esta inactivo', 422);
            }
            if ($openingId !== null && $this->repository->cajaAbierta($empresaId, (int) $credit['sucursal_id'], $openingId) === null) {
                throw new HttpException('Caja apertura no existe o no esta abierta', 422);
            }
            $paymentId = $this->repository->insertarPago([
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) $credit['sucursal_id'],
                'credito_cliente_id' => $id,
                'cliente_id' => (int) $credit['cliente_id'],
                'usuario_id' => $userId,
                'caja_apertura_id' => $openingId,
                'metodo_pago_id' => $methodId,
                'monto' => $amount,
                'observacion' => $this->nullable($payload['observacion'] ?? null),
            ]);
            $paid = (int) $credit['monto_pagado'] + $amount;
            $balance = (int) $credit['monto_original'] - $paid;
            $state = $balance === 0 ? 'PAGADO' : 'PARCIAL';
            $this->repository->actualizarSaldo($id, $paid, $balance, $state);
            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) $credit['sucursal_id'],
                'usuario_id' => $userId,
                'modulo' => 'creditos',
                'accion' => 'pagar',
                'entidad' => 'creditos_clientes',
                'entidad_id' => $id,
                'descripcion' => 'Pago de credito registrado',
                'datos_anteriores' => [
                    'monto_pagado' => (int) $credit['monto_pagado'],
                    'saldo_pendiente' => (int) $credit['saldo_pendiente'],
                    'estado' => (string) $credit['estado'],
                ],
                'datos_nuevos' => [
                    'pago_id' => $paymentId,
                    'monto_pago' => $amount,
                    'monto_pagado' => $paid,
                    'saldo_pendiente' => $balance,
                    'estado' => $state,
                    'caja_apertura_id' => $openingId,
                    'metodo_pago_id' => $methodId,
                ],
            ], $connection);
            $connection->commit();

            return [
                'credito_cliente_id' => $id,
                'pago_id' => $paymentId,
                'monto_pagado' => $paid,
                'saldo_pendiente' => $balance,
                'estado' => $state,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function anularPendientePorVenta(int $empresaId, int $ventaId): void
    {
        $this->repository->marcarAnuladoPorVenta($empresaId, $ventaId);
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'modulo' => 'creditos',
            'accion' => 'anular_por_venta',
            'entidad' => 'creditos_clientes',
            'descripcion' => 'Credito pendiente anulado por anulacion de venta',
            'metadata' => ['venta_id' => $ventaId],
        ], $this->repository->connection());
    }

    public function estadoCuenta(int $clienteId, array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateDateRange($filters);
        $client = $this->repository->detalleCliente($empresaId, $clienteId);
        if ($client === null) {
            throw new HttpException('Cliente no encontrado', 404);
        }
        $filters['cliente_id'] = $clienteId;
        return [
            'cliente' => ['id' => (int) $client['id'], 'nombre' => (string) $client['nombre'], 'rut' => $client['rut']],
            'resumen' => array_map('intval', $this->repository->resumenCliente($empresaId, $clienteId)),
            'creditos' => $this->repository->list($empresaId, $filters),
            'pagos' => $this->repository->pagosCliente($empresaId, $clienteId, $filters),
        ];
    }

    public function historialCliente(int $clienteId, array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateDateRange($filters);
        $client = $this->repository->detalleCliente($empresaId, $clienteId);
        if ($client === null) {
            throw new HttpException('Cliente no encontrado', 404);
        }
        $filters['cliente_id'] = $clienteId;
        return [
            'cliente' => ['id' => (int) $client['id'], 'nombre' => (string) $client['nombre'], 'rut' => $client['rut']],
            'ventas' => $this->repository->ventasCliente($empresaId, $clienteId, $filters),
            'documentos' => $this->repository->documentosCliente($empresaId, $clienteId, $filters),
            'creditos' => $this->repository->list($empresaId, $filters),
            'pagos' => $this->repository->pagosCliente($empresaId, $clienteId, $filters),
        ];
    }

    private function validateFilters(array $filters): void
    {
        $this->validateDateRange($filters);
        if (!empty($filters['estado']) && !in_array(strtoupper((string) $filters['estado']), self::ESTADOS, true)) {
            throw new HttpException('estado invalido', 422);
        }
    }

    private function validateDateRange(array $filters): void
    {
        foreach (['fecha_desde', 'fecha_hasta'] as $field) {
            if (!empty($filters[$field])) {
                $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $filters[$field]);
                if (!$date || $date->format('Y-m-d') !== (string) $filters[$field]) {
                    throw new HttpException('Formato de fecha invalido', 422);
                }
            }
        }
        if (!empty($filters['fecha_desde']) && !empty($filters['fecha_hasta']) && $filters['fecha_desde'] > $filters['fecha_hasta']) {
            throw new HttpException('fecha_desde no puede ser mayor que fecha_hasta', 422);
        }
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);
        if ($value <= 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }
        return $value;
    }

    private function positiveMoney(mixed $value, string $field): int
    {
        if (!is_numeric($value) || (int) $value <= 0) {
            throw new HttpException("{$field} debe ser mayor que 0", 422);
        }
        return (int) $value;
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
