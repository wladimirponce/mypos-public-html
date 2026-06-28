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
        $this->useSiiTables = false;
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

    private function cafXmlForDatabase(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (!preg_match('/encoding\s*=/i', substr($content, 0, 200))) {
            $encoding = preg_match('//u', $content) ? 'UTF-8' : 'ISO-8859-1';
            $content = preg_replace(
                '/<\?xml\s+version\s*=\s*"1\.0"\s*\?>/',
                '<?xml version="1.0" encoding="' . $encoding . '"?>',
                $content,
                1
            ) ?? $content;
        }

        if (!preg_match('//u', $content)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        $content = preg_replace(
            '/<\?xml([^>]*?)encoding=["\'][^"\']+["\']([^>]*?)\?>/i',
            '<?xml$1encoding="UTF-8"$2?>',
            $content,
            1,
            $count
        ) ?? $content;

        if (empty($count)) {
            $content = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . ltrim($content);
        }

        return $content;
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

        // El modelo SaaS no dispone de una API key empresarial segura.
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
                       COALESCE(dc.ambiente, '') AS ambiente_default,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.acteco')), '[]') ELSE '[]' END AS acteco,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.numero_resolucion')), '') ELSE '' END AS numero_resolucion,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.fecha_resolucion')), '') ELSE '' END AS fecha_resolucion,
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
                       COALESCE(dc.ambiente, '') AS ambiente_default,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.acteco')), '[]') ELSE '[]' END AS acteco,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.numero_resolucion')), '') ELSE '' END AS numero_resolucion,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.fecha_resolucion')), '') ELSE '' END AS fecha_resolucion,
                       CASE WHEN JSON_VALID(ec.metadata_json) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ec.metadata_json, '$.unidad_sii')), '') ELSE '' END AS unidad_sii
                FROM empresas e
                LEFT JOIN dte_configuracion dc ON e.id = dc.empresa_id
                LEFT JOIN empresa_configuracion ec ON e.id = ec.empresa_id
                WHERE dc.ambiente = ? AND e.activo = 1 LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([strtoupper($ambiente)]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Obtiene el certificado vigente de una empresa.
     */
    public function getCertificado(int $empresaId, ?string $ambiente = null): ?array
    {
        if ($this->useSiiTables) {
            $sql = "SELECT * FROM sii_certificado
                    WHERE empresa_id = ? AND ambiente_sii = ? AND activo = 1
                    ORDER BY vigencia_hasta DESC LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$empresaId, strtolower((string)$ambiente)]);
            
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

        return 0;
    }

    /**
     * Obtiene UN CAF activo.
     */
    public function getCAFConEstado(int $empresaId, int $tipoDte, string $ambiente): ?array
    {
        if ($this->useSiiTables) {
            $sql = "SELECT c.*,
                           GREATEST((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COALESCE(fc.consumidos, 0), 0) AS disponibles,
                           CASE
                               WHEN ((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COALESCE(fc.consumidos, 0)) <= 50
                                   THEN 'critico'
                               WHEN ((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COALESCE(fc.consumidos, 0)) <= 200
                                   THEN 'pre_critico'
                               ELSE 'normal'
                           END AS nivel_alerta
                    FROM sii_caf c
                    LEFT JOIN (
                        SELECT caf_id, COUNT(*) AS consumidos
                        FROM sii_folio_consumo
                        WHERE estado != 'rechazado_sii'
                        GROUP BY caf_id
                    ) fc ON fc.caf_id = c.id
                    WHERE c.empresa_id = ? AND c.tipo_dte = ? AND c.ambiente_sii = ? AND c.activo = 1
                    ORDER BY c.folio_desde ASC
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$empresaId, $tipoDte, strtolower($ambiente)]);

            return $stmt->fetch() ?: null;
        }

        return null;
    }

    /**
     * Retorna todos los CAFs activos para (empresa, tipo, ambiente).
     * En modo SaaS el ambiente es implícito de la empresa; se consulta caf_archivos.
     */
    public function getCAFsActivos(int $empresaId, int $tipoDte, string $ambiente): array
    {
        if ($this->useSiiTables) {
            $sql = "SELECT c.*,
                           GREATEST((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COALESCE(fc.consumidos, 0), 0) AS disponibles,
                           CASE
                               WHEN ((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COALESCE(fc.consumidos, 0)) <= 50
                                   THEN 'critico'
                               WHEN ((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COALESCE(fc.consumidos, 0)) <= 200
                                   THEN 'pre_critico'
                               ELSE 'normal'
                           END AS nivel_alerta
                    FROM sii_caf c
                    LEFT JOIN (
                        SELECT caf_id, COUNT(*) AS consumidos
                        FROM sii_folio_consumo
                        WHERE estado != 'rechazado_sii'
                        GROUP BY caf_id
                    ) fc ON fc.caf_id = c.id
                    WHERE c.empresa_id = ? AND c.tipo_dte = ? AND c.ambiente_sii = ? AND c.activo = 1
                    ORDER BY c.folio_desde ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$empresaId, $tipoDte, strtolower($ambiente)]);
            return $stmt->fetchAll() ?: [];
        }

        // Para MyPOS SaaS: los CAFs se guardan en caf_archivos.
        // El ambiente es implícito (pertenece a la empresa activa).
        $tipoEnum = $this->mapTipoDteToEnum($tipoDte);
        $sql = "SELECT id, empresa_id,
                       folio_desde, folio_hasta,
                       fecha_autorizacion,
                       created_at   AS fecha_subida,
                       caf_xml      AS xml_content,
                       archivo_path AS xml_path,
                       'normal'     AS nivel_alerta
                FROM caf_archivos
                WHERE empresa_id    = ?
                  AND tipo_documento = ?
                  AND estado         = 'ACTIVO'
                ORDER BY folio_desde ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId, $tipoEnum]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Retorna el CAF activo cuyo rango contiene el folio dado.
     * En modo SaaS consulta caf_archivos.
     */
    public function getCAFForFolio(int $empresaId, int $tipoDte, string $ambiente, int $folio): ?array
    {
        if ($this->useSiiTables) {
            $sql = "SELECT c.*,
                           GREATEST((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COALESCE(fc.consumidos, 0), 0) AS disponibles,
                           CASE
                               WHEN ((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COALESCE(fc.consumidos, 0)) <= 50
                                   THEN 'critico'
                               WHEN ((CAST(c.folio_hasta AS SIGNED) - CAST(c.folio_desde AS SIGNED) + 1) - COALESCE(fc.consumidos, 0)) <= 200
                                   THEN 'pre_critico'
                               ELSE 'normal'
                           END AS nivel_alerta,
                           NULL AS xml_content
                    FROM sii_caf c
                    LEFT JOIN (
                        SELECT caf_id, COUNT(*) AS consumidos
                        FROM sii_folio_consumo
                        WHERE estado != 'rechazado_sii'
                        GROUP BY caf_id
                    ) fc ON fc.caf_id = c.id
                    WHERE c.empresa_id = ? AND c.tipo_dte = ? AND c.ambiente_sii = ? AND c.activo = 1
                      AND ? BETWEEN c.folio_desde AND c.folio_hasta
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$empresaId, $tipoDte, strtolower($ambiente), $folio]);
            return $stmt->fetch() ?: null;
        }

        // Para MyPOS SaaS: buscar en caf_archivos el CAF cuyo rango contenga el folio.
        $tipoEnum = $this->mapTipoDteToEnum($tipoDte);
        $sql = "SELECT id, empresa_id,
                       folio_desde, folio_hasta,
                       fecha_autorizacion,
                       caf_xml      AS xml_content,
                       archivo_path AS xml_path,
                       'normal'     AS nivel_alerta
                FROM caf_archivos
                WHERE empresa_id    = ?
                  AND tipo_documento = ?
                  AND estado         = 'ACTIVO'
                  AND ? BETWEEN folio_desde AND folio_hasta
                ORDER BY folio_desde ASC
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId, $tipoEnum, $folio]);
        return $stmt->fetch() ?: null;
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
        
        // El XML firmado del SII viene en ISO-8859-1; sin convertir, json_encode
        // devuelve false (bytes no-UTF8) y se guarda '' → viola el CHECK
        // json_valid(payload_json) de MariaDB. Convertimos a UTF-8 (preserva Ñ/tildes)
        // y dejamos JSON_INVALID_UTF8_SUBSTITUTE + fallback como red de seguridad.
        $xmlForStore = (string)($data['xml'] ?? '');
        if ($xmlForStore !== '' && !mb_check_encoding($xmlForStore, 'UTF-8')) {
            $xmlForStore = mb_convert_encoding($xmlForStore, 'UTF-8', 'ISO-8859-1');
        }
        $payloadJson = json_encode([
            'xml_firmado' => $xmlForStore,
            'rut_receptor' => $data['rut_receptor'] ?? '',
            'razon_receptor' => $data['razon_receptor'] ?? '',
            'ambiente' => strtolower($data['ambiente'])
        ], JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

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
    public function registrarConsumoFolio(int $cafId, int $empresaId, int $tipoDte, int $folio, int $dteId, string $ambiente): void
    {
        if ($this->useSiiTables) {
            $sql = "INSERT IGNORE INTO sii_folio_consumo (caf_id, empresa_id, tipo_dte, folio, dte_id, ambiente)
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$cafId, $empresaId, $tipoDte, $folio, $dteId, strtolower($ambiente)]);
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
            // Desactiva TODOS los CAFs previos del mismo tipo/ambiente para esta empresa.
            // No filtrar por rango: el CAF anterior tiene un rango distinto al nuevo y
            // sin esto quedan dos CAFs activos → certFolioBaseLibre elige el viejo (folio bajo).
            $sqlOff = "UPDATE sii_caf SET activo = 0
                       WHERE empresa_id = ? AND tipo_dte = ? AND ambiente_sii = ?";
            $stmtOff = $this->db->prepare($sqlOff);
            $stmtOff->execute([
                $data['empresa_id'], $data['tipo_dte'], strtolower($data['ambiente']),
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

        // En CERTIFICACION el SII solo reconoce el CAF más reciente emitido — los
        // anteriores quedan inválidos aunque no estén agotados. Desactivarlos evita
        // que certFolioBaseLibre los elija primero y genere folios fuera del rango
        // aceptado por maullin.sii.cl (error CAF-3-605).
        if (strtoupper((string)($data['ambiente'] ?? '')) === 'CERTIFICACION') {
            $stmtDeact = $this->db->prepare(
                "UPDATE caf_archivos SET estado = 'ANULADO'
                 WHERE empresa_id = ? AND tipo_documento = ? AND estado = 'ACTIVO'"
            );
            $stmtDeact->execute([$data['empresa_id'], $tipoEnum]);
        }

        $xmlContent = '';
        if (file_exists($data['xml_path'])) {
            $xmlContent = $this->cafXmlForDatabase((string)file_get_contents($data['xml_path']));
        }

        $fechaVenc = null;
        if (!empty($data['fecha_auth'])) {
            $fechaVenc = date('Y-m-d', strtotime($data['fecha_auth'] . ' +365 days'));
        }

        $sql = "INSERT INTO caf_archivos (
                    empresa_id, tipo_documento, folio_desde, folio_hasta,
                    fecha_autorizacion, fecha_vencimiento, caf_xml, archivo_path, estado, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVO', NOW())
                ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    fecha_autorizacion = VALUES(fecha_autorizacion),
                    fecha_vencimiento = VALUES(fecha_vencimiento),
                    caf_xml = VALUES(caf_xml),
                    archivo_path = VALUES(archivo_path),
                    estado = 'ACTIVO'";
        
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
            $sqlOff = "UPDATE sii_certificado SET activo = 0 WHERE empresa_id = ? AND ambiente_sii = ?";
            $stmtOff = $this->db->prepare($sqlOff);
            $stmtOff->execute([$data['empresa_id'], strtolower($data['ambiente'])]);

            $sql = "INSERT INTO sii_certificado (
                        empresa_id, nombre_archivo, ruta_pfx, clave_enc, 
                        rut_titular, nombre_titular, vigencia_desde, vigencia_hasta, ambiente_sii, activo
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['empresa_id'], $data['original'], $data['ruta_pfx'], $data['pass'], 
                $data['rut'], $data['nombre'], $data['desde'], $data['hasta'], strtolower($data['ambiente'])
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
