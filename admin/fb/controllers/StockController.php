<?php
/**
 * fb/controllers/StockController.php
 *
 * Stock por sucursal y descuento de stock al emitir documentos.
 * Tablas: stock_actual, hechos_inventario, hechos_ventas
 */

namespace Fb\Controllers;

use Fb\Database;
use PDO;

class StockController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    // ── action=stock_producto ─────────────────────────────────────────────────
    // GET ?action=stock_producto&id_producto=ABC&sucursal_id=5

    public function stockProducto(array $params): array
    {
        $idProducto = trim($params['id_producto'] ?? '');
        $sucursalId = trim($params['sucursal_id'] ?? '');

        if ($idProducto === '' || $sucursalId === '') {
            return ['ok' => false, 'error' => 'id_producto y sucursal_id son requeridos'];
        }

        $stmt = $this->db->prepare("
            SELECT
                sa.stock_actual,
                sa.stock_minimo,
                sa.fecha_actualizacion
            FROM stock_actual sa
            WHERE sa.id_producto_fk  = :id
              AND sa.id_sucursal_fk  = :suc
            LIMIT 1
        ");
        $stmt->execute([':id' => $idProducto, ':suc' => $sucursalId]);
        $row = $stmt->fetch();

        $stock = $row ? (float)$row['stock_actual'] : 0.0;
        $min   = $row ? (float)$row['stock_minimo']  : 0.0;

        return [
            'ok'          => true,
            'id_producto' => $idProducto,
            'sucursal_id' => $sucursalId,
            'stock'       => $stock,
            'stock_minimo'=> $min,
            'estado'      => $stock <= 0 ? 'sin_stock' : ($min > 0 && $stock <= $min ? 'stock_bajo' : 'disponible'),
            'actualizado' => $row['fecha_actualizacion'] ?? null,
        ];
    }

    // ── action=stock_multiple ─────────────────────────────────────────────────
    // POST ?action=stock_multiple  body: { sucursal_id, ids: ["A","B",...] }

    public function stockMultiple(array $params): array
    {
        $sucursalId = trim($params['sucursal_id'] ?? '');
        $ids        = $params['ids'] ?? [];

        if ($sucursalId === '' || empty($ids)) {
            return ['ok' => false, 'error' => 'sucursal_id e ids[] son requeridos'];
        }

        $ids = array_map('strval', array_unique(array_filter($ids)));
        if (count($ids) > 500) {
            return ['ok' => false, 'error' => 'Máximo 500 productos por consulta'];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            SELECT
                sa.id_producto_fk  AS id_producto,
                sa.stock_actual,
                sa.stock_minimo
            FROM stock_actual sa
            WHERE sa.id_sucursal_fk = ?
              AND sa.id_producto_fk IN ($placeholders)
        ");
        $stmt->execute(array_merge([$sucursalId], $ids));
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $r) {
            $stock = (float)$r['stock_actual'];
            $min   = (float)$r['stock_minimo'];
            $result[$r['id_producto']] = [
                'stock'  => $stock,
                'estado' => $stock <= 0 ? 'sin_stock' : ($min > 0 && $stock <= $min ? 'stock_bajo' : 'disponible'),
            ];
        }

        return ['ok' => true, 'sucursal_id' => $sucursalId, 'data' => $result];
    }

    // ── action=venta_registrar ────────────────────────────────────────────────
    // POST ?action=venta_registrar
    // Body: { sucursal_id, folio_dte, tipo_dte, items: [{id_producto, cantidad, precio_unitario}] }
    //
    // Descuenta stock en stock_actual y registra en hechos_ventas.
    // Se llama DESPUÉS de que dte_php confirme la emisión del documento.

    public function registrarVenta(array $params): array
    {
        $sucursalId = trim($params['sucursal_id'] ?? '');
        $folio      = trim($params['folio_dte']   ?? '');
        $tipoDte    = (int)($params['tipo_dte']    ?? 39);
        $items      = $params['items']             ?? [];

        if ($sucursalId === '' || empty($items)) {
            return ['ok' => false, 'error' => 'sucursal_id e items[] son requeridos'];
        }

        try {
            $this->db->beginTransaction();

            $stmtDesc = $this->db->prepare("
                UPDATE stock_actual
                SET    stock_actual       = GREATEST(0, stock_actual - :qty),
                       fecha_actualizacion = NOW()
                WHERE  id_producto_fk  = :id
                  AND  id_sucursal_fk  = :suc
            ");

            $stmtVenta = $this->db->prepare("
                INSERT INTO hechos_ventas
                    (id_sucursal_fk, id_producto_fk, id_fecha_fk,
                     nrodocto, precio_unitario, cantidad, total_linea,
                     ventas_boleta, fecha_hora_trx, estado_caja, estado_venta)
                VALUES
                    (:suc, :id, :fecha_fk,
                     :folio, :precio, :qty, :total,
                     :total_doc, NOW(), 'COBRADA', 'ACTIVA')
            ");

            $fechaFk  = (int)date('Ymd');
            $totalDoc = 0.0;
            foreach ($items as $item) {
                $totalDoc += (float)($item['precio_unitario'] ?? 0) * (float)($item['cantidad'] ?? 1);
            }

            foreach ($items as $item) {
                $idProd  = trim($item['id_producto'] ?? '');
                $qty     = max(0.0, (float)($item['cantidad']       ?? 1));
                $precio  = max(0.0, (float)($item['precio_unitario'] ?? 0));
                $total   = round($precio * $qty);

                if ($idProd === '' || $qty <= 0) continue;

                // Descontar stock
                $stmtDesc->execute([':qty' => $qty, ':id' => $idProd, ':suc' => $sucursalId]);

                // Registrar en hechos_ventas
                $stmtVenta->execute([
                    ':suc'       => $sucursalId,
                    ':id'        => $idProd,
                    ':fecha_fk'  => $fechaFk,
                    ':folio'     => "DTE-{$tipoDte}-{$folio}",
                    ':precio'    => $precio,
                    ':qty'       => $qty,
                    ':total'     => $total,
                    ':total_doc' => $totalDoc,
                ]);
            }

            $this->db->commit();

            return ['ok' => true, 'folio_dte' => $folio, 'items_procesados' => count($items)];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('fb/StockController::registrarVenta: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Error registrando venta: ' . $e->getMessage()];
        }
    }
}
