<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\EmpleadoService;
use Throwable;

final class EmpleadoController
{
    private EmpleadoService $service;

    public function __construct()
    {
        $this->service = new EmpleadoService();
    }

    public function index(): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = (int) ($_GET['empresa_id'] ?? 0);

            if ($empresaId <= 0) {
                throw new HttpException('Empresa ID requerido', 422);
            }

            (new TenantMiddleware())->handle($userId, $empresaId);

            Response::success($this->service->getAll($empresaId));
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    public function store(): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $payload = Request::json();
            $empresaId = (int) ($payload['empresa_id'] ?? 0);

            if ($empresaId <= 0) {
                throw new HttpException('Empresa ID requerido', 422);
            }

            (new TenantMiddleware())->handle($userId, $empresaId);

            Response::success($this->service->create($empresaId, $payload), 'Empleado creado', 201);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    public function update(int $id): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $payload = Request::json();
            $empresaId = (int) ($payload['empresa_id'] ?? 0);

            if ($empresaId <= 0) {
                throw new HttpException('Empresa ID requerido', 422);
            }

            (new TenantMiddleware())->handle($userId, $empresaId);

            Response::success($this->service->update($empresaId, $id, $payload), 'Empleado actualizado');
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    public function search(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            $payload = \Mypos\Core\Request::json();
            $empresaId = (int) ($payload['empresa_id'] ?? 0);
        }
        
        $q = trim((string) ($_GET['q'] ?? ''));
        if (strlen($q) < 2) {
            Response::success([]);
            return;
        }

        $query = "SELECT id, rut, nombres, apellidos, cargo 
                  FROM empleados 
                  WHERE empresa_id = :empresa_id 
                  AND activo = 1 
                  AND (rut LIKE :q_rut OR nombres LIKE :q_nom OR apellidos LIKE :q_ape)
                  LIMIT 20";

        $like = "%{$q}%";
        $stmt = Database::connection()->prepare($query);
        $stmt->execute([
            'empresa_id' => $empresaId,
            'q_rut' => $like,
            'q_nom' => $like,
            'q_ape' => $like
        ]);

        Response::success($stmt->fetchAll());
    }
}
