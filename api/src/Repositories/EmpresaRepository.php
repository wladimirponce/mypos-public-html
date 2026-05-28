<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class EmpresaRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function listEmpresas(): array
    {
        $statement = $this->connection->query(
            'SELECT id, razon_social, nombre_fantasia, rut, giro, email, telefono, direccion, comuna, ciudad, activo
             FROM empresas
             ORDER BY id'
        );

        return $statement->fetchAll();
    }

    public function getEmpresaById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, razon_social, nombre_fantasia, rut, giro, email, telefono, direccion, comuna, ciudad, activo, created_at, updated_at
             FROM empresas
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findEmpresaByRut(string $rut): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, razon_social, nombre_fantasia, rut, giro, email, telefono, direccion, comuna, ciudad, activo
             FROM empresas
             WHERE rut = :rut
             LIMIT 1'
        );
        $statement->execute(['rut' => $rut]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createEmpresa(
        string $rut,
        string $razonSocial,
        string $nombreFantasia,
        ?string $giro,
        ?string $email,
        ?string $telefono,
        ?string $direccion,
        ?string $comuna,
        ?string $ciudad,
        int $activo
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO empresas (rut, razon_social, nombre_fantasia, giro, email, telefono, direccion, comuna, ciudad, activo)
             VALUES (:rut, :razon_social, :nombre_fantasia, :giro, :email, :telefono, :direccion, :comuna, :ciudad, :activo)'
        );
        $statement->execute([
            'rut' => $rut,
            'razon_social' => $razonSocial,
            'nombre_fantasia' => $nombreFantasia,
            'giro' => $giro,
            'email' => $email,
            'telefono' => $telefono,
            'direccion' => $direccion,
            'comuna' => $comuna,
            'ciudad' => $ciudad,
            'activo' => $activo,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateEmpresa(
        int $id,
        string $razonSocial,
        string $nombreFantasia,
        ?string $giro,
        ?string $email,
        ?string $telefono,
        ?string $direccion,
        ?string $comuna,
        ?string $ciudad,
        int $activo
    ): void {
        $statement = $this->connection->prepare(
            'UPDATE empresas
             SET razon_social = :razon_social, nombre_fantasia = :nombre_fantasia, giro = :giro,
                 email = :email, telefono = :telefono, direccion = :direccion,
                 comuna = :comuna, ciudad = :ciudad, activo = :activo
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'razon_social' => $razonSocial,
            'nombre_fantasia' => $nombreFantasia,
            'giro' => $giro,
            'email' => $email,
            'telefono' => $telefono,
            'direccion' => $direccion,
            'comuna' => $comuna,
            'ciudad' => $ciudad,
            'activo' => $activo,
        ]);
    }

    public function deleteEmpresa(int $id): void
    {
        $statement = $this->connection->prepare('UPDATE empresas SET activo = 0 WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function listSucursales(int $empresaId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, nombre, codigo, direccion, comuna, ciudad, telefono, activo
             FROM sucursales
             WHERE empresa_id = :empresa_id
               AND activo = 1
             ORDER BY id'
        );
        $statement->execute(['empresa_id' => $empresaId]);

        return $statement->fetchAll();
    }

    public function getSucursalById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, nombre, codigo, direccion, comuna, ciudad, telefono, activo
             FROM sucursales
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findSucursalByCodigo(int $empresaId, string $codigo): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, nombre, codigo, direccion, comuna, ciudad, telefono, activo
             FROM sucursales
             WHERE empresa_id = :empresa_id AND codigo = :codigo AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'codigo' => $codigo]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createSucursal(
        int $empresaId,
        string $nombre,
        string $codigo,
        ?string $direccion,
        ?string $comuna,
        ?string $ciudad,
        ?string $telefono,
        int $activo
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO sucursales (empresa_id, nombre, codigo, direccion, comuna, ciudad, telefono, activo)
             VALUES (:empresa_id, :nombre, :codigo, :direccion, :comuna, :ciudad, :telefono, :activo)'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'nombre' => $nombre,
            'codigo' => $codigo,
            'direccion' => $direccion,
            'comuna' => $comuna,
            'ciudad' => $ciudad,
            'telefono' => $telefono,
            'activo' => $activo,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateSucursal(
        int $id,
        string $nombre,
        string $codigo,
        ?string $direccion,
        ?string $comuna,
        ?string $ciudad,
        ?string $telefono,
        int $activo
    ): void {
        $statement = $this->connection->prepare(
            'UPDATE sucursales
             SET nombre = :nombre, codigo = :codigo, direccion = :direccion,
                 comuna = :comuna, ciudad = :ciudad, telefono = :telefono, activo = :activo
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'nombre' => $nombre,
            'codigo' => $codigo,
            'direccion' => $direccion,
            'comuna' => $comuna,
            'ciudad' => $ciudad,
            'telefono' => $telefono,
            'activo' => $activo,
        ]);
    }

    public function deleteSucursal(int $id): void
    {
        $statement = $this->connection->prepare('UPDATE sucursales SET activo = 0 WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function listCajas(int $empresaId, ?int $sucursalId = null): array
    {
        $sql = 'SELECT c.id, c.empresa_id, c.sucursal_id, c.nombre, c.codigo, c.activo, s.nombre AS sucursal_nombre
                FROM cajas c
                INNER JOIN sucursales s ON s.id = c.sucursal_id
                WHERE c.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        if ($sucursalId !== null && $sucursalId > 0) {
            $sql .= ' AND c.sucursal_id = :sucursal_id';
            $params['sucursal_id'] = $sucursalId;
        }

        $sql .= ' ORDER BY c.id';

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function getCajaById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, nombre, codigo, activo
             FROM cajas
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findCajaByCodigo(int $sucursalId, string $codigo): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, nombre, codigo, activo
             FROM cajas
             WHERE sucursal_id = :sucursal_id AND codigo = :codigo
             LIMIT 1'
        );
        $statement->execute(['sucursal_id' => $sucursalId, 'codigo' => $codigo]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createCaja(
        int $empresaId,
        int $sucursalId,
        string $nombre,
        string $codigo,
        int $activo
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO cajas (empresa_id, sucursal_id, nombre, codigo, activo)
             VALUES (:empresa_id, :sucursal_id, :nombre, :codigo, :activo)'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'nombre' => $nombre,
            'codigo' => $codigo,
            'activo' => $activo,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateCaja(int $id, string $nombre, string $codigo, int $activo): void
    {
        $statement = $this->connection->prepare(
            'UPDATE cajas
             SET nombre = :nombre, codigo = :codigo, activo = :activo
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'nombre' => $nombre,
            'codigo' => $codigo,
            'activo' => $activo,
        ]);
    }

    public function deleteCaja(int $id): void
    {
        $statement = $this->connection->prepare('UPDATE cajas SET activo = 0 WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function listUsuariosEmpresa(int $empresaId): array
    {
        $statement = $this->connection->prepare(
            'SELECT eu.id, eu.empresa_id, eu.usuario_id, eu.rol_id, eu.sucursal_id, eu.activo,
                    u.nombre AS usuario_nombre, u.email AS usuario_email,
                    r.nombre AS rol_nombre, r.codigo AS rol_codigo,
                    s.nombre AS sucursal_nombre
             FROM empresa_usuarios eu
             INNER JOIN usuarios u ON u.id = eu.usuario_id
             INNER JOIN roles r ON r.id = eu.rol_id
             LEFT JOIN sucursales s ON s.id = eu.sucursal_id
             WHERE eu.empresa_id = :empresa_id
             ORDER BY eu.id'
        );
        $statement->execute(['empresa_id' => $empresaId]);

        return $statement->fetchAll();
    }

    public function countEmpresaAdministradores(int $empresaId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*)
             FROM empresa_usuarios eu
             INNER JOIN roles r ON r.id = eu.rol_id
             WHERE eu.empresa_id = :empresa_id
               AND r.codigo IN (\'SUPER_ADMIN\', \'ADMIN_EMPRESA\')
               AND eu.activo = 1'
        );
        $statement->execute(['empresa_id' => $empresaId]);

        return (int) $statement->fetchColumn();
    }

    public function countSucursalesActivas(int $empresaId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM sucursales WHERE empresa_id = :empresa_id AND activo = 1'
        );
        $statement->execute(['empresa_id' => $empresaId]);

        return (int) $statement->fetchColumn();
    }

    public function buscarUsuariosGlobales(string $q): array
    {
        $term = '%' . $q . '%';
        $statement = $this->connection->prepare(
            'SELECT id, nombre, email, activo
             FROM usuarios
             WHERE (nombre LIKE :term1 OR email LIKE :term2)
               AND activo = 1
             LIMIT 20'
        );
        $statement->execute(['term1' => $term, 'term2' => $term]);

        return $statement->fetchAll();
    }

    public function checkUsuarioPertenencia(int $empresaId, int $usuarioId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM empresa_usuarios WHERE empresa_id = :empresa_id AND usuario_id = :usuario_id LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'usuario_id' => $usuarioId]);

        return (bool) $statement->fetchColumn();
    }

    public function asociarUsuarioEmpresa(
        int $empresaId,
        int $usuarioId,
        int $rolId,
        ?int $sucursalId,
        int $activo
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO empresa_usuarios (empresa_id, usuario_id, rol_id, sucursal_id, activo)
             VALUES (:empresa_id, :usuario_id, :rol_id, :sucursal_id, :activo)'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'usuario_id' => $usuarioId,
            'rol_id' => $rolId,
            'sucursal_id' => $sucursalId,
            'activo' => $activo,
        ]);
    }

    public function updateUsuarioEmpresa(
        int $empresaId,
        int $usuarioId,
        int $rolId,
        ?int $sucursalId,
        int $activo
    ): void {
        $statement = $this->connection->prepare(
            'UPDATE empresa_usuarios
             SET rol_id = :rol_id, sucursal_id = :sucursal_id, activo = :activo
             WHERE empresa_id = :empresa_id AND usuario_id = :usuario_id'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'usuario_id' => $usuarioId,
            'rol_id' => $rolId,
            'sucursal_id' => $sucursalId,
            'activo' => $activo,
        ]);
    }

    public function removerUsuarioEmpresa(int $empresaId, int $usuarioId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM empresa_usuarios WHERE empresa_id = :empresa_id AND usuario_id = :usuario_id'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'usuario_id' => $usuarioId,
        ]);
    }
}
