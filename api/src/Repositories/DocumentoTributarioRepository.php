<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class DocumentoTributarioRepository
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
        $statement = $this->connection->prepare(
            'SELECT 1 FROM sucursales WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1'
        );
        $statement->execute(['id' => $sucursalId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function findSale(int $empresaId, int $saleId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT v.id, v.empresa_id, v.sucursal_id, v.usuario_id, v.cliente_id,
                    v.tipo_venta, v.estado, v.subtotal, v.descuento_total,
                    v.impuesto_total, v.total, v.fecha_venta,
                    c.rut AS cliente_rut, c.razon_social AS cliente_razon_social,
                    c.direccion AS cliente_direccion, c.comuna AS cliente_comuna,
                    c.ciudad AS cliente_ciudad
             FROM ventas v
             LEFT JOIN clientes c ON c.id = v.cliente_id AND c.empresa_id = v.empresa_id
             WHERE v.id = :id AND v.empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $saleId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function activeDocumentForSaleType(int $empresaId, int $saleId, string $type): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, estado
             FROM documentos_emitidos
             WHERE empresa_id = :empresa_id
               AND venta_id = :venta_id
               AND tipo_documento = :tipo_documento
               AND estado <> \'ANULADO\'
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'venta_id' => $saleId,
            'tipo_documento' => $type,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function saleDetails(int $empresaId, int $saleId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, producto_id, codigo_producto, nombre_producto, cantidad,
                    precio_unitario, descuento_total, neto, exento, impuestos_total, total
             FROM venta_detalles
             WHERE empresa_id = :empresa_id AND venta_id = :venta_id
             ORDER BY linea'
        );
        $statement->execute(['empresa_id' => $empresaId, 'venta_id' => $saleId]);

        return $statement->fetchAll();
    }

    public function saleTaxes(int $empresaId, int $saleId): array
    {
        $statement = $this->connection->prepare(
            'SELECT impuesto_id, codigo_impuesto, nombre_impuesto,
                    porcentaje AS tasa_base_10000, SUM(monto_impuesto) AS monto
             FROM venta_detalle_impuestos
             WHERE empresa_id = :empresa_id AND venta_id = :venta_id
             GROUP BY impuesto_id, codigo_impuesto, nombre_impuesto, porcentaje
             ORDER BY codigo_impuesto'
        );
        $statement->execute(['empresa_id' => $empresaId, 'venta_id' => $saleId]);

        return $statement->fetchAll();
    }

    public function createDocument(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO documentos_emitidos (
                empresa_id, sucursal_id, venta_id, cliente_id, tipo_documento, folio,
                folio_origen, estado, total, fecha_emision, rut_receptor,
                razon_social_receptor, giro_receptor, direccion_receptor, comuna_receptor,
                ciudad_receptor, neto, exento, impuestos, payload_json, metadata_json,
                created_by_usuario_id
             ) VALUES (
                :empresa_id, :sucursal_id, :venta_id, :cliente_id, :tipo_documento, :folio,
                :folio_origen, :estado, :total, :fecha_emision, :rut_receptor,
                :razon_social_receptor, :giro_receptor, :direccion_receptor, :comuna_receptor,
                :ciudad_receptor, :neto, :exento, :impuestos, :payload_json, :metadata_json,
                :created_by_usuario_id
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function insertDetail(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO documento_emitido_detalles (
                documento_emitido_id, venta_detalle_id, producto_id, codigo_producto,
                nombre_producto, cantidad, precio_unitario, descuento, neto, exento,
                impuestos, total
             ) VALUES (
                :documento_emitido_id, :venta_detalle_id, :producto_id, :codigo_producto,
                :nombre_producto, :cantidad, :precio_unitario, :descuento, :neto, :exento,
                :impuestos, :total
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function insertTax(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO documento_emitido_impuestos (
                documento_emitido_id, impuesto_id, codigo_impuesto, nombre_impuesto,
                tasa_base_10000, monto
             ) VALUES (
                :documento_emitido_id, :impuesto_id, :codigo_impuesto, :nombre_impuesto,
                :tasa_base_10000, :monto
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function list(int $empresaId, array $filters): array
    {
        $sql = 'SELECT id, empresa_id, sucursal_id, venta_id, cliente_id, tipo_documento,
                       folio, folio_origen, estado, fecha_emision, total, created_at
                FROM documentos_emitidos
                WHERE empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        foreach (['sucursal_id', 'venta_id', 'cliente_id'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND {$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }

        foreach (['tipo_documento', 'estado'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND {$field} = :{$field}";
                $params[$field] = strtoupper((string) $filters[$field]);
            }
        }

        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND DATE(fecha_emision) >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }

        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND DATE(fecha_emision) <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }

        $sql .= ' ORDER BY id DESC LIMIT 300';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function find(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT d.id, d.empresa_id, d.sucursal_id, s.nombre AS sucursal_nombre,
                    d.venta_id, d.cliente_id, d.tipo_documento, d.folio, d.folio_origen,
                    d.estado, d.fecha_emision, d.rut_receptor, d.razon_social_receptor,
                    d.giro_receptor, d.direccion_receptor, d.comuna_receptor,
                    d.ciudad_receptor, d.neto, d.exento, d.impuestos, d.total,
                    d.xml_path, d.pdf_path, d.track_id, d.respuesta_sii_json,
                    d.error_sii, d.referencia_documento_id, d.motivo_anulacion,
                    d.metadata_json, d.created_by_usuario_id, d.created_at, d.updated_at
             FROM documentos_emitidos d
             LEFT JOIN sucursales s ON s.id = d.sucursal_id
             WHERE d.id = :id AND d.empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function documentDetails(int $documentId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, documento_emitido_id, venta_detalle_id, producto_id,
                    codigo_producto, nombre_producto, cantidad, precio_unitario,
                    descuento, neto, exento, impuestos, total, created_at
             FROM documento_emitido_detalles
             WHERE documento_emitido_id = :documento_emitido_id
             ORDER BY id'
        );
        $statement->execute(['documento_emitido_id' => $documentId]);

        return $statement->fetchAll();
    }

    public function documentTaxes(int $documentId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, documento_emitido_id, impuesto_id, codigo_impuesto,
                    nombre_impuesto, tasa_base_10000, monto, created_at
             FROM documento_emitido_impuestos
             WHERE documento_emitido_id = :documento_emitido_id
             ORDER BY id'
        );
        $statement->execute(['documento_emitido_id' => $documentId]);

        return $statement->fetchAll();
    }

    public function relatedSale(int $empresaId, ?int $saleId): ?array
    {
        if ($saleId === null || $saleId <= 0) {
            return null;
        }

        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, cliente_id, tipo_venta, estado,
                    total, fecha_venta
             FROM ventas
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $saleId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function updateStatus(int $empresaId, int $id, array $data): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_emitidos
             SET estado = :estado,
                 folio = COALESCE(:folio, folio),
                 folio_origen = COALESCE(:folio_origen, folio_origen),
                 track_id = COALESCE(:track_id, track_id),
                 respuesta_sii_json = COALESCE(:respuesta_sii_json, respuesta_sii_json),
                 error_sii = COALESCE(:error_sii, error_sii),
                 motivo_anulacion = COALESCE(:motivo_anulacion, motivo_anulacion),
                 metadata_json = COALESCE(:metadata_json, metadata_json),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $data['id'] = $id;
        $data['empresa_id'] = $empresaId;
        $statement->execute($data);
    }
}
