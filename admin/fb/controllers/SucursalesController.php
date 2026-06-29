<?php
/**
 * fb/controllers/SucursalesController.php
 *
 * Lista de locales/sucursales.
 * Tabla fuente: dim_sucursal (mypos)
 * Enriquece con cdg_sii_sucur desde sii_sucursal (misma BD).
 */

namespace Fb\Controllers;

use Fb\Database;
use PDO;

class SucursalesController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    // ── action=sucursales ─────────────────────────────────────────────────────

    public function lista(array $params): array
    {
        $stmt = $this->db->query("
            SELECT
                id_sucursal             AS id,
                nombre,
                COALESCE(direccion, '') AS direccion,
                COALESCE(comuna, '')    AS comuna
            FROM dim_sucursal
            ORDER BY nombre ASC
        ");
        $rows = $stmt->fetchAll();
        $rows = $this->enriquecerConSiiSucursal($rows);

        return ['success' => true, 'data' => $rows];
    }

    // ── action=sucursal_detalle ───────────────────────────────────────────────

    public function detalle(array $params): array
    {
        $id = trim($params['id'] ?? '');
        if ($id === '') {
            return ['ok' => false, 'error' => 'id es requerido'];
        }

        $stmt = $this->db->prepare("
            SELECT
                id_sucursal             AS id,
                nombre,
                COALESCE(direccion, '') AS direccion,
                COALESCE(comuna, '')    AS comuna
            FROM dim_sucursal
            WHERE id_sucursal = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['ok' => false, 'error' => 'Sucursal no encontrada'];
        }

        $rows = $this->enriquecerConSiiSucursal([$row]);
        return array_merge(['ok' => true], $rows[0]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function enriquecerConSiiSucursal(array $rows): array
    {
        $map = $this->cargarMapaSiiSucursal();
        foreach ($rows as &$row) {
            $row['cdg_sii'] = $map[(string)($row['id'] ?? '')] ?? '';
        }
        unset($row);
        return $rows;
    }

    private function cargarMapaSiiSucursal(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];

        try {
            // dte_php y central comparten la misma BD → misma conexión
            $stmt = $this->db->query(
                "SELECT id_local, cdg_sii_sucur FROM sii_sucursal WHERE activo = 1"
            );
            if ($stmt) {
                foreach ($stmt->fetchAll() as $r) {
                    $cache[(string)$r['id_local']] = $r['cdg_sii_sucur'];
                }
            }
        } catch (\Throwable $e) {
            // sii_sucursal puede no existir aún — no es error fatal
            error_log('fb/SucursalesController sii_sucursal: ' . $e->getMessage());
        }

        return $cache;
    }
}
