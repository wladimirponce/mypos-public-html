<?php
declare(strict_types=1);

namespace App\Repositories;

class UsuarioRepository extends BaseRepository
{
    public function getUsuariosPorEmpresa(int $empresaId): array
    {
        $sql = "SELECT u.*, s.nombre AS sucursal_nombre 
                FROM sii_usuario u
                LEFT JOIN sii_sucursal s ON u.sucursal_id = s.id
                WHERE u.empresa_id = ? AND u.activo = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId]);
        return $stmt->fetchAll() ?: [];
    }

    public function getUsuarioPorId(int $id, int $empresaId): ?array
    {
        $sql = "SELECT * FROM sii_usuario WHERE id = ? AND empresa_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id, $empresaId]);
        return $stmt->fetch() ?: null;
    }

    public function crearUsuario(array $data): int
    {
        $sql = "INSERT INTO sii_usuario (empresa_id, sucursal_id, nombre, email, password_hash, rol, pin_caja)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['empresa_id'],
            $data['sucursal_id'] ?: null,
            $data['nombre'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['rol'] ?? 'cajero',
            $data['pin_caja'] ?? null
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function desactivarUsuario(int $id, int $empresaId): void
    {
        $sql = "UPDATE sii_usuario SET activo = 0 WHERE id = ? AND empresa_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id, $empresaId]);
    }
}
