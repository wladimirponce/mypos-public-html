<?php
/**
 * fb/controllers/ProductosController.php
 *
 * Búsqueda y detalle de productos desde la BD central de Farmacias Belén.
 * Tablas: dim_producto, productos, codigosdebarra, stock_actual
 */

namespace Fb\Controllers;

use Fb\Database;
use PDO;

class ProductosController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    // ── action=productos_buscar ───────────────────────────────────────────────
    // GET ?action=productos_buscar&q=paracetamol&sucursal_id=5&page=1&limit=50

    public function buscar(array $params): array
    {
        $query      = trim($params['q'] ?? $params['query'] ?? '');
        $sucursalId = trim($params['sucursal_id'] ?? '');
        $page       = max(1, (int)($params['page'] ?? 1));
        $limit      = min(200, max(1, (int)($params['limit'] ?? 50)));
        $offset     = ($page - 1) * $limit;

        if (strlen($query) < 2) {
            return ['ok' => false, 'error' => 'La búsqueda debe tener al menos 2 caracteres'];
        }
        if ($sucursalId === '') {
            return ['ok' => false, 'error' => 'sucursal_id es requerido'];
        }

        // 1. Buscar por código de barras exacto
        $cbRow = $this->buscarPorBarcode($query);

        $results = [];

        if ($cbRow) {
            $results = $this->fetchProductoPorId($cbRow['id_producto'], $sucursalId, $query);
        }

        // 2. Búsqueda por SKU exacto o descripción parcial
        if (empty($results)) {
            $results = $this->fetchProductosPorTexto($query, $sucursalId, $limit, $offset);
        }

        return $this->enriquecer($results);
    }

    // ── action=producto_detalle ───────────────────────────────────────────────
    // GET ?action=producto_detalle&id_producto=ABC123&sucursal_id=5

    public function detalle(array $params): array
    {
        $idProducto = trim($params['id_producto'] ?? '');
        $sucursalId = trim($params['sucursal_id'] ?? '');

        if ($idProducto === '' || $sucursalId === '') {
            return ['ok' => false, 'error' => 'id_producto y sucursal_id son requeridos'];
        }

        $stmt = $this->db->prepare("
            SELECT
                d.id_producto,
                d.descripcion                        AS nombre,
                d.codigo_producto                    AS sku,
                d.laboratorio                        AS marca,
                d.presentacion,
                d.pactivo,
                d.categoria,
                COALESCE(p.ValorVenta,   0)          AS precio_venta,
                COALESCE(p.pdescuento,   0)          AS pdescuento,
                COALESCE(p.comision,     0)          AS comision,
                COALESCE(sa.stock_actual, 0)         AS cantidad_disponible,
                COALESCE(sa.stock_minimo, 0)         AS stock_minimo
            FROM dim_producto d
            LEFT JOIN productos    p  ON d.id_producto = p.CodigoProducto
            LEFT JOIN stock_actual sa ON d.id_producto = sa.id_producto_fk
                                     AND sa.id_sucursal_fk = :suc
            WHERE d.id_producto = :id
              AND d.activo = 1
            LIMIT 1
        ");
        $stmt->execute([':suc' => $sucursalId, ':id' => $idProducto]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['ok' => false, 'error' => 'Producto no encontrado'];
        }

        $row = $this->enriquecerUno($row);
        $row['ok'] = true;
        return $row;
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function buscarPorBarcode(string $query): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id_producto FROM codigosdebarra
             WHERE codigo_barras = :q AND estado = 1 LIMIT 1"
        );
        $stmt->execute([':q' => $query]);
        $r = $stmt->fetch();
        return $r ?: null;
    }

    private function fetchProductoPorId(string $idProducto, string $sucursalId, string $barcode): array
    {
        $stmt = $this->db->prepare("
            SELECT
                d.id_producto,
                d.descripcion    AS nombre,
                d.codigo_producto AS sku,
                d.laboratorio    AS marca,
                d.presentacion,
                COALESCE(p.ValorVenta,  0)  AS precio_venta,
                COALESCE(p.pdescuento, 0)  AS pdescuento,
                COALESCE(p.comision,   0)  AS comision,
                COALESCE(sa.stock_actual, 0) AS cantidad_disponible,
                :barcode                   AS barcode_escaneado
            FROM dim_producto d
            LEFT JOIN productos    p  ON d.id_producto = p.CodigoProducto
            LEFT JOIN stock_actual sa ON d.id_producto = sa.id_producto_fk
                                     AND sa.id_sucursal_fk = :suc
            WHERE d.activo = 1 AND d.id_producto = :id
            LIMIT 1
        ");
        $stmt->execute([':suc' => $sucursalId, ':id' => $idProducto, ':barcode' => $barcode]);
        $r = $stmt->fetch();
        return $r ? [$r] : [];
    }

    private function fetchProductosPorTexto(string $query, string $sucursalId, int $limit, int $offset): array
    {
        $term  = '%' . $query . '%';
        $start = $query . '%';

        $stmt = $this->db->prepare("
            SELECT
                d.id_producto,
                d.descripcion    AS nombre,
                d.codigo_producto AS sku,
                d.laboratorio    AS marca,
                d.presentacion,
                COALESCE(p.ValorVenta,  0)  AS precio_venta,
                COALESCE(p.pdescuento, 0)  AS pdescuento,
                COALESCE(p.comision,   0)  AS comision,
                COALESCE(sa.stock_actual, 0) AS cantidad_disponible,
                CASE
                    WHEN d.codigo_producto = :exact1 THEN 0
                    WHEN d.id_producto     = :exact2 THEN 1
                    WHEN d.descripcion LIKE :start   THEN 2
                    ELSE 3
                END AS _relevancia
            FROM dim_producto d
            LEFT JOIN productos    p  ON d.id_producto = p.CodigoProducto
            LEFT JOIN stock_actual sa ON d.id_producto = sa.id_producto_fk
                                     AND sa.id_sucursal_fk = :suc
            WHERE d.activo = 1
              AND (d.descripcion    LIKE :term
                OR d.codigo_producto = :exact3
                OR d.id_producto     = :exact4)
            ORDER BY _relevancia ASC, d.descripcion ASC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':suc',    $sucursalId);
        $stmt->bindValue(':term',   $term);
        $stmt->bindValue(':start',  $start);
        $stmt->bindValue(':exact1', $query);
        $stmt->bindValue(':exact2', $query);
        $stmt->bindValue(':exact3', $query);
        $stmt->bindValue(':exact4', $query);
        $stmt->bindValue(':lim',    $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off',    $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function enriquecer(array $rows): array
    {
        foreach ($rows as &$r) {
            $r = $this->enriquecerUno($r);
        }
        unset($r);
        return $rows;
    }

    private function enriquecerUno(array $r): array
    {
        $r['precio_venta']        = (float)($r['precio_venta']        ?? 0);
        $r['cantidad_disponible'] = (float)($r['cantidad_disponible'] ?? 0);
        $stock = $r['cantidad_disponible'];
        $min   = (float)($r['stock_minimo'] ?? 0);

        if ($stock <= 0) {
            $r['estado_stock'] = 'sin_stock';
            $r['estado_texto'] = 'Sin stock';
        } elseif ($min > 0 && $stock <= $min) {
            $r['estado_stock'] = 'stock_bajo';
            $r['estado_texto'] = 'Stock bajo';
        } else {
            $r['estado_stock'] = 'disponible';
            $r['estado_texto'] = 'Disponible';
        }

        // Barcodes
        $r['barcodes'] = $this->barcodesDeProducto((string)($r['id_producto'] ?? ''));
        if (empty($r['barcodes'])) {
            $r['barcodes'] = [$r['sku'] ?? ''];
        }

        // Quitar columna auxiliar de ranking
        unset($r['_relevancia']);

        return $r;
    }

    private function barcodesDeProducto(string $idProducto): array
    {
        if ($idProducto === '') return [];
        $stmt = $this->db->prepare(
            "SELECT codigo_barras FROM codigosdebarra
             WHERE id_producto = :id AND estado = 1"
        );
        $stmt->execute([':id' => $idProducto]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
}
