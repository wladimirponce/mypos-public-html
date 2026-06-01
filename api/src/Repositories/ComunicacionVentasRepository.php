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
        $this->ensureSchema();

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
        $this->ensureSchema();

        $limit = max(1, min(200, $limit));
        $stmt = $this->db->query(
            "SELECT id, canal, area, nombre, email, telefono, empresa, plan_interes, motivo, mensaje, origen, estado, created_at, updated_at
             FROM comunicaciones_ventas
             ORDER BY id DESC
             LIMIT {$limit}"
        );

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    private function ensureSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS comunicaciones_ventas (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                canal VARCHAR(40) NOT NULL DEFAULT 'formulario',
                area VARCHAR(40) NOT NULL DEFAULT 'ventas',
                nombre VARCHAR(160) NULL,
                email VARCHAR(180) NULL,
                telefono VARCHAR(60) NULL,
                empresa VARCHAR(180) NULL,
                plan_interes VARCHAR(80) NULL,
                motivo VARCHAR(120) NULL,
                mensaje TEXT NULL,
                origen VARCHAR(160) NULL,
                estado VARCHAR(40) NOT NULL DEFAULT 'nuevo',
                metadata_json JSON NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_comunicaciones_ventas_estado_fecha (estado, created_at),
                INDEX idx_comunicaciones_ventas_area_fecha (area, created_at),
                INDEX idx_comunicaciones_ventas_plan_fecha (plan_interes, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
