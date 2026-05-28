<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\DispositivoRepository;

final class DispositivoService
{
    private const TIPOS = ['POS', 'MOVIL', 'WEB', 'OTRO', 'ANDROID', 'WEB_POS', 'CAJA', 'TABLET'];
    private const ESTADOS = ['ACTIVO', 'BLOQUEADO', 'REVOCADO'];

    private DispositivoRepository $repository;

    public function __construct(?DispositivoRepository $repository = null)
    {
        $this->repository = $repository ?? new DispositivoRepository(Database::connection());
    }

    public function registrar(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $sucursalId = $this->optionalPositiveInt($payload['sucursal_id'] ?? null, 'sucursal_id');
        $uuid = trim((string) ($payload['uuid_dispositivo'] ?? ''));
        $nombre = trim((string) ($payload['nombre'] ?? ''));
        $tipo = $this->tipo($payload['tipo'] ?? 'POS');

        $this->validateEmpresa($empresaId);
        if ($sucursalId !== null) {
            $this->validateSucursal($empresaId, $sucursalId);
        }

        if ($uuid === '') {
            throw new HttpException('uuid_dispositivo obligatorio', 422);
        }

        if ($nombre === '') {
            throw new HttpException('nombre obligatorio', 422);
        }

        $existing = $this->repository->findByUuid($empresaId, $uuid);
        if ($existing !== null) {
            $this->repository->update((int) $existing['id'], $empresaId, [
                'sucursal_id' => $sucursalId ?? $existing['sucursal_id'],
                'usuario_id' => $userId,
                'nombre' => $nombre,
                'tipo' => $tipo,
                'estado' => (string) ($existing['estado'] ?? 'ACTIVO'),
                'metadata_json' => $this->json($payload['metadata'] ?? null),
            ]);
            $deviceId = (int) $existing['id'];
        } else {
            $deviceId = $this->repository->create([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'usuario_id' => $userId,
                'uuid_dispositivo' => $uuid,
                'device_uuid' => $uuid,
                'nombre' => $nombre,
                'tipo' => $tipo,
                'estado' => 'ACTIVO',
                'metadata_json' => $this->json($payload['metadata'] ?? null),
            ]);
        }

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'usuario_id' => $userId,
            'dispositivo_id' => $deviceId,
            'modulo' => 'offline',
            'accion' => 'dispositivo.registrar',
            'entidad' => 'dispositivos',
            'entidad_id' => $deviceId,
            'descripcion' => 'Dispositivo registrado para operacion offline',
            'datos_nuevos' => [
                'uuid_dispositivo' => $uuid,
                'nombre' => $nombre,
                'tipo' => $tipo,
                'estado' => 'ACTIVO',
            ],
        ]);

        return [
            'dispositivo_id' => $deviceId,
            'uuid_dispositivo' => $uuid,
            'estado' => (string) ($this->repository->findById($empresaId, $deviceId)['estado'] ?? 'ACTIVO'),
        ];
    }

    public function listar(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateEmpresa($empresaId);

        if (!empty($filters['sucursal_id'])) {
            $this->validateSucursal($empresaId, (int) $filters['sucursal_id']);
        }

        if (!empty($filters['estado'])) {
            $this->estado($filters['estado']);
        }

        return $this->repository->list($empresaId, $filters);
    }

    public function detalle(int $id, int $empresaId): array
    {
        $device = $this->repository->findById($empresaId, $id);

        if ($device === null) {
            throw new HttpException('Dispositivo no encontrado', 404);
        }

        return $device;
    }

    public function actualizar(int $userId, int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $device = $this->detalle($id, $empresaId);
        $sucursalId = $this->optionalPositiveInt($payload['sucursal_id'] ?? $device['sucursal_id'], 'sucursal_id');

        if ($sucursalId !== null) {
            $this->validateSucursal($empresaId, $sucursalId);
        }

        $estado = $this->estado($payload['estado'] ?? $device['estado']);
        $this->repository->update($id, $empresaId, [
            'sucursal_id' => $sucursalId,
            'usuario_id' => $device['usuario_id'],
            'nombre' => trim((string) ($payload['nombre'] ?? $device['nombre'])),
            'tipo' => $this->tipo($payload['tipo'] ?? $device['tipo']),
            'estado' => $estado,
            'metadata_json' => $this->json($payload['metadata'] ?? null),
        ]);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'usuario_id' => $userId,
            'dispositivo_id' => $id,
            'modulo' => 'offline',
            'accion' => 'dispositivo.actualizar',
            'entidad' => 'dispositivos',
            'entidad_id' => $id,
            'descripcion' => 'Dispositivo actualizado',
            'datos_anteriores' => $device,
            'datos_nuevos' => $this->repository->findById($empresaId, $id),
        ]);

        return $this->detalle($id, $empresaId);
    }

    public function cambiarEstado(int $userId, int $id, array $payload, string $estado, string $accion): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $device = $this->detalle($id, $empresaId);
        $metadata = ['motivo' => trim((string) ($payload['motivo'] ?? ''))];

        $this->repository->updateState($id, $empresaId, $estado, $this->json($metadata));

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $device['sucursal_id'] !== null ? (int) $device['sucursal_id'] : null,
            'usuario_id' => $userId,
            'dispositivo_id' => $id,
            'modulo' => 'offline',
            'accion' => $accion,
            'entidad' => 'dispositivos',
            'entidad_id' => $id,
            'descripcion' => 'Estado de dispositivo actualizado',
            'datos_anteriores' => ['estado' => $device['estado']],
            'datos_nuevos' => ['estado' => $estado, 'motivo' => $metadata['motivo']],
            'severidad' => 'WARNING',
        ]);

        return $this->detalle($id, $empresaId);
    }

    private function validateEmpresa(int $empresaId): void
    {
        if ($empresaId <= 0 || !$this->repository->empresaExists($empresaId)) {
            throw new HttpException('Empresa no encontrada', 422);
        }
    }

    private function validateSucursal(int $empresaId, int $sucursalId): void
    {
        if ($sucursalId <= 0 || !$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 422);
        }
    }

    private function positiveInt(array $payload, string $field): int
    {
        $value = (int) ($payload[$field] ?? 0);
        if ($value <= 0) {
            throw new HttpException($field . ' obligatorio', 422);
        }

        return $value;
    }

    private function optionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;
        if ($int <= 0) {
            throw new HttpException($field . ' invalido', 422);
        }

        return $int;
    }

    private function tipo(mixed $value): string
    {
        $type = strtoupper(trim((string) $value));
        if (!in_array($type, self::TIPOS, true)) {
            throw new HttpException('tipo de dispositivo invalido', 422);
        }

        return match ($type) {
            'ANDROID', 'CAJA', 'TABLET' => 'POS',
            'WEB_POS' => 'WEB',
            default => $type,
        };
    }

    private function estado(mixed $value): string
    {
        $state = strtoupper(trim((string) $value));
        if (!in_array($state, self::ESTADOS, true)) {
            throw new HttpException('estado de dispositivo invalido', 422);
        }

        return $state;
    }

    private function json(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
