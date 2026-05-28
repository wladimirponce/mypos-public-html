<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class VentaRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function sucursalExists(int $empresaId, int $sucursalId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM sucursales WHERE id = :sucursal_id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1');
        $statement->execute(['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId]);

        return (bool) $statement->fetchColumn();
    }

    public function findProductById(int $empresaId, int $productId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT p.id, p.empresa_id, p.codigo, p.sku, p.nombre, p.precio_venta,
                    p.precio_costo, p.costo_actual, p.controla_stock
             FROM productos p
             WHERE p.id = :id AND p.empresa_id = :empresa_id AND p.activo = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $productId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findProductByCode(int $empresaId, string $code): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT p.id, p.empresa_id, p.codigo, p.sku, p.nombre, p.precio_venta,
                    p.precio_costo, p.costo_actual, p.controla_stock,
                    pcb.codigo_barra AS codigo_barra_usado
             FROM productos p
             LEFT JOIN productos_codigos_barra pcb ON pcb.producto_id = p.id
                AND pcb.empresa_id = p.empresa_id
                AND pcb.codigo_barra = :codigo_barra_join
                AND pcb.activo = 1
             WHERE p.empresa_id = :empresa_id
               AND p.activo = 1
               AND (p.codigo = :codigo OR p.sku = :sku OR pcb.codigo_barra = :codigo_barra)
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'codigo' => $code,
            'sku' => $code,
            'codigo_barra_join' => $code,
            'codigo_barra' => $code,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function activeDiscounts(int $empresaId, int $productId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, tipo_descuento, valor_descuento, tipo, valor, acumulable
             FROM productos_descuentos
             WHERE empresa_id = :empresa_id
               AND producto_id = :producto_id
               AND activo = 1
               AND (fecha_inicio IS NULL OR fecha_inicio <= NOW())
               AND (fecha_fin IS NULL OR fecha_fin >= NOW())
             ORDER BY valor_descuento DESC, valor DESC'
        );
        $statement->execute(['empresa_id' => $empresaId, 'producto_id' => $productId]);

        return $statement->fetchAll();
    }

    public function activeTaxes(int $empresaId, int $productId): array
    {
        $statement = $this->connection->prepare(
            'SELECT pi.id, pi.impuesto_id, pi.orden_aplicacion, pi.incluido_en_precio,
                    i.codigo, i.nombre, i.tipo, i.porcentaje, i.monto_fijo
             FROM producto_impuestos pi
             INNER JOIN impuestos i ON i.id = pi.impuesto_id
             WHERE pi.empresa_id = :empresa_id
               AND pi.producto_id = :producto_id
               AND pi.activo = 1
               AND i.activo = 1
             ORDER BY pi.orden_aplicacion, pi.id'
        );
        $statement->execute(['empresa_id' => $empresaId, 'producto_id' => $productId]);

        return $statement->fetchAll();
    }

    public function activeCommissions(int $empresaId, int $productId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, tipo_comision, valor_comision, tipo, valor
             FROM productos_comisiones
             WHERE empresa_id = :empresa_id
               AND producto_id = :producto_id
               AND activo = 1
               AND (fecha_inicio IS NULL OR fecha_inicio <= NOW())
               AND (fecha_fin IS NULL OR fecha_fin >= NOW())
             ORDER BY id'
        );
        $statement->execute(['empresa_id' => $empresaId, 'producto_id' => $productId]);

        return $statement->fetchAll();
    }

    public function activePaymentMethod(string $code): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, codigo, nombre FROM metodos_pago WHERE codigo = :codigo AND activo = 1 LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function clienteActivo(int $empresaId, int $clienteId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, nombre, razon_social, permite_credito, limite_credito, activo
             FROM clientes
             WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $clienteId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function openCashOpening(int $empresaId, int $sucursalId, int $openingId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT ca.id, ca.caja_id, ca.estado
             FROM caja_aperturas ca
             WHERE ca.id = :id
               AND ca.empresa_id = :empresa_id
               AND ca.sucursal_id = :sucursal_id
               AND ca.estado = \'ABIERTA\'
             LIMIT 1'
        );
        $statement->execute([
            'id' => $openingId,
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function insertSale(array $data): int
    {
        $data['uuid'] = $data['uuid'] ?? $data['uuid_offline'] ?? null;
        $data['uuid_offline'] = $data['uuid_offline'] ?? null;
        $data['dispositivo_id'] = $data['dispositivo_id'] ?? null;
        $data['sync_evento_id'] = $data['sync_evento_id'] ?? null;
        $data['origen'] = $data['origen'] ?? 'ONLINE';
        $data['sync_estado'] = $data['sync_estado'] ?? 'SYNC_OK';
        $data['created_offline_at'] = $data['created_offline_at'] ?? null;
        $data['sync_status'] = $data['origen'] === 'OFFLINE' ? 'SYNCED' : 'SYNCED';

        $statement = $this->connection->prepare(
            'INSERT INTO ventas (
                empresa_id, sucursal_id, caja_id, apertura_id, caja_apertura_id, usuario_id, cliente_id,
                uuid, uuid_offline, dispositivo_id, sync_evento_id, origen, sync_estado, created_offline_at, sync_status,
                tipo_venta, condicion_pago, subtotal, descuento_total, impuesto_total, total,
                margen_total, comision_total
             ) VALUES (
                :empresa_id, :sucursal_id, :caja_id, :apertura_id, :caja_apertura_id, :usuario_id, :cliente_id,
                :uuid, :uuid_offline, :dispositivo_id, :sync_evento_id, :origen, :sync_estado, :created_offline_at, :sync_status,
                :tipo_venta, :condicion_pago, :subtotal, :descuento_total, :impuesto_total, :total,
                :margen_total, :comision_total
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function updateSaleCredit(int $empresaId, int $saleId, int $creditId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE ventas
             SET credito_cliente_id = :credito_cliente_id, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['credito_cliente_id' => $creditId, 'id' => $saleId, 'empresa_id' => $empresaId]);
    }

    public function insertSaleDetail(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO venta_detalles (
                empresa_id, venta_id, producto_id, linea, codigo_producto, codigo_barra_usado,
                nombre_producto, cantidad, precio_unitario, costo_unitario, descuento_tipo,
                descuento_valor, subtotal, descuento_total, neto, impuestos_total, exento,
                impuesto_total, total, margen_total, margen_estimado, comision_total,
                comision_vendedor
             ) VALUES (
                :empresa_id, :venta_id, :producto_id, :linea, :codigo_producto, :codigo_barra_usado,
                :nombre_producto, :cantidad, :precio_unitario, :costo_unitario, :descuento_tipo,
                :descuento_valor, :subtotal, :descuento_total, :neto, :impuestos_total, :exento,
                :impuesto_total, :total, :margen_total, :margen_estimado, :comision_total,
                :comision_vendedor
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function insertSaleDetailTax(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO venta_detalle_impuestos (
                empresa_id, venta_id, venta_detalle_id, impuesto_id, codigo_impuesto,
                nombre_impuesto, tipo_impuesto, porcentaje, monto_fijo, base_calculo,
                monto_impuesto
             ) VALUES (
                :empresa_id, :venta_id, :venta_detalle_id, :impuesto_id, :codigo_impuesto,
                :nombre_impuesto, :tipo_impuesto, :porcentaje, :monto_fijo, :base_calculo,
                :monto_impuesto
             )'
        );
        $statement->execute($data);
    }

    public function insertPayment(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO venta_pagos (empresa_id, venta_id, metodo_pago_id, metodo_pago_codigo, monto, referencia)
             VALUES (:empresa_id, :venta_id, :metodo_pago_id, :metodo_pago_codigo, :monto, :referencia)'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }
}
