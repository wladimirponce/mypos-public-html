<?php
declare(strict_types=1);

namespace App\Repositories;

use Exception;
use PDO;

/**
 * Repositorio para la gestión de datos de empresas emisoras.
 */
class EmpresaRepository extends BaseRepository
{
    private bool $useSiiTables = false;

    public function __construct()
    {
        parent::__construct();
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sii_empresa'");
            if ((int)$stmt->fetchColumn() > 0) {
                $stmt = $this->db->query("SELECT COUNT(*) FROM sii_empresa WHERE activo = 1");
                $this->useSiiTables = ((int)$stmt->fetchColumn()) > 0;
            }
        } catch (Exception $e) {
            $this->useSiiTables = false;
        }
    }

    /**
     * Helper para mapear tipo DTE numérico a ENUM string de MyPOS
     */
    private function mapTipoDteToEnum(int $tipo): string
    {
        switch ($tipo) {
            case 33:
            case 34:
                return 'FACTURA';
            case 39:
            case 41:
                return 'BOLETA';
            case 52:
                return 'GUIA_DESPACHO';
            case 61:
                return 'NOTA_CREDITO';
            case 56:
                return 'NOTA_DEBITO';
            default:
                return 'BOLETA';
        }
    }

    /**
     * Obtiene los datos de una empresa mediante su API Key.
     */
    public function getByApiKey(string $apiKey): ?array
    {
        if ($this->useSiiTables) {
            $sql = "SELECT e.*, ak.permisos 
                    FROM sii_empresa e
                    JOIN sii_api_key ak ON e.id = ak.empresa_id
                    WHERE ak.clave_hash = SHA2(?, 256) AND ak.activa = 1 AND e.activo = 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$apiKey]);
            
            $result = $stmt->fetch();
            return $result ?: null;
        }

        // Para MyPOS SaaS: fallback si la API key coincide con la variable global.
        $envKey = getenv('DTE_ADMIN_API_KEY') ?: 'mypos_dte_key_default';
        if ($apiKey === $envKey) {
            $sql = "SELECT e.id, e.rut, e.razon_social, e.nombre_fantasia, e.giro,
                           e.direccion AS direccion_origen, e.comuna AS comuna_origen, e.ciudad AS ciudad_origen,
                           e.telefono, e.email AS email_sii, e.activo,
                           'CERTIFICACION' AS ambiente_default, '[\"emitir\",\"consultar\",\"config\"]' AS permisos
                    FROM empresas e WHERE e.activo = 1 LIMIT 1";
            return $this->db->query($sql)->fetch() ?: null;
        }
        return null;
    }

    /**
     * Obtiene los datos de una empresa mediante su ID.
     */
    public function getById(int $id): ?array
    {
        if ($this->useSiiTables) {
            $sql = "SELECT * FROM sii_empresa WHERE id = ? AND activo = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        }

        // Para MyPOS SaaS:
        $sql = "SELECT e.id, e.rut, e.razon_social, e.nombre_fantasia, e.giro,
                       e.direccion AS direccion_origen, e.comuna AS comuna_origen, e.ciudad AS ciudad_origen,
                       e.telefono, e.email AS email_sii, e.activo,
                       COALESCE(dc.ambiente, 'CERTIFICACION') AS ambiente_default,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.acteco')), '[]') ELSE '[]' END AS acteco,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.numero_resolucion')), '80') ELSE '80' END AS numero_resolucion,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.fecha_resolucion')), '2026-05-17') ELSE '2026-05-17' END AS fecha_resolucion,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.unidad_sii')), '') ELSE '' END AS unidad_sii
                FROM empresas e
                LEFT JOIN dte_configuracion dc ON e.id = dc.empresa_id
                LEFT JOIN empresa_configuracion ec ON e.id = ec.empresa_id
                WHERE e.id = ? AND e.activo = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getByAmbiente(string $ambiente): ?array
    {
        if ($this->useSiiTables) {
            $sql = "SELECT * FROM sii_empresa WHERE ambiente_default = ? AND activo = 1 LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([strtoupper($ambiente)]);
            return $stmt->fetch() ?: null;
        }

        // Para MyPOS SaaS:
        $sql = "SELECT e.id, e.rut, e.razon_social, e.nombre_fantasia, e.giro,
                       e.direccion AS direccion_origen, e.comuna AS comuna_origen, e.ciudad AS ciudad_origen,
                       e.telefono, e.email AS email_sii, e.activo,
                       COALESCE(dc.ambiente, 'CERTIFICACION') AS ambiente_default,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.acteco')), '[]') ELSE '[]' END AS acteco,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.numero_resolucion')), '80') ELSE '80' END AS numero_resolucion,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.fecha_resolucion')), '2026-05-17') ELSE '2026-05-17' END AS fecha_resolucion,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.unidad_sii')), '') ELSE '' END AS unidad_sii
                FROM empresas e
                LEFT JOIN dte_configuracion dc ON e.id = dc.empresa_id
                LEFT JOIN empresa_configuracion ec ON e.id = ec.empresa_id
                WHERE COALESCE(dc.ambiente, 'CERTIFICACION') = ? AND e.activo = 1 LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([strtoupper($ambiente)]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Obtiene el certificado vigente de una empresa.
     */
    public function getCertificado(int $empresaId): ?array
    {
        if ($this->useSiiTables) {
            $sql = "SELECT * FROM sii_certificado 
                    WHERE empresa_id = ? AND activo = 1 
                    ORDER BY vigencia_hasta DESC LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$empresaId]);
            
            return $stmt->fetch() ?: null;
        }

        // Para MyPOS SaaS (archivos_subidos):
        $sql = "SELECT id, empresa_id, nombre_original AS nombre_archivo,
                       ruta_relativa AS ruta_pfx, 1 AS activo,
                       created_at AS creado_en, metadata_json
                FROM archivos_subidos
                WHERE empresa_id = ? AND entidad = 'certificado_sii' AND estado = 'ACTIVO'
                ORDER BY id DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId]);
        $row = $stmt->fetch();
        if ($row) {
            $meta = json_decode((string)($row['metadata_json'] ?? '{}'), true);
            $row['clave_enc'] = $meta['password_certificado'] ?? '';
            $row['rut_titular'] = '';
            $row['nombre_titular'] = $meta['titular'] ?? '';
            $row['vigencia_desde'] = !empty($meta['valido_desde']) ? substr($meta['valido_desde'], 0, 10) : null;
            $row['vigencia_hasta'] = !empty($meta['valido_hasta']) ? substr($meta['valido_hasta'], 0, 10) : null;
            return $row;
        }
        return null;
    }

    /**
     * Retorna el último folio registrado en sii_dte para empresa+tipo+ambiente.
     */
    public function getUltimoFolioUsado(int $empresaId, int $tipoDte, string $ambiente): int
    {
        if ($this->useSiiTables) {
            $sql  = "SELECT COALESCE(MAX(folio), 0) FROM sii_dte WHERE empresa_id = ? AND tipo_dte = ? AND ambiente = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$empresaId, $tipoDte, strtolower($ambiente)]);
            return (int)$stmt->fetchColumn();
        }

        // Para MyPOS SaaS:
        $tipoEnum = $this->mapTipoDteToEnum($tipoDte);
        $sql = "SELECT COALESCE(MAX(CAST(folio AS UNSIGNED)), 0) FROM documentos_emitidos WHERE empresa_id = ? AND tipo_documento = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId, $tipoEnum]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Obtiene UN CAF activo.
     */
    public function getCAFConEstado(int $empresaId, int $tipoDte, string $ambiente): ?array
    {
        if ($this->useSiiTables) {
            $sql = "SELECT c.*, v.disponibles, v.nivel_alerta
                    FROM sii_caf c
                    JOIN v_sii_folios_disponibles v ON c.id = v.caf_id
                    WHERE c.empresa_id = ? AND c.tipo_dte = ? AND c.ambiente_sii = ? AND c.activo = 1
                    ORDER BY c.folio_desde ASC
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$empresaId, $tipoDte, strtolower($ambiente)]);

            return $stmt->fetch() ?: null;
        }

        // Para MyPOS SaaS:
        $tipoEnum = $this->mapTipoDteToEnum($tipoDte);
        $sql = "SELECT c.id, c.empresa_id, ? AS tipo_dte, c.folio_desde, c.folio_hasta,
                       c.archivo_path AS xml_path, 'produccion' AS ambiente_sii, c.fecha_autorizacion,
                       (c.folio_hasta - c.folio_desde + 1) AS total_folios,
                       COALESCE(fc.consumidos, 0) AS consumidos,
                       GREATEST(c.folio_hasta - c.folio_desde + 1 - COALESCE(fc.consumidos, 0), 0) AS disponibles,
                       'normal' AS nivel_alerta
                FROM caf_archivos c
                LEFT JOIN (
                    SELECT caf_archivo_id, COUNT(*) AS consumidos
                    FROM folios_consumidos
                    GROUP BY caf_archivo_id
                ) fc ON fc.caf_archivo_id = c.id
                WHERE c.empresa_id = ? AND c.tipo_documento = ? AND c.estado = 'ACTIVO'
                ORDER BY c.folio_desde ASC
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tipoDte, $empresaId, $tipoEnum]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Retorna todos los CAFs activos para (empresa, tipo, ambiente).
     */
    public function getCAFsActivos(int $empresaId, int $tipoDte, string $ambiente): array
    {
        if ($this->useSiiTables) {
            $sql = "SELECT c.*, v.disponibles, v.nivel_alerta
                    FROM sii_caf c
                    JOIN v_sii_folios_disponibles v ON c.id = v.caf_id
                    WHERE c.empresa_id = ? AND c.tipo_dte = ? AND c.ambiente_sii = ? AND c.activo = 1
                    ORDER BY c.folio_desde ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$empresaId, $tipoDte, strtolower($ambiente)]);
            return $stmt->fetchAll() ?: [];
        }

        // Para MyPOS SaaS:
        $tipoEnum = $this->mapTipoDteToEnum($tipoDte);
        $sql = "SELECT c.id, c.empresa_id, ? AS tipo_dte, c.folio_desde, c.folio_hasta,
                       c.archivo_path AS xml_path, 'produccion' AS ambiente_sii, c.fecha_autorizacion,
                       (c.folio_hasta - c.folio_desde + 1) AS total_folios,
                       COALESCE(fc.consumidos, 0) AS consumidos,
                       GREATEST(c.folio_hasta - c.folio_desde + 1 - COALESCE(fc.consumidos, 0), 0) AS disponibles,
                       'normal' AS nivel_alerta
                FROM caf_archivos c
                LEFT JOIN (
                    SELECT caf_archivo_id, COUNT(*) AS consumidos
                    FROM folios_consumidos
                    GROUP BY caf_archivo_id
                ) fc ON fc.caf_archivo_id = c.id
                WHERE c.empresa_id = ? AND c.tipo_documento = ? AND c.estado = 'ACTIVO'
                ORDER BY c.folio_desde ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tipoDte, $empresaId, $tipoEnum]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Retorna el CAF activo cuyo rango contiene el folio dado.
     */
    public function getCAFForFolio(int $empresaId, int $tipoDte, string $ambiente, int $folio): ?array
    {
        if ($this->useSiiTables) {
            $sql = "SELECT c.*, v.disponibles, v.nivel_alerta, NULL AS xml_content
                    FROM sii_caf c
                    JOIN v_sii_folios_disponibles v ON c.id = v.caf_id
                    WHERE c.empresa_id = ? AND c.tipo_dte = ? AND c.ambiente_sii = ? AND c.activo = 1
                      AND ? BETWEEN c.folio_desde AND c.folio_hasta
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$empresaId, $tipoDte, strtolower($ambiente), $folio]);
            $result = $stmt->fetch() ?: null;
            if ($result) return $result;
        } else {
            // Para MyPOS SaaS:
            $tipoEnum = $this->mapTipoDteToEnum($tipoDte);
            $sql = "SELECT c.id, c.empresa_id, ? AS tipo_dte, c.folio_desde, c.folio_hasta,
                           c.archivo_path AS xml_path, 'produccion' AS ambiente_sii, c.fecha_autorizacion,
                           (c.folio_hasta - c.folio_desde + 1) AS total_folios,
                           COALESCE(fc.consumidos, 0) AS consumidos,
                           GREATEST(c.folio_hasta - c.folio_desde + 1 - COALESCE(fc.consumidos, 0), 0) AS disponibles,
                           'normal' AS nivel_alerta, NULL AS xml_content
                    FROM caf_archivos c
                    LEFT JOIN (
                        SELECT caf_archivo_id, COUNT(*) AS consumidos
                        FROM folios_consumidos
                        GROUP BY caf_archivo_id
                    ) fc ON fc.caf_archivo_id = c.id
                    WHERE c.empresa_id = ? AND c.tipo_documento = ? AND c.estado = 'ACTIVO'
                      AND ? BETWEEN c.folio_desde AND c.folio_hasta
                    LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$tipoDte, $empresaId, $tipoEnum, $folio]);
            $result = $stmt->fetch() ?: null;
            if ($result) return $result;
        }

        // Fallback: tabla cafs (sistema centralizado)
        $sql2 = "SELECT id, tipo_dte, folio_desde, folio_hasta, xml_content,
                        NULL AS xml_path, NULL AS nivel_alerta, NULL AS disponibles
                 FROM cafs
                 WHERE tipo_dte = ? AND agotado = 0
                   AND ? BETWEEN folio_desde AND folio_hasta
                 LIMIT 1";
        $stmt2 = $this->db->prepare($sql2);
        $stmt2->execute([$tipoDte, $folio]);
        return $stmt2->fetch() ?: null;
    }

    /**
     * Retorna el CAF activo con folios disponibles.
     */
    public function getCAFNextAvailable(int $empresaId, int $tipoDte, string $ambiente): ?array
    {
        foreach ($this->getCAFsActivos($empresaId, $tipoDte, $ambiente) as $caf) {
            $ult = $this->getUltimoFolioUsadoEnRango(
                $empresaId, $tipoDte, $ambiente,
                (int)$caf['folio_desde'], (int)$caf['folio_hasta']
            );
            $proximo = max((int)$caf['folio_desde'], $ult + 1);
            if ($proximo <= (int)$caf['folio_hasta']) {
                return $caf;
            }
        }
        return null;
    }

    /**
     * MAX(folio) dentro de un rango concreto.
     */
    public function getUltimoFolioUsadoEnRango(
        int $empresaId, int $tipoDte, string $ambiente, int $desde, int $hasta
    ): int {
        if ($this->useSiiTables) {
            $sql = "SELECT COALESCE(MAX(folio), 0) FROM sii_dte
                    WHERE empresa_id = ? AND tipo_dte = ? AND ambiente = ?
                      AND folio BETWEEN ? AND ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$empresaId, $tipoDte, strtolower($ambiente), $desde, $hasta]);
            return (int)$stmt->fetchColumn();
        }

        // Para MyPOS SaaS:
        $tipoEnum = $this->mapTipoDteToEnum($tipoDte);
        $sql = "SELECT COALESCE(MAX(CAST(folio AS UNSIGNED)), 0) FROM documentos_emitidos
                WHERE empresa_id = ? AND tipo_documento = ?
                  AND CAST(folio AS UNSIGNED) BETWEEN ? AND ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId, $tipoEnum, $desde, $hasta]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Registra un DTE emitido en la base de datos.
     */
    public function registrarDTE(array $data): int
    {
        if ($this->useSiiTables) {
            $sql = "INSERT IGNORE INTO sii_dte (
                        empresa_id, tipo_dte, folio, ambiente, fecha_emision, 
                        rut_receptor, razon_receptor, monto_total, xml_firmado, estado_local
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'firmado')";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['empresa_id'], $data['tipo_dte'], $data['folio'], strtolower($data['ambiente']),
                $data['fecha'], $data['rut_receptor'], $data['razon_receptor'], 
                $data['total'], $data['xml'],
            ]);
            
            $id = (int)$this->db->lastInsertId();
            if ($id === 0) {
                $s = $this->db->prepare('SELECT id FROM sii_dte WHERE empresa_id=? AND tipo_dte=? AND folio=? AND ambiente=?');
                $s->execute([$data['empresa_id'], $data['tipo_dte'], $data['folio'], strtolower($data['ambiente'])]);
                $id = (int)($s->fetchColumn() ?: 0);
            }
            return $id;
        }

        // Para MyPOS SaaS (documentos_emitidos):
        $tipoEnum = $this->mapTipoDteToEnum((int)$data['tipo_dte']);
        $sql = "INSERT IGNORE INTO documentos_emitidos (
                    empresa_id, tipo_documento, folio, estado, total, fecha_emision, payload_json
                ) VALUES (?, ?, ?, 'EMITIDO', ?, ?, ?)";
        
        $payloadJson = json_encode([
            'xml_firmado' => $data['xml'],
            'rut_receptor' => $data['rut_receptor'] ?? '',
            'razon_receptor' => $data['razon_receptor'] ?? '',
            'ambiente' => strtolower($data['ambiente'])
        ]);

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['empresa_id'], $tipoEnum, (string)$data['folio'],
            $data['total'], $data['fecha'], $payloadJson
        ]);
        
        $id = (int)$this->db->lastInsertId();
        if ($id === 0) {
            $s = $this->db->prepare('SELECT id FROM documentos_emitidos WHERE empresa_id=? AND tipo_documento=? AND folio=?');
            $s->execute([$data['empresa_id'], $tipoEnum, (string)$data['folio']]);
            $id = (int)($s->fetchColumn() ?: 0);
        }
        return $id;
    }

    /**
     * Registra el consumo de un folio.
     */
    public function registrarConsumoFolio(int $cafId, int $empresaId, int $tipoDte, int $folio, int $dteId): void
    {
        if ($this->useSiiTables) {
            $sql = "INSERT IGNORE INTO sii_folio_consumo (caf_id, empresa_id, tipo_dte, folio, dte_id)
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$cafId, $empresaId, $tipoDte, $folio, $dteId]);
            return;
        }

        // Para MyPOS SaaS:
        $tipoEnum = $this->mapTipoDteToEnum($tipoDte);

        // 1. Obtener la sucursal de la empresa
        $stmtSuc = $this->db->prepare("SELECT id FROM sucursales WHERE empresa_id = ? LIMIT 1");
        $stmtSuc->execute([$empresaId]);
        $sucursalId = (int)($stmtSuc->fetchColumn() ?: 0);
        if ($sucursalId === 0) {
            $stmtNewSuc = $this->db->prepare("INSERT INTO sucursales (empresa_id, nombre, activo) VALUES (?, 'Casa Matriz', 1)");
            $stmtNewSuc->execute([$empresaId]);
            $sucursalId = (int)$this->db->lastInsertId();
        }

        // 2. Buscar/Crear asignación correspondiente
        $stmtAsig = $this->db->prepare("SELECT id FROM folios_asignaciones WHERE empresa_id = ? AND caf_id = ? LIMIT 1");
        $stmtAsig->execute([$empresaId, $cafId]);
        $asignacionId = (int)($stmtAsig->fetchColumn() ?: 0);

        if ($asignacionId === 0) {
            $stmtCaf = $this->db->prepare("SELECT folio_desde, folio_hasta FROM caf_archivos WHERE id = ?");
            $stmtCaf->execute([$cafId]);
            $cafRow = $stmtCaf->fetch();
            $desde = $cafRow ? (int)$cafRow['folio_desde'] : $folio;
            $hasta = $cafRow ? (int)$cafRow['folio_hasta'] : $folio;

            $stmtNewAsig = $this->db->prepare("
                INSERT INTO folios_asignaciones (
                    empresa_id, sucursal_id, caf_id, tipo_documento,
                    folio_desde, folio_hasta, folio_actual, estado
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVA')
            ");
            $stmtNewAsig->execute([
                $empresaId, $sucursalId, $cafId, $tipoEnum,
                $desde, $hasta, $folio
            ]);
            $asignacionId = (int)$this->db->lastInsertId();
        }

        // 3. Registrar consumo
        $sql = "INSERT IGNORE INTO folios_consumidos (
                    empresa_id, sucursal_id, asignacion_id, caf_archivo_id,
                    documento_emitido_id, tipo_documento, folio, estado, sync_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ENVIADO_SII', 'SYNCED')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $empresaId, $sucursalId, $asignacionId, $cafId,
            $dteId, $tipoEnum, $folio
        ]);
    }

    /**
     * Obtiene los últimos DTEs emitidos de un tipo específico.
     */
    public function getUltimosDTEs(int $empresaId, int $tipoDte, int $limit = 10): array
    {
        if ($this->useSiiTables) {
            $sql = "SELECT folio, fecha_emision as fecha 
                    FROM sii_dte 
                    WHERE empresa_id = ? AND tipo_dte = ? 
                    ORDER BY folio DESC LIMIT ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$empresaId, $tipoDte, $limit]);
            return $stmt->fetchAll() ?: [];
        }

        // Para MyPOS SaaS:
        $tipoEnum = $this->mapTipoDteToEnum($tipoDte);
        $sql = "SELECT CAST(folio AS UNSIGNED) as folio, fecha_emision as fecha 
                FROM documentos_emitidos 
                WHERE empresa_id = ? AND tipo_documento = ? 
                ORDER BY CAST(folio AS UNSIGNED) DESC LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId, $tipoEnum, $limit]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Registra un nuevo CAF en la base de datos.
     */
    public function registrarCAF(array $data): int
    {
        if ($this->useSiiTables) {
            $sqlOff = "UPDATE sii_caf SET activo = 0
                       WHERE empresa_id = ? AND tipo_dte = ? AND ambiente_sii = ?
                         AND folio_desde = ? AND folio_hasta = ?";
            $stmtOff = $this->db->prepare($sqlOff);
            $stmtOff->execute([
                $data['empresa_id'], $data['tipo_dte'], strtolower($data['ambiente']),
                $data['desde'], $data['hasta'],
            ]);

            $sql = "INSERT INTO sii_caf (
                        empresa_id, tipo_dte, folio_desde, folio_hasta, xml_path, 
                        ambiente_sii, fecha_autorizacion, activo
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['empresa_id'], $data['tipo_dte'], $data['desde'], $data['hasta'],
                $data['xml_path'], strtolower($data['ambiente']), $data['fecha_auth']
            ]);
            
            return (int)$this->db->lastInsertId();
        }

        // Para MyPOS SaaS:
        $tipoEnum = $this->mapTipoDteToEnum((int)$data['tipo_dte']);
        $sqlOff = "UPDATE caf_archivos SET estado = 'ANULADO'
                   WHERE empresa_id = ? AND tipo_documento = ?
                     AND folio_desde = ? AND folio_hasta = ?";
        $stmtOff = $this->db->prepare($sqlOff);
        $stmtOff->execute([
            $data['empresa_id'], $tipoEnum, $data['desde'], $data['hasta']
        ]);

        $xmlContent = '';
        if (file_exists($data['xml_path'])) {
            $xmlContent = file_get_contents($data['xml_path']);
        }

        $fechaVenc = null;
        if (!empty($data['fecha_auth'])) {
            $fechaVenc = date('Y-m-d', strtotime($data['fecha_auth'] . ' +365 days'));
        }

        $sql = "INSERT INTO caf_archivos (
                    empresa_id, tipo_documento, folio_desde, folio_hasta,
                    fecha_autorizacion, fecha_vencimiento, caf_xml, archivo_path, estado, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVO', NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['empresa_id'], $tipoEnum, $data['desde'], $data['hasta'],
            $data['fecha_auth'], $fechaVenc, $xmlContent, $data['xml_path']
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Registra un nuevo certificado en la base de datos.
     */
    public function registrarCertificado(array $data): int
    {
        if ($this->useSiiTables) {
            $sqlOff = "UPDATE sii_certificado SET activo = 0 WHERE empresa_id = ?";
            $stmtOff = $this->db->prepare($sqlOff);
            $stmtOff->execute([$data['empresa_id']]);

            $sql = "INSERT INTO sii_certificado (
                        empresa_id, nombre_archivo, ruta_pfx, clave_enc, 
                        rut_titular, nombre_titular, vigencia_desde, vigencia_hasta, activo
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['empresa_id'], $data['original'], $data['ruta_pfx'], $data['pass'], 
                $data['rut'], $data['nombre'], $data['desde'], $data['hasta']
            ]);
            
            return (int)$this->db->lastInsertId();
        }

        // Para MyPOS SaaS:
        $sqlOff = "UPDATE archivos_subidos SET estado = 'ELIMINADO' WHERE empresa_id = ? AND entidad = 'certificado_sii'";
        $stmtOff = $this->db->prepare($sqlOff);
        $stmtOff->execute([$data['empresa_id']]);

        $metadataJson = json_encode([
            'password_certificado' => $data['pass'],
            'titular' => $data['nombre'] ?? '',
            'valido_desde' => $data['desde'] ?? '',
            'valido_hasta' => $data['hasta'] ?? '',
            'certificado_valido' => true
        ]);

        $sql = "INSERT INTO archivos_subidos (
                    empresa_id, modulo, entidad, nombre_original, nombre_storage, ruta_relativa,
                    mime_type, extension, size_bytes, hash_sha256, estado, metadata_json, created_at, usuario_id
                ) VALUES (?, 'DTE', 'certificado_sii', ?, ?, ?, 'application/x-pkcs12', 'pfx', 0, '', 'ACTIVO', ?, NOW(), 1)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['empresa_id'], $data['original'], basename($data['ruta_pfx']), $data['ruta_pfx'], $metadataJson
        ]);
        
        return (int)$this->db->lastInsertId();
    }
}
