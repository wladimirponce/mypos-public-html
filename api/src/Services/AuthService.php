<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\Auth;
use Mypos\Core\HttpException;
use Mypos\Repositories\AuthRepository;
use Mypos\Repositories\PermissionRepository;
use Mypos\Repositories\SuscripcionRepository;

final class AuthService
{
    public function __construct(private readonly AuthRepository $repository)
    {
    }

    /**
     * Registra un nuevo usuario, crea su empresa, sucursal principal y caja,
     * y le asigna el rol de SUPER_ADMIN.
     *
     * @return array<string, mixed>
     */
    public function register(array $data): array
    {
        $rutEmpresa = trim($data['rut_empresa'] ?? '');
        $razonSocial = trim($data['razon_social'] ?? '');
        $nombreUsuario = trim($data['nombre_usuario'] ?? '');
        $email = trim(strtolower($data['email'] ?? ''));
        $password = trim($data['password'] ?? '');

        // Validaciones básicas
        if ($rutEmpresa === '' || $razonSocial === '' || $nombreUsuario === '' || $email === '' || $password === '') {
            throw new HttpException('Todos los campos son obligatorios', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('Formato de email inválido', 422, ['email' => ['Formato de email inválido']]);
        }

        if (strlen($password) < 6) {
            throw new HttpException('La contraseña debe tener al menos 6 caracteres', 422, ['password' => ['La contraseña debe tener al menos 6 caracteres']]);
        }

        // Verificar email existente
        $existingUser = $this->repository->findUserByEmail($email);
        $userId = 0;
        
        if ($existingUser !== null) {
            if (!password_verify($password, (string) $existingUser['password_hash'])) {
                throw new HttpException('El correo ya existe pero la contraseña no coincide. Ingrese su contraseña actual para agregar una nueva empresa.', 422, ['password' => ['Contraseña incorrecta']]);
            }
            $userId = (int) $existingUser['id'];
        }

        $connection = Database::connection();
        $empresaService = new EmpresaService();
        $permissionRepo = new PermissionRepository($connection);

        $superAdminRole = $permissionRepo->findRoleByCodigo('SUPER_ADMIN');
        if ($superAdminRole === null) {
            throw new HttpException('Rol SUPER_ADMIN no encontrado en el sistema', 500);
        }
        $superAdminRoleId = (int) $superAdminRole['id'];

        try {
            $connection->beginTransaction();

            // 1. Crear Usuario si no existe
            if ($userId === 0) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $userId = $this->repository->createUser($nombreUsuario, $email, $passwordHash);
            }

            // 2. Crear Empresa
            $empresa = $empresaService->crearEmpresa([
                'rut' => $rutEmpresa,
                'razon_social' => $razonSocial,
                'nombre_fantasia' => $razonSocial, // por defecto
                'activo' => 1
            ]);
            $empresaId = (int) $empresa['id'];

            // 3. Crear Sucursal (Casa Matriz)
            $sucursal = $empresaService->crearSucursal($empresaId, [
                'nombre' => 'Casa Matriz',
                'codigo' => 'MATRIZ',
                'activo' => 1
            ]);
            $sucursalId = (int) $sucursal['id'];

            // 4. Crear Caja
            $empresaService->crearCaja($empresaId, [
                'sucursal_id' => $sucursalId,
                'nombre' => 'Caja 1',
                'codigo' => 'CAJA1',
                'activo' => 1
            ]);

            // 5. Asociar Usuario a Empresa como SUPER_ADMIN
            // Como asociarUsuario usa checkUsuarioPertenencia y otros métodos del repo,
            // EmpresaService tiene que recibir la misma conexión si no la obtiene por defecto de Database::connection().
            // Dado que usan singleton Database::connection(), estarán en la misma transacción.
            $empresaService->asociarUsuario($empresaId, [
                'usuario_id' => $userId,
                'rol_id' => $superAdminRoleId,
                'sucursal_principal_id' => $sucursalId,
                'activo' => 1
            ]);

            // 6. Activar 14 días de prueba gratis (Free Trial - MultiSucursal)
            $suscripcionRepo = new SuscripcionRepository($connection);
            $stmt = $connection->prepare(
                'INSERT INTO empresas_suscripcion (empresa_id, plan_id, fecha_inicio, fecha_fin, estado)
                 VALUES (:empresa_id, "multisucursal", NOW(), DATE_ADD(NOW(), INTERVAL 14 DAY), "activa")'
            );
            $stmt->execute(['empresa_id' => $empresaId]);

            $connection->commit();

            // Enviar correo de bienvenida
            $mailService = new MailService();
            $mailService->enviarCorreoBienvenida($email, $nombreUsuario, $razonSocial);

            // Iniciar sesión automáticamente después de registrarse
            return $this->login($email, $password);

        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function login(string $email, string $password): array
    {
        $email = trim(strtolower($email));

        if ($email === '' || trim($password) === '') {
            $errors = [];

            if ($email === '') {
                $errors['email'] = ['El email es obligatorio'];
            }

            if (trim($password) === '') {
                $errors['password'] = ['La password es obligatoria'];
            }

            throw new HttpException('Error de validación', 422, [
                ...$errors,
            ]);
        }

        $user = $this->repository->findUserByEmail($email);

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            AuditoriaService::registrarEvento([
                'modulo' => 'auth',
                'accion' => 'login_fallido',
                'entidad' => 'usuarios',
                'descripcion' => 'Intento de login fallido',
                'metadata' => ['email' => $email, 'motivo' => 'credenciales_invalidas'],
                'severidad' => 'WARNING',
                'resultado' => 'ERROR',
            ]);
            throw new HttpException('Credenciales incorrectas', 401);
        }

        if ((int) $user['activo'] !== 1) {
            AuditoriaService::registrarEvento([
                'usuario_id' => (int) $user['id'],
                'modulo' => 'auth',
                'accion' => 'login_fallido',
                'entidad' => 'usuarios',
                'entidad_id' => (int) $user['id'],
                'descripcion' => 'Intento de login de usuario inactivo',
                'metadata' => ['email' => $email, 'motivo' => 'usuario_inactivo'],
                'severidad' => 'WARNING',
                'resultado' => 'ERROR',
            ]);
            throw new HttpException('Usuario inactivo', 403);
        }

        $now = time();
        $token = Auth::issueToken([
            'user_id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'iat' => $now,
            'exp' => $now + 28800,
        ]);

        $this->repository->updateLastLogin((int) $user['id']);
        AuditoriaService::registrarEvento([
            'usuario_id' => (int) $user['id'],
            'modulo' => 'auth',
            'accion' => 'login_exitoso',
            'entidad' => 'usuarios',
            'entidad_id' => (int) $user['id'],
            'descripcion' => 'Login correcto',
            'metadata' => ['email' => (string) $user['email']],
        ]);

        return [
            'token' => $token,
            'user' => $this->publicUser($user),
            'empresas' => $this->repository->empresasByUserId((int) $user['id']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function me(array $claims, ?int $empresaId = null): array
    {
        $userId = (int) ($claims['user_id'] ?? 0);

        if ($userId <= 0) {
            throw new HttpException('Token inválido', 401);
        }

        $user = $this->repository->findUserById($userId);

        if ($user === null) {
            throw new HttpException('Usuario no encontrado', 401);
        }

        if ((int) $user['activo'] !== 1) {
            throw new HttpException('Usuario inactivo', 403);
        }

        $empresas = $this->repository->empresasByUserId($userId);
        $permissionEmpresaId = $empresaId !== null && $empresaId > 0
            ? $empresaId
            : (isset($empresas[0]['empresa_id']) ? (int) $empresas[0]['empresa_id'] : null);

        $data = [
            'user' => $this->publicUser($user),
            'empresas' => $empresas,
            'permisos' => $permissionEmpresaId !== null
                ? (new PermissionService())->getUserPermissions($userId, $permissionEmpresaId)
                : [],
        ];

        if ($empresaId !== null && $empresaId > 0) {
            $context = (new PermissionService())->userContext($userId, $empresaId);
            if ($context === null || (int) $context['empresa_usuario_activo'] !== 1) {
                throw new HttpException('Usuario no pertenece a la empresa', 403);
            }
            $data['empresa'] = ['id' => $empresaId];
            $data['rol'] = (string) $context['rol_codigo'];
        }

        return $data;
    }

    public function logout(array $claims): void
    {
        $userId = (int) ($claims['user_id'] ?? 0);

        AuditoriaService::registrarEvento([
            'usuario_id' => $userId > 0 ? $userId : null,
            'modulo' => 'auth',
            'accion' => 'logout',
            'entidad' => 'usuarios',
            'entidad_id' => $userId > 0 ? $userId : null,
            'descripcion' => 'Logout solicitado',
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'nombre' => (string) $user['nombre'],
            'email' => (string) $user['email'],
        ];
    }
}
