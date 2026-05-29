<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\EmpresaRepository;

final class EmpresaService
{
    private EmpresaRepository $repository;

    public function __construct(?EmpresaRepository $repository = null)
    {
        $this->repository = $repository ?? new EmpresaRepository(Database::connection());
    }

    public function listarEmpresas(): array
    {
        return ['empresas' => $this->repository->listEmpresas()];
    }

    public function obtenerEmpresa(int $id): array
    {
        $empresa = $this->repository->getEmpresaById($id);
        if ($empresa === null) {
            throw new HttpException('Empresa no encontrada', 404);
        }

        return $empresa;
    }

    public function crearEmpresa(array $data): array
    {
        $rut = $this->validateRut($data['rut'] ?? '');
        $razonSocial = trim($data['razon_social'] ?? '');
        $nombreFantasia = trim($data['nombre_fantasia'] ?? '');

        if ($razonSocial === '') {
            throw new HttpException('La razón social es obligatoria', 422, ['razon_social' => ['La razón social es obligatoria']]);
        }
        if ($nombreFantasia === '') {
            $nombreFantasia = $razonSocial;
        }

        if ($this->repository->findEmpresaByRut($rut) !== null) {
            throw new HttpException('Ya existe una empresa registrada con ese RUT', 422, ['rut' => ['Ya existe una empresa registrada con ese RUT']]);
        }

        $giro = $this->nullable($data['giro'] ?? null);
        $email = $this->nullable($data['email'] ?? null);
        $telefono = $this->nullable($data['telefono'] ?? null);
        $direccion = $this->nullable($data['direccion'] ?? null);
        $comuna = $this->nullable($data['comuna'] ?? null);
        $ciudad = $this->nullable($data['ciudad'] ?? null);
        $activo = isset($data['activo']) ? ((bool) $data['activo'] ? 1 : 0) : 1;

        $id = $this->repository->createEmpresa(
            $rut,
            $razonSocial,
            $nombreFantasia,
            $giro,
            $email,
            $telefono,
            $direccion,
            $comuna,
            $ciudad,
            $activo
        );

        return $this->obtenerEmpresa($id);
    }

    public function actualizarEmpresa(int $id, array $data): array
    {
        $empresa = $this->obtenerEmpresa($id);

        $razonSocial = trim($data['razon_social'] ?? $empresa['razon_social']);
        $nombreFantasia = trim($data['nombre_fantasia'] ?? $empresa['nombre_fantasia']);

        if ($razonSocial === '') {
            throw new HttpException('La razón social es obligatoria', 422, ['razon_social' => ['La razón social es obligatoria']]);
        }

        $giro = $this->nullable($data['giro'] ?? $empresa['giro']);
        $email = $this->nullable($data['email'] ?? $empresa['email']);
        $telefono = $this->nullable($data['telefono'] ?? $empresa['telefono']);
        $direccion = $this->nullable($data['direccion'] ?? $empresa['direccion']);
        $comuna = $this->nullable($data['comuna'] ?? $empresa['comuna']);
        $ciudad = $this->nullable($data['ciudad'] ?? $empresa['ciudad']);
        $activo = isset($data['activo']) ? ((bool) $data['activo'] ? 1 : 0) : (int) $empresa['activo'];

        $this->repository->updateEmpresa(
            $id,
            $razonSocial,
            $nombreFantasia,
            $giro,
            $email,
            $telefono,
            $direccion,
            $comuna,
            $ciudad,
            $activo
        );

        return $this->obtenerEmpresa($id);
    }

    public function desactivarEmpresa(int $id, int $currentEmpresaId): void
    {
        $this->obtenerEmpresa($id);

        if ($id === $currentEmpresaId) {
            throw new HttpException('No se puede desactivar o eliminar la empresa activa en la que estás operando', 422);
        }

        $this->repository->deleteEmpresa($id);
    }

    public function listarSucursales(int $empresaId): array
    {
        $this->obtenerEmpresa($empresaId);

        return ['sucursales' => $this->repository->listSucursales($empresaId)];
    }

    public function obtenerSucursal(int $id): array
    {
        $sucursal = $this->repository->getSucursalById($id);
        if ($sucursal === null) {
            throw new HttpException('Sucursal no encontrada', 404);
        }

        return $sucursal;
    }

