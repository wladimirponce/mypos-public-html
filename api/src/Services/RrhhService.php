<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use PDO;

final class RrhhService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function getDescuentosMensuales(int $empresaId, int $mes, int $anio): array
    {
        $query = "
            SELECT 
                e.id AS empleado_id,
                e.rut,
                e.nombres,
                e.apellidos,
                e.cargo,
                COUNT(vci.id) as cantidad_compras,
                SUM(vci.monto_total) as monto_total_descuento,
                GROUP_CONCAT(DISTINCT vci.numero_boleta SEPARATOR ', ') as boletas
            FROM empleados e
            LEFT JOIN ventas_credito_interno vci 
                ON e.id = vci.empleado_id 
                AND vci.empresa_id = :empresa_id_join
                AND MONTH(vci.created_at) = :mes
                AND YEAR(vci.created_at) = :anio
            WHERE e.empresa_id = :empresa_id
            GROUP BY e.id, e.rut, e.nombres, e.apellidos, e.cargo
            HAVING monto_total_descuento > 0
            ORDER BY e.nombres ASC
        ";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            'empresa_id' => $empresaId,
            'empresa_id_join' => $empresaId,
            'mes' => $mes,
            'anio' => $anio
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
