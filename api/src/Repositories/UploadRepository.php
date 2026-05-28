<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class UploadRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function empresaExists(int $empresaId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM empresas WHERE id = :id AND activo = 1 LIMIT 1');
        $statement->execute(['id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function sucursalExists(int $empresaId, int $sucursalId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM sucursales WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1'
        );
        $statement->execute(['id' => $sucursalId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function productoExists(int $empresaId, int $productoId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM productos WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1'
        );
        $statement->execute(['id' => $productoId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function insert(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO archivos_subidos (
                empresa_id, sucursal_id, usuario_id, modulo, entidad, entidad_id,
                nombre_original, nombre_storage, ruta_relativa, mime_type, extension,
                size_bytes, hash_sha256, estado, metadata_json
             ) VALUES (
                :empresa_id, :sucursal_id, :usuario_id, :modulo, :entidad, :entidad_id,
                :nombre_original, :nombre_storage, :ruta_relativa, :mime_type, :extension,
                :size_bytes, :hash_sha256, :estado, :metadata_json
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function find(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, usuario_id, modulo, entidad, entidad_id,
                    nombre_original, nombre_storage, ruta_relativa, mime_type, extension,
                    size_bytes, hash_sha256, estado, metadata_json, created_at,
                    updated_at, deleted_at
             FROM archivos_subidos
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function softDelete(int $empresaId, int $id): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE archivos_subidos
             SET estado = \'ELIMINADO\', deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id AND estado <> \'ELIMINADO\''
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    public function createProductImage(int $empresaId, int $productoId, string $rutaRelativa): int
    {
        $this->connection->prepare(
            'UPDATE productos_imagenes SET principal = 0 WHERE empresa_id = :empresa_id AND producto_id = :producto_id'
        )->execute(['empresa_id' => $empresaId, 'producto_id' => $productoId]);

        $statement = $this->connection->prepare(
            'INSERT INTO productos_imagenes (
                empresa_id, producto_id, producto_codigo_barra_id, codigo_barra_id, ruta,
                imagen_url, titulo, descripcion, principal, orden
             ) VALUES (
                :empresa_id, :producto_id, NULL, NULL, :ruta, :imagen_url,
                :titulo, NULL, 1, 1
             )'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'producto_id' => $productoId,
            'ruta' => $rutaRelativa,
            'imagen_url' => $rutaRelativa,
            'titulo' => 'Imagen subida',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateLogo(int $empresaId, string $rutaRelativa): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO empresa_configuracion (empresa_id, logo_url)
             VALUES (:empresa_id, :logo_url)
             ON DUPLICATE KEY UPDATE logo_url = VALUES(logo_url), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute(['empresa_id' => $empresaId, 'logo_url' => $rutaRelativa]);
    }
}
