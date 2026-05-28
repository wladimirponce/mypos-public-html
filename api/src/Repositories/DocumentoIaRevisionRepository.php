<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class DocumentoIaRevisionRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function findDocument(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT d.id, d.empresa_id, d.sucursal_id, d.usuario_id, d.archivo_subido_id,
                    d.proveedor_id, d.compra_id, d.tipo_documento, d.tipo_documento_detectado,
                    d.proveedor_detectado, d.proveedor_rut_detectado, d.proveedor_nombre_detectado,
                    d.proveedor_confianza, d.folio, d.folio_detectado, d.fecha_detectada,
                    d.fecha_documento_detectada, d.neto_detectado, d.iva_detectado, d.exento_detectado,
                    d.total_detectado, d.total_calculado, d.diferencia_total, d.confianza_global,
                    d.requiere_revision, d.estado_revision, d.resumen_alertas_json, d.estado,
                    d.respuesta_json, d.created_at, d.updated_at, d.normalizado_at, d.revisado_at,
                    d.revisado_por_usuario_id, p.rut AS proveedor_rut, p.nombre AS proveedor_nombre,
                    p.razon_social AS proveedor_razon_social
             FROM documentos_ia d
             LEFT JOIN proveedores p ON p.id = d.proveedor_id AND p.empresa_id = d.empresa_id
             WHERE d.id = :id AND d.empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function details(int $empresaId, int $documentId): array
    {
        $statement = $this->connection->prepare(
            'SELECT dd.id, dd.empresa_id, dd.documento_ia_id, dd.producto_id,
                    pr.codigo AS producto_codigo, pr.nombre AS producto_nombre,
                    dd.linea, dd.codigo_detectado, dd.codigo_barra_detectado, dd.nombre_detectado,
                    dd.cantidad_detectada, dd.costo_unitario_detectado, dd.total_detectado,
                    dd.cantidad_normalizada, dd.costo_unitario_normalizado, dd.total_normalizado,
                    dd.cantidad, dd.costo_unitario, dd.total, dd.confianza, dd.metodo_match,
                    dd.requiere_revision, dd.alertas_json, dd.confirmado
             FROM documentos_ia_detalles dd
             LEFT JOIN productos pr ON pr.id = dd.producto_id AND pr.empresa_id = dd.empresa_id
             WHERE dd.empresa_id = :empresa_id AND dd.documento_ia_id = :documento_ia_id
             ORDER BY dd.linea'
        );
        $statement->execute(['empresa_id' => $empresaId, 'documento_ia_id' => $documentId]);

        return $statement->fetchAll();
    }

    public function findDetail(int $empresaId, int $detailId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT dd.id, dd.empresa_id, dd.documento_ia_id, dd.producto_id,
                    dd.linea, dd.codigo_detectado, dd.codigo_barra_detectado, dd.nombre_detectado,
                    dd.cantidad_detectada, dd.costo_unitario_detectado, dd.total_detectado,
                    dd.cantidad_normalizada, dd.costo_unitario_normalizado, dd.total_normalizado,
                    dd.confianza, dd.metodo_match, dd.requiere_revision, dd.confirmado
             FROM documentos_ia_detalles dd
             WHERE dd.id = :id AND dd.empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $detailId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function updateNormalizedDocument(int $empresaId, int $id, array $data): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia
             SET estado = \'PROCESADO\',
                 proveedor_id = :proveedor_id,
                 proveedor_rut_detectado = :proveedor_rut_detectado,
                 proveedor_nombre_detectado = :proveedor_nombre_detectado,
                 proveedor_detectado = :proveedor_detectado,
                 proveedor_confianza = :proveedor_confianza,
                 folio_detectado = :folio_detectado,
                 fecha_documento_detectada = :fecha_documento_detectada,
                 fecha_detectada = :fecha_detectada,
                 tipo_documento_detectado = :tipo_documento_detectado,
                 tipo_documento = :tipo_documento,
                 neto_detectado = :neto_detectado,
                 iva_detectado = :iva_detectado,
                 exento_detectado = :exento_detectado,
                 total_detectado = :total_detectado,
                 total_calculado = :total_calculado,
                 diferencia_total = :diferencia_total,
                 confianza_global = :confianza_global,
                 requiere_revision = :requiere_revision,
                 estado_revision = :estado_revision,
                 resumen_alertas_json = :resumen_alertas_json,
                 normalizado_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $data['proveedor_detectado'] = $data['proveedor_nombre_detectado'];
        $data['fecha_detectada'] = $data['fecha_documento_detectada'];
        $data['tipo_documento'] = $data['tipo_documento_detectado'];
        $data['id'] = $id;
        $data['empresa_id'] = $empresaId;
        $statement->execute($data);
    }

    public function replaceDetails(int $empresaId, int $documentId, array $details): void
    {
        $delete = $this->connection->prepare(
            'DELETE FROM documentos_ia_detalles
             WHERE empresa_id = :empresa_id AND documento_ia_id = :documento_ia_id'
        );
        $delete->execute(['empresa_id' => $empresaId, 'documento_ia_id' => $documentId]);

        $insert = $this->connection->prepare(
            'INSERT INTO documentos_ia_detalles (
                empresa_id, documento_ia_id, producto_id, linea, codigo_detectado, codigo_barra_detectado,
                nombre_detectado, cantidad_detectada, costo_unitario_detectado, total_detectado,
                cantidad_normalizada, costo_unitario_normalizado, total_normalizado,
                cantidad, costo_unitario, total, confianza, metodo_match, requiere_revision,
                alertas_json, confirmado
             ) VALUES (
                :empresa_id, :documento_ia_id, :producto_id, :linea, :codigo_detectado, :codigo_barra_detectado,
                :nombre_detectado, :cantidad_detectada, :costo_unitario_detectado, :total_detectado,
                :cantidad_normalizada, :costo_unitario_normalizado, :total_normalizado,
                :cantidad, :costo_unitario, :total, :confianza, :metodo_match, :requiere_revision,
                :alertas_json, :confirmado
             )'
        );

        foreach ($details as $detail) {
            $insert->execute($detail);
        }
    }

    public function closeOpenAlerts(int $empresaId, int $documentId, int $userId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia_alertas
             SET resuelta = 1, resuelta_por_usuario_id = :usuario_id, resuelta_at = CURRENT_TIMESTAMP
             WHERE empresa_id = :empresa_id AND documento_ia_id = :documento_ia_id AND resuelta = 0'
        );
        $statement->execute(['usuario_id' => $userId, 'empresa_id' => $empresaId, 'documento_ia_id' => $documentId]);
    }

    public function insertAlert(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO documentos_ia_alertas (
                empresa_id, documento_ia_id, documento_ia_detalle_id, tipo_alerta,
                severidad, mensaje, metadata_json
             ) VALUES (
                :empresa_id, :documento_ia_id, :documento_ia_detalle_id, :tipo_alerta,
                :severidad, :mensaje, :metadata_json
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function alerts(int $empresaId, int $documentId, ?bool $resolved = null): array
    {
        $sql = 'SELECT id, empresa_id, documento_ia_id, documento_ia_detalle_id, tipo_alerta,
                       severidad, mensaje, metadata_json, resuelta, resuelta_por_usuario_id,
                       resuelta_at, created_at
                FROM documentos_ia_alertas
                WHERE empresa_id = :empresa_id AND documento_ia_id = :documento_ia_id';
        $params = ['empresa_id' => $empresaId, 'documento_ia_id' => $documentId];

        if ($resolved !== null) {
            $sql .= ' AND resuelta = :resuelta';
            $params['resuelta'] = $resolved ? 1 : 0;
        }

        $sql .= ' ORDER BY resuelta ASC, FIELD(severidad, \'ERROR\', \'WARNING\', \'INFO\'), id ASC';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function alertCounts(int $empresaId, int $documentId): array
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN severidad = \'ERROR\' AND resuelta = 0 THEN 1 ELSE 0 END) AS error,
                    SUM(CASE WHEN severidad = \'WARNING\' AND resuelta = 0 THEN 1 ELSE 0 END) AS warning,
                    SUM(CASE WHEN severidad = \'INFO\' AND resuelta = 0 THEN 1 ELSE 0 END) AS info
             FROM documentos_ia_alertas
             WHERE empresa_id = :empresa_id AND documento_ia_id = :documento_ia_id AND resuelta = 0'
        );
        $statement->execute(['empresa_id' => $empresaId, 'documento_ia_id' => $documentId]);
        $row = $statement->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'error' => (int) ($row['error'] ?? 0),
            'warning' => (int) ($row['warning'] ?? 0),
            'info' => (int) ($row['info'] ?? 0),
        ];
    }

    public function unresolvedErrorCount(int $empresaId, int $documentId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*)
             FROM documentos_ia_alertas
             WHERE empresa_id = :empresa_id AND documento_ia_id = :documento_ia_id
               AND severidad = \'ERROR\' AND resuelta = 0'
        );
        $statement->execute(['empresa_id' => $empresaId, 'documento_ia_id' => $documentId]);

        return (int) $statement->fetchColumn();
    }

    public function resolveAlert(int $empresaId, int $alertId, int $userId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia_alertas
             SET resuelta = 1, resuelta_por_usuario_id = :usuario_id, resuelta_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id AND resuelta = 0'
        );
        $statement->execute(['usuario_id' => $userId, 'id' => $alertId, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    public function resolveAlertsByType(int $empresaId, int $documentId, string $type, int $userId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia_alertas
             SET resuelta = 1, resuelta_por_usuario_id = :usuario_id, resuelta_at = CURRENT_TIMESTAMP
             WHERE empresa_id = :empresa_id AND documento_ia_id = :documento_ia_id
               AND tipo_alerta = :tipo_alerta AND resuelta = 0'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'empresa_id' => $empresaId,
            'documento_ia_id' => $documentId,
            'tipo_alerta' => $type,
        ]);
    }

    public function findAlert(int $empresaId, int $alertId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, documento_ia_id, documento_ia_detalle_id, tipo_alerta,
                    severidad, mensaje, metadata_json, resuelta, created_at
             FROM documentos_ia_alertas
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $alertId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function insertCorrection(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO documentos_ia_correcciones (
                empresa_id, documento_ia_id, documento_ia_detalle_id, usuario_id,
                campo, valor_anterior, valor_nuevo, motivo
             ) VALUES (
                :empresa_id, :documento_ia_id, :documento_ia_detalle_id, :usuario_id,
                :campo, :valor_anterior, :valor_nuevo, :motivo
             )'
        );
        $statement->execute($data);
    }

    public function updateHeader(int $empresaId, int $documentId, array $data): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia
             SET proveedor_id = :proveedor_id,
                 proveedor_rut_detectado = :proveedor_rut_detectado,
                 proveedor_nombre_detectado = :proveedor_nombre_detectado,
                 proveedor_detectado = :proveedor_detectado,
                 folio_detectado = :folio_detectado,
                 folio = :folio,
                 fecha_documento_detectada = :fecha_documento_detectada,
                 fecha_detectada = :fecha_detectada,
                 tipo_documento_detectado = :tipo_documento_detectado,
                 tipo_documento = :tipo_documento,
                 neto_detectado = :neto_detectado,
                 iva_detectado = :iva_detectado,
                 exento_detectado = :exento_detectado,
                 total_detectado = :total_detectado,
                 total_calculado = :total_calculado,
                 diferencia_total = :diferencia_total,
                 estado_revision = \'OBSERVADO\',
                 requiere_revision = 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $data['proveedor_detectado'] = $data['proveedor_nombre_detectado'];
        $data['folio'] = $data['folio_detectado'];
        $data['fecha_detectada'] = $data['fecha_documento_detectada'];
        $data['tipo_documento'] = $data['tipo_documento_detectado'];
        $data['id'] = $documentId;
        $data['empresa_id'] = $empresaId;
        $statement->execute($data);
    }

    public function updateDetail(int $empresaId, int $detailId, array $data): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia_detalles
             SET producto_id = :producto_id,
                 codigo_detectado = :codigo_detectado,
                 codigo_barra_detectado = :codigo_barra_detectado,
                 nombre_detectado = :nombre_detectado,
                 cantidad_normalizada = :cantidad_normalizada,
                 costo_unitario_normalizado = :costo_unitario_normalizado,
                 total_normalizado = :total_normalizado,
                 cantidad = :cantidad,
                 costo_unitario = :costo_unitario,
                 total = :total,
                 metodo_match = :metodo_match,
                 requiere_revision = :requiere_revision,
                 confirmado = :confirmado,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $data['cantidad'] = $data['cantidad_normalizada'];
        $data['costo_unitario'] = $data['costo_unitario_normalizado'];
        $data['total'] = $data['total_normalizado'];
        $data['id'] = $detailId;
        $data['empresa_id'] = $empresaId;
        $statement->execute($data);
    }

    public function updateTotalsFromDetails(int $empresaId, int $documentId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia d
             SET total_calculado = neto_detectado + iva_detectado + exento_detectado,
                 diferencia_total = total_detectado - (neto_detectado + iva_detectado + exento_detectado),
                 updated_at = CURRENT_TIMESTAMP
             WHERE d.id = :id AND d.empresa_id = :empresa_id'
        );
        $statement->execute([
            'id' => $documentId,
            'empresa_id' => $empresaId,
        ]);
    }

    public function approveDocument(int $empresaId, int $documentId, int $userId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia
             SET estado_revision = \'APROBADO\',
                 requiere_revision = 0,
                 revisado_at = CURRENT_TIMESTAMP,
                 revisado_por_usuario_id = :usuario_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['usuario_id' => $userId, 'id' => $documentId, 'empresa_id' => $empresaId]);
    }

    public function markPurchaseGenerated(int $empresaId, int $documentId, int $purchaseId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia
             SET compra_id = :compra_id,
                 estado = \'COMPRA_GENERADA\',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['compra_id' => $purchaseId, 'id' => $documentId, 'empresa_id' => $empresaId]);
    }

    public function providerByRut(int $empresaId, string $rut): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, rut, nombre, razon_social, activo
             FROM proveedores
             WHERE empresa_id = :empresa_id AND rut = :rut AND activo = 1 AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'rut' => $rut]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function providerByName(int $empresaId, string $name): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, rut, nombre, razon_social, activo
             FROM proveedores
             WHERE empresa_id = :empresa_id
               AND activo = 1
               AND deleted_at IS NULL
               AND (nombre LIKE :q_nombre OR razon_social LIKE :q_razon)
             ORDER BY nombre
             LIMIT 2'
        );
        $query = '%' . $name . '%';
        $statement->execute(['empresa_id' => $empresaId, 'q_nombre' => $query, 'q_razon' => $query]);
        $rows = $statement->fetchAll();

        return count($rows) === 1 ? $rows[0] : null;
    }

    public function providerExists(int $empresaId, int $providerId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, rut, nombre, razon_social
             FROM proveedores
             WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $providerId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function productByCode(int $empresaId, string $code): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, codigo, nombre
             FROM productos
             WHERE empresa_id = :empresa_id
               AND activo = 1
               AND deleted_at IS NULL
               AND (codigo = :codigo OR sku = :sku)
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'codigo' => $code, 'sku' => $code]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function productByBarcode(int $empresaId, string $barcode): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT p.id, p.empresa_id, p.codigo, p.nombre
             FROM productos_codigos_barra cb
             INNER JOIN productos p ON p.id = cb.producto_id
             WHERE p.empresa_id = :empresa_id
               AND p.activo = 1
               AND p.deleted_at IS NULL
               AND cb.codigo_barra = :codigo_barra
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'codigo_barra' => $barcode]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function productByName(int $empresaId, string $name): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, codigo, nombre
             FROM productos
             WHERE empresa_id = :empresa_id
               AND activo = 1
               AND deleted_at IS NULL
               AND nombre LIKE :nombre
             ORDER BY nombre
             LIMIT 2'
        );
        $statement->execute(['empresa_id' => $empresaId, 'nombre' => '%' . $name . '%']);
        $rows = $statement->fetchAll();

        return count($rows) === 1 ? $rows[0] : null;
    }

    public function productExists(int $empresaId, int $productId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, codigo, nombre
             FROM productos
             WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $productId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function possibleDuplicate(int $empresaId, ?string $rut, ?string $folio, ?string $type, int $documentId): bool
    {
        if ($folio === null || $folio === '') {
            return false;
        }

        $statement = $this->connection->prepare(
            'SELECT 1
             FROM documentos_ia
             WHERE empresa_id = :empresa_id_doc
               AND id <> :documento_ia_id
               AND folio_detectado = :folio_doc
               AND (:tipo_doc IS NULL OR tipo_documento_detectado = :tipo_doc_cmp)
               AND (:rut_doc IS NULL OR proveedor_rut_detectado = :rut_doc_cmp)
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id_doc' => $empresaId,
            'documento_ia_id' => $documentId,
            'folio_doc' => $folio,
            'tipo_doc' => $type,
            'tipo_doc_cmp' => $type,
            'rut_doc' => $rut,
            'rut_doc_cmp' => $rut,
        ]);

        if ($statement->fetchColumn()) {
            return true;
        }

        $purchase = $this->connection->prepare(
            'SELECT 1
             FROM compras c
             LEFT JOIN proveedores p ON p.id = c.proveedor_id
             WHERE c.empresa_id = :empresa_id_compra
               AND c.folio = :folio_compra
               AND (:tipo_compra IS NULL OR c.tipo_documento = :tipo_compra_cmp)
               AND (:rut_compra IS NULL OR p.rut = :rut_compra_cmp)
             LIMIT 1'
        );
        $purchase->execute([
            'empresa_id_compra' => $empresaId,
            'folio_compra' => $folio,
            'tipo_compra' => $type,
            'tipo_compra_cmp' => $type,
            'rut_compra' => $rut,
            'rut_compra_cmp' => $rut,
        ]);

        return (bool) $purchase->fetchColumn();
    }

    public function detailsWithoutProductCount(int $empresaId, int $documentId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*)
             FROM documentos_ia_detalles
             WHERE empresa_id = :empresa_id AND documento_ia_id = :documento_ia_id
               AND producto_id IS NULL'
        );
        $statement->execute(['empresa_id' => $empresaId, 'documento_ia_id' => $documentId]);

        return (int) $statement->fetchColumn();
    }

    public function updateDocumentProvider(int $empresaId, int $documentId, array $provider): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia
             SET proveedor_id = :proveedor_id,
                 proveedor_rut_detectado = :rut,
                 proveedor_nombre_detectado = :nombre,
                 proveedor_detectado = :proveedor_detectado,
                 proveedor_confianza = 1.0000,
                 estado_revision = \'OBSERVADO\',
                 requiere_revision = 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute([
            'proveedor_id' => (int) $provider['id'],
            'rut' => $provider['rut'] ?? null,
            'nombre' => $provider['razon_social'] ?: ($provider['nombre'] ?? null),
            'proveedor_detectado' => $provider['razon_social'] ?: ($provider['nombre'] ?? null),
            'id' => $documentId,
            'empresa_id' => $empresaId,
        ]);
    }
}
