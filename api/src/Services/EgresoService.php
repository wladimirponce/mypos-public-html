<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\CajaRepository;
use Mypos\Repositories\EgresoRepository;
use Throwable;

final class EgresoService
{
    private EgresoRepository $repository;
    private CajaRepository $cajas;

    public function __construct()
    {
        $connection = Database::connection();
        $this->repository = new EgresoRepository($connection);
        $this->cajas = new CajaRepository($connection);
    }

    public function list(int $empresaId, array $filters): array
    {
        if ($empresaId <= 0) throw new HttpException('empresa_id obligatorio', 422);
        $this->assertSchemaReady();
        return ['egresos' => $this->repository->list($empresaId, $filters)];
    }

    public function create(int $userId, array $payload): array
    {
        $this->assertSchemaReady();
        $empresaId = $this->positive($payload, 'empresa_id');
        $sucursalId = $this->positive($payload, 'sucursal_id');
        $monto = $this->positive($payload, 'monto');
        $quienRecibe = trim((string) ($payload['quien_recibe'] ?? ''));
        $motivo = trim((string) ($payload['motivo'] ?? ''));
        $fecha = (string) ($payload['fecha_egreso'] ?? date('Y-m-d'));
        if ($quienRecibe === '' || $motivo === '') throw new HttpException('Quien recibe y motivo son obligatorios', 422);
        if (!DateTimeImmutable::createFromFormat('Y-m-d', $fecha)) throw new HttpException('Fecha de egreso invalida', 422);

        $connection = $this->repository->connection();
        try {
            $connection->beginTransaction();
            $openingId = $this->positive($payload, 'caja_apertura_id');
            $opening = $this->cajas->findOpeningForUpdate($empresaId, $openingId);
            if ($opening === null || $opening['estado'] !== 'ABIERTA' || (int) $opening['sucursal_id'] !== $sucursalId) {
                throw new HttpException('Para registrar un egreso debe existir una caja abierta en la sucursal', 422);
            }
            $id = $this->repository->insert([
                'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId, 'caja_apertura_id' => $openingId,
                'usuario_id' => $userId, 'fecha_egreso' => $fecha, 'quien_recibe' => $quienRecibe,
                'motivo' => $motivo, 'descripcion' => $motivo, 'monto' => $monto,
            ]);
            $movementId = $this->cajas->insertMovement([
                'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId, 'caja_apertura_id' => $openingId,
                'usuario_id' => $userId, 'tipo' => 'RETIRO', 'concepto' => 'Egreso: ' . $motivo, 'monto' => $monto,
                'observacion' => 'Recibe: ' . $quienRecibe, 'referencia_tipo' => 'EGRESO', 'referencia_id' => $id,
            ]);
            $this->repository->linkMovement($id, $movementId);
            AuditoriaService::registrarEvento(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId, 'usuario_id' => $userId,
                'modulo' => 'egresos', 'accion' => 'crear', 'entidad' => 'egresos', 'entidad_id' => $id,
                'descripcion' => 'Egreso registrado', 'datos_nuevos' => ['monto' => $monto, 'quien_recibe' => $quienRecibe, 'motivo' => $motivo]], $connection);
            $connection->commit();
            return ['egreso_id' => $id, 'monto' => $monto, 'metodo_pago_codigo' => 'EFECTIVO'];
        } catch (Throwable $e) {
            if ($connection->inTransaction()) $connection->rollBack();
            throw $e;
        }
    }

    public function cancel(int $userId, int $id, array $payload): array
    {
        $this->assertSchemaReady();
        $empresaId = $this->positive($payload, 'empresa_id');
        $reason = trim((string) ($payload['motivo'] ?? ''));
        if ($reason === '') throw new HttpException('El motivo de anulacion es obligatorio', 422);
        $connection = $this->repository->connection();
        try {
            $connection->beginTransaction();
            $expense = $this->repository->findForUpdate($empresaId, $id);
            if ($expense === null) throw new HttpException('Egreso no encontrado', 404);
            if ($expense['estado'] === 'ANULADO') throw new HttpException('El egreso ya esta anulado', 422);
            $opening = $this->cajas->findOpeningForUpdate($empresaId, (int) $expense['caja_apertura_id']);
            if ($opening === null || $opening['estado'] !== 'ABIERTA') throw new HttpException('No se puede anular un egreso despues de cerrar su caja', 422);
            $this->cajas->insertMovement([
                'empresa_id' => $empresaId, 'sucursal_id' => (int) $expense['sucursal_id'],
                'caja_apertura_id' => (int) $expense['caja_apertura_id'], 'usuario_id' => $userId, 'tipo' => 'INGRESO',
                'concepto' => 'Reversa egreso: ' . ($expense['motivo'] ?? $expense['descripcion']), 'monto' => (int) $expense['monto'],
                'observacion' => $reason, 'referencia_tipo' => 'EGRESO_ANULACION', 'referencia_id' => $id,
            ]);
            $this->repository->cancel($id, $userId, $reason);
            AuditoriaService::registrarEvento(['empresa_id' => $empresaId, 'sucursal_id' => (int) $expense['sucursal_id'], 'usuario_id' => $userId,
                'modulo' => 'egresos', 'accion' => 'anular', 'entidad' => 'egresos', 'entidad_id' => $id, 'descripcion' => 'Egreso anulado'], $connection);
            $connection->commit();
            return ['egreso_id' => $id, 'estado' => 'ANULADO'];
        } catch (Throwable $e) {
            if ($connection->inTransaction()) $connection->rollBack();
            throw $e;
        }
    }

    private function positive(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);
        if ($value <= 0) throw new HttpException("El campo {$field} es obligatorio", 422);
        return $value;
    }

    private function assertSchemaReady(): void
    {
        if (!$this->repository->schemaReady()) {
            throw new HttpException('El modulo Egresos requiere aplicar las migraciones 046_egresos_caja y 047_egresos_solo_efectivo.', 503);
        }
    }
}
