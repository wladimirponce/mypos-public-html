<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\CajaRepository;
use PDOException;
use Throwable;

final class CajaService
{
    private CajaRepository $repository;

    public function __construct(?CajaRepository $repository = null)
    {
        $this->repository = $repository ?? new CajaRepository(Database::connection());
    }

    public function crearCaja(array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $sucursalId = $this->positiveInt($payload, 'sucursal_id');
        $codigo = $this->text($payload, 'codigo');
        $nombre = $this->text($payload, 'nombre');

        if (!$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 422);
        }

        try {
            $id = $this->repository->createBox([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'codigo' => $codigo,
                'nombre' => $nombre,
            ]);
            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'modulo' => 'caja',
                'accion' => 'crear_caja',
                'entidad' => 'cajas',
                'entidad_id' => $id,
                'descripcion' => 'Caja creada',
                'datos_nuevos' => ['codigo' => $codigo, 'nombre' => $nombre],
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new HttpException('Ya existe una caja con ese codigo en la sucursal', 422);
            }

            throw $exception;
        }

        return ['caja_id' => $id, 'codigo' => $codigo, 'nombre' => $nombre];
    }

    public function listarCajas(int $empresaId, array $filters): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        if (!empty($filters['sucursal_id']) && !$this->repository->sucursalExists($empresaId, (int) $filters['sucursal_id'])) {
            throw new HttpException('Sucursal no encontrada', 422);
        }

        return ['cajas' => $this->repository->listBoxes($empresaId, $filters)];
    }

    public function abrirCaja(int $userId, int $boxId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $sucursalId = $this->positiveInt($payload, 'sucursal_id');
        $initialAmount = $this->nonNegativeInt($payload, 'monto_inicial');
        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();
            $box = $this->repository->findBox($empresaId, $boxId, $sucursalId);

            if ($box === null || (int) $box['activo'] !== 1) {
                throw new HttpException('Caja no encontrada', 422);
            }

            if ($this->repository->findOpenByBoxForUpdate($empresaId, $boxId) !== null) {
                throw new HttpException('La caja ya se encuentra abierta', 422);
            }

            if ($this->repository->findOpenByUserSucursalForUpdate($empresaId, $sucursalId, $userId) !== null) {
                throw new HttpException('El usuario ya tiene una caja abierta en la sucursal', 422);
            }

            $openingId = $this->repository->createOpening([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'caja_id' => $boxId,
                'usuario_id' => $userId,
                'monto_inicial' => $initialAmount,
                'observacion_apertura' => $payload['observacion'] ?? null,
            ]);

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'usuario_id' => $userId,
                'modulo' => 'caja',
                'accion' => 'abrir',
                'entidad' => 'caja_aperturas',
                'entidad_id' => $openingId,
                'descripcion' => 'Caja abierta',
                'datos_nuevos' => [
                    'caja_id' => $boxId,
                    'monto_inicial' => $initialAmount,
                    'estado' => 'ABIERTA',
                ],
            ], $connection);

            $connection->commit();

            return [
                'caja_apertura_id' => $openingId,
                'caja_id' => $boxId,
                'estado' => 'ABIERTA',
                'monto_inicial' => $initialAmount,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function estadoCaja(int $empresaId, int $sucursalId, ?int $boxId): array
    {
        if ($empresaId <= 0 || $sucursalId <= 0) {
            throw new HttpException('empresa_id y sucursal_id son obligatorios', 422);
        }

        if (!$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 422);
        }

        $status = $this->repository->openStatus($empresaId, $sucursalId, $boxId);

        if ($status === null) {
            return ['tiene_caja_abierta' => false];
        }

        return ['tiene_caja_abierta' => true] + $status;
    }

    public function registrarMovimiento(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $openingId = $this->positiveInt($payload, 'caja_apertura_id');
        $type = strtoupper((string) ($payload['tipo'] ?? ''));
        $concept = $this->text($payload, 'concepto');
        $amount = $this->positiveAmount($payload, 'monto');

        if (!in_array($type, ['INGRESO', 'RETIRO'], true)) {
            throw new HttpException('Tipo de movimiento invalido', 422);
        }

        $opening = $this->repository->findOpeningForUpdate($empresaId, $openingId);
        if ($opening === null) {
            throw new HttpException('Apertura de caja no encontrada', 404);
        }

        if ($opening['estado'] !== 'ABIERTA') {
            throw new HttpException('La caja no esta abierta', 422);
        }

        $movementId = $this->repository->insertMovement([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $opening['sucursal_id'],
            'caja_apertura_id' => $openingId,
            'usuario_id' => $userId,
            'tipo' => $type,
            'concepto' => $concept,
            'monto' => $amount,
            'observacion' => $payload['observacion'] ?? null,
        ]);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $opening['sucursal_id'],
            'usuario_id' => $userId,
            'modulo' => 'caja',
            'accion' => 'movimiento',
            'entidad' => 'caja_movimientos',
            'entidad_id' => $movementId,
            'descripcion' => 'Movimiento de caja registrado',
            'datos_nuevos' => [
                'caja_apertura_id' => $openingId,
                'tipo' => $type,
                'concepto' => $concept,
                'monto' => $amount,
            ],
        ], $this->repository->connection());

        return ['movimiento_id' => $movementId, 'tipo' => $type, 'monto' => $amount];
    }

    public function listarMovimientos(int $empresaId, int $boxId, ?int $openingId): array
    {
        if ($empresaId <= 0 || $boxId <= 0) {
            throw new HttpException('empresa_id y caja son obligatorios', 422);
        }

        if ($this->repository->findBox($empresaId, $boxId) === null) {
            throw new HttpException('Caja no encontrada', 404);
        }

        return ['movimientos' => $this->repository->listMovements($empresaId, $boxId, $openingId)];
    }

    public function cerrarCaja(int $userId, int $openingId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $countedAmount = $this->nonNegativeInt($payload, 'monto_contado');
        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();
            $opening = $this->repository->findOpeningForUpdate($empresaId, $openingId);

            if ($opening === null) {
                throw new HttpException('Apertura de caja no encontrada', 404);
            }

            if ($opening['estado'] !== 'ABIERTA') {
                throw new HttpException('La caja ya se encuentra cerrada', 422);
            }

            if ($this->repository->closureExists($empresaId, $openingId)) {
                throw new HttpException('Ya existe cierre para esta apertura', 422);
            }

            $payments = $this->repository->paymentTotals($empresaId, $openingId);
            $movements = $this->repository->movementTotals($empresaId, $openingId);
            $initial = (int) $opening['monto_inicial'];
            $cash = (int) $payments['efectivo'];
            $income = (int) $movements['ingresos'];
            $withdrawals = (int) $movements['retiros'];
            $expected = $initial + $cash + $income - $withdrawals;
            $difference = $countedAmount - $expected;

            $closureId = $this->repository->insertClosure([
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) $opening['sucursal_id'],
                'caja_id' => (int) $opening['caja_id'],
                'caja_apertura_id' => $openingId,
                'apertura_id' => $openingId,
                'usuario_id' => $userId,
                'monto_inicial' => $initial,
                'total_ventas_efectivo' => $cash,
                'total_ventas_tarjeta' => (int) $payments['tarjeta'],
                'total_ventas_transferencia' => (int) $payments['transferencia'],
                'total_ventas_otros' => (int) $payments['otros'],
                'total_ingresos' => $income,
                'total_retiros' => $withdrawals,
                'monto_esperado' => $expected,
                'monto_contado' => $countedAmount,
                'monto_declarado' => $countedAmount,
                'monto_sistema' => $expected,
                'diferencia' => $difference,
                'observacion' => $payload['observacion'] ?? null,
                'observacion_cierre' => $payload['observacion'] ?? null,
            ]);

            $this->repository->markOpeningClosed($empresaId, $openingId);
            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) $opening['sucursal_id'],
                'usuario_id' => $userId,
                'modulo' => 'caja',
                'accion' => 'cerrar',
                'entidad' => 'caja_cierres',
                'entidad_id' => $closureId,
                'descripcion' => 'Caja cerrada',
                'datos_anteriores' => [
                    'caja_apertura_id' => $openingId,
                    'estado' => (string) $opening['estado'],
                ],
                'datos_nuevos' => [
                    'caja_apertura_id' => $openingId,
                    'monto_esperado' => $expected,
                    'monto_contado' => $countedAmount,
                    'diferencia' => $difference,
                ],
            ], $connection);
            $connection->commit();

            return [
                'caja_cierre_id' => $closureId,
                'caja_apertura_id' => $openingId,
                'monto_inicial' => $initial,
                'total_ventas_efectivo' => $cash,
                'total_ingresos' => $income,
                'total_retiros' => $withdrawals,
                'monto_esperado' => $expected,
                'monto_contado' => $countedAmount,
                'diferencia' => $difference,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function listarCierres(int $empresaId, array $filters): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $this->validateOptionalDates($filters);

        return ['cierres' => $this->repository->listClosures($empresaId, $filters)];
    }

    public function detalleCierre(int $empresaId, int $closureId): array
    {
        if ($empresaId <= 0 || $closureId <= 0) {
            throw new HttpException('Parametros invalidos', 422);
        }

        $closure = $this->repository->closureDetail($empresaId, $closureId);
        if ($closure === null) {
            throw new HttpException('Cierre de caja no encontrado', 404);
        }

        $openingId = (int) ($closure['caja_apertura_id'] ?? $closure['apertura_id']);

        return [
            'caja' => [
                'id' => (int) $closure['caja_id'],
                'codigo' => $closure['codigo'],
                'nombre' => $closure['nombre'],
            ],
            'apertura' => [
                'id' => $openingId,
                'fecha_apertura' => $closure['fecha_apertura'],
                'monto_inicial' => (int) $closure['monto_inicial'],
                'observacion_apertura' => $closure['observacion_apertura'],
            ],
            'cierre' => $closure,
            'movimientos' => $this->repository->listMovements($empresaId, (int) $closure['caja_id'], $openingId),
            'resumen_pagos' => $this->repository->paymentSummaryForOpening($empresaId, $openingId),
            'diferencia' => (int) $closure['diferencia'],
        ];
    }

    private function validateOptionalDates(array $filters): void
    {
        foreach (['fecha_desde', 'fecha_hasta'] as $field) {
            if (!empty($filters[$field])) {
                $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $filters[$field]);
                if (!$date || $date->format('Y-m-d') !== $filters[$field]) {
                    throw new HttpException($field . ' invalida', 422);
                }
            }
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

    private function nonNegativeInt(array $data, string $field): int
    {
        if (!isset($data[$field]) || !is_numeric($data[$field]) || (int) $data[$field] < 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} debe ser >= 0"]]);
        }

        return (int) $data[$field];
    }

    private function positiveAmount(array $data, string $field): int
    {
        $amount = $this->nonNegativeInt($data, $field);
        if ($amount <= 0) {
            throw new HttpException('El monto debe ser mayor a 0', 422);
        }

        return $amount;
    }

    private function text(array $data, string $field): string
    {
        $value = trim((string) ($data[$field] ?? ''));
        if ($value === '') {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return $value;
    }
}
