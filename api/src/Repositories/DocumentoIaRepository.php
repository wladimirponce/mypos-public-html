<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class DocumentoIaRepository
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

    public function productExists(int $empresaId, int $productId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM productos WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1'
        );
        $statement->execute(['id' => $productId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function create(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO documentos_ia (
                empresa_id, sucursal_id, usuario_id, archivo_subido_id, tipo_documento,
                tipo_documento_detectado, archivo_ruta, archivo_url, estado
             ) VALUES (
                :empresa_id, :sucursal_id, :usuario_id, :archivo_subido_id, :tipo_documento,
                :tipo_documento_detectado, :archivo_ruta, :archivo_url, :estado
             )'
        );
        $data['archivo_subido_id'] = $data['archivo_subido_id'] ?? null;
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function find(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT d.id, d.empresa_id, d.sucursal_id, s.nombre AS sucursal_nombre,
                    d.usuario_id, d.archivo_subido_id, u.nombre AS usuario_nombre, d.proveedor_id,
                    p.razon_social AS proveedor_razon_social, d.proveedor_detectado,
                    d.proveedor_rut_detectado, d.proveedor_nombre_detectado,
                    d.proveedor_confianza, d.compra_id, d.tipo_documento,
                    d.tipo_documento_detectado, d.folio, d.folio_detectado,
                    d.fecha_detectada, d.fecha_documento_detectada, d.neto_detectado,
                    d.iva_detectado, d.exento_detectado, d.total_detectado,
                    d.total_calculado, d.diferencia_total, d.confianza_global,
                    d.requiere_revision, d.estado_revision, d.resumen_alertas_json,
                    d.archivo_ruta, d.archivo_url, d.estado,
                    d.respuesta_json, d.created_at, d.updated_at
             FROM documentos_ia d
             INNER JOIN sucursales s ON s.id = d.sucursal_id
             INNER JOIN usuarios u ON u.id = d.usuario_id
             LEFT JOIN proveedores p ON p.id = d.proveedor_id
             WHERE d.id = :id AND d.empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function list(int $empresaId, array $filters): array
    {
        $sql = 'SELECT id, empresa_id, sucursal_id, usuario_id, proveedor_id, compra_id,
                       archivo_subido_id, tipo_documento_detectado, proveedor_detectado,
                       proveedor_nombre_detectado, folio_detectado, fecha_detectada,
                       fecha_documento_detectada, total_detectado, total_calculado,
                       diferencia_total, estado_revision, archivo_url, estado, created_at
                FROM documentos_ia
                WHERE empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        foreach (['sucursal_id'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND {$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }

        if (!empty($filters['estado'])) {
            $sql .= ' AND estado = :estado';
            $params['estado'] = strtoupper((string) $filters['estado']);
        }

        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND DATE(created_at) >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }

        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND DATE(created_at) <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }

        $sql .= ' ORDER BY id DESC LIMIT 300';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function details(int $empresaId, int $documentId): array
    {
        $statement = $this->connection->prepare(
            'SELECT dd.id, dd.empresa_id, dd.documento_ia_id, dd.producto_id,
                    pr.codigo AS producto_codigo, pr.nombre AS producto_nombre,
                    dd.linea, dd.codigo_detectado, dd.codigo_barra_detectado, dd.nombre_detectado,
                    dd.cantidad_detectada, dd.costo_unitario_detectado,
                    dd.total_detectado, dd.cantidad_normalizada, dd.costo_unitario_normalizado,
                    dd.total_normalizado, dd.cantidad, dd.costo_unitario, dd.total,
                    dd.confianza, dd.metodo_match, dd.requiere_revision, dd.alertas_json,
                    dd.confirmado
             FROM documentos_ia_detalles dd
             LEFT JOIN productos pr ON pr.id = dd.producto_id AND pr.empresa_id = dd.empresa_id
             WHERE dd.empresa_id = :empresa_id AND dd.documento_ia_id = :documento_ia_id
             ORDER BY dd.linea'
        );
        $statement->execute(['empresa_id' => $empresaId, 'documento_ia_id' => $documentId]);

        return $statement->fetchAll();
    }

    public function updateProcessed(int $empresaId, int $id, array $data): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia
             SET estado = \'PROCESADO\',
                 tipo_documento = :tipo_documento,
                 tipo_documento_detectado = :tipo_documento_detectado,
                 proveedor_detectado = :proveedor_detectado,
                 proveedor_rut_detectado = :proveedor_rut_detectado,
                 proveedor_nombre_detectado = :proveedor_nombre_detectado,
                 folio = :folio,
                 folio_detectado = :folio_detectado,
                 fecha_detectada = :fecha_detectada,
                 fecha_documento_detectada = :fecha_documento_detectada,
                 neto_detectado = :neto_detectado,
                 iva_detectado = :iva_detectado,
                 exento_detectado = :exento_detectado,
                 total_detectado = :total_detectado,
                 total_calculado = :total_calculado,
                 diferencia_total = 0,
                 estado_revision = \'PENDIENTE\',
                 requiere_revision = 1,
                 json_extraido = :json_extraido,
                 respuesta_json = :respuesta_json,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $data['proveedor_nombre_detectado'] = $data['proveedor_detectado'] ?? null;
        $data['exento_detectado'] = $data['exento_detectado'] ?? 0;
        $data['fecha_documento_detectada'] = $data['fecha_detectada'] ?? null;
        $data['total_calculado'] = $data['total_detectado'] ?? 0;
        $data['json_extraido'] = $data['respuesta_json'];
        $data['id'] = $id;
        $data['empresa_id'] = $empresaId;
        $statement->execute($data);
    }

    public function updateEdited(int $empresaId, int $id, array $data): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia
             SET estado = \'EDITADO\',
                 proveedor_id = :proveedor_id,
                 tipo_documento = :tipo_documento,
                 tipo_documento_detectado = :tipo_documento_detectado,
                 folio = :folio,
                 folio_detectado = :folio_detectado,
                 fecha_detectada = :fecha_detectada,
                 total_detectado = :total_detectado,
                 json_editado = :json_editado,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $data['id'] = $id;
        $data['empresa_id'] = $empresaId;
        $statement->execute($data);
    }

    public function clearDetails(int $empresaId, int $documentId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM documentos_ia_detalles WHERE empresa_id = :empresa_id AND documento_ia_id = :documento_ia_id'
        );
        $statement->execute(['empresa_id' => $empresaId, 'documento_ia_id' => $documentId]);
    }

    public function insertDetail(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO documentos_ia_detalles (
                empresa_id, documento_ia_id, producto_id, linea, codigo_detectado, codigo_barra_detectado,
                nombre_detectado, cantidad_detectada, costo_unitario_detectado,
                total_detectado, cantidad_normalizada, costo_unitario_normalizado, total_normalizado,
                cantidad, costo_unitario, total, confianza, metodo_match, requiere_revision, confirmado
             ) VALUES (
                :empresa_id, :documento_ia_id, :producto_id, :linea, :codigo_detectado, :codigo_barra_detectado,
                :nombre_detectado, :cantidad_detectada, :costo_unitario_detectado,
                :total_detectado, :cantidad_normalizada, :costo_unitario_normalizado, :total_normalizado,
                :cantidad, :costo_unitario, :total, :confianza, :metodo_match, :requiere_revision, :confirmado
             )'
        );
        $data['codigo_barra_detectado'] = $data['codigo_barra_detectado'] ?? null;
        $data['cantidad_normalizada'] = $data['cantidad_normalizada'] ?? $data['cantidad_detectada'];
        $data['costo_unitario_normalizado'] = $data['costo_unitario_normalizado'] ?? $data['costo_unitario_detectado'];
        $data['total_normalizado'] = $data['total_normalizado'] ?? $data['total_detectado'];
        $data['confianza'] = $data['confianza'] ?? null;
        $data['metodo_match'] = $data['metodo_match'] ?? ($data['producto_id'] !== null ? 'CODIGO' : 'SIN_MATCH');
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function linkProduct(int $empresaId, int $documentId, int $detailId, int $productId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia_detalles
             SET producto_id = :producto_id, confirmado = 1, requiere_revision = 0, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id AND documento_ia_id = :documento_ia_id'
        );
        $statement->execute([
            'producto_id' => $productId,
            'id' => $detailId,
            'empresa_id' => $empresaId,
            'documento_ia_id' => $documentId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function markConfirmed(int $empresaId, int $id, int $purchaseId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia
             SET compra_id = :compra_id, estado = \'CONFIRMADO\', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['compra_id' => $purchaseId, 'id' => $id, 'empresa_id' => $empresaId]);
    }

    public function markError(int $empresaId, int $id, string $message): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_ia
             SET estado = \'ERROR\', respuesta_json = :respuesta_json, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute([
            'respuesta_json' => json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $id,
            'empresa_id' => $empresaId,
        ]);
    }

    public function createProcessing(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO ia_procesamientos (
                empresa_id, documento_ia_id, archivo_subido_id, proveedor, modelo, estado,
                request_json, created_by_usuario_id
             ) VALUES (
                :empresa_id, :documento_ia_id, :archivo_subido_id, :proveedor, :modelo, :estado,
                :request_json, :created_by_usuario_id
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function updateProcessing(int $id, string $estado, ?array $response, ?string $error, ?int $tokensInput = null, ?int $tokensOutput = null): void
    {
        $statement = $this->connection->prepare(
            'UPDATE ia_procesamientos
             SET estado = :estado,
                 response_json = :response_json,
                 error_mensaje = :error_mensaje,
                 tokens_input = :tokens_input,
                 tokens_output = :tokens_output,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'estado' => $estado,
            'response_json' => $response === null ? null : json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_mensaje' => $error,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'id' => $id,
        ]);
    }

    public function uploadedFile(int $empresaId, int $documentId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT a.id, a.empresa_id, a.sucursal_id, a.usuario_id, a.modulo, a.entidad,
                    a.entidad_id, a.nombre_original, a.ruta_relativa, a.mime_type,
                    a.extension, a.size_bytes, a.estado
             FROM documentos_ia d
             INNER JOIN archivos_subidos a ON a.id = d.archivo_subido_id
             WHERE d.id = :documento_ia_id AND d.empresa_id = :empresa_id_documento AND a.empresa_id = :empresa_id_archivo
             LIMIT 1'
        );
        $statement->execute([
            'documento_ia_id' => $documentId,
            'empresa_id_documento' => $empresaId,
            'empresa_id_archivo' => $empresaId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }
}
