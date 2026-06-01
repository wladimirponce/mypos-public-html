<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class EmpleadoRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function all(int $empresaId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM empleados WHERE empresa_id = :empresa_id ORDER BY nombres ASC");
        $stmt->execute(['empresa_id' => $empresaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function find(int $empresaId, int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM empleados WHERE empresa_id = :empresa_id AND id = :id");
        $stmt->execute(['empresa_id' => $empresaId, 'id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByRut(int $empresaId, string $rut): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM empleados WHERE empresa_id = :empresa_id AND rut = :rut");
        $stmt->execute(['empresa_id' => $empresaId, 'rut' => $rut]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO empleados (empresa_id, rut, nombres, apellidos, cargo, activo)
            VALUES (:empresa_id, :rut, :nombres, :apellidos, :cargo, :activo)
        ");
        
        $stmt->execute([
            'empresa_id' => $data['empresa_id'],
            'rut' => $data['rut'],
            'nombres' => $data['nombres'],
            'apellidos' => $data['apellidos'],
            'cargo' => $data['cargo'] ?? null,
            'activo' => $data['activo'] ?? 1
        ]);
        
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare("
            UPDATE empleados 
            SET rut = :rut, nombres = :nombres, apellidos = :apellidos, cargo = :cargo, activo = :activo
            WHERE id = :id AND empresa_id = :empresa_id
        ");
        
        $stmt->execute([
            'id' => $id,
            'empresa_id' => $data['empresa_id'],
            'rut' => $data['rut'],
            'nombres' => $data['nombres'],
            'apellidos' => $data['apellidos'],
            'cargo' => $data['cargo'] ?? null,
            'activo' => $data['activo'] ?? 1
        ]);
    }
}
