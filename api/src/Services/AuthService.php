<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\Auth;
use Mypos\Core\HttpException;
use Mypos\Repositories\AuthRepository;
use Mypos\Repositories\PermissionRepository;
use Mypos\Support\PlanCatalog;
use Mypos\Support\SecurityAlert;

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
        $planId = PlanCatalog::normalize((string) ($data['plan_id'] ?? 'mypos-start'));
        $requiresEmailVerification = $this->requiresEmailVerification();

        // Link de precio especial (opcional). Se valida ANTES de crear nada: un
        // código inválido/expirado corta el registro con un error claro. El plan
        // del link manda sobre el ?plan= del formulario.
        $promoService = new PromoLinkService();
        $promoLink = $promoService->validarParaRegistro($data['promo_codigo'] ?? null);
        if ($promoLink !== null) {
            $planId = PlanCatalog::normalize((string) $promoLink['plan_id']);
            // El link comercial es un alta directa: la cuenta queda autenticada
            // para poder llevar al cliente al pago sin pasos intermedios.
            $requiresEmailVerification = false;
        }

        // Validaciones básicas
        if ($rutEmpresa === '' || $razonSocial === '' || $nombreUsuario === '' || $email === '' || $password === '') {
            throw new HttpException('Todos los campos son obligatorios', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('Formato de email inválido', 422, ['email' => ['Formato de email inválido']]);
        }

        if (strlen($password) < 12) {
            throw new HttpException('La contraseña debe tener al menos 12 caracteres', 422, ['password' => ['La contraseña debe tener al menos 12 caracteres']]);
        }
        if (strlen($password) > 128) {
            throw new HttpException('La contraseña no puede superar 128 caracteres', 422, ['password' => ['La contraseña no puede superar 128 caracteres']]);
        }

        // Verificar email existente
        $existingUser = $this->repository->findUserByEmail($email);
        $userId = 0;
        
        if ($existingUser !== null) {
            if (!password_verify($password, (string) $existingUser['password_hash'])) {
                throw new HttpException('El correo ya existe pero la contraseña no coincide. Ingrese su contraseña actual para agregar una nueva empresa.', 422, ['password' => ['Contraseña incorrecta']]);
            }
            $userId = (int) $existingUser['id'];
            $requiresEmailVerification = $requiresEmailVerification
                && (int) ($existingUser['email_verificado'] ?? 0) !== 1;
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

            $token = bin2hex(random_bytes(32));
            // 1. Crear Usuario si no existe
            if ($userId === 0) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $userId = $this->repository->createUser($nombreUsuario, $email, $passwordHash, $token);
            } else {
                $this->repository->setUserVerificationToken($userId, $token);
            }

            // 2. Crear Empresa
            $empresa = $empresaService->crearEmpresa([
                'rut' => $rutEmpresa,
                'razon_social' => $razonSocial,
                'nombre_fantasia' => $razonSocial, // por defecto
                // Correo de registro: destino garantizado de las alertas del
                // agente (empresas.email); antes quedaba NULL y las empresas
                // nacian inalcanzables para los avisos.
                'email' => $email,
                'activo' => 1
            ]);
            $empresaId = (int) $empresa['id'];
            if ($promoLink !== null) {
                // CAF, emisor y datos operativos se completan luego, desde
                // Configuracion. No forman parte del checkout promocional.
                $empresaService->completarOnboarding($empresaId);
            }

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

            // 6. Activar primer mes gratis (Free Trial). Aplica al plan elegido en el
            //    registro; del segundo mes en adelante se paga la cuota del plan (pago
            //    manual vía Flow/PayPal, validado por SubscriptionMiddleware al vencer).
            // Precio especial recurrente si vino por link de promoción: se estampa
            // en la suscripción y el cobro mensual lo usará hasta que se cambie a mano.
            $precioEspecial = $promoLink !== null ? (int) $promoLink['precio_clp'] : null;
            $promoCodigo = $promoLink !== null ? (string) $promoLink['codigo'] : null;

            $subscriptionValues = $promoLink !== null
                ? '(:empresa_id, :plan_id, :precio_especial_clp, :promo_codigo, NOW(), NOW(), "vencida")'
                : '(:empresa_id, :plan_id, :precio_especial_clp, :promo_codigo, NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), "activa")';
            $statement = $connection->prepare(
                'INSERT INTO empresas_suscripcion
                    (empresa_id, plan_id, precio_especial_clp, promo_codigo, fecha_inicio, fecha_fin, estado)
                 VALUES ' . $subscriptionValues
            );
            $statement->execute([
                'empresa_id' => $empresaId,
                'plan_id' => $planId,
                'precio_especial_clp' => $precioEspecial,
                'promo_codigo' => $promoCodigo,
            ]);

            // El cupo se reserva dentro de la misma transaccion que crea la
            // empresa y su suscripcion. Si dos registros compiten por el ultimo
            // uso, solo uno puede confirmar y el otro revierte completamente.
            if ($promoLink !== null) {
                $promoService->consumirUso((int) $promoLink['id']);
            }

            if (!$requiresEmailVerification) {
                $this->repository->verifyUserEmail($userId);
            }

            $connection->commit();

            if ($promoLink !== null) {
                try {
                    AuditoriaService::registrarEvento([
                        'usuario_id' => $userId,
                        'empresa_id' => $empresaId,
                        'modulo' => 'auth',
                        'accion' => 'promo_consumida',
                        'entidad' => 'suscripcion_promo_links',
                        'entidad_id' => (int) $promoLink['id'],
                        'descripcion' => 'Registro completado con promocion',
                        'metadata' => ['plan_id' => $planId],
                    ]);
                } catch (\Throwable $e) {
                    error_log('[PromoLink] no se pudo auditar el uso: ' . $e->getMessage());
                }
            }

            // Enviar correo de verificación
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }

        if ($requiresEmailVerification) {
            try {
                $mailService = new MailService();
                $mailService->enviarCorreoVerificacion($email, $nombreUsuario, $razonSocial, $token);
            } catch (\Throwable $exception) {
                error_log('No se pudo enviar correo de verificacion: ' . $exception->getMessage());
            }

            return ['require_verification' => true, 'email' => $email];
        }

        $user = $this->repository->findUserById($userId);
        if ($user === null) {
            throw new HttpException('Usuario no encontrado despues del registro', 500);
        }

        return $this->issueLoginResponse($user);
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

        if ($this->requiresEmailVerification() && isset($user['email_verificado']) && (int) $user['email_verificado'] !== 1) {
            throw new HttpException('Debes verificar tu dirección de correo electrónico antes de iniciar sesión. Te hemos enviado un correo con instrucciones.', 403, ['email_unverified' => true]);
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
            // Login anómalo: credenciales VÁLIDAS sobre una cuenta desactivada
            // (posible cuenta comprometida ya inhabilitada siendo sondeada).
            SecurityAlert::emit('auth.login_usuario_inactivo', 'high', [
                'component' => 'auth',
                'usuario_id' => (int) $user['id'],
                'reason' => 'credenciales_validas_cuenta_inactiva',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            throw new HttpException('Usuario inactivo', 403);
        }

        return $this->issueLoginResponse($user);
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

    /** @return array<string, mixed> */
    public function resumeSession(int $userId): array
    {
        $user = $this->repository->findUserById($userId);
        if ($user === null || (int) $user['activo'] !== 1) {
            throw new HttpException('Usuario inactivo o inexistente', 401);
        }

        return $this->issueLoginResponse($user, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function resendVerificationEmail(string $email): array
    {
        $email = trim(strtolower($email));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('Ingresa un email valido para reenviar la verificacion.', 422, [
                'email' => ['Email invalido'],
            ]);
        }

        $user = $this->repository->findUserByEmail($email);
        if ($user === null || (int) ($user['email_verificado'] ?? 0) === 1 || (int) ($user['activo'] ?? 0) !== 1) {
            return ['sent' => true];
        }

        $sentAt = strtotime((string) ($user['email_verification_sent_at'] ?? '')) ?: 0;
        if ($sentAt > 0 && $sentAt > time() - 60) {
            return ['sent' => true];
        }

        // Nunca se recupera ni reutiliza el token anterior: en BD solo existe
        // su hash y cada reenvio invalida el enlace previo.
        $token = bin2hex(random_bytes(32));
        $this->repository->setUserVerificationToken((int) $user['id'], $token);

        $empresas = $this->repository->empresasByUserId((int) $user['id']);
        $razonSocial = (string) ($empresas[0]['razon_social'] ?? $empresas[0]['nombre_fantasia'] ?? 'tu empresa');

        (new MailService())->enviarCorreoVerificacion(
            $email,
            (string) ($user['nombre'] ?? 'Usuario MyPOS'),
            $razonSocial,
            $token
        );

        return ['sent' => true];
    }

    /** @return array{success: true, message: string} */
    public function verifyEmail(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new HttpException('Token de verificación obligatorio', 422);
        }

        $user = $this->repository->findUserByVerificationToken($token);
        if ($user === null) {
            throw new HttpException('El enlace de verificación no es válido o ya ha expirado.', 404);
        }

        $this->repository->verifyUserEmail((int) $user['id']);
        AuditoriaService::registrarEvento([
            'usuario_id' => (int) $user['id'],
            'modulo' => 'auth',
            'accion' => 'email_verificado',
            'entidad' => 'usuarios',
            'entidad_id' => (int) $user['id'],
            'descripcion' => 'Correo verificado correctamente',
        ]);

        return ['success' => true, 'message' => 'Correo verificado exitosamente.'];
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
            'is_platform_owner' => \Mypos\Support\AppConfig::isPlatformOwnerEmail((string) $user['email']),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function issueLoginResponse(array $user, bool $recordLogin = true): array
    {
        $now = time();
        $ttl = AuthSessionService::enabled() ? 600 : 28800;
        $token = Auth::issueToken([
            'user_id' => (int) $user['id'],
            'sub' => (string) $user['id'],
            'email' => (string) $user['email'],
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
        ]);

        if ($recordLogin) {
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
        }

        return [
            'token' => $token,
            'user' => $this->publicUser($user),
            'empresas' => $this->repository->empresasByUserId((int) $user['id']),
        ];
    }

    private function requiresEmailVerification(): bool
    {
        $raw = $_ENV['REQUIRE_EMAIL_VERIFICATION'] ?? getenv('REQUIRE_EMAIL_VERIFICATION') ?: '0';

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
