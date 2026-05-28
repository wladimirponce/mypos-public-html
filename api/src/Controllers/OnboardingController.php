<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Repositories\EmpresaRepository;
use Mypos\Services\EmpresaService;
use Throwable;

final class OnboardingController
{
    private EmpresaService $empresaService;

    public function __construct()
    {
        $this->empresaService = new EmpresaService(new EmpresaRepository(Database::connection()));
    }

    public function saveOnboarding(): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $empresaId = (int) ($claims['empresa_id'] ?? 0);
            $userId = (int) ($claims['user_id'] ?? 0);

            if ($empresaId <= 0) {
                throw new HttpException('Token inválido', 401);
            }

            // Datos que pueden venir en $_POST si es multipart, o en JSON
            $payload = $_POST;
            if (empty($payload)) {
                $payload = Request::json();
            }

            // 1. Datos Empresa
            $telefono = (string) ($payload['telefono'] ?? '');
            $direccion = (string) ($payload['direccion'] ?? '');
            $comuna = (string) ($payload['comuna'] ?? '');
            $ciudad = (string) ($payload['ciudad'] ?? '');

            // Actualizamos la empresa usando PDO directo para setear onboarding_completado
            $connection = Database::connection();
            $stmt = $connection->prepare(
                'UPDATE empresas SET telefono = :tel, direccion = :dir, comuna = :com, ciudad = :ciu, onboarding_completado = 1 WHERE id = :id'
            );
            $stmt->execute([
                'tel' => $telefono,
                'dir' => $direccion,
                'com' => $comuna,
                'ciu' => $ciudad,
                'id' => $empresaId
            ]);

            // 2. Logo y Certificado (opcional)
            // Si vinieran en $_FILES, aquí guardaríamos en storage/
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $logoPath = __DIR__ . '/../../../public/storage/logos/' . $empresaId . '.png';
                @mkdir(dirname($logoPath), 0777, true);
                move_uploaded_file($_FILES['logo']['tmp_name'], $logoPath);
            }

            if (isset($_FILES['certificado']) && $_FILES['certificado']['error'] === UPLOAD_ERR_OK) {
                $certPath = __DIR__ . '/../../../public/storage/certs/' . $empresaId . '.pfx';
                @mkdir(dirname($certPath), 0777, true);
                move_uploaded_file($_FILES['certificado']['tmp_name'], $certPath);
            }

            // 3. Crear Cajero (opcional)
            if (!empty($payload['cajero_email']) && !empty($payload['cajero_password'])) {
                // Aquí deberíamos llamar a un UserService para crearlo, pero por simplicidad
                // lo insertamos si el email no existe
                $stmtCheck = $connection->prepare('SELECT id FROM usuarios WHERE email = ?');
                $stmtCheck->execute([$payload['cajero_email']]);
                if (!$stmtCheck->fetch()) {
                    $stmtUser = $connection->prepare(
                        'INSERT INTO usuarios (nombre, email, password) VALUES (?, ?, ?)'
                    );
                    $stmtUser->execute([
                        $payload['cajero_nombre'] ?? 'Cajero',
                        $payload['cajero_email'],
                        password_hash($payload['cajero_password'], PASSWORD_DEFAULT)
                    ]);
                    $newUserId = (int) $connection->lastInsertId();

                    // Asociar a la empresa como vendedor (rol 3)
                    $stmtAsoc = $connection->prepare(
                        'INSERT INTO empresa_usuarios (empresa_id, usuario_id, rol_id, sucursal_principal_id) VALUES (?, ?, 3, (SELECT id FROM sucursales WHERE empresa_id = ? LIMIT 1))'
                    );
                    $stmtAsoc->execute([$empresaId, $newUserId, $empresaId]);
                }
            }

            // 4. Datos SII
            $resolucion = (string) ($payload['sii_resolucion'] ?? '');
            $fechaResolucion = (string) ($payload['sii_fecha_resolucion'] ?? '');
            $migracion = (string) ($payload['sii_migracion'] ?? 'nueva');

            $stmtDte = $connection->prepare('SELECT id FROM dte_configuracion WHERE empresa_id = ?');
            $stmtDte->execute([$empresaId]);
            if (!$stmtDte->fetch()) {
                $stmtInsertDte = $connection->prepare(
                    'INSERT INTO dte_configuracion (empresa_id, modo, ambiente, metadata_json) VALUES (?, "SIMULADO", "CERTIFICACION", ?)'
                );
                $meta = json_encode([
                    'resolucion' => $resolucion,
                    'fecha_resolucion' => $fechaResolucion,
                    'tipo' => $migracion
                ]);
                $stmtInsertDte->execute([$empresaId, $meta]);
            }

            Response::success(['status' => 'ok', 'onboarding_completado' => true], 'Onboarding guardado exitosamente');

        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }
}
