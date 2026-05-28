<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\CierreDiarioRepository;
use Throwable;

final class CierreDiarioService
{
    private CierreDiarioRepository $repository;

    public function __construct(?CierreDiarioRepository $repository = null)
    {
        $this->repository = $repository ?? new CierreDiarioRepository(Database::connection());
    }

    public function generar(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $sucursalId = $this->positiveInt($payload, 'sucursal_id');
        $date = $this->date((string) ($payload['fecha_cierre'] ?? ''));

        if (!$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 422);
        }

        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();

            $existing = $this->repository->findByDateForUpdate($empresaId, $sucursalId, $date);

            if ($existing !== null && $existing['estado'] === 'CERRADO') {
                throw new HttpException('El día ya se encuentra cerrado', 422);
            }

            $totals = $this->repository->saleTotals($empresaId, $sucursalId, $date);
            $data = [
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'usuario_id' => $userId,
                'fecha_cierre' => $date,
                'total_ventas' => (int) $totals['total_ventas'],
                'total_descuentos' => (int) $totals['total_descuentos'],
                'total_impuestos' => (int) $totals['total_impuestos'],
                'total_margen' => (int) $totals['total_margen'],
                'total_comisiones' => (int) $totals['total_comisiones'],
                'cantidad_ventas' => (int) $totals['cantidad_ventas'],
            ];

            if ($existing === null) {
                $closureId = $this->repository->createClosure($data);
            } else {
                $closureId = (int) $existing['id'];
                $this->repository->updateClosure($closureId, $data);
            }

            $this->repository->clearSummaries($closureId);
            $this->repository->insertPaymentSummaries($closureId, $empresaId, $sucursalId, $date);
            $this->repository->insertProductSummaries($closureId, $empresaId, $sucursalId, $date);
            $this->repository->insertRubroSummaries($closureId, $empresaId, $sucursalId, $date);
            $this->repository->insertUserSummaries($closureId, $empresaId, $sucursalId, $date);

            $connection->commit();

            return [
                'cierre_id' => $closureId,
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'fecha_cierre' => $date,
                'total_ventas' => (int) $data['total_ventas'],
                'cantidad_ventas' => (int) $data['cantidad_ventas'],
                'estado' => 'CERRADO',
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function listar(int $empresaId, int $sucursalId, ?string $from, ?string $to): array
    {
        if ($empresaId <= 0 || $sucursalId <= 0) {
            throw new HttpException('empresa_id y sucursal_id son obligatorios', 422);
        }

        return [
            'cierres' => $this->repository->listClosures(
                $empresaId,
                $sucursalId,
                $from ? $this->date($from) : null,
                $to ? $this->date($to) : null
            ),
        ];
    }

    public function detalle(int $id, int $empresaId): array
    {
        if ($id <= 0 || $empresaId <= 0) {
            throw new HttpException('Parámetros inválidos', 422);
        }

        $closure = $this->repository->detail($id, $empresaId);

        if ($closure === null) {
            throw new HttpException('Cierre no encontrado', 404);
        }

        return [
            'cierre' => $closure,
            'resumen_pagos' => $this->repository->summaryPayments($id),
            'resumen_productos' => $this->repository->summaryProducts($id),
            'resumen_rubros' => $this->repository->summaryRubros($id),
            'resumen_usuarios' => $this->repository->summaryUsers($id),
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

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new HttpException('fecha_cierre inválida', 422);
        }

        return $value;
    }
}