    public function crearSucursal(int $empresaId, array $data): array
    {
        $this->obtenerEmpresa($empresaId);

        $nombre = trim($data['nombre'] ?? '');
        $codigo = strtoupper(trim($data['codigo'] ?? ''));

        if ($nombre === '') {
            throw new HttpException('El nombre es obligatorio', 422, ['nombre' => ['El nombre es obligatorio']]);
        }
        if ($codigo === '') {
            throw new HttpException('El código es obligatorio', 422, ['codigo' => ['El código es obligatorio']]);
        }

        if ($this->repository->findSucursalByCodigo($empresaId, $codigo) !== null) {
            throw new HttpException('Ya existe una sucursal con ese código en esta empresa', 422, ['codigo' => ['Ya existe una sucursal con ese código en esta empresa']]);
        }

        $direccion = $this->nullable($data['direccion'] ?? null);
        $comuna = $this->nullable($data['comuna'] ?? null);
        $ciudad = $this->nullable($data['ciudad'] ?? null);
        $telefono = $this->nullable($data['telefono'] ?? null);
        $activo = isset($data['activo']) ? ((bool) $data['activo'] ? 1 : 0) : 1;

        $id = $this->repository->createSucursal(
            $empresaId,
            $nombre,
            $codigo,
            $direccion,
            $comuna,
            $ciudad,
            $telefono,
            $activo
        );

        return $this->obtenerSucursal($id);
    }

    public function actualizarSucursal(int $id, array $data): array
    {
        $sucursal = $this->obtenerSucursal($id);
        $empresaId = (int) $sucursal['empresa_id'];

        $nombre = trim($data['nombre'] ?? $sucursal['nombre']);
        $codigo = strtoupper(trim($data['codigo'] ?? $sucursal['codigo']));

        if ($nombre === '') {
            throw new HttpException('El nombre es obligatorio', 422, ['nombre' => ['El nombre es obligatorio']]);
        }
        if ($codigo === '') {
            throw new HttpException('El código es obligatorio', 422, ['codigo' => ['El código es obligatorio']]);
        }

        $existente = $this->repository->findSucursalByCodigo($empresaId, $codigo);
        if ($existente !== null && (int) $existente['id'] !== $id) {
            throw new HttpException('Ya existe otra sucursal con ese código en esta empresa', 422, ['codigo' => ['Ya existe otra sucursal con ese código en esta empresa']]);
        }

        $direccion = $this->nullable($data['direccion'] ?? $sucursal['direccion']);
        $comuna = $this->nullable($data['comuna'] ?? $sucursal['comuna']);
        $ciudad = $this->nullable($data['ciudad'] ?? $sucursal['ciudad']);
        $telefono = $this->nullable($data['telefono'] ?? $sucursal['telefono']);
        $activo = isset($data['activo']) ? ((bool) $data['activo'] ? 1 : 0) : (int) $sucursal['activo'];

        // Si desactivamos la sucursal, validar que no sea la única activa de la empresa
        if ($activo === 0 && (int) $sucursal['activo'] === 1) {
            if ($this->repository->countSucursalesActivas($empresaId) <= 1) {
                throw new HttpException('No se puede desactivar la única sucursal activa de la empresa', 422, ['activo' => ['No se puede desactivar la única sucursal activa de la empresa']]);
            }
        }

        $this->repository->updateSucursal(
            $id,
            $nombre,
            $codigo,
            $direccion,
            $comuna,
            $ciudad,
            $telefono,
            $activo
        );

        return $this->obtenerSucursal($id);
    }

    public function desactivarSucursal(int $id): void
    {
        $sucursal = $this->obtenerSucursal($id);
        $empresaId = (int) $sucursal['empresa_id'];

        if ($this->repository->countSucursalesActivas($empresaId) <= 1) {
            throw new HttpException('No se puede eliminar la única sucursal de la empresa', 422);
        }

        $this->repository->deleteSucursal($id);
    }

    public function listarCajas(int $empresaId, ?int $sucursalId = null): array
    {
        $this->obtenerEmpresa($empresaId);

        return ['cajas' => $this->repository->listCajas($empresaId, $sucursalId)];
    }

    public function obtenerCaja(int $id): array
    {
        $caja = $this->repository->getCajaById($id);
        if ($caja === null) {
            throw new HttpException('Caja no encontrada', 404);
        }

        return $caja;
    }

