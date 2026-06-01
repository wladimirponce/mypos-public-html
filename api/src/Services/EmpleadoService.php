<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\EmpleadoRepository;

final class EmpleadoService
{
    private EmpleadoRepository $repository;

    public function __construct(?EmpleadoRepository $repository = null)
    {
        $this->repository = $repository ?? new EmpleadoRepository(Database::connection());
    }

    public function getAll(int $empresaId): array
    {
        return $this->repository->all($empresaId);
    }

    public function create(int $empresaId, array $data): array
    {
        $rut = trim((string) ($data['rut'] ?? ''));
        $nombres = trim((string) ($data['nombres'] ?? ''));
        $apellidos = trim((string) ($data['apellidos'] ?? ''));

        if ($rut === '') {
            throw new HttpException('El RUT es obligatorio', 422);
        }
        if ($nombres === '') {
            throw new HttpException('Los nombres son obligatorios', 422);
        }
        if ($apellidos === '') {
            throw new HttpException('Los apellidos son obligatorios', 422);
        }

        $existing = $this->repository->findByRut($empresaId, $rut);
        if ($existing) {
            throw new HttpException('Ya existe un empleado con este RUT en la empresa', 422);
        }

        $data['empresa_id'] = $empresaId;
        $data['rut'] = $rut;
        $data['nombres'] = $nombres;
        $data['apellidos'] = $apellidos;
        $data['cargo'] = trim((string) ($data['cargo'] ?? ''));
        $data['activo'] = isset($data['activo']) ? (int) $data['activo'] : 1;

        $id = $this->repository->create($data);
        return $this->repository->find($empresaId, $id) ?? [];
    }

    public function update(int $empresaId, int $id, array $data): array
    {
        $empleado = $this->repository->find($empresaId, $id);
        if (!$empleado) {
            throw new HttpException('Empleado no encontrado', 404);
        }

        $rut = trim((string) ($data['rut'] ?? $empleado['rut']));
        $nombres = trim((string) ($data['nombres'] ?? $empleado['nombres']));
        $apellidos = trim((string) ($data['apellidos'] ?? $empleado['apellidos']));

        if ($rut === '' || $nombres === '' || $apellidos === '') {
            throw new HttpException('RUT, nombres y apellidos son obligatorios', 422);
        }

        if ($rut !== $empleado['rut']) {
            $existing = $this->repository->findByRut($empresaId, $rut);
            if ($existing) {
                throw new HttpException('Ya existe otro empleado con este RUT', 422);
            }
        }

        $data['empresa_id'] = $empresaId;
        $data['rut'] = $rut;
        $data['nombres'] = $nombres;
        $data['apellidos'] = $apellidos;
        $data['cargo'] = isset($data['cargo']) ? trim((string) $data['cargo']) : $empleado['cargo'];
        $data['activo'] = isset($data['activo']) ? (int) $data['activo'] : $empleado['activo'];

        $this->repository->update($id, $data);
        return $this->repository->find($empresaId, $id) ?? [];
    }
}
