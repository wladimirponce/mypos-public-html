<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\PermissionRepository;

final class PermissionService
{
    private const ROLES_SISTEMA = ['SUPER_ADMIN', 'ADMIN_EMPRESA', 'CAJERO', 'VENDEDOR', 'BODEGA', 'CONTADOR', 'AUDITOR'];

    private PermissionRepository $repository;

    public function __construct(?PermissionRepository $repository = null)
    {
        $this->repository = $repository ?? new PermissionRepository(Database::connection());
    }

    public function userHasPermission(int $usuarioId, int $empresaId, string $permiso): bool
    {
        if ($usuarioId <= 0 || $empresaId <= 0 || trim($permiso) === '') {
            return false;
        }

        return $this->repository->userHasPermission($usuarioId, $empresaId, $permiso);
    }

    public function assertPermission(int $usuarioId, int $empresaId, string $permiso, array $metadata = []): void
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        if ($this->userHasPermission($usuarioId, $empresaId, $permiso)) {
            return;
        }

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $usuarioId > 0 ? $usuarioId : null,
            'modulo' => 'seguridad',
            'accion' => 'permiso_denegado',
            'entidad' => 'permisos',
            'descripcion' => 'Acceso denegado por permiso insuficiente',
            'metadata' => [
                'permiso_requerido' => $permiso,
                'ruta' => $_SERVER['REQUEST_URI'] ?? null,
                'metodo' => $_SERVER['REQUEST_METHOD'] ?? null,
            ] + $metadata,
            'severidad' => 'WARNING',
            'resultado' => 'ERROR',
        ]);

        throw new HttpException('No autorizado para realizar esta accion', 403);
    }

    public function getUserPermissions(int $usuarioId, int $empresaId): array
    {
        if ($usuarioId <= 0 || $empresaId <= 0) {
            return [];
        }

        return $this->repository->userPermissions($usuarioId, $empresaId);
    }

    public function getRolePermissions(int $rolId): array
    {
        if ($rolId <= 0) {
            return [];
        }

        return $this->repository->rolePermissions($rolId);
    }

    public function userContext(int $usuarioId, int $empresaId): ?array
    {
        return $this->repository->userContext($usuarioId, $empresaId);
    }

    public function misPermisos(int $usuarioId, int $empresaId): array
    {
        $context = $this->userContext($usuarioId, $empresaId);

        if ($context === null || (int) $context['empresa_usuario_activo'] !== 1) {
            throw new HttpException('Usuario no pertenece a la empresa', 403);
        }

        return [
            'empresa_id' => $empresaId,
            'rol' => (string) $context['rol_codigo'],
            'permisos' => $this->getUserPermissions($usuarioId, $empresaId),
        ];
    }

    public function listarPermisos(): array
    {
        return ['permisos' => $this->repository->listPermissions()];
    }

    public function listarRoles(): array
    {
        $roles = $this->repository->listRoles();
        foreach ($roles as &$rol) {
            $rol['es_sistema'] = in_array($rol['codigo'], self::ROLES_SISTEMA, true);
        }
        return ['roles' => $roles];
    }

    public function obtenerRol(int $id): array
    {
        $rol = $this->repository->getRoleById($id);
        if ($rol === null) {
            throw new HttpException('Rol no encontrado', 404);
        }
        $rol['es_sistema'] = in_array($rol['codigo'], self::ROLES_SISTEMA, true);
        return $rol;
    }

    public function crearRol(array $data): array
    {
        $nombre = trim($data['nombre'] ?? '');
        if ($nombre === '') {
            throw new HttpException('El nombre es requerido', 422, ['nombre' => ['El nombre es requerido']]);
        }
        if (strlen($nombre) < 3) {
            throw new HttpException('El nombre debe tener al menos 3 caracteres', 422, ['nombre' => ['El nombre debe tener al menos 3 caracteres']]);
        }

        $descripcion = trim($data['descripcion'] ?? '');
        $activo = isset($data['activo']) ? ((bool) $data['activo'] ? 1 : 0) : 1;

        // Generar codigo
        $codigo = strtoupper(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $nombre)));
        $codigo = trim($codigo, '_');

        if ($codigo === '') {
            throw new HttpException('Nombre de rol invalido', 422);
        }

        if ($this->repository->findRoleByCodigo($codigo) !== null) {
            throw new HttpException('Ya existe un rol con ese nombre o codigo', 422, ['nombre' => ['Ya existe un rol con ese nombre']]);
        }

        $id = $this->repository->createRole($codigo, $nombre, $descripcion, $activo);

        return $this->obtenerRol($id);
    }

    public function actualizarRol(int $id, array $data): array
    {
        $rol = $this->obtenerRol($id);

        if ($rol['es_sistema']) {
            throw new HttpException('No se puede modificar un rol de sistema', 422);
        }

        $nombre = trim($data['nombre'] ?? $rol['nombre']);
        if ($nombre === '') {
            throw new HttpException('El nombre es requerido', 422, ['nombre' => ['El nombre es requerido']]);
        }
        if (strlen($nombre) < 3) {
            throw new HttpException('El nombre debe tener al menos 3 caracteres', 422, ['nombre' => ['El nombre debe tener al menos 3 caracteres']]);
        }

        $descripcion = trim($data['descripcion'] ?? (string) $rol['descripcion']);
        $activo = isset($data['activo']) ? ((bool) $data['activo'] ? 1 : 0) : (int) $rol['activo'];

        // Comprobar si el nuevo nombre genera un codigo que colisione con otro rol diferente
        $nuevoCodigo = strtoupper(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $nombre)));
        $nuevoCodigo = trim($nuevoCodigo, '_');
        $existente = $this->repository->findRoleByCodigo($nuevoCodigo);
        if ($existente !== null && (int) $existente['id'] !== $id) {
            throw new HttpException('Ya existe otro rol con ese nombre', 422, ['nombre' => ['Ya existe otro rol con ese nombre']]);
        }

        $this->repository->updateRole($id, $nombre, $descripcion, $activo);

        return $this->obtenerRol($id);
    }

    public function eliminarRol(int $id): void
    {
        $rol = $this->obtenerRol($id);

        if ($rol['es_sistema']) {
            throw new HttpException('No se puede eliminar un rol de sistema', 422);
        }

        if ($this->repository->isRoleAssigned($id)) {
            throw new HttpException('No se puede eliminar un rol que tiene usuarios asignados', 422);
        }

        $this->repository->deleteRole($id);
    }

    public function obtenerPermisosRol(int $roleId): array
    {
        // Verificar que el rol exista
        $this->obtenerRol($roleId);

        return [
            'role_id' => $roleId,
            'permission_ids' => $this->repository->getRolePermissionsIds($roleId),
        ];
    }

    public function actualizarPermisosRol(int $roleId, array $permissionIds, int $currentUserId, int $empresaId): array
    {
        $rol = $this->obtenerRol($roleId);

        if ($rol['codigo'] === 'SUPER_ADMIN') {
            throw new HttpException('No se pueden modificar los permisos del rol SUPER_ADMIN', 422);
        }

        // Obtener todos los permisos del sistema para mapear IDs a codigos
        $allPermissions = $this->repository->listPermissions();
        $permissionIdMap = [];
        foreach ($allPermissions as $p) {
            $permissionIdMap[(int) $p['id']] = (string) $p['codigo'];
        }

        // Mapear los IDs de permisos recibidos a sus codigos
        $selectedCodes = [];
        foreach ($permissionIds as $pid) {
            if (isset($permissionIdMap[$pid])) {
                $selectedCodes[] = $permissionIdMap[$pid];
            }
        }

        // Auto-proteccion del usuario administrador
        $context = $this->repository->userContext($currentUserId, $empresaId);
        if ($context !== null && (int) $context['rol_id'] === $roleId) {
            if (!in_array('roles.ver', $selectedCodes, true) || !in_array('roles.gestionar', $selectedCodes, true)) {
                throw new HttpException('No puedes quitarte a ti mismo los permisos de administracion de roles (roles.ver y roles.gestionar)', 422);
            }
        }

        $this->repository->setRolePermissions($roleId, $permissionIds);

        return $this->obtenerPermisosRol($roleId);
    }
}