    public function crearCaja(int $empresaId, array $data): array
    {
        $this->obtenerEmpresa($empresaId);

        $sucursalId = (int) ($data['sucursal_id'] ?? 0);
        $nombre = trim($data['nombre'] ?? '');
        $codigo = strtoupper(trim($data['codigo'] ?? ''));

        if ($sucursalId <= 0) {
            throw new HttpException('La sucursal es obligatoria', 422, ['sucursal_id' => ['La sucursal es obligatoria']]);
        }
        $this->obtenerSucursal($sucursalId);

        if ($nombre === '') {
            throw new HttpException('El nombre es obligatorio', 422, ['nombre' => ['El nombre es obligatorio']]);
        }
        if ($codigo === '') {
            throw new HttpException('El código es obligatorio', 422, ['codigo' => ['El código es obligatorio']]);
        }

        if ($this->repository->findCajaByCodigo($sucursalId, $codigo) !== null) {
            throw new HttpException('Ya existe una caja con ese código en esta sucursal', 422, ['codigo' => ['Ya existe una caja con ese código en esta sucursal']]);
        }

        $activo = isset($data['activo']) ? ((bool) $data['activo'] ? 1 : 0) : 1;

        $id = $this->repository->createCaja($empresaId, $sucursalId, $nombre, $codigo, $activo);

        return $this->obtenerCaja($id);
    }

    public function actualizarCaja(int $id, array $data): array
    {
        $caja = $this->obtenerCaja($id);
        $sucursalId = (int) $caja['sucursal_id'];

        $nombre = trim($data['nombre'] ?? $caja['nombre']);
        $codigo = strtoupper(trim($data['codigo'] ?? $caja['codigo']));

        if ($nombre === '') {
            throw new HttpException('El nombre es obligatorio', 422, ['nombre' => ['El nombre es obligatorio']]);
        }
        if ($codigo === '') {
            throw new HttpException('El código es obligatorio', 422, ['codigo' => ['El código es obligatorio']]);
        }

        $existente = $this->repository->findCajaByCodigo($sucursalId, $codigo);
        if ($existente !== null && (int) $existente['id'] !== $id) {
            throw new HttpException('Ya existe otra caja con ese código en esta sucursal', 422, ['codigo' => ['Ya existe otra caja con ese código en esta sucursal']]);
        }

        $activo = isset($data['activo']) ? ((bool) $data['activo'] ? 1 : 0) : (int) $caja['activo'];

        $this->repository->updateCaja($id, $nombre, $codigo, $activo);

        return $this->obtenerCaja($id);
    }

    public function desactivarCaja(int $id): void
    {
        $this->obtenerCaja($id);
        $this->repository->deleteCaja($id);
    }

    public function listarUsuarios(int $empresaId): array
    {
        $this->obtenerEmpresa($empresaId);

        return ['usuarios' => $this->repository->listUsuariosEmpresa($empresaId)];
    }

    public function buscarUsuariosGlobales(string $q): array
    {
        $query = trim($q);
        if (strlen($query) < 2) {
            return ['usuarios' => []];
        }

        return ['usuarios' => $this->repository->buscarUsuariosGlobales($query)];
    }

    public function asociarUsuario(int $empresaId, array $data): array
    {
        $this->obtenerEmpresa($empresaId);

        $usuarioId = (int) ($data['usuario_id'] ?? 0);
        $rolId = (int) ($data['rol_id'] ?? 0);
        $sucursalId = isset($data['sucursal_principal_id']) && $data['sucursal_principal_id'] !== '' ? (int) $data['sucursal_principal_id'] : null;

        if ($usuarioId <= 0) {
            throw new HttpException('El usuario es obligatorio', 422, ['usuario_id' => ['El usuario es obligatorio']]);
        }
        if ($rolId <= 0) {
            throw new HttpException('El rol es obligatorio', 422, ['rol_id' => ['El rol es obligatorio']]);
        }

        if ($this->repository->checkUsuarioPertenencia($empresaId, $usuarioId)) {
            throw new HttpException('El usuario ya pertenece a esta empresa', 422, ['usuario_id' => ['El usuario ya pertenece a esta empresa']]);
        }

        if ($sucursalId !== null && $sucursalId > 0) {
            $this->obtenerSucursal($sucursalId);
        }

        $activo = isset($data['activo']) ? ((bool) $data['activo'] ? 1 : 0) : 1;

        $this->repository->asociarUsuarioEmpresa($empresaId, $usuarioId, $rolId, $sucursalId, $activo);

        return ['success' => true];
    }

