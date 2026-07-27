<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\MyposSubscriptionRepository;
use DateTimeImmutable;
use InvalidArgumentException;

final class MyposSubscriptionService
{
    public function __construct(
        private readonly MyposSubscriptionRepository $repository = new MyposSubscriptionRepository()
    ) {
    }

    public function actualizarMontoRecurrente(int $empresaId, int $montoClp): void
    {
        if ($empresaId <= 0) {
            throw new InvalidArgumentException('Empresa invalida.');
        }
        if ($montoClp < 1 || $montoClp > 100000000) {
            throw new InvalidArgumentException('El monto mensual debe estar entre $1 y $100.000.000.');
        }
        $this->repository->updateRecurringPrice($empresaId, $montoClp);
    }

    public function adelantarFechaPago(int $empresaId, string $fechaPago): void
    {
        if ($empresaId <= 0) {
            throw new InvalidArgumentException('Empresa invalida.');
        }

        $fechaPago = trim($fechaPago);
        $fechaObjetivo = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaPago);
        $erroresFecha = DateTimeImmutable::getLastErrors();
        if (
            $fechaObjetivo === false
            || ($erroresFecha !== false && ($erroresFecha['warning_count'] > 0 || $erroresFecha['error_count'] > 0))
            || $fechaObjetivo->format('Y-m-d') !== $fechaPago
        ) {
            throw new InvalidArgumentException('Selecciona una fecha de pago valida.');
        }

        $hoy = new DateTimeImmutable('today');
        if ($fechaObjetivo < $hoy) {
            throw new InvalidArgumentException('La fecha de pago no puede estar en el pasado.');
        }

        $suscripcion = $this->repository->findByEmpresaId($empresaId);
        if ($suscripcion === null) {
            throw new InvalidArgumentException('La empresa no tiene una suscripcion asociada.');
        }
        if ((string)($suscripcion['estado'] ?? '') !== 'activa') {
            throw new InvalidArgumentException('Solo se puede adelantar el pago de una suscripcion activa.');
        }

        $fechaFinActualRaw = trim((string)($suscripcion['fecha_fin'] ?? ''));
        if ($fechaFinActualRaw === '') {
            throw new InvalidArgumentException('La suscripcion no tiene una fecha de vencimiento valida.');
        }

        try {
            $fechaFinActual = new DateTimeImmutable($fechaFinActualRaw);
        } catch (\Throwable) {
            throw new InvalidArgumentException('La suscripcion no tiene una fecha de vencimiento valida.');
        }
        if ($fechaFinActual < new DateTimeImmutable()) {
            throw new InvalidArgumentException('La suscripcion ya esta vencida y debe regularizarse.');
        }

        $fechaFinActualDia = $fechaFinActual->setTime(0, 0);
        if ($fechaObjetivo >= $fechaFinActualDia) {
            throw new InvalidArgumentException(
                'La nueva fecha debe ser anterior al vencimiento actual (' . $fechaFinActual->format('d/m/Y') . ').'
            );
        }

        $this->repository->advancePaymentDate($empresaId, $fechaObjetivo->format('Y-m-d'));
    }
}
