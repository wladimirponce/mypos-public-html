<?php
declare(strict_types=1);

namespace App\Repositories;

class UsuarioRepository extends BaseRepository
{
    public function getUsuariosPorEmpresa(int $empresaId): array
    {
        $sql = "SELECT u.id, u.nombre, u.email, u.activo,
                       eu.sucursal_id, s.nombre AS sucursal_nombre,
                       eu.rol_id, r.codigo AS rol, r.nombre AS rol_nombre,
                       NULL AS pin_caja
                FROM usuarios u
                JOIN empresa_usuarios eu ON u.id = eu.usuario_id
                LEFT JOIN sucursales s ON eu.sucursal_id = s.id
                LEFT JOIN roles r ON eu.rol_id = r.id
                WHERE eu.empresa_id = ? AND u.activo = 1 AND eu.activo = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId]);
        return $stmt->fetchAll() ?: [];
    }

    public function getUsuarioPorId(int $id, int $empresaId): ?array
    {
        $sql = "SELECT u.id, u.nombre, u.email, u.activo,
                       eu.sucursal_id, eu.rol_id, r.codigo AS rol
                FROM usuarios u
                JOIN empresa_usuarios eu ON u.id = eu.usuario_id
                LEFT JOIN roles r ON eu.rol_id = r.id
                WHERE u.id = ? AND eu.empresa_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id, $empresaId]);
        return $stmt->fetch() ?: null;
    }

    public function crearUsuario(array $data): int
    {
        // 1. Obtener ID del rol dinámicamente
        $stmtRol = $this->db->prepare("SELECT id FROM roles WHERE codigo = ? LIMIT 1");
        $stmtRol->execute([$data['rol'] ?? 'CAJERO']);
        $rolId = (int)$stmtRol->fetchColumn();
        if ($rolId === 0) {
            $rolId = (int)$this->db->query("SELECT id FROM roles ORDER BY id ASC LIMIT 1")->fetchColumn();
        }

        // Buscar si el usuario ya existe por email a nivel global
        $stmtCheck = $this->db->prepare("SELECT id, activo FROM usuarios WHERE email = ? LIMIT 1");
        $stmtCheck->execute([$data['email']]);
        $existingUser = $stmtCheck->fetch();

        $this->db->beginTransaction();
        try {
            if ($existingUser) {
                $usuarioId = (int)$existingUser['id'];

                // Reactivar cuenta de usuario global si estaba inactiva; marcar email verificado
                if ((int)$existingUser['activo'] === 0) {
                    $this->db->prepare("UPDATE usuarios SET activo = 1, email_verificado = 1 WHERE id = ?")
                             ->execute([$usuarioId]);
                } else {
                    $this->db->prepare("UPDATE usuarios SET email_verificado = 1 WHERE id = ? AND email_verificado = 0")
                             ->execute([$usuarioId]);
                }

                // Verificar si ya está asociado a esta empresa específica
                $stmtCheckLink = $this->db->prepare("SELECT id, activo FROM empresa_usuarios WHERE empresa_id = ? AND usuario_id = ? LIMIT 1");
                $stmtCheckLink->execute([$data['empresa_id'], $usuarioId]);
                $existingLink = $stmtCheckLink->fetch();

                if ($existingLink) {
                    if ((int)$existingLink['activo'] === 1) {
                        throw new \Exception("El usuario con el email '" . $data['email'] . "' ya se encuentra asociado y activo en esta empresa.");
                    } else {
                        // Reactivar la asociación existente y actualizar rol/sucursal
                        $sqlEmpUsr = "UPDATE empresa_usuarios SET rol_id = ?, sucursal_id = ?, activo = 1 WHERE id = ?";
                        $stmtEmpUsr = $this->db->prepare($sqlEmpUsr);
                        $stmtEmpUsr->execute([
                            $rolId,
                            $data['sucursal_id'] ?: null,
                            $existingLink['id']
                        ]);
                    }
                } else {
                    // Crear nueva asociación para esta empresa
                    $sqlEmpUsr = "INSERT INTO empresa_usuarios (empresa_id, usuario_id, rol_id, sucursal_id, activo) VALUES (?, ?, ?, ?, 1)";
                    $stmtEmpUsr = $this->db->prepare($sqlEmpUsr);
                    $stmtEmpUsr->execute([
                        $data['empresa_id'],
                        $usuarioId,
                        $rolId,
                        $data['sucursal_id'] ?: null
                    ]);
                }
            } else {
                // Si no existe el usuario, crearlo en la tabla central y luego vincularlo
                $sqlUser = "INSERT INTO usuarios (nombre, email, password_hash, activo, email_verificado) VALUES (?, ?, ?, 1, 1)";
                $stmtUser = $this->db->prepare($sqlUser);
                $stmtUser->execute([
                    $data['nombre'],
                    $data['email'],
                    password_hash($data['password'], PASSWORD_DEFAULT)
                ]);
                $usuarioId = (int)$this->db->lastInsertId();

                $sqlEmpUsr = "INSERT INTO empresa_usuarios (empresa_id, usuario_id, rol_id, sucursal_id, activo) VALUES (?, ?, ?, ?, 1)";
                $stmtEmpUsr = $this->db->prepare($sqlEmpUsr);
                $stmtEmpUsr->execute([
                    $data['empresa_id'],
                    $usuarioId,
                    $rolId,
                    $data['sucursal_id'] ?: null
                ]);
            }

            $this->db->commit();
            return $usuarioId;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function desactivarUsuario(int $id, int $empresaId): void
    {
        $this->db->beginTransaction();
        try {
            $sqlUser = "UPDATE usuarios SET activo = 0 WHERE id = ?";
            $stmtUser = $this->db->prepare($sqlUser);
            $stmtUser->execute([$id]);

            $sqlEmpUsr = "UPDATE empresa_usuarios SET activo = 0 WHERE usuario_id = ? AND empresa_id = ?";
            $stmtEmpUsr = $this->db->prepare($sqlEmpUsr);
            $stmtEmpUsr->execute([$id, $empresaId]);

            $this->db->commit();
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function actualizarUsuario(int $id, int $empresaId, array $data): bool
    {
        // 1. Obtener ID del rol dinámicamente
        $stmtRol = $this->db->prepare("SELECT id FROM roles WHERE codigo = ? LIMIT 1");
        $stmtRol->execute([$data['rol'] ?? 'CAJERO']);
        $rolId = (int)$stmtRol->fetchColumn();
        if ($rolId === 0) {
            $rolId = (int)$this->db->query("SELECT id FROM roles ORDER BY id ASC LIMIT 1")->fetchColumn();
        }

        $this->db->beginTransaction();
        try {
            // 2. Actualizar usuarios
            $fields = ['nombre = ?', 'email = ?'];
            $params = [$data['nombre'], $data['email']];
            if (!empty($data['password'])) {
                $fields[] = 'password_hash = ?';
                $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            $params[] = $id;
            $sqlUser = "UPDATE usuarios SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmtUser = $this->db->prepare($sqlUser);
            $stmtUser->execute($params);

            // 3. Actualizar empresa_usuarios
            $sqlEmpUsr = "UPDATE empresa_usuarios SET rol_id = ?, sucursal_id = ? WHERE usuario_id = ? AND empresa_id = ?";
            $stmtEmpUsr = $this->db->prepare($sqlEmpUsr);
            $stmtEmpUsr->execute([
                $rolId,
                $data['sucursal_id'] ?: null,
                $id,
                $empresaId
            ]);

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}