    public function actualizarUsuario(int $empresaId, int $usuarioId, array $data): array
    {
        $this->obtenerEmpresa($empresaId);

        if (!$this->repository->checkUsuarioPertenencia($empresaId, $usuarioId)) {
            throw new HttpException('El usuario no pertenece a esta empresa', 404);
        }

        $rolId = (int) ($data['rol_id'] ?? 0);
        $sucursalId = isset($data['sucursal_principal_id']) && $data['sucursal_principal_id'] !== '' ? (int) $data['sucursal_principal_id'] : null;

        if ($rolId <= 0) {
            throw new HttpException('El rol es obligatorio', 422, ['rol_id' => ['El rol es obligatorio']]);
        }

        if ($sucursalId !== null && $sucursalId > 0) {
            $this->obtenerSucursal($sucursalId);
        }

        $activo = isset($data['activo']) ? ((bool) $data['activo'] ? 1 : 0) : 1;

        // Auto-protección del último administrador
        if ($activo === 0) {
            if ($this->repository->countEmpresaAdministradores($empresaId) <= 1) {
                // Verificar si el usuario que estamos desactivando es administrador de la empresa
                $usuarios = $this->repository->listUsuariosEmpresa($empresaId);
                $usuarioItem = null;
                foreach ($usuarios as $u) {
                    if ((int) $u['usuario_id'] === $usuarioId) {
                        $usuarioItem = $u;
                        break;
                    }
                }
                if ($usuarioItem !== null && in_array($usuarioItem['rol_codigo'], ['SUPER_ADMIN', 'ADMIN_EMPRESA'], true)) {
                    throw new HttpException('No se puede desactivar al último administrador de la empresa', 422, ['activo' => ['No se puede desactivar al último administrador de la empresa']]);
                }
            }
        }

        $this->repository->updateUsuarioEmpresa($empresaId, $usuarioId, $rolId, $sucursalId, $activo);

        return ['success' => true];
    }

    public function removerUsuario(int $empresaId, int $usuarioId, int $currentUserId): void
    {
        $this->obtenerEmpresa($empresaId);

        if (!$this->repository->checkUsuarioPertenencia($empresaId, $usuarioId)) {
            throw new HttpException('El usuario no pertenece a esta empresa', 404);
        }

        // Auto-protección del último administrador de la empresa
        if ($this->repository->countEmpresaAdministradores($empresaId) <= 1) {
            $usuarios = $this->repository->listUsuariosEmpresa($empresaId);
            $usuarioItem = null;
            foreach ($usuarios as $u) {
                if ((int) $u['usuario_id'] === $usuarioId) {
                    $usuarioItem = $u;
                    break;
                }
            }
            if ($usuarioItem !== null && in_array($usuarioItem['rol_codigo'], ['SUPER_ADMIN', 'ADMIN_EMPRESA'], true)) {
                throw new HttpException('No se puede quitar al último administrador de la empresa', 422);
            }
        }

        $this->repository->removerUsuarioEmpresa($empresaId, $usuarioId);
    }

    private function validateRut(string $rut): string
    {
        $clean = str_replace(['.', '-'], '', strtoupper(trim($rut)));
        if (strlen($clean) < 8) {
            throw new HttpException('Formato de RUT invalido', 422, ['rut' => ['Formato de RUT invalido']]);
        }

        $dv = substr($clean, -1);
        $num = substr($clean, 0, -1);

        if (!is_numeric($num)) {
            throw new HttpException('El RUT ingresado no es valido', 422, ['rut' => ['El RUT ingresado no es valido']]);
        }

        $factor = 2;
        $suma = 0;
        $strNum = strrev($num);
        for ($i = 0; $i < strlen($strNum); $i++) {
            $suma += (int)$strNum[$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }

        $resto = $suma % 11;
        $dvEsperado = 11 - $resto;

        if ($dvEsperado === 11) {
            $dvCalculado = '0';
        } elseif ($dvEsperado === 10) {
            $dvCalculado = 'K';
        } else {
            $dvCalculado = (string) $dvEsperado;
        }

        if ($dv !== $dvCalculado) {
            throw new HttpException('El RUT ingresado no es valido (Digito Verificador incorrecto)', 422, ['rut' => ['El RUT ingresado no es valido (Digito Verificador incorrecto)']]);
        }

        return $num . '-' . $dv;
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
