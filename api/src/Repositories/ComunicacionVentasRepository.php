<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

class ComunicacionVentasRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO comunicaciones_ventas
                (canal, area, nombre, email, telefono, empresa, plan_interes, motivo, mensaje, origen, estado, metadata_json)
             VALUES
                (:canal, :area, :nombre, :email, :telefono, :empresa, :plan_interes, :motivo, :mensaje, :origen, :estado, :metadata_json)'
        );

        $stmt->execute([
            'canal' => $data['canal'],
            'area' => $data['area'],
            'nombre' => $data['nombre'],
            'email' => $data['email'],
            'telefono' => $data['telefono'],
            'empresa' => $data['empresa'],
            'plan_interes' => $data['plan_interes'],
            'motivo' => $data['motivo'],
            'mensaje' => $data['mensaje'],
            'origen' => $data['origen'],
            'estado' => $data['estado'],
            'metadata_json' => $data['metadata_json'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function latest(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->query(
            "SELECT id, canal, area, nombre, email, telefono, empresa, plan_interes, motivo, mensaje, origen, estado, created_at, updated_at
             FROM comunicaciones_ventas
             ORDER BY id DESC
             LIMIT {$limit}"
        );

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}
