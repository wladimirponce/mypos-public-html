<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class FolioRepository
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
        $statement = $this->connection->prepare(
            'SELECT 1 FROM empresas WHERE id = :id AND activo = 1 LIMIT 1'
        );
        $statement->execute(['id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function sucursalExists(int $empresaId, int $sucursalId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM sucursales WHERE id = :id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1'
        );
        $statement->execute(['id' => $sucursalId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function cajaExists(int $empresaId, int $sucursalId, int $cajaId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1
             FROM cajas
             WHERE id = :id AND empresa_id = :empresa_id AND sucursal_id = :sucursal_id AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $cajaId, 'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId]);

        return (bool) $statement->fetchColumn();
    }

    public function dispositivoExists(int $empresaId, int $sucursalId, int $dispositivoId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1
             FROM dispositivos
             WHERE id = :id
               AND empresa_id = :empresa_id
               AND activo = 1
               AND (sucursal_id IS NULL OR sucursal_id = :sucursal_id)
             LIMIT 1'
        );
        $statement->execute([
            'id' => $dispositivoId,
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function cafOverlapExists(int $empresaId, string $type, int $from, int $to): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1
             FROM caf_archivos
             WHERE empresa_id = :empresa_id
               AND tipo_documento = :tipo_documento
               AND estado = \'ACTIVO\'
               AND folio_desde <= :folio_hasta
               AND folio_hasta >= :folio_desde
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'tipo_documento' => $type,
            'folio_desde' => $from,
            'folio_hasta' => $to,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function createCaf(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO caf_archivos (
                empresa_id, tipo_documento, rut_emisor, razon_social_emisor,
                folio_desde, folio_hasta, fecha_autorizacion, fecha_vencimiento,
                archivo_path, caf_xml, estado, created_by_usuario_id
             ) VALUES (
                :empresa_id, :tipo_documento, :rut_emisor, :razon_social_emisor,
                :folio_desde, :folio_hasta, :fecha_autorizacion, :fecha_vencimiento,
                :archivo_path, :caf_xml, \'ACTIVO\', :created_by_usuario_id
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function listCafs(int $empresaId, array $filters): array
    {
        $sql = 'SELECT id, empresa_id, tipo_documento, rut_emisor, razon_social_emisor,
                       folio_desde, folio_hasta, fecha_autorizacion, fecha_vencimiento,
                       archivo_path, estado, created_by_usuario_id, created_at, updated_at
                FROM caf_archivos
                WHERE empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        foreach (['tipo_documento', 'estado'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND {$field} = :{$field}";
                $params[$field] = strtoupper((string) $filters[$field]);
            }
        }

        $sql .= ' ORDER BY tipo_documento, folio_desde';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function findCaf(int $empresaId, int $cafId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, tipo_documento, folio_desde, folio_hasta,
                    fecha_vencimiento, estado
             FROM caf_archivos
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $cafId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function assignmentOverlapExists(int $empresaId, string $type, int $from, int $to): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1
             FROM folios_asignaciones
             WHERE empresa_id = :empresa_id
               AND tipo_documento = :tipo_documento
               AND estado = \'ACTIVA\'
               AND folio_desde <= :folio_hasta
               AND folio_hasta >= :folio_desde
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'tipo_documento' => $type,
            'folio_desde' => $from,
            'folio_hasta' => $to,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function createAssignment(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO folios_asignaciones (
                empresa_id, sucursal_id, caja_id, dispositivo_id, caf_id, tipo_documento,
                folio_desde, folio_hasta, folio_actual, alerta_minimo, estado,
                asignado_at, created_by_usuario_id, created_at
             ) VALUES (
                :empresa_id, :sucursal_id, :caja_id, :dispositivo_id, :caf_id, :tipo_documento,
                :folio_desde, :folio_hasta, :folio_actual, :alerta_minimo, \'ACTIVA\',
                CURRENT_TIMESTAMP, :created_by_usuario_id, CURRENT_TIMESTAMP
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function listAssignments(int $empresaId, array $filters): array
    {
        $sql = 'SELECT fa.id, fa.empresa_id, fa.sucursal_id, s.nombre AS sucursal_nombre,
                       fa.caja_id, c.nombre AS caja_nombre, fa.dispositivo_id, d.nombre AS dispositivo_nombre,
                       fa.caf_id AS caf_archivo_id, fa.tipo_documento, fa.folio_desde,
                       fa.folio_hasta, fa.folio_actual, fa.alerta_minimo, fa.estado,
                       fa.asignado_at, fa.agotado_at, fa.created_by_usuario_id, fa.created_at, fa.updated_at
                FROM folios_asignaciones fa
                INNER JOIN sucursales s ON s.id = fa.sucursal_id
                LEFT JOIN cajas c ON c.id = fa.caja_id
                LEFT JOIN dispositivos d ON d.id = fa.dispositivo_id
                WHERE fa.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        foreach (['sucursal_id', 'caja_id', 'dispositivo_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $sql .= " AND fa.{$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }

        foreach (['tipo_documento', 'estado'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND fa.{$field} = :{$field}";
                $params[$field] = strtoupper((string) $filters[$field]);
            }
        }

        $sql .= ' ORDER BY fa.id DESC';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function findApplicableAssignment(
        int $empresaId,
        int $sucursalId,
        string $type,
        ?int $cajaId,
        ?int $dispositivoId,
        bool $lock = false
    ): ?array {
        $clauses = [];
        $params = [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'tipo_documento' => $type,
        ];

        if ($dispositivoId !== null) {
            $clauses[] = '(fa.dispositivo_id = :dispositivo_id)';
            $params['dispositivo_id'] = $dispositivoId;
        }

        if ($cajaId !== null) {
            $clauses[] = '(fa.caja_id = :caja_id AND fa.dispositivo_id IS NULL)';
            $params['caja_id'] = $cajaId;
        }

        $clauses[] = '(fa.caja_id IS NULL AND fa.dispositivo_id IS NULL)';

        $targetSql = implode(' OR ', $clauses);
        $sql = "SELECT fa.id, fa.empresa_id, fa.sucursal_id, fa.caja_id, fa.dispositivo_id,
                       fa.caf_id, fa.tipo_documento, fa.folio_desde, fa.folio_hasta,
                       fa.folio_actual, fa.alerta_minimo, fa.estado,
                       ca.fecha_vencimiento, ca.estado AS caf_estado
                FROM folios_asignaciones fa
                INNER JOIN caf_archivos ca ON ca.id = fa.caf_id
                WHERE fa.empresa_id = :empresa_id
                  AND fa.sucursal_id = :sucursal_id
                  AND fa.tipo_documento = :tipo_documento
                  AND fa.estado = 'ACTIVA'
                  AND ca.estado = 'ACTIVO'
                  AND ({$targetSql})
                ORDER BY
                  CASE
                    WHEN fa.dispositivo_id IS NOT NULL THEN 1
                    WHEN fa.caja_id IS NOT NULL THEN 2
                    ELSE 3
                  END,
                  fa.id
                LIMIT 1";

        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function updateAssignmentAfterConsumption(int $assignmentId, int $newCurrent, bool $depleted): void
    {
        $statement = $this->connection->prepare(
            'UPDATE folios_asignaciones
             SET folio_actual = :folio_actual,
                 estado = CASE WHEN :agotada_estado = 1 THEN \'AGOTADA\' ELSE estado END,
                 agotado_at = CASE WHEN :agotada_fecha = 1 THEN CURRENT_TIMESTAMP ELSE agotado_at END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $assignmentId,
            'folio_actual' => $newCurrent,
            'agotada_estado' => $depleted ? 1 : 0,
            'agotada_fecha' => $depleted ? 1 : 0,
        ]);
    }

    public function createConsumedFolio(array $data): int
    {
        $data['uuid_offline'] = $data['uuid_offline'] ?? null;
        $data['folio_asignacion_id'] = $data['folio_asignacion_id'] ?? $data['asignacion_id'];

        $statement = $this->connection->prepare(
            'INSERT INTO folios_consumidos (
                empresa_id, sucursal_id, caja_id, dispositivo_id, caf_archivo_id,
                asignacion_id, folio_asignacion_id, documento_emitido_id, tipo_documento, folio, uuid_offline,
                estado, sync_status, origen, fecha_consumo, reservado_at, usado_at,
                created_by_usuario_id, metadata_json, created_at
             ) VALUES (
                :empresa_id, :sucursal_id, :caja_id, :dispositivo_id, :caf_archivo_id,
                :asignacion_id, :folio_asignacion_id, :documento_emitido_id, :tipo_documento, :folio, :uuid_offline,
                \'USADO_INTERNO\', \'SYNCED\', :origen, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
                :created_by_usuario_id, :metadata_json, CURRENT_TIMESTAMP
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function consumedFolioExists(int $empresaId, string $type, int $folio): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1
             FROM folios_consumidos
             WHERE empresa_id = :empresa_id
               AND tipo_documento = :tipo_documento
               AND folio = :folio
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'tipo_documento' => $type,
            'folio' => $folio,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function consumedOfflineUuidExists(int $empresaId, string $type, string $uuidOffline): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, folio, documento_emitido_id, estado
             FROM folios_consumidos
             WHERE empresa_id = :empresa_id
               AND tipo_documento = :tipo_documento
               AND uuid_offline = :uuid_offline
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'tipo_documento' => $type,
            'uuid_offline' => $uuidOffline,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findAssignmentContainingFolio(
        int $empresaId,
        int $sucursalId,
        string $type,
        int $folio,
        ?int $deviceId,
        bool $lock
    ): ?array {
        $params = [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'tipo_documento' => $type,
            'folio' => $folio,
        ];
        $deviceSql = 'AND fa.dispositivo_id IS NULL';

        if ($deviceId !== null) {
            $deviceSql = 'AND (fa.dispositivo_id = :dispositivo_id OR fa.dispositivo_id IS NULL)';
            $params['dispositivo_id'] = $deviceId;
        }

        $sql = "SELECT fa.id, fa.empresa_id, fa.sucursal_id, fa.caja_id, fa.dispositivo_id,
                       fa.caf_id, fa.tipo_documento, fa.folio_desde, fa.folio_hasta,
                       fa.folio_actual, fa.alerta_minimo, fa.estado,
                       ca.fecha_vencimiento, ca.estado AS caf_estado
                FROM folios_asignaciones fa
                INNER JOIN caf_archivos ca ON ca.id = fa.caf_id
                WHERE fa.empresa_id = :empresa_id
                  AND fa.sucursal_id = :sucursal_id
                  AND fa.tipo_documento = :tipo_documento
                  AND fa.estado = 'ACTIVA'
                  AND ca.estado = 'ACTIVO'
                  AND :folio BETWEEN fa.folio_desde AND fa.folio_hasta
                  {$deviceSql}
                ORDER BY CASE WHEN fa.dispositivo_id IS NOT NULL THEN 1 ELSE 2 END, fa.id
                LIMIT 1";

        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findDocumentForUpdate(int $empresaId, int $documentId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, sucursal_id, tipo_documento, folio, estado
             FROM documentos_emitidos
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $documentId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function updateDocumentFolio(array $data): void
    {
        $statement = $this->connection->prepare(
            'UPDATE documentos_emitidos
             SET folio = :folio,
                 folio_origen = \'INTERNO\',
                 caf_id = :caf_id,
                 folio_asignacion_id = :folio_asignacion_id,
                 folio_consumido_id = :folio_consumido_id,
                 dispositivo_id = :dispositivo_id,
                 emision_origen = :emision_origen,
                 estado = CASE
                    WHEN estado IN (\'BORRADOR\', \'PENDIENTE_EMISION\') THEN \'EMITIDO_INTERNO\'
                    ELSE estado
                 END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :documento_emitido_id AND empresa_id = :empresa_id'
        );
        $statement->execute($data);
    }

    public function listConsumed(int $empresaId, array $filters): array
    {
        $sql = 'SELECT fc.id, fc.empresa_id, fc.sucursal_id, fc.caja_id, fc.dispositivo_id,
                       fc.caf_archivo_id, fc.asignacion_id AS folio_asignacion_id,
                       fc.documento_emitido_id, fc.tipo_documento, fc.folio, fc.estado,
                       fc.origen, fc.reservado_at, fc.usado_at, fc.created_by_usuario_id,
                       fc.fecha_consumo, fc.created_at, fc.updated_at
                FROM folios_consumidos fc
                WHERE fc.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        foreach (['sucursal_id', 'documento_emitido_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $sql .= " AND fc.{$field} = :{$field}";
                $params[$field] = (int) $filters[$field];
            }
        }

        foreach (['tipo_documento', 'estado'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND fc.{$field} = :{$field}";
                $params[$field] = strtoupper((string) $filters[$field]);
            }
        }

        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND DATE(fc.fecha_consumo) >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }

        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND DATE(fc.fecha_consumo) <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }

        $sql .= ' ORDER BY fc.id DESC LIMIT 500';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function lowFolioAssignments(int $empresaId, array $filters): array
    {
        $sql = 'SELECT fa.id, fa.sucursal_id, fa.caja_id, fa.dispositivo_id, fa.tipo_documento,
                       fa.folio_desde, fa.folio_hasta, fa.folio_actual, fa.alerta_minimo,
                       (fa.folio_hasta - fa.folio_actual) AS disponibles
                FROM folios_asignaciones fa
                WHERE fa.empresa_id = :empresa_id
                  AND fa.estado = \'ACTIVA\'
                  AND (fa.folio_hasta - fa.folio_actual) <= fa.alerta_minimo';
        $params = ['empresa_id' => $empresaId];

        if (!empty($filters['sucursal_id'])) {
            $sql .= ' AND fa.sucursal_id = :sucursal_id';
            $params['sucursal_id'] = (int) $filters['sucursal_id'];
        }

        if (!empty($filters['tipo_documento'])) {
            $sql .= ' AND fa.tipo_documento = :tipo_documento';
            $params['tipo_documento'] = strtoupper((string) $filters['tipo_documento']);
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function depletedAssignments(int $empresaId, array $filters): array
    {
        return $this->assignmentsByState($empresaId, $filters, 'AGOTADA');
    }

    public function expiredCafs(int $empresaId, array $filters): array
    {
        return $this->cafsByExpiration($empresaId, $filters, '< CURRENT_DATE');
    }

    public function expiringCafs(int $empresaId, array $filters): array
    {
        return $this->cafsByExpiration($empresaId, $filters, 'BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)');
    }

    private function assignmentsByState(int $empresaId, array $filters, string $state): array
    {
        $sql = 'SELECT id, sucursal_id, caja_id, dispositivo_id, tipo_documento,
                       folio_desde, folio_hasta, folio_actual, alerta_minimo, estado
                FROM folios_asignaciones
                WHERE empresa_id = :empresa_id AND estado = :estado';
        $params = ['empresa_id' => $empresaId, 'estado' => $state];

        if (!empty($filters['sucursal_id'])) {
            $sql .= ' AND sucursal_id = :sucursal_id';
            $params['sucursal_id'] = (int) $filters['sucursal_id'];
        }

        if (!empty($filters['tipo_documento'])) {
            $sql .= ' AND tipo_documento = :tipo_documento';
            $params['tipo_documento'] = strtoupper((string) $filters['tipo_documento']);
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    private function cafsByExpiration(int $empresaId, array $filters, string $condition): array
    {
        $sql = "SELECT id, tipo_documento, folio_desde, folio_hasta, fecha_vencimiento, estado
                FROM caf_archivos
                WHERE empresa_id = :empresa_id
                  AND fecha_vencimiento IS NOT NULL
                  AND fecha_vencimiento {$condition}";
        $params = ['empresa_id' => $empresaId];

        if (!empty($filters['tipo_documento'])) {
            $sql .= ' AND tipo_documento = :tipo_documento';
            $params['tipo_documento'] = strtoupper((string) $filters['tipo_documento']);
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }
}
