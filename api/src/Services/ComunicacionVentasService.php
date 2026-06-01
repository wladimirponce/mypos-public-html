<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\ComunicacionVentasRepository;

class ComunicacionVentasService
{
    private ComunicacionVentasRepository $repository;

    public function __construct()
    {
        $this->repository = new ComunicacionVentasRepository(Database::connection());
    }

    public function create(array $payload): array
    {
        $data = [
            'canal' => $this->clean($payload['canal'] ?? 'formulario', 40) ?: 'formulario',
            'area' => $this->clean($payload['area'] ?? 'ventas', 40) ?: 'ventas',
            'nombre' => $this->clean($payload['nombre'] ?? null, 160),
            'email' => $this->clean($payload['email'] ?? null, 180),
            'telefono' => $this->clean($payload['telefono'] ?? null, 60),
            'empresa' => $this->clean($payload['empresa'] ?? null, 180),
            'plan_interes' => $this->clean($payload['plan_interes'] ?? null, 80),
            'motivo' => $this->clean($payload['motivo'] ?? null, 120),
            'mensaje' => $this->clean($payload['mensaje'] ?? null, 2000),
            'origen' => $this->clean($payload['origen'] ?? null, 160),
            'estado' => 'nuevo',
            'metadata_json' => null,
        ];

        if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('Email invalido', 422);
        }

        if ($data['nombre'] === null && $data['email'] === null && $data['telefono'] === null) {
            throw new HttpException('Debes indicar nombre, email o telefono', 422);
        }

        $metadata = [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'ip_hash' => isset($_SERVER['REMOTE_ADDR']) ? hash('sha256', (string) $_SERVER['REMOTE_ADDR']) : null,
        ];
        $data['metadata_json'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $id = $this->repository->create($data);

        return ['id' => $id, 'estado' => 'recibido'];
    }

    public function latest(int $limit = 100): array
    {
        return ['comunicaciones' => $this->repository->latest($limit)];
    }

    private function clean(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(strip_tags((string) $value));
        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, $maxLength);
    }
}
