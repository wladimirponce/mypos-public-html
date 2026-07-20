<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use PDO;

final class AnalyticsSnapshotService
{
    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= Database::connection();
    }

    /** @return array{fecha:string,ventas:int,productos:int,cajas:int,proveedores:int} */
    public function actualizar(string $fecha, ?int $empresaId = null): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if (!$date || $date->format('Y-m-d') !== $fecha) {
            throw new \InvalidArgumentException('Fecha analitica invalida');
        }

        $this->db->beginTransaction();
        try {
            $ventas = $this->actualizarVentas($fecha, $empresaId);
            $productos = $this->actualizarProductos($fecha, $empresaId);
            $cajas = $this->actualizarCajas($fecha, $empresaId);
            $proveedores = $this->actualizarProveedores($fecha, $empresaId);
            $this->db->commit();
            return compact('fecha', 'ventas', 'productos', 'cajas', 'proveedores');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function actualizarVentas(string $fecha, ?int $empresaId): int
    {
        $filtro = $empresaId ? ' AND v.empresa_id = :empresa_id' : '';
        $sql = "INSERT INTO analytics_ventas_diarias
            (empresa_id, sucursal_id, fecha, hora, ventas, anulaciones, unidades, total, descuentos, margen)
            SELECT h.empresa_id,h.sucursal_id,h.fecha,h.hora,h.ventas,h.anulaciones,
                   COALESCE(d.unidades,0),h.total,h.descuentos,COALESCE(d.margen,0)
            FROM (
                SELECT v.empresa_id,v.sucursal_id,DATE(v.fecha_venta) fecha,HOUR(v.fecha_venta) hora,
                       SUM(v.estado='REGISTRADA') ventas,SUM(v.estado='ANULADA') anulaciones,
                       SUM(CASE WHEN v.estado='REGISTRADA' THEN v.total ELSE 0 END) total,
                       SUM(CASE WHEN v.estado='REGISTRADA' THEN v.descuento_total ELSE 0 END) descuentos
                FROM ventas v WHERE DATE(v.fecha_venta)=:fecha{$filtro}
                GROUP BY v.empresa_id,v.sucursal_id,DATE(v.fecha_venta),HOUR(v.fecha_venta)
            ) h LEFT JOIN (
                SELECT v.empresa_id,v.sucursal_id,DATE(v.fecha_venta) fecha,HOUR(v.fecha_venta) hora,
                       SUM(vd.cantidad) unidades,SUM(vd.margen_total) margen
                FROM ventas v INNER JOIN venta_detalles vd ON vd.venta_id=v.id AND vd.empresa_id=v.empresa_id
                WHERE DATE(v.fecha_venta)=:fecha_detalle AND v.estado='REGISTRADA'" . ($empresaId ? ' AND v.empresa_id = :empresa_id_detalle' : '') . "
                GROUP BY v.empresa_id,v.sucursal_id,DATE(v.fecha_venta),HOUR(v.fecha_venta)
            ) d ON d.empresa_id=h.empresa_id AND d.sucursal_id=h.sucursal_id AND d.fecha=h.fecha AND d.hora=h.hora
            ON DUPLICATE KEY UPDATE ventas=VALUES(ventas), anulaciones=VALUES(anulaciones), unidades=VALUES(unidades), total=VALUES(total), descuentos=VALUES(descuentos), margen=VALUES(margen)";
        $stmt = $this->db->prepare($sql);
        $params = ['fecha' => $fecha, 'fecha_detalle' => $fecha];
        if ($empresaId) {
            $params['empresa_id'] = $empresaId;
            $params['empresa_id_detalle'] = $empresaId;
        }
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private function actualizarProductos(string $fecha, ?int $empresaId): int
    {
        $filtro = $empresaId ? ' AND p.empresa_id = :empresa_id' : '';
        $sql = "INSERT INTO analytics_producto_sucursal_diario
            (empresa_id, sucursal_id, producto_id, fecha, unidades_vendidas, venta_neta, margen, stock_cierre, reservado_cierre, valor_stock)
            SELECT p.empresa_id, s.id, p.id, :fecha,
                   COALESCE(sd.unidades,0),COALESCE(sd.venta_neta,0),COALESCE(sd.margen,0),
                   COALESCE(st.stock,0),COALESCE(st.reservado,0),ROUND(COALESCE(st.stock,0)*p.costo_actual)
            FROM productos p
            INNER JOIN sucursales s ON s.empresa_id=p.empresa_id AND s.activo=1
            LEFT JOIN (
                SELECT v.empresa_id,v.sucursal_id,vd.producto_id,SUM(vd.cantidad) unidades,SUM(vd.total) venta_neta,SUM(vd.margen_total) margen
                FROM ventas v INNER JOIN venta_detalles vd ON vd.venta_id=v.id AND vd.empresa_id=v.empresa_id
                WHERE v.estado='REGISTRADA' AND v.fecha_venta>=:desde AND v.fecha_venta<:hasta
                GROUP BY v.empresa_id,v.sucursal_id,vd.producto_id
            ) sd ON sd.empresa_id=p.empresa_id AND sd.sucursal_id=s.id AND sd.producto_id=p.id
            LEFT JOIN (
                SELECT su.empresa_id,u.sucursal_id,su.producto_id,SUM(su.cantidad) stock,SUM(su.reservado) reservado
                FROM stock_ubicacion su INNER JOIN ubicaciones_stock u ON u.id=su.ubicacion_id AND u.empresa_id=su.empresa_id AND u.activo=1
                WHERE u.sucursal_id IS NOT NULL GROUP BY su.empresa_id,u.sucursal_id,su.producto_id
            ) st ON st.empresa_id=p.empresa_id AND st.sucursal_id=s.id AND st.producto_id=p.id
            WHERE p.activo=1{$filtro}
            ON DUPLICATE KEY UPDATE unidades_vendidas=VALUES(unidades_vendidas), venta_neta=VALUES(venta_neta), margen=VALUES(margen), stock_cierre=VALUES(stock_cierre), reservado_cierre=VALUES(reservado_cierre), valor_stock=VALUES(valor_stock)";
        $params = [
            'fecha' => $fecha,
            'desde' => $fecha . ' 00:00:00', 'hasta' => date('Y-m-d H:i:s', strtotime($fecha . ' +1 day')),
        ];
        if ($empresaId) $params['empresa_id'] = $empresaId;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private function actualizarCajas(string $fecha, ?int $empresaId): int
    {
        $filtro = $empresaId ? ' AND cc.empresa_id = :empresa_id' : '';
        $sql = "INSERT INTO analytics_caja_diaria
            (empresa_id,sucursal_id,fecha,cierres,cierres_con_diferencia,diferencia_absoluta,diferencia_neta)
            SELECT cc.empresa_id,cc.sucursal_id,DATE(cc.fecha_cierre),COUNT(*),SUM(cc.diferencia<>0),SUM(ABS(cc.diferencia)),SUM(cc.diferencia)
            FROM caja_cierres cc WHERE DATE(cc.fecha_cierre)=:fecha{$filtro}
            GROUP BY cc.empresa_id,cc.sucursal_id,DATE(cc.fecha_cierre)
            ON DUPLICATE KEY UPDATE cierres=VALUES(cierres),cierres_con_diferencia=VALUES(cierres_con_diferencia),diferencia_absoluta=VALUES(diferencia_absoluta),diferencia_neta=VALUES(diferencia_neta)";
        return $this->execute($sql, $fecha, $empresaId);
    }

    private function actualizarProveedores(string $fecha, ?int $empresaId): int
    {
        $filtro = $empresaId ? ' AND oc.empresa_id = :empresa_id' : '';
        $sql = "INSERT INTO analytics_proveedor_desempeno
            (empresa_id,proveedor_id,fecha_calculo,ordenes_recibidas,entregas_atrasadas,plazo_real_promedio,cumplimiento_pct)
            SELECT oc.empresa_id,oc.proveedor_id,:fecha,COUNT(DISTINCT oc.id),
                   SUM(DATE(r.primera_recepcion)>oc.fecha_entrega_esperada),
                   AVG(DATEDIFF(r.primera_recepcion,oc.fecha_emision)),
                   ROUND(100*(1-SUM(DATE(r.primera_recepcion)>oc.fecha_entrega_esperada)/GREATEST(COUNT(DISTINCT oc.id),1)),2)
            FROM ordenes_compra oc
            INNER JOIN (SELECT orden_id,MIN(fecha) primera_recepcion FROM ordenes_compra_recepciones GROUP BY orden_id) r ON r.orden_id=oc.id
            WHERE oc.fecha_entrega_esperada IS NOT NULL{$filtro}
            GROUP BY oc.empresa_id,oc.proveedor_id
            ON DUPLICATE KEY UPDATE ordenes_recibidas=VALUES(ordenes_recibidas),entregas_atrasadas=VALUES(entregas_atrasadas),plazo_real_promedio=VALUES(plazo_real_promedio),cumplimiento_pct=VALUES(cumplimiento_pct)";
        return $this->execute($sql, $fecha, $empresaId);
    }

    private function execute(string $sql, string $fecha, ?int $empresaId): int
    {
        $stmt = $this->db->prepare($sql);
        $params = ['fecha' => $fecha];
        if ($empresaId) $params['empresa_id'] = $empresaId;
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
