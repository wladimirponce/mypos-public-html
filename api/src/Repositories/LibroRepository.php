<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class LibroRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function sucursalExists(int $empresaId, int $sucursalId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM sucursales WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1'
        );
        $statement->execute(['id' => $sucursalId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function libroVentas(int $empresaId, ?int $sucursalId, string $from, string $to, array $filters): array
    {
        [$where, $params] = $this->documentWhere($empresaId, $sucursalId, $from, $to, $filters);
        $statement = $this->connection->prepare(
            "SELECT d.id AS documento_emitido_id,
                    DATE(d.fecha_emision) AS fecha_emision,
                    d.tipo_documento,
                    d.folio,
                    d.estado,
                    d.rut_receptor,
                    d.razon_social_receptor,
                    d.neto,
                    d.exento,
                    d.impuestos,
                    d.total,
                    d.venta_id
             FROM documentos_emitidos d
             WHERE {$where}
             ORDER BY d.fecha_emision, d.tipo_documento, CAST(d.folio AS UNSIGNED), d.id"
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function totalesVentas(int $empresaId, ?int $sucursalId, string $from, string $to, array $filters): array
    {
        [$where, $params] = $this->documentWhere($empresaId, $sucursalId, $from, $to, $filters);
        $statement = $this->connection->prepare(
            "SELECT COUNT(d.id) AS cantidad_documentos,
                    COALESCE(SUM(d.neto), 0) AS neto,
                    COALESCE(SUM(d.exento), 0) AS exento,
                    COALESCE(SUM(d.impuestos), 0) AS impuestos,
                    COALESCE(SUM(d.total), 0) AS total
             FROM documentos_emitidos d
             WHERE {$where}"
        );
        $statement->execute($params);

        return $statement->fetch() ?: [];
    }

    public function libroCompras(int $empresaId, ?int $sucursalId, string $from, string $to, array $filters): array
    {
        [$where, $params] = $this->purchaseWhere($empresaId, $sucursalId, $from, $to, $filters);
        $statement = $this->connection->prepare(
            "SELECT c.id AS compra_id,
                    c.fecha_documento,
                    c.tipo_documento,
                    c.folio,
                    c.proveedor_id,
                    COALESCE(p.razon_social, 'Sin proveedor') AS proveedor_nombre,
                    c.subtotal AS neto,
                    0 AS exento,
                    c.impuesto_total AS impuestos,
                    c.total,
                    c.estado,
                    di.id AS documento_ia_id
             FROM compras c
             LEFT JOIN proveedores p ON p.id = c.proveedor_id AND p.empresa_id = c.empresa_id
             LEFT JOIN documentos_ia di ON di.compra_id = c.id AND di.empresa_id = c.empresa_id
             WHERE {$where}
             ORDER BY c.fecha_documento, c.tipo_documento, c.folio, c.id"
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function totalesCompras(int $empresaId, ?int $sucursalId, string $from, string $to, array $filters): array
    {
        [$where, $params] = $this->purchaseWhere($empresaId, $sucursalId, $from, $to, $filters);
        $statement = $this->connection->prepare(
            "SELECT COUNT(c.id) AS cantidad_documentos,
                    COALESCE(SUM(c.subtotal), 0) AS neto,
                    0 AS exento,
                    COALESCE(SUM(c.impuesto_total), 0) AS impuestos,
                    COALESCE(SUM(c.total), 0) AS total
             FROM compras c
             WHERE {$where}"
        );
        $statement->execute($params);

        return $statement->fetch() ?: [];
    }

    public function resumenVentasPorTipo(int $empresaId, ?int $sucursalId, string $from, string $to): array
    {
        [$where, $params] = $this->documentWhere($empresaId, $sucursalId, $from, $to, []);
        $statement = $this->connection->prepare(
            "SELECT d.tipo_documento,
                    COUNT(d.id) AS cantidad_documentos,
                    COALESCE(SUM(d.neto), 0) AS neto,
                    COALESCE(SUM(d.exento), 0) AS exento,
                    COALESCE(SUM(d.impuestos), 0) AS impuestos,
                    COALESCE(SUM(d.total), 0) AS total
             FROM documentos_emitidos d
             WHERE {$where}
             GROUP BY d.tipo_documento
             ORDER BY d.tipo_documento"
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function resumenComprasPorProveedor(int $empresaId, ?int $sucursalId, string $from, string $to, int $limit): array
    {
        [$where, $params] = $this->purchaseWhere($empresaId, $sucursalId, $from, $to, []);
        $statement = $this->connection->prepare(
            "SELECT c.proveedor_id,
                    COALESCE(p.razon_social, 'Sin proveedor') AS proveedor_nombre,
                    COUNT(c.id) AS cantidad_documentos,
                    COALESCE(SUM(c.subtotal), 0) AS neto,
                    0 AS exento,
                    COALESCE(SUM(c.impuesto_total), 0) AS impuestos,
                    COALESCE(SUM(c.total), 0) AS total
             FROM compras c
             LEFT JOIN proveedores p ON p.id = c.proveedor_id AND p.empresa_id = c.empresa_id
             WHERE {$where}
             GROUP BY c.proveedor_id, p.razon_social
             ORDER BY total DESC
             LIMIT {$limit}"
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    private function documentWhere(int $empresaId, ?int $sucursalId, string $from, string $to, array $filters): array
    {
        $where = "d.empresa_id = :empresa_id
            AND DATE(d.fecha_emision) >= :fecha_desde
            AND DATE(d.fecha_emision) <= :fecha_hasta";
        $params = ['empresa_id' => $empresaId, 'fecha_desde' => $from, 'fecha_hasta' => $to];

        if ($sucursalId !== null) {
            $where .= ' AND d.sucursal_id = :sucursal_id';
            $params['sucursal_id'] = $sucursalId;
        }

        if (!empty($filters['tipo_documento'])) {
            $where .= ' AND d.tipo_documento = :tipo_documento';
            $params['tipo_documento'] = strtoupper((string) $filters['tipo_documento']);
        }

        if (!empty($filters['estado'])) {
            $where .= ' AND d.estado = :estado';
            $params['estado'] = strtoupper((string) $filters['estado']);
        } else {
            $states = $this->boolValue($filters['incluir_anulados'] ?? false)
                ? ['EMITIDO_INTERNO', 'ENVIADO_SII', 'ACEPTADO_SII', 'RECHAZADO_SII', 'ANULADO']
                : ['EMITIDO_INTERNO', 'ENVIADO_SII', 'ACEPTADO_SII', 'RECHAZADO_SII'];
            $where .= " AND d.estado IN ('" . implode("','", $states) . "')";
        }

        return [$where, $params];
    }

    private function purchaseWhere(int $empresaId, ?int $sucursalId, string $from, string $to, array $filters): array
    {
        $where = "c.empresa_id = :empresa_id
            AND c.fecha_documento >= :fecha_desde
            AND c.fecha_documento <= :fecha_hasta";
        $params = ['empresa_id' => $empresaId, 'fecha_desde' => $from, 'fecha_hasta' => $to];

        if ($sucursalId !== null) {
            $where .= ' AND c.sucursal_id = :sucursal_id';
            $params['sucursal_id'] = $sucursalId;
        }

        if (!empty($filters['proveedor_id'])) {
            $where .= ' AND c.proveedor_id = :proveedor_id';
            $params['proveedor_id'] = (int) $filters['proveedor_id'];
        }

        if (!empty($filters['estado'])) {
            $where .= ' AND c.estado = :estado';
            $params['estado'] = strtoupper((string) $filters['estado']);
        } else {
            $states = $this->boolValue($filters['incluir_anuladas'] ?? false)
                ? ['CONFIRMADA', 'ANULADA', 'REVERSADA']
                : ['CONFIRMADA'];
            $where .= " AND c.estado IN ('" . implode("','", $states) . "')";
        }

        return [$where, $params];
    }

    private function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
