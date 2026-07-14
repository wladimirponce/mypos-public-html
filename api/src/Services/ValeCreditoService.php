<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\ValeCreditoRepository;
use PDO;
use Throwable;

/**
 * Vale de crédito interno: emitido por cambios/devoluciones, envases o
 * manualmente; canjeable como medio de pago VALE en el POS (canje parcial
 * permitido, el saldo queda en el vale).
 */
final class ValeCreditoService
{
    private ValeCreditoRepository $repository;

    public function __construct(?ValeCreditoRepository $repository = null)
    {
        $this->repository = $repository ?? new ValeCreditoRepository(Database::connection());
    }

    /**
     * Emite un vale. Cuando forma parte de otra operación (cambio, venta con
     * canje) debe llamarse con la conexión de esa transacción ya iniciada
     * (via constructor con repository compartido) y $gestionarTransaccion=false.
     */
    public function emitir(int $userId, array $data, bool $gestionarTransaccion = true): array
    {
        $empresaId = (int) ($data['empresa_id'] ?? 0);
        $monto = (int) ($data['monto'] ?? 0);
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        if ($monto <= 0) {
            throw new HttpException('El monto del vale debe ser mayor que cero', 422);
        }

        $origen = strtoupper((string) ($data['origen'] ?? 'MANUAL'));
        if (!in_array($origen, ['CAMBIO', 'ENVASE', 'MANUAL'], true)) {
            throw new HttpException('origen de vale invalido', 422);
        }

        $vencimiento = null;
        if (!empty($data['fecha_vencimiento'])) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $data['fecha_vencimiento']);
            if (!$date || $date->format('Y-m-d') !== (string) $data['fecha_vencimiento']) {
                throw new HttpException('fecha_vencimiento invalida (formato Y-m-d)', 422);
            }
            $vencimiento = $date->format('Y-m-d');
        }

        $connection = $this->repository->connection();
        $ownTransaction = $gestionarTransaccion && !$connection->inTransaction();

        try {
            if ($ownTransaction) {
                $connection->beginTransaction();
            }

            $codigo = $this->generarCodigo($empresaId);
            $valeId = $this->repository->insertar([
                'empresa_id' => $empresaId,
                'sucursal_id' => isset($data['sucursal_id']) && (int) $data['sucursal_id'] > 0 ? (int) $data['sucursal_id'] : null,
                'cliente_id' => isset($data['cliente_id']) && (int) $data['cliente_id'] > 0 ? (int) $data['cliente_id'] : null,
                'codigo' => $codigo,
                'monto_original' => $monto,
                'saldo' => $monto,
                'origen' => $origen,
                'referencia_tipo' => $data['referencia_tipo'] ?? null,
                'referencia_id' => isset($data['referencia_id']) && (int) $data['referencia_id'] > 0 ? (int) $data['referencia_id'] : null,
                'fecha_vencimiento' => $vencimiento,
                'observacion' => $this->nullable($data['observacion'] ?? null),
                'created_by' => $userId,
            ]);

            $this->repository->insertarMovimiento([
                'empresa_id' => $empresaId,
                'vale_id' => $valeId,
                'tipo' => 'EMISION',
                'monto' => $monto,
                'saldo_resultante' => $monto,
                'venta_id' => null,
                'usuario_id' => $userId,
                'observacion' => $origen === 'MANUAL' ? 'Emision manual' : "Emision por {$origen}",
            ]);

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'usuario_id' => $userId,
                'modulo' => 'vales',
                'accion' => 'emitir',
                'entidad' => 'vales_credito',
                'entidad_id' => $valeId,
                'descripcion' => 'Vale de credito emitido',
                'datos_nuevos' => ['codigo' => $codigo, 'monto' => $monto, 'origen' => $origen],
            ], $connection);

            if ($ownTransaction) {
                $connection->commit();
            }

            return ['vale_id' => $valeId, 'codigo' => $codigo, 'monto' => $monto, 'saldo' => $monto];
        } catch (Throwable $exception) {
            if ($ownTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    /** Consulta por código para el POS (valida vigencia y devuelve saldo). */
    public function consultarPorCodigo(int $empresaId, string $codigo): array
    {
        $vale = $this->repository->findPorCodigo($empresaId, $this->normalizarCodigo($codigo));
        if ($vale === null) {
            throw new HttpException('Vale no encontrado', 404);
        }

        return $this->presentar($this->aplicarVencimiento($vale));
    }

    public function detalle(int $empresaId, int $id): array
    {
        $vale = $this->repository->find($empresaId, $id);
        if ($vale === null) {
            throw new HttpException('Vale no encontrado', 404);
        }

        return [
            'vale' => $this->presentar($this->aplicarVencimiento($vale)),
            'movimientos' => $this->repository->movimientos($empresaId, $id),
        ];
    }

    public function listar(int $empresaId, array $filters): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        return ['vales' => array_map(
            fn (array $vale): array => $this->presentar($vale),
            $this->repository->listar($empresaId, $filters)
        )];
    }

    /**
     * Canjea (parcial o total) un vale dentro de una transacción de venta YA
     * INICIADA por el llamador. Lanza 422 si el vale no cubre el monto.
     */
    public function canjearEnVenta(PDO $connection, int $userId, int $empresaId, string $codigo, int $monto, int $ventaId): array
    {
        if ($monto <= 0) {
            throw new HttpException('El monto a canjear debe ser mayor que cero', 422);
        }

        $repository = new ValeCreditoRepository($connection);
        $vale = $repository->findPorCodigo($empresaId, $this->normalizarCodigo($codigo), true);
        if ($vale === null) {
            throw new HttpException("Vale {$codigo} no encontrado", 422);
        }

        $vale = $this->aplicarVencimiento($vale, $repository);
        if ((string) $vale['estado'] !== 'ACTIVO') {
            throw new HttpException("El vale {$codigo} no esta vigente (estado: {$vale['estado']})", 422);
        }
        if ($monto > (int) $vale['saldo']) {
            throw new HttpException(
                "El vale {$codigo} tiene saldo insuficiente (disponible: {$vale['saldo']})",
                422,
                ['vale' => ['Saldo insuficiente']]
            );
        }

        $saldo = (int) $vale['saldo'] - $monto;
        $estado = $saldo === 0 ? 'CANJEADO' : 'ACTIVO';
        $repository->actualizarSaldo((int) $vale['id'], $saldo, $estado);
        $repository->insertarMovimiento([
            'empresa_id' => $empresaId,
            'vale_id' => (int) $vale['id'],
            'tipo' => 'CANJE',
            'monto' => -$monto,
            'saldo_resultante' => $saldo,
            'venta_id' => $ventaId,
            'usuario_id' => $userId,
            'observacion' => 'Canje en venta #' . $ventaId,
        ]);

        return ['vale_id' => (int) $vale['id'], 'codigo' => (string) $vale['codigo'], 'saldo' => $saldo, 'estado' => $estado];
    }

    public function anular(int $userId, int $empresaId, int $id, ?string $motivo): array
    {
        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();
            $vale = $this->repository->find($empresaId, $id);
            if ($vale === null) {
                throw new HttpException('Vale no encontrado', 404);
            }
            if (!in_array((string) $vale['estado'], ['ACTIVO'], true)) {
                throw new HttpException('Solo se puede anular un vale activo', 422);
            }

            $this->repository->actualizarSaldo($id, 0, 'ANULADO');
            $this->repository->insertarMovimiento([
                'empresa_id' => $empresaId,
                'vale_id' => $id,
                'tipo' => 'ANULACION',
                'monto' => -(int) $vale['saldo'],
                'saldo_resultante' => 0,
                'venta_id' => null,
                'usuario_id' => $userId,
                'observacion' => $this->nullable($motivo) ?? 'Anulacion manual',
            ]);

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'usuario_id' => $userId,
                'modulo' => 'vales',
                'accion' => 'anular',
                'entidad' => 'vales_credito',
                'entidad_id' => $id,
                'descripcion' => 'Vale de credito anulado',
                'datos_anteriores' => ['saldo' => (int) $vale['saldo'], 'estado' => (string) $vale['estado']],
                'datos_nuevos' => ['estado' => 'ANULADO', 'motivo' => $motivo],
            ], $connection);

            $connection->commit();

            return ['vale_id' => $id, 'estado' => 'ANULADO'];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    /** Marca VENCIDO en lectura si la fecha pasó (enforcement perezoso, como suscripciones). */
    private function aplicarVencimiento(array $vale, ?ValeCreditoRepository $repository = null): array
    {
        if (
            (string) $vale['estado'] === 'ACTIVO'
            && !empty($vale['fecha_vencimiento'])
            && $vale['fecha_vencimiento'] < date('Y-m-d')
        ) {
            ($repository ?? $this->repository)->actualizarSaldo((int) $vale['id'], (int) $vale['saldo'], 'VENCIDO');
            $vale['estado'] = 'VENCIDO';
        }

        return $vale;
    }

    private function presentar(array $vale): array
    {
        return [
            'id' => (int) $vale['id'],
            'codigo' => (string) $vale['codigo'],
            'monto_original' => (int) $vale['monto_original'],
            'saldo' => (int) $vale['saldo'],
            'estado' => (string) $vale['estado'],
            'origen' => (string) $vale['origen'],
            'cliente_id' => isset($vale['cliente_id']) && $vale['cliente_id'] !== null ? (int) $vale['cliente_id'] : null,
            'cliente_nombre' => $vale['cliente_nombre'] ?? null,
            'cliente_rut' => $vale['cliente_rut'] ?? null,
            'referencia_tipo' => $vale['referencia_tipo'] ?? null,
            'referencia_id' => isset($vale['referencia_id']) && $vale['referencia_id'] !== null ? (int) $vale['referencia_id'] : null,
            'fecha_vencimiento' => $vale['fecha_vencimiento'] ?? null,
            'observacion' => $vale['observacion'] ?? null,
            'created_at' => $vale['created_at'] ?? null,
        ];
    }

    /** Código corto legible: V + 7 caracteres sin ambiguos (0/O, 1/I). */
    private function generarCodigo(int $empresaId): string
    {
        $chars = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        for ($try = 0; $try < 10; $try++) {
            $codigo = 'V';
            for ($i = 0; $i < 7; $i++) {
                $codigo .= $chars[random_int(0, strlen($chars) - 1)];
            }
            if (!$this->repository->codigoExiste($empresaId, $codigo)) {
                return $codigo;
            }
        }

        throw new HttpException('No se pudo generar un codigo de vale unico', 500);
    }

    private function normalizarCodigo(string $codigo): string
    {
        return strtoupper(trim($codigo));
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
