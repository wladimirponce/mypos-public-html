<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Repositories\AuthRepository;
use Mypos\Repositories\EmpresaRepository;
use Mypos\Repositories\PermissionRepository;
use Mypos\Services\EmpresaService;
use PDO;
use Throwable;

final class OnboardingController
{
    private PDO $connection;
    private AuthRepository $authRepository;
    private EmpresaRepository $empresaRepository;
    private EmpresaService $empresaService;
    private PermissionRepository $permissionRepository;

    public function __construct()
    {
        $this->connection = Database::connection();
        $this->authRepository = new AuthRepository($this->connection);
        $this->empresaRepository = new EmpresaRepository($this->connection);
        $this->empresaService = new EmpresaService($this->empresaRepository);
        $this->permissionRepository = new PermissionRepository($this->connection);
    }

    public function saveOnboarding(): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) ($claims['user_id'] ?? 0);

            if ($userId <= 0) {
                throw new HttpException('Token invalido', 401);
            }

            $payload = $_POST;
            if (empty($payload)) {
                $payload = Request::json();
            }

            $empresaId = $this->resolveEmpresaId($userId, $payload);
            $context = $this->permissionRepository->userContext($userId, $empresaId);
            if ($context === null || !in_array((string) $context['rol_codigo'], ['SUPER_ADMIN', 'ADMIN_EMPRESA'], true)) {
                throw new HttpException('No tienes permiso para completar la inscripcion de esta empresa', 403);
            }

            $this->connection->beginTransaction();

            $sucursalId = $this->updateEmpresaAndSucursal($empresaId, $payload);
            $logoPath = $this->storeUpload('logo', $empresaId, 'logos', ['png', 'jpg', 'jpeg']);
            $certPath = $this->storeUpload('certificado', $empresaId, 'sii/certificados', ['pfx', 'p12']);
            $cajero = $this->createCashierIfRequested($empresaId, $sucursalId, $payload);
            $this->upsertDteConfiguration($empresaId, $payload, $certPath);

            $this->connection->commit();

            Response::success([
                'status' => 'ok',
                'empresa_id' => $empresaId,
                'onboarding_completado' => true,
                'logo_guardado' => $logoPath !== null,
                'certificado_recibido' => $certPath !== null,
                'cajero' => $cajero,
                'dte_estado' => 'SIMULADO',
            ], 'Onboarding guardado exitosamente');
        } catch (HttpException $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function resolveEmpresaId(int $userId, array $payload): int
    {
        $requestedEmpresaId = (int) ($payload['empresa_id'] ?? $_GET['empresa_id'] ?? 0);
        $empresas = $this->authRepository->empresasByUserId($userId);

        if ($requestedEmpresaId > 0) {
            foreach ($empresas as $empresa) {
                if ((int) $empresa['empresa_id'] === $requestedEmpresaId) {
                    return $requestedEmpresaId;
                }
            }

            throw new HttpException('Usuario no pertenece a la empresa indicada', 403);
        }

        if (count($empresas) === 0) {
            throw new HttpException('El usuario no tiene empresas activas', 403);
        }

        return (int) $empresas[0]['empresa_id'];
    }

    private function updateEmpresaAndSucursal(int $empresaId, array $payload): int
    {
        $telefono = $this->nullable($payload['telefono'] ?? null);
        $direccion = $this->nullable($payload['direccion'] ?? null);
        $comuna = $this->nullable($payload['comuna'] ?? null);
        $ciudad = $this->nullable($payload['ciudad'] ?? null);

        $statement = $this->connection->prepare(
            'UPDATE empresas
             SET telefono = :telefono,
                 direccion = :direccion,
                 comuna = :comuna,
                 ciudad = :ciudad,
                 onboarding_completado = 1
             WHERE id = :empresa_id'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'telefono' => $telefono,
            'direccion' => $direccion,
            'comuna' => $comuna,
            'ciudad' => $ciudad,
        ]);

        $sucursales = $this->empresaRepository->listSucursales($empresaId);
        $sucursalId = isset($sucursales[0]['id']) ? (int) $sucursales[0]['id'] : 0;

        if ($sucursalId > 0) {
            $statement = $this->connection->prepare(
                'UPDATE sucursales
                 SET direccion = COALESCE(:direccion, direccion),
                     comuna = COALESCE(:comuna, comuna),
                     ciudad = COALESCE(:ciudad, ciudad),
                     telefono = COALESCE(:telefono, telefono)
                 WHERE id = :sucursal_id AND empresa_id = :empresa_id'
            );
            $statement->execute([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'direccion' => $direccion,
                'comuna' => $comuna,
                'ciudad' => $ciudad,
                'telefono' => $telefono,
            ]);
        }

        return $sucursalId;
    }

    private function createCashierIfRequested(int $empresaId, int $sucursalId, array $payload): ?array
    {
        $email = trim(strtolower((string) ($payload['cajero_email'] ?? '')));
        $password = trim((string) ($payload['cajero_password'] ?? ''));

        if ($email === '' && $password === '') {
            return null;
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('El email del cajero no es valido', 422, ['cajero_email' => ['Email invalido']]);
        }

        if (strlen($password) < 6) {
            throw new HttpException('La contrasena del cajero debe tener al menos 6 caracteres', 422, ['cajero_password' => ['Minimo 6 caracteres']]);
        }

        $role = $this->permissionRepository->findRoleByCodigo('CAJERO')
            ?? $this->permissionRepository->findRoleByCodigo('VENDEDOR');
        if ($role === null) {
            throw new HttpException('Rol CAJERO no encontrado en el sistema', 500);
        }

        $existingUser = $this->authRepository->findUserByEmail($email);
        $created = false;

        if ($existingUser === null) {
            $userId = $this->authRepository->createUser(
                trim((string) ($payload['cajero_nombre'] ?? 'Cajero')) ?: 'Cajero',
                $email,
                password_hash($password, PASSWORD_DEFAULT)
            );
            $created = true;
        } else {
            $userId = (int) $existingUser['id'];
        }

        $this->empresaService->asociarUsuario($empresaId, [
            'usuario_id' => $userId,
            'rol_id' => (int) $role['id'],
            'sucursal_principal_id' => $sucursalId > 0 ? $sucursalId : null,
            'activo' => 1,
        ]);

        return [
            'usuario_id' => $userId,
            'email' => $email,
            'creado' => $created,
            'rol' => (string) $role['codigo'],
        ];
    }

    private function upsertDteConfiguration(int $empresaId, array $payload, ?string $certPath): void
    {
        $metadata = [
            'onboarding' => [
                'resolucion' => $this->nullable($payload['sii_resolucion'] ?? null),
                'fecha_resolucion' => $this->nullable($payload['sii_fecha_resolucion'] ?? null),
                'tipo' => $this->nullable($payload['sii_migracion'] ?? null) ?? 'nueva',
                'certificado_recibido' => $certPath !== null,
                'certificado_path' => $certPath,
                'admin_estado' => 'pendiente_configuracion',
                'admin_api_key_env' => 'DTE_ADMIN_API_KEY_' . $empresaId,
            ],
        ];

        $statement = $this->connection->prepare(
            'INSERT INTO dte_configuracion (empresa_id, modo, ambiente, endpoint_http, metadata_json)
             VALUES (:empresa_id, "SIMULADO", "CERTIFICACION", :endpoint_http, :metadata_json)
             ON DUPLICATE KEY UPDATE
                ambiente = VALUES(ambiente),
                endpoint_http = COALESCE(dte_configuracion.endpoint_http, VALUES(endpoint_http)),
                metadata_json = VALUES(metadata_json)'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'endpoint_http' => getenv('DTE_ADMIN_ENDPOINT') ?: null,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function storeUpload(string $field, int $empresaId, string $folder, array $allowedExtensions): ?string
    {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            throw new HttpException('No se pudo recibir el archivo ' . $field, 422);
        }

        $originalName = (string) ($_FILES[$field]['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new HttpException('Formato de archivo no permitido para ' . $field, 422);
        }

        $baseDir = dirname(__DIR__, 2) . '/storage/uploads/' . $folder . '/empresa_' . $empresaId;
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            throw new HttpException('No se pudo preparar el directorio de subida', 500);
        }

        $filename = $field . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $target = $baseDir . '/' . $filename;

        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
            throw new HttpException('No se pudo guardar el archivo ' . $field, 500);
        }

        return $target;
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
