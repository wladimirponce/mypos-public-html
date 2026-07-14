<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class PromoLinkRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function create(array $data): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO suscripcion_promo_links
                (codigo, descripcion, plan_id, precio_clp, moneda, activo, fecha_expiracion, creado_por)
             VALUES
                (:codigo, :descripcion, :plan_id, :precio_clp, :moneda, :activo, :fecha_expiracion, :creado_por)'
        );
        $stmt->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function findByCodigo(string $codigo): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT * FROM suscripcion_promo_links WHERE codigo = :codigo LIMIT 1'
        );
        $stmt->execute(['codigo' => $codigo]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT * FROM suscripcion_promo_links WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $stmt = $this->connection->query(
            'SELECT * FROM suscripcion_promo_links ORDER BY creado_el DESC'
        );

        return $stmt->fetchAll();
    }

    public function setActivo(int $id, bool $activo): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE suscripcion_promo_links SET activo = :activo WHERE id = :id'
        );
        $stmt->execute(['activo' => $activo ? 1 : 0, 'id' => $id]);
    }

    public function incrementUsos(int $id): void
    {
        $this->connection
            ->prepare('UPDATE suscripcion_promo_links SET usos = usos + 1 WHERE id = :id')
            ->execute(['id' => $id]);
    }

    public function codigoExists(string $codigo): bool
    {
        $stmt = $this->connection->prepare(
            'SELECT 1 FROM suscripcion_promo_links WHERE codigo = :codigo LIMIT 1'
        );
        $stmt->execute(['codigo' => $codigo]);

        return (bool) $stmt->fetchColumn();
    }
}
