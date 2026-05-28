<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class DteIntegrationRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function empresaExists(int $empresaId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM empresas WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function config(int $empresaId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, modo, sistema_path, endpoint_cli, endpoint_http,
                    salida_xml_dir, salida_pdf_dir, ambiente, activo, metadata_json,
                    created_at, updated_at
             FROM dte_configuracion
             WHERE empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function upsertConfig(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO dte_configuracion (
                empresa_id, modo, sistema_path, endpoint_cli, endpoint_http,
                salida_xml_dir, salida_pdf_dir, ambiente, activo, metadata_json
             ) VALUES (
                :empresa_id, :modo, :sistema_path, :endpoint_cli, :endpoint_http,
                :salida_xml_dir, :salida_pdf_dir, :ambiente, :activo, :metadata_json
             )
             ON DUPLICATE KEY UPDATE
                modo = VALUES(modo),
                sistema_path = VALUES(sistema_path),
                endpoint_cli = VALUES(endpoint_cli),
                endpoint_http = VALUES(endpoint_http),
                salida_xml_dir = VALUES(salida_xml_dir),
                salida_pdf_dir = VALUES(salida_pdf_dir),
                ambiente = VALUES(ambiente),
                activo = VALUES(activo),
                metadata_json = VALUES(metadata_json),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute($data);
    }

    public function findDocument(int $empresaId, int $documentId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, venta_id, cliente_id, tipo_documento,
                    folio, folio_origen, estado, fecha_emision, rut_receptor,
                    razon_social_receptor, giro_receptor, direccion_receptor,
                    comuna_receptor, ciudad_receptor, neto, exento, impuestos,
                    total, xml_path, pdf_path, track_id, respuesta_sii_json,
                    error_sii, metadata_json
             FROM documentos_emitidos
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $documentId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function documentDetails(int $documentId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, documento_emitido_id, venta_detalle_id, producto_id,
                    codigo_producto, nombre_producto, cantidad, precio_unitario,
                    descuento, neto, exento, impuestos, total
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
                    nombre_impuesto, tasa_base_10000, monto
             FROM documento_emitido_impuestos
             WHERE documento_emitido_id = :documento_emitido_id
             ORDER BY id'
        );
        $statement->execute(['documento_emitido_id' => $documentId]);

        return $statement->fetchAll();
    }

    public function createEmission(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO dte_emisiones (
                empresa_id, sucursal_id, documento_emitido_id, tipo_documento,
                folio, modo, estado, request_json, intentos, ultimo_intento_at,
                created_by_usuario_id
             ) VALUES (
                :empresa_id, :sucursal_id, :documento_emitido_id, :tipo_documento,
                :folio, :modo, :estado, :request_json, :intentos, CURRENT_TIMESTAMP,
                :created_by_usuario_id
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function updateEmissionResult(int $empresaId, int $id, array $data): void
    {
        $statement = $this->connection->prepare(
            'UPDATE dte_emisiones
             SET estado = :estado,
                 response_json = :response_json,
                 xml_path = :xml_path,
                 pdf_path = :pdf_path,
                 track_id = :track_id,
                 error_mensaje = :error_mensaje,
                 intentos = intentos + :incrementar_intentos,
                 ultimo_intento_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $data['id'] = $id;
        $data['empresa_id'] = $empresaId;
        $statement->execute($data);
    }

    public function updateDocumentDte(int $empresaId, int $documentId, array $data): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_emitidos
             SET estado = COALESCE(:estado, estado),
                 xml_path = COALESCE(:xml_path, xml_path),
                 pdf_path = COALESCE(:pdf_path, pdf_path),
                 track_id = COALESCE(:track_id, track_id),
                 respuesta_sii_json = COALESCE(:respuesta_sii_json, respuesta_sii_json),
                 error_sii = COALESCE(:error_sii, error_sii),
                 estado_sii = COALESCE(:estado_sii, estado_sii),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $data['id'] = $documentId;
        $data['empresa_id'] = $empresaId;
        $statement->execute($data);
    }

    public function createEvent(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO dte_eventos (
                empresa_id, dte_emision_id, documento_emitido_id, tipo_evento,
                descripcion, metadata_json
             ) VALUES (
                :empresa_id, :dte_emision_id, :documento_emitido_id, :tipo_evento,
                :descripcion, :metadata_json
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function listEmissions(int $empresaId, array $filters): array
    {
        $sql = 'SELECT id, empresa_id, sucursal_id, documento_emitido_id, tipo_documento,
                       folio, modo, estado, xml_path, pdf_path, track_id,
                       error_mensaje, intentos, ultimo_intento_at, created_at, updated_at
                FROM dte_emisiones
                WHERE empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        foreach (['sucursal_id', 'documento_emitido_id'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND {$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }

        foreach (['tipo_documento', 'estado', 'modo'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND {$field} = :{$field}";
                $params[$field] = strtoupper((string) $filters[$field]);
            }
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

    public function findEmission(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, documento_emitido_id, tipo_documento,
                    folio, modo, estado, request_json, response_json, xml_path,
                    pdf_path, track_id, error_mensaje, intentos, ultimo_intento_at,
                    created_by_usuario_id, created_at, updated_at
             FROM dte_emisiones
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function emissionEvents(int $empresaId, int $emissionId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, dte_emision_id, documento_emitido_id,
                    tipo_evento, descripcion, metadata_json, created_at
             FROM dte_eventos
             WHERE empresa_id = :empresa_id AND dte_emision_id = :dte_emision_id
             ORDER BY id'
        );
        $statement->execute(['empresa_id' => $empresaId, 'dte_emision_id' => $emissionId]);

        return $statement->fetchAll();
    }
}
