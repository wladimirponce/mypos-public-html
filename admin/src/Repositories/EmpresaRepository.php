<?php
declare(strict_types=1);

namespace App\Repositories;

use Exception;

/**
 * Repositorio para la gestión de datos de empresas emisoras.
 */
class EmpresaRepository extends BaseRepository
{
    /**
     * Obtiene los datos de una empresa mediante su API Key.
     */
    public function getByApiKey(string $apiKey): ?array
    {
        $sql = "SELECT e.*, ak.permisos 
                FROM sii_empresa e
                JOIN sii_api_key ak ON e.id = ak.empresa_id
                WHERE ak.clave_hash = SHA2(?, 256) AND ak.activa = 1 AND e.activo = 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$apiKey]);
        
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Obtiene los datos de una empresa mediante su ID.
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM sii_empresa WHERE id = ? AND activo = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getByAmbiente(string $ambiente): ?array
    {
        $sql = "SELECT * FROM sii_empresa WHERE ambiente_default = ? AND activo = 1 LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([strtoupper($ambiente)]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Obtiene el certificado vigente de una empresa.
     */
    public function getCertificado(int $empresaId): ?array
    {
        $sql = "SELECT * FROM sii_certificado 
                WHERE empresa_id = ? AND activo = 1 
                ORDER BY vigencia_hasta DESC LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId]);
        
        return $stmt->fetch() ?: null;
    }

    /**
     * Retorna el último folio registrado en sii_dte para empresa+tipo+ambiente.
     * Se consulta sii_dte (no sii_folio_consumo) porque registrarDTE va primero
     * y puede fallar antes de que registrarConsumoFolio se ejecute.
     */
    public function getUltimoFolioUsado(int $empresaId, int $tipoDte, string $ambiente): int
    {
        $sql  = "SELECT COALESCE(MAX(folio), 0) FROM sii_dte WHERE empresa_id = ? AND tipo_dte = ? AND ambiente = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId, $tipoDte, strtolower($ambiente)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Obtiene UN CAF activo (legacy: el primero que la vista retorne).
     * Se mantiene por compatibilidad. Para multi-CAF use getCAFsActivos /
     * getCAFForFolio / getCAFNextAvailable.
     */
    public function getCAFConEstado(int $empresaId, int $tipoDte, string $ambiente): ?array
    {
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

    /**
     * Retorna todos los CAFs activos para (empresa, tipo, ambiente),
     * ordenados por rango ascendente.
     */
    public function getCAFsActivos(int $empresaId, int $tipoDte, string $ambiente): array
    {
        $sql = "SELECT c.*, v.disponibles, v.nivel_alerta
                FROM sii_caf c
                JOIN v_sii_folios_disponibles v ON c.id = v.caf_id
                WHERE c.empresa_id = ? AND c.tipo_dte = ? AND c.ambiente_sii = ? AND c.activo = 1
                ORDER BY c.folio_desde ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId, $tipoDte, strtolower($ambiente)]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Retorna el CAF activo cuyo rango contiene el folio dado, o null si no
     * hay ninguno autorizado para ese folio.
     */
    public function getCAFForFolio(int $empresaId, int $tipoDte, string $ambiente, int $folio): ?array
    {
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

        // Fallback: tabla cafs (sistema centralizado; xml_content inline)
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
     * Retorna el CAF activo con folios disponibles (el de menor rango que
     * todavía tenga folios sin usar). Devuelve null si todos están agotados.
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
     * MAX(folio) dentro de un rango concreto — para multi-CAF, donde el
     * "último folio usado" depende del CAF (no del global por tipo).
     */
    public function getUltimoFolioUsadoEnRango(
        int $empresaId, int $tipoDte, string $ambiente, int $desde, int $hasta
    ): int {
        $sql = "SELECT COALESCE(MAX(folio), 0) FROM sii_dte
                WHERE empresa_id = ? AND tipo_dte = ? AND ambiente = ?
                  AND folio BETWEEN ? AND ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId, $tipoDte, strtolower($ambiente), $desde, $hasta]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Registra un DTE emitido en la base de datos.
     */
    public function registrarDTE(array $data): int
    {
        // INSERT IGNORE: si el folio ya existe (ejecución concurrente o reintento),
        // la inserción se omite silenciosamente evitando un error fatal.
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
        
        // Si fue IGNORE (ya existía), recuperar el id existente
        $id = (int)$this->db->lastInsertId();
        if ($id === 0) {
            $s = $this->db->prepare('SELECT id FROM sii_dte WHERE empresa_id=? AND tipo_dte=? AND folio=? AND ambiente=?');
            $s->execute([$data['empresa_id'], $data['tipo_dte'], $data['folio'], strtolower($data['ambiente'])]);
            $id = (int)($s->fetchColumn() ?: 0);
        }
        return $id;
    }

    /**
     * Registra el consumo de un folio.
     * INSERT IGNORE: si el folio ya está registrado (reintento tras fallo de envío SII),
     * no falla — el folio sigue siendo válido hasta que el SII confirme recepción.
     */
    public function registrarConsumoFolio(int $cafId, int $empresaId, int $tipoDte, int $folio, int $dteId): void
    {
        $sql = "INSERT IGNORE INTO sii_folio_consumo (caf_id, empresa_id, tipo_dte, folio, dte_id)
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$cafId, $empresaId, $tipoDte, $folio, $dteId]);
    }
    /**
     * Obtiene los últimos DTEs emitidos de un tipo específico.
     */
    public function getUltimosDTEs(int $empresaId, int $tipoDte, int $limit = 10): array
    {
        $sql = "SELECT folio, fecha_emision as fecha 
                FROM sii_dte 
                WHERE empresa_id = ? AND tipo_dte = ? 
                ORDER BY folio DESC LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId, $tipoDte, $limit]);
        return $stmt->fetchAll() ?: [];
    }
    /**
     * Registra un nuevo CAF en la base de datos.
     */
    public function registrarCAF(array $data): int
    {
        // Multi-CAF: NO se desactivan otros CAFs del mismo (empresa, tipo, ambiente).
        // Pueden convivir varios rangos activos en paralelo (típico: uno por local).
        // Solo se desactiva el CAF EXACTAMENTE igual (mismo rango) si existía,
        // para evitar duplicar el mismo rango al recargar el XML.
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
    /**
     * Registra un nuevo certificado en la base de datos.
     */
    public function registrarCertificado(array $data): int
    {
        // Desactivar certificados anteriores para esta empresa
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
}
