<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Exception;
use SimpleXMLElement;

/**
 * Gestión centralizada de CAFs SII para múltiples sucursales.
 *
 * Modelo:
 *   - CAFs subidos por POS (origen=LEGACY) → sucursal_id ya asignada
 *   - CAFs subidos por admin (origen=CENTRAL) → sucursal_id NULL hasta distribuir
 *   - distribuirCaf(): divide un CAF en sub-rangos por sucursal según consumo medio
 *   - cafsDisponibles(sucursal): lista CAFs no agotados con folios disponibles
 *   - descargarCaf(id): devuelve XML completo (requiere sucursal_id coincidente)
 *   - consumirFolio(id, folio, sucursal): registra uso y avanza folio_actual
 */
class CafCentralManager
{
    private PDO $pdo;
    private bool $useLegacyTables = false;
    private string $ambiente = 'CERTIFICACION';

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        // Detect ambiente from globalContext (set by api.php via Context.php)
        if (isset($GLOBALS['globalContext']) && method_exists($GLOBALS['globalContext'], 'getAmbiente')) {
            $amb = strtoupper((string)$GLOBALS['globalContext']->getAmbiente());
            if (in_array($amb, ['CERTIFICACION', 'PRODUCCION'], true)) {
                $this->ambiente = $amb;
            }
        }
        try {
            $stmt = $this->pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cafs' LIMIT 1");
            $this->useLegacyTables = (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            $this->useLegacyTables = false;
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
     * Helper para mapear ENUM string de MyPOS a tipo DTE numérico
     */
    private function mapEnumToTipoDte(string $enum): int
    {
        switch (strtoupper($enum)) {
            case 'FACTURA':
                return 33;
            case 'BOLETA':
                return 39;
            case 'GUIA_DESPACHO':
                return 52;
            case 'NOTA_CREDITO':
                return 61;
            case 'NOTA_DEBITO':
                return 56;
            default:
                return 39;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  SUBIR — registra un CAF en la BD (desde POS o desde admin)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param string $xmlContent  contenido XML del CAF SII
     * @param string|null $sucursalId  asignar a una sucursal (LEGACY) o null (CENTRAL pendiente)
     * @param string $origen  'LEGACY' | 'CENTRAL'
     * @return array{ok:bool, id?:int, tipo?:int, desde?:int, hasta?:int, error?:string, duplicado?:bool}
     */
    public function subirCaf(string $xmlContent, ?string $sucursalId, string $origen = 'CENTRAL'): array
    {
        try {
            $xml = @simplexml_load_string($xmlContent);
            if (!$xml || !isset($xml->CAF->DA->TD)) {
                return ['ok' => false, 'error' => 'XML no es un CAF SII válido'];
            }
            $tipo  = (int)$xml->CAF->DA->TD;
            $desde = (int)$xml->CAF->DA->RNG->D;
            $hasta = (int)$xml->CAF->DA->RNG->H;
            $rut   = (string)$xml->CAF->DA->RE;
            $rs    = substr((string)$xml->CAF->DA->RS, 0, 120);
            $fa    = (string)$xml->CAF->DA->FA ?: null;

            if ($desde <= 0 || $hasta < $desde) {
                return ['ok' => false, 'error' => 'Rango de folios inválido'];
            }

            if ($this->useLegacyTables) {
                // Detectar duplicado exacto (mismo rut+tipo+desde+hasta)
                $sel = $this->pdo->prepare(
                    "SELECT id FROM cafs WHERE rut_emisor=? AND tipo_dte=? AND folio_desde=? AND folio_hasta=? LIMIT 1"
                );
                $sel->execute([$rut, $tipo, $desde, $hasta]);
                if ($exists = $sel->fetch(PDO::FETCH_ASSOC)) {
                    return ['ok' => true, 'duplicado' => true, 'id' => (int)$exists['id'],
                            'tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta];
                }

                $ins = $this->pdo->prepare("
                    INSERT INTO cafs
                      (tipo_dte, rut_emisor, razon_social, folio_desde, folio_hasta,
                       folio_actual, sucursal_id, xml_content, fecha_autorizacion, origen)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $tipo, $rut, $rs, $desde, $hasta, $desde,
                    $sucursalId ?: null, $xmlContent, $fa, $origen
                ]);

                return ['ok' => true, 'id' => (int)$this->pdo->lastInsertId(),
                        'tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta];
            } else {
                // MyPOS SaaS mode
                // Buscar empresa por RUT
                $stmtEmp = $this->pdo->prepare("SELECT id FROM empresas WHERE rut = ? LIMIT 1");
                $stmtEmp->execute([$rut]);
                $empresaId = (int)($stmtEmp->fetchColumn() ?: 0);
                if ($empresaId === 0) {
                    $cleanRut = str_replace(['.', '-'], '', $rut);
                    $stmtEmp = $this->pdo->prepare("SELECT id FROM empresas WHERE REPLACE(REPLACE(rut, '.', ''), '-', '') = ? LIMIT 1");
                    $stmtEmp->execute([$cleanRut]);
                    $empresaId = (int)($stmtEmp->fetchColumn() ?: 0);
                }
                if ($empresaId === 0) {
                    return ['ok' => false, 'error' => "No se encontró empresa registrada en MyPOS con el RUT $rut"];
                }

                $tipoEnum = $this->mapTipoDteToEnum($tipo);

                // Detectar duplicado (mismo ambiente)
                $sel = $this->pdo->prepare(
                    "SELECT id FROM caf_archivos WHERE empresa_id=? AND tipo_documento=? AND folio_desde=? AND folio_hasta=? AND ambiente=? LIMIT 1"
                );
                $sel->execute([$empresaId, $tipoEnum, $desde, $hasta, $this->ambiente]);
                if ($exists = $sel->fetch(PDO::FETCH_ASSOC)) {
                    return ['ok' => true, 'duplicado' => true, 'id' => (int)$exists['id'],
                            'tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta];
                }

                // Generar ruta local e intentar guardar en disco por compatibilidad
                $xmlPath = "cafs/caf_{$rut}_{$tipo}_{$desde}_{$hasta}.xml";
                $dir = dirname(__DIR__, 2) . "/caf";
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $fullPath = $dir . "/caf_{$rut}_{$tipo}_{$desde}_{$hasta}.xml";
                @file_put_contents($fullPath, $xmlContent);

                $fechaVenc = null;
                if (!empty($fa)) {
                    $fechaVenc = date('Y-m-d', strtotime($fa . ' +365 days'));
                }

                $ins = $this->pdo->prepare("
                    INSERT INTO caf_archivos
                      (empresa_id, tipo_documento, rut_emisor, razon_social_emisor, folio_desde, folio_hasta,
                       fecha_autorizacion, fecha_vencimiento, archivo_path, caf_xml, estado, ambiente, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVO', ?, NOW())
                ");
                $ins->execute([
                    $empresaId, $tipoEnum, $rut, $rs, $desde, $hasta,
                    $fa, $fechaVenc, $xmlPath, $xmlContent, $this->ambiente
                ]);
                $cafId = (int)$this->pdo->lastInsertId();

                if ($sucursalId !== null) {
                    $stmtNewAsig = $this->pdo->prepare("
                        INSERT INTO folios_asignaciones (
                            empresa_id, sucursal_id, caf_id, tipo_documento,
                            folio_desde, folio_hasta, folio_actual, estado, asignado_at, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVA', NOW(), NOW())
                    ");
                    $stmtNewAsig->execute([
                        $empresaId, $sucursalId, $cafId, $tipoEnum,
                        $desde, $hasta, $desde
                    ]);
                }

                return ['ok' => true, 'id' => $cafId,
                        'tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta];
            }
        } catch (Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  LISTAR — CAFs disponibles para una sucursal
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Devuelve CAFs vigentes asignados a la sucursal.
     * No incluye el XML completo (usar descargarCaf para eso).
     *
     * Si la sucursal no tiene CAFs disponibles y hay pool central, intenta
     * auto-asignarle una cuota antes de devolver la lista.
     *
     * @param bool $autoAsignar  habilita auto-asignación desde pool (default true)
     */
    public function cafsDisponibles(string $sucursalId, ?int $tipoDte = null, bool $autoAsignar = true): array
    {
        // 1) Buscar lo que ya tiene asignado
        $cafs = $this->buscarCafsAsignados($sucursalId, $tipoDte);

        // 2) Si no tiene nada Y se permite auto-asignar Y se pidió un tipo específico:
        //    intentar tomar del pool
        $autoAsignaciones = [];
        if (empty($cafs) && $autoAsignar && $tipoDte !== null) {
            $r = $this->autoAsignarDesdePool($sucursalId, $tipoDte);
            if (!empty($r['ok'])) {
                $autoAsignaciones[] = $r;
                $cafs = $this->buscarCafsAsignados($sucursalId, $tipoDte);
            }
        }
        // 3) Si no se pidió tipo específico pero hay pool de varios tipos, intentar todos
        if ($tipoDte === null && $autoAsignar) {
            if ($this->useLegacyTables) {
                $tiposEnPool = $this->pdo->query("
                    SELECT DISTINCT tipo_dte FROM cafs
                    WHERE sucursal_id IS NULL AND agotado = 0
                ")->fetchAll(PDO::FETCH_COLUMN);
            } else {
                // Para SaaS:
                // Obtener empresa de la sucursal
                $stmtSuc = $this->pdo->prepare("SELECT empresa_id FROM sucursales WHERE id = ? LIMIT 1");
                $stmtSuc->execute([$sucursalId]);
                $empresaId = (int)($stmtSuc->fetchColumn() ?: 0);
                
                // Buscar tipos de documentos con pool activo en caf_archivos no totalmente asignados
                $stPool = $this->pdo->prepare("
                    SELECT DISTINCT ca.tipo_documento
                    FROM caf_archivos ca
                    LEFT JOIN folios_asignaciones fa ON fa.caf_id = ca.id
                    WHERE ca.empresa_id = ?
                      AND ca.ambiente = ?
                      AND ca.estado = 'ACTIVO'
                    GROUP BY ca.id, ca.tipo_documento
                    HAVING COALESCE(MAX(fa.folio_hasta), ca.folio_desde - 1) < ca.folio_hasta
                ");
                $stPool->execute([$empresaId, $this->ambiente]);
                $tiposEnPool = array_map(
                    fn($enum) => $this->mapEnumToTipoDte($enum),
                    $stPool->fetchAll(PDO::FETCH_COLUMN)
                );
            }
            $tiposYaAsignados = array_column($cafs, 'tipo_dte');
            foreach ($tiposEnPool as $t) {
                if (in_array($t, $tiposYaAsignados)) continue;
                $r = $this->autoAsignarDesdePool($sucursalId, (int)$t);
                if (!empty($r['ok'])) $autoAsignaciones[] = $r;
            }
            if (!empty($autoAsignaciones)) {
                $cafs = $this->buscarCafsAsignados($sucursalId, null);
            }
        }

        return [
            'ok' => true,
            'cafs' => $cafs,
            'auto_asignaciones' => $autoAsignaciones  // info de qué se asignó en esta llamada
        ];
    }

    private function buscarCafsAsignados(string $sucursalId, ?int $tipoDte): array
    {
        if ($this->useLegacyTables) {
            $sql = "
                SELECT id, tipo_dte, rut_emisor, razon_social,
                       folio_desde, folio_hasta, folio_actual,
                       fecha_autorizacion, fecha_subida, origen, agotado,
                       (folio_hasta - folio_actual + 1) AS folios_restantes
                FROM cafs
                WHERE sucursal_id = ?
                  AND agotado = 0
                  AND folio_actual <= folio_hasta
            ";
            $params = [$sucursalId];
            if ($tipoDte !== null) {
                $sql .= " AND tipo_dte = ?";
                $params[] = $tipoDte;
            }
            $sql .= " ORDER BY tipo_dte, folio_desde";
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // MyPOS SaaS mode
            $sql = "
                SELECT fa.id,
                       fa.tipo_documento,
                       ca.rut_emisor,
                       ca.razon_social_emisor AS razon_social,
                       fa.folio_desde,
                       fa.folio_hasta,
                       fa.folio_actual,
                       ca.fecha_autorizacion,
                       ca.created_at AS fecha_subida,
                       'CENTRAL' AS origen,
                       CASE WHEN fa.estado = 'AGOTADA' THEN 1 ELSE 0 END AS agotado,
                       (fa.folio_hasta - fa.folio_actual + 1) AS folios_restantes
                FROM folios_asignaciones fa
                INNER JOIN caf_archivos ca ON ca.id = fa.caf_id
                WHERE fa.sucursal_id = ?
                  AND fa.estado = 'ACTIVA'
                  AND ca.estado = 'ACTIVO'
                  AND ca.ambiente = ?
                  AND fa.folio_actual <= fa.folio_hasta
            ";
            $params = [$sucursalId, $this->ambiente];
            if ($tipoDte !== null) {
                $tipoEnum = $this->mapTipoDteToEnum($tipoDte);
                $sql .= " AND fa.tipo_documento = ?";
                $params[] = $tipoEnum;
            }
            $sql .= " ORDER BY fa.tipo_documento, fa.folio_desde";
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($rows as &$r) {
                $r['tipo_dte'] = $this->mapEnumToTipoDte($r['tipo_documento']);
                unset($r['tipo_documento']);
            }
            return $rows;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  DESCARGAR — XML completo de un CAF (con clave privada)
    // ─────────────────────────────────────────────────────────────────────────

    public function descargarCaf(int $cafId, string $sucursalId): array
    {
        if ($this->useLegacyTables) {
            $st = $this->pdo->prepare("
                SELECT xml_content, sucursal_id, agotado
                FROM cafs WHERE id = ? LIMIT 1
            ");
            $st->execute([$cafId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['ok' => false, 'error' => 'CAF no encontrado'];
            if ($row['sucursal_id'] !== null && $row['sucursal_id'] !== $sucursalId) {
                return ['ok' => false, 'error' => 'CAF no asignado a esta sucursal'];
            }
            return ['ok' => true, 'xml' => $row['xml_content'], 'agotado' => (int)$row['agotado']];
        } else {
            // MyPOS SaaS mode: descargar CAF a partir de la asignación
            $st = $this->pdo->prepare("
                SELECT ca.caf_xml, fa.sucursal_id, ca.estado
                FROM folios_asignaciones fa
                INNER JOIN caf_archivos ca ON ca.id = fa.caf_id
                WHERE fa.id = ? LIMIT 1
            ");
            $st->execute([$cafId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['ok' => false, 'error' => 'Asignación de folios no encontrada'];
            if ((int)$row['sucursal_id'] !== (int)$sucursalId) {
                return ['ok' => false, 'error' => 'CAF no asignado a esta sucursal'];
            }
            $agotado = ($row['estado'] === 'AGOTADO') ? 1 : 0;
            return ['ok' => true, 'xml' => $row['caf_xml'], 'agotado' => $agotado];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CONSUMIR — registra el uso de un folio y avanza folio_actual
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Marca un folio como consumido. Idempotente (UNIQUE en caf_consumos).
     */
    public function consumirFolio(int $cafId, int $folio, string $sucursalId, ?string $maquinaId = null): array
    {
        try {
            $this->pdo->beginTransaction();

            if ($this->useLegacyTables) {
                // Validar CAF y rango
                $st = $this->pdo->prepare("SELECT tipo_dte, folio_desde, folio_hasta, folio_actual FROM cafs WHERE id=? FOR UPDATE");
                $st->execute([$cafId]);
                $caf = $st->fetch(PDO::FETCH_ASSOC);
                if (!$caf) { $this->pdo->rollBack(); return ['ok' => false, 'error' => 'CAF no encontrado']; }
                if ($folio < $caf['folio_desde'] || $folio > $caf['folio_hasta']) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => "Folio $folio fuera de rango del CAF"];
                }

                // Insertar consumo (ignora si ya existía por UNIQUE)
                $ins = $this->pdo->prepare("
                    INSERT IGNORE INTO caf_consumos
                      (caf_id, tipo_dte, folio, sucursal_id, maquina_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $ins->execute([$cafId, $caf['tipo_dte'], $folio, $sucursalId, $maquinaId]);

                // Avanzar folio_actual si corresponde
                if ($folio >= $caf['folio_actual']) {
                    $nuevoActual = $folio + 1;
                    $agotado = $nuevoActual > $caf['folio_hasta'] ? 1 : 0;
                    $upd = $this->pdo->prepare("UPDATE cafs SET folio_actual=?, agotado=? WHERE id=?");
                    $upd->execute([min($nuevoActual, $caf['folio_hasta']), $agotado, $cafId]);
                }
            } else {
                // MyPOS SaaS mode: validar asignación y rango
                $st = $this->pdo->prepare("
                    SELECT fa.empresa_id, fa.tipo_documento, fa.folio_desde, fa.folio_hasta, fa.folio_actual, fa.caf_id
                    FROM folios_asignaciones fa
                    WHERE fa.id = ? FOR UPDATE
                ");
                $st->execute([$cafId]);
                $asig = $st->fetch(PDO::FETCH_ASSOC);
                if (!$asig) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => 'Asignación no encontrada'];
                }
                if ($folio < $asig['folio_desde'] || $folio > $asig['folio_hasta']) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => "Folio $folio fuera de rango de la asignación"];
                }

                // Insertar consumo en folios_consumidos si no existe
                $stCheck = $this->pdo->prepare("
                    SELECT id FROM folios_consumidos 
                    WHERE empresa_id = ? AND tipo_documento = ? AND folio = ? LIMIT 1
                ");
                $stCheck->execute([$asig['empresa_id'], $asig['tipo_documento'], $folio]);
                if (!$stCheck->fetchColumn()) {
                    $ins = $this->pdo->prepare("
                        INSERT INTO folios_consumidos (
                            empresa_id, sucursal_id, caf_archivo_id, asignacion_id, folio_asignacion_id,
                            tipo_documento, folio, estado, sync_status, fecha_consumo, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ENVIADO_SII', 'SYNCED', NOW(), NOW())
                    ");
                    $ins->execute([
                        $asig['empresa_id'], $sucursalId, $asig['caf_id'], $cafId, $cafId,
                        $asig['tipo_documento'], $folio
                    ]);
                }

                // Avanzar folio_actual en folios_asignaciones si corresponde
                if ($folio >= $asig['folio_actual']) {
                    $nuevoActual = $folio + 1;
                    $estado = $nuevoActual > $asig['folio_hasta'] ? 'AGOTADA' : 'ACTIVA';
                    $upd = $this->pdo->prepare("
                        UPDATE folios_asignaciones 
                        SET folio_actual = ?, estado = ?, agotado_at = ?
                        WHERE id = ?
                    ");
                    $agotadoAt = ($estado === 'AGOTADA') ? date('Y-m-d H:i:s') : null;
                    $upd->execute([
                        min($nuevoActual, $asig['folio_hasta']), $estado, $agotadoAt, $cafId
                    ]);
                }
            }

            $this->pdo->commit();
            return ['ok' => true];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  DISTRIBUIR — divide un CAF (sucursal_id=NULL) en sub-rangos por sucursal
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Toma el CAF de pool central (sucursal_id NULL) y lo reparte entre las
     * sucursales activas según su consumo medio diario.
     */
    public function distribuirCaf(int $cafId, array $sucursales): array
    {
        try {
            $this->pdo->beginTransaction();

            if ($this->useLegacyTables) {
                $st = $this->pdo->prepare("SELECT * FROM cafs WHERE id=? FOR UPDATE");
                $st->execute([$cafId]);
                $caf = $st->fetch(PDO::FETCH_ASSOC);
                if (!$caf) { $this->pdo->rollBack(); return ['ok' => false, 'error' => 'CAF no encontrado']; }
                if ($caf['sucursal_id'] !== null) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => 'CAF ya está asignado a sucursal ' . $caf['sucursal_id']];
                }
                if ((int)$caf['agotado'] === 1) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => 'CAF ya está agotado'];
                }

                $tipo = (int)$caf['tipo_dte'];
                $total = (int)$caf['folio_hasta'] - (int)$caf['folio_actual'] + 1;
                if ($total <= 0 || count($sucursales) === 0) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => 'Nada que distribuir'];
                }

                // Obtener consumo medio diario por sucursal (últimos 30 días)
                $in = implode(',', array_fill(0, count($sucursales), '?'));
                $stm = $this->pdo->prepare("
                    SELECT sucursal_id, COUNT(*) as folios
                    FROM caf_consumos
                    WHERE tipo_dte = ?
                      AND sucursal_id IN ($in)
                      AND fecha_consumo >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY sucursal_id
                ");
                $stm->execute(array_merge([$tipo], $sucursales));
                $consumos = [];
                foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $consumos[$r['sucursal_id']] = (int)$r['folios'];
                }
                $sumConsumos = array_sum($consumos);

                // Calcular cuota por sucursal
                $cuotas = [];
                if ($sumConsumos > 0) {
                    foreach ($sucursales as $s) {
                        $c = $consumos[$s] ?? 0;
                        $cuotas[$s] = (int) round($total * ($c / $sumConsumos));
                    }
                } else {
                    // Sin histórico: dividir parejo
                    $base = intdiv($total, count($sucursales));
                    foreach ($sucursales as $s) $cuotas[$s] = $base;
                }

                // Ajustar para no exceder el total (por redondeos)
                $asignado = array_sum($cuotas);
                if ($asignado != $total) {
                    $maxKey = $sucursales[0];
                    $maxV = -1;
                    foreach ($sucursales as $s) {
                        $v = $consumos[$s] ?? 0;
                        if ($v > $maxV) { $maxV = $v; $maxKey = $s; }
                    }
                    $cuotas[$maxKey] += ($total - $asignado);
                }

                // Garantizar al menos 1 folio por sucursal que pidió
                if ($total >= count($sucursales)) {
                    foreach ($cuotas as $s => &$q) if ($q < 1) $q = 1;
                    unset($q);
                    $asignado = array_sum($cuotas);
                    while ($asignado > $total) {
                        arsort($cuotas);
                        foreach ($cuotas as $s => &$q) { if ($q > 1) { $q--; $asignado--; break; } }
                        unset($q);
                    }
                }

                // Crear sub-CAFs con sus sub-rangos
                $folioCursor = (int)$caf['folio_actual'];
                $distribucion = [];
                $ins = $this->pdo->prepare("
                    INSERT INTO cafs
                      (tipo_dte, rut_emisor, razon_social, folio_desde, folio_hasta,
                       folio_actual, sucursal_id, xml_content, fecha_autorizacion, origen, observaciones)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'CENTRAL', ?)
                ");
                foreach ($cuotas as $sucursal => $cantidad) {
                    if ($cantidad <= 0) continue;
                    $desde = $folioCursor;
                    $hasta = $folioCursor + $cantidad - 1;
                    if ($hasta > (int)$caf['folio_hasta']) $hasta = (int)$caf['folio_hasta'];
                    $obs = "Distribuido desde CAF padre #$cafId";
                    $ins->execute([
                        $tipo, $caf['rut_emisor'], $caf['razon_social'],
                        $desde, $hasta, $desde, $sucursal,
                        $caf['xml_content'], $caf['fecha_autorizacion'], $obs
                    ]);
                    $distribucion[] = [
                        'id' => (int)$this->pdo->lastInsertId(),
                        'sucursal_id' => $sucursal,
                        'desde' => $desde, 'hasta' => $hasta, 'cantidad' => $hasta - $desde + 1
                    ];
                    $folioCursor = $hasta + 1;
                    if ($folioCursor > (int)$caf['folio_hasta']) break;
                }

                // Marcar el padre como agotado
                $this->pdo->prepare("UPDATE cafs SET agotado=1, observaciones=? WHERE id=?")
                    ->execute(["Distribuido en " . count($distribucion) . " sub-CAFs", $cafId]);
            } else {
                // MyPOS SaaS mode: distribuir folios de caf_archivos
                $st = $this->pdo->prepare("SELECT * FROM caf_archivos WHERE id=? FOR UPDATE");
                $st->execute([$cafId]);
                $caf = $st->fetch(PDO::FETCH_ASSOC);
                if (!$caf) { $this->pdo->rollBack(); return ['ok' => false, 'error' => 'CAF no encontrado']; }
                if ($caf['estado'] !== 'ACTIVO') {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => 'El CAF de origen no está activo'];
                }

                // Determinar el inicio del rango libre
                $stMax = $this->pdo->prepare("SELECT COALESCE(MAX(folio_hasta), ?) FROM folios_asignaciones WHERE caf_id = ?");
                $stMax->execute([$caf['folio_desde'] - 1, $cafId]);
                $maxAsignado = (int)$stMax->fetchColumn();

                $folioCursor = $maxAsignado + 1;
                $total = (int)$caf['folio_hasta'] - $folioCursor + 1;
                if ($total <= 0 || count($sucursales) === 0) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => 'Nada que distribuir o folios completamente asignados'];
                }

                // Obtener consumo medio diario por sucursal (últimos 30 días) en folios_consumidos
                $in = implode(',', array_fill(0, count($sucursales), '?'));
                $stm = $this->pdo->prepare("
                    SELECT sucursal_id, COUNT(*) as folios
                    FROM folios_consumidos
                    WHERE tipo_documento = ?
                      AND sucursal_id IN ($in)
                      AND fecha_consumo >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY sucursal_id
                ");
                $stm->execute(array_merge([$caf['tipo_documento']], $sucursales));
                $consumos = [];
                foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $consumos[$r['sucursal_id']] = (int)$r['folios'];
                }
                $sumConsumos = array_sum($consumos);

                // Calcular cuota por sucursal
                $cuotas = [];
                if ($sumConsumos > 0) {
                    foreach ($sucursales as $s) {
                        $c = $consumos[$s] ?? 0;
                        $cuotas[$s] = (int) round($total * ($c / $sumConsumos));
                    }
                } else {
                    $base = intdiv($total, count($sucursales));
                    foreach ($sucursales as $s) $cuotas[$s] = $base;
                }

                $asignado = array_sum($cuotas);
                if ($asignado != $total) {
                    $maxKey = $sucursales[0];
                    $maxV = -1;
                    foreach ($sucursales as $s) {
                        $v = $consumos[$s] ?? 0;
                        if ($v > $maxV) { $maxV = $v; $maxKey = $s; }
                    }
                    $cuotas[$maxKey] += ($total - $asignado);
                }

                if ($total >= count($sucursales)) {
                    foreach ($cuotas as $s => &$q) if ($q < 1) $q = 1;
                    unset($q);
                    $asignado = array_sum($cuotas);
                    while ($indigo = ($asignado > $total)) {
                        arsort($cuotas);
                        foreach ($cuotas as $s => &$q) { if ($q > 1) { $q--; $asignado--; break; } }
                        unset($q);
                    }
                }

                // Crear las asignaciones
                $distribucion = [];
                $ins = $this->pdo->prepare("
                    INSERT INTO folios_asignaciones (
                        empresa_id, sucursal_id, caf_id, tipo_documento,
                        folio_desde, folio_hasta, folio_actual, estado, asignado_at, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVA', NOW(), NOW())
                ");
                foreach ($cuotas as $sucursal => $cantidad) {
                    if ($cantidad <= 0) continue;
                    $desde = $folioCursor;
                    $hasta = $folioCursor + $cantidad - 1;
                    if ($hasta > (int)$caf['folio_hasta']) $hasta = (int)$caf['folio_hasta'];
                    
                    $ins->execute([
                        $caf['empresa_id'], $sucursal, $cafId, $caf['tipo_documento'],
                        $desde, $hasta, $desde
                    ]);
                    $distribucion[] = [
                        'id' => (int)$this->pdo->lastInsertId(),
                        'sucursal_id' => $sucursal,
                        'desde' => $desde, 'hasta' => $hasta, 'cantidad' => $hasta - $desde + 1
                    ];
                    $folioCursor = $hasta + 1;
                    if ($folioCursor > (int)$caf['folio_hasta']) break;
                }

                if ($folioCursor > (int)$caf['folio_hasta']) {
                    $this->pdo->prepare("UPDATE caf_archivos SET estado = 'AGOTADO' WHERE id = ?")
                        ->execute([$cafId]);
                }
            }

            $this->pdo->commit();
            return ['ok' => true, 'distribucion' => $distribucion];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ESTADÍSTICAS — para dashboard y alertas
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resumen global: por tipo, total folios disponibles, sucursales sin stock,
     * sucursales con stock crítico (< N días de consumo).
     */
    public function resumenGlobal(int $diasUmbral = 7): array
    {
        if ($this->useLegacyTables) {
            $sql = "
                SELECT tipo_dte,
                       COUNT(*) as cafs_activos,
                       SUM(folio_hasta - folio_actual + 1) as folios_disponibles,
                       SUM(CASE WHEN sucursal_id IS NULL THEN 1 ELSE 0 END) as cafs_pool
                FROM cafs
                WHERE agotado = 0
                GROUP BY tipo_dte
                ORDER BY tipo_dte
            ";
            $resumen = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            // Sucursales con stock crítico
            $sqlAlertas = "
                SELECT * FROM (
                    SELECT
                        c.sucursal_id,
                        c.tipo_dte,
                        SUM(c.folio_hasta - c.folio_actual + 1) AS folios_disponibles,
                        IFNULL(cm.folios_dia_prom, 0)            AS consumo_dia_prom
                    FROM cafs c
                    LEFT JOIN caf_consumo_medio cm
                      ON cm.sucursal_id = c.sucursal_id AND cm.tipo_dte = c.tipo_dte
                    WHERE c.agotado = 0 AND c.sucursal_id IS NOT NULL
                    GROUP BY c.sucursal_id, c.tipo_dte
                ) AS resumen_sucursal
                WHERE consumo_dia_prom > 0
                  AND folios_disponibles / consumo_dia_prom < ?
                ORDER BY folios_disponibles / consumo_dia_prom ASC
            ";
            $st = $this->pdo->prepare($sqlAlertas);
            $st->execute([$diasUmbral]);
            $alertas = $st->fetchAll(PDO::FETCH_ASSOC);

            return [
                'ok' => true,
                'por_tipo' => $resumen,
                'alertas_stock_bajo' => $alertas,
                'umbral_dias' => $diasUmbral
            ];
        } else {
            // MyPOS SaaS mode
            // Resumen global por tipo
            $sql = "
                SELECT ca.tipo_documento,
                       COUNT(DISTINCT ca.id) as cafs_activos,
                       SUM(ca.folio_hasta - ca.folio_desde + 1 - COALESCE(fc.consumidos, 0)) AS folios_disponibles
                FROM caf_archivos ca
                LEFT JOIN (
                    SELECT caf_archivo_id, COUNT(*) AS consumidos
                    FROM folios_consumidos
                    GROUP BY caf_archivo_id
                ) fc ON fc.caf_archivo_id = ca.id
                WHERE ca.estado = 'ACTIVO'
                GROUP BY ca.tipo_documento
            ";
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            
            $resumen = [];
            foreach ($rows as $r) {
                $tipoDte = $this->mapEnumToTipoDte($r['tipo_documento']);
                
                // Calcular pool central por tipo
                $stPool = $this->pdo->prepare("
                    SELECT SUM(ca.folio_hasta - COALESCE(max_fa.max_hasta, ca.folio_desde - 1)) AS pool_restantes
                    FROM caf_archivos ca
                    LEFT JOIN (
                        SELECT caf_id, MAX(folio_hasta) AS max_hasta
                        FROM folios_asignaciones
                        GROUP BY caf_id
                    ) max_fa ON max_fa.caf_id = ca.id
                    WHERE ca.estado = 'ACTIVO' AND ca.tipo_documento = ?
                      AND COALESCE(max_fa.max_hasta, ca.folio_desde - 1) < ca.folio_hasta
                ");
                $stPool->execute([$r['tipo_documento']]);
                $poolCount = (int)$stPool->fetchColumn() ? 1 : 0;

                $resumen[] = [
                    'tipo_dte' => $tipoDte,
                    'cafs_activos' => (int)$r['cafs_activos'],
                    'folios_disponibles' => (int)$r['folios_disponibles'],
                    'cafs_pool' => $poolCount
                ];
            }

            // Sucursales con stock crítico
            $sqlAsig = "
                SELECT fa.sucursal_id, 
                       s.nombre AS sucursal_nombre,
                       fa.tipo_documento,
                       SUM(fa.folio_hasta - fa.folio_actual + 1) AS folios_disponibles
                FROM folios_asignaciones fa
                INNER JOIN sucursales s ON s.id = fa.sucursal_id
                WHERE fa.estado = 'ACTIVA'
                GROUP BY fa.sucursal_id, fa.tipo_documento, s.nombre
            ";
            $resumenSuc = $this->pdo->query($sqlAsig)->fetchAll(PDO::FETCH_ASSOC);

            $alertas = [];
            foreach ($resumenSuc as $row) {
                $sucId = $row['sucursal_id'];
                $tipoDoc = $row['tipo_documento'];
                
                // Consumo últimos 30 días
                $stCons = $this->pdo->prepare("
                    SELECT COUNT(*) FROM folios_consumidos
                    WHERE sucursal_id = ? AND tipo_documento = ?
                      AND fecha_consumo >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ");
                $stCons->execute([$sucId, $tipoDoc]);
                $consumidos = (int)$stCons->fetchColumn();
                $prom = $consumidos / 30.0;
                
                if ($prom > 0) {
                    $diasEst = $row['folios_disponibles'] / $prom;
                    if ($diasEst < $diasUmbral) {
                        $alertas[] = [
                            'sucursal_id' => $row['sucursal_nombre'] ?? $sucId,
                            'tipo_dte' => $this->mapEnumToTipoDte($tipoDoc),
                            'folios_disponibles' => (int)$row['folios_disponibles'],
                            'consumo_dia_prom' => round($prom, 2)
                        ];
                    }
                }
            }

            return [
                'ok' => true,
                'por_tipo' => $resumen,
                'alertas_stock_bajo' => $alertas,
                'umbral_dias' => $diasUmbral
            ];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CALCULADORA: cuántos folios pedir al SII
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Calcula la cantidad óptima de folios a pedir al SII para cada tipo de DTE.
     */
    public function calcularPedidoOptimo(int $diasProyeccion = 60, float $factorSeguridad = 1.3): array
    {
        if ($this->useLegacyTables) {
            // Consumo histórico 90 días (global, todas las sucursales)
            $sqlHist = "
                SELECT tipo_dte, COUNT(*) AS consumidos_90d
                FROM caf_consumos
                WHERE fecha_consumo >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY tipo_dte
            ";
            $hist = [];
            foreach ($this->pdo->query($sqlHist) as $r) {
                $hist[(int)$r['tipo_dte']] = (int)$r['consumidos_90d'];
            }

            // Disponibles ahora
            $sqlDisp = "
                SELECT tipo_dte, SUM(folio_hasta - folio_actual + 1) AS disponibles
                FROM cafs
                WHERE agotado = 0
                GROUP BY tipo_dte
            ";
            $disp = [];
            foreach ($this->pdo->query($sqlDisp) as $r) {
                $disp[(int)$r['tipo_dte']] = (int)$r['disponibles'];
            }
        } else {
            // MyPOS SaaS mode
            $sqlHist = "
                SELECT tipo_documento, COUNT(*) AS consumidos_90d
                FROM folios_consumidos
                WHERE fecha_consumo >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY tipo_documento
            ";
            $hist = [];
            foreach ($this->pdo->query($sqlHist) as $r) {
                $t = $this->mapEnumToTipoDte($r['tipo_documento']);
                $hist[$t] = (int)$r['consumidos_90d'];
            }

            $sqlDisp = "
                SELECT ca.tipo_documento, SUM(ca.folio_hasta - ca.folio_desde + 1 - COALESCE(fc.consumidos, 0)) AS disponibles
                FROM caf_archivos ca
                LEFT JOIN (
                    SELECT caf_archivo_id, COUNT(*) AS consumidos
                    FROM folios_consumidos
                    GROUP BY caf_archivo_id
                ) fc ON fc.caf_archivo_id = ca.id
                WHERE ca.estado = 'ACTIVO'
                GROUP BY ca.tipo_documento
            ";
            $disp = [];
            foreach ($this->pdo->query($sqlDisp) as $r) {
                $t = $this->mapEnumToTipoDte($r['tipo_documento']);
                $disp[$t] = (int)$r['disponibles'];
            }
        }

        $tipos = array_unique(array_merge(array_keys($hist), array_keys($disp)));
        sort($tipos);
        $sugerencias = [];
        foreach ($tipos as $t) {
            $consumido90 = $hist[$t] ?? 0;
            $consumoDiario = $consumido90 / 90.0;
            $necesidad = (int) ceil($consumoDiario * $diasProyeccion);
            $disponible = $disp[$t] ?? 0;
            $faltante = max(0, $necesidad - $disponible);
            $sugerido = (int) ceil($faltante * $factorSeguridad);
            if ($sugerido > 0) $sugerido = (int) (ceil($sugerido / 100) * 100);

            $diasRestantes = $consumoDiario > 0
                ? round($disponible / $consumoDiario, 1)
                : null;
            $sugerencias[] = [
                'tipo_dte'         => $t,
                'consumo_diario'   => round($consumoDiario, 2),
                'disponibles'      => $disponible,
                'dias_restantes'   => $diasRestantes,
                'necesidad_proyectada' => $necesidad,
                'faltante'         => $faltante,
                'sugerido_pedir'   => $sugerido,
                'urgente'          => $diasRestantes !== null && $diasRestantes < 14,
            ];
        }
        return [
            'ok' => true,
            'dias_proyeccion'    => $diasProyeccion,
            'factor_seguridad'   => $factorSeguridad,
            'sugerencias'        => $sugerencias
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  AUTO-ASIGNACIÓN cuando un POS pide y solo hay pool
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Si una sucursal pide CAFs (caf_disponibles) y no tiene asignados, intenta
     * auto-asignarle una cuota del pool central.
     */
    public function autoAsignarDesdePool(string $sucursalId, int $tipoDte, int $cuotaDefault = 100): array
    {
        try {
            $this->pdo->beginTransaction();

            if ($this->useLegacyTables) {
                // Buscar CAF en pool con folios disponibles
                $st = $this->pdo->prepare("
                    SELECT * FROM cafs
                    WHERE sucursal_id IS NULL
                      AND tipo_dte = ?
                      AND agotado = 0
                      AND folio_actual <= folio_hasta
                    ORDER BY folio_desde
                    LIMIT 1
                    FOR UPDATE
                ");
                $st->execute([$tipoDte]);
                $pool = $st->fetch(PDO::FETCH_ASSOC);
                if (!$pool) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'reason' => 'pool_vacio',
                            'mensaje' => "Sin CAFs en pool para tipo $tipoDte"];
                }

                // Determinar cuota basada en consumo medio
                $stCm = $this->pdo->prepare("
                    SELECT folios_dia_prom FROM caf_consumo_medio
                    WHERE sucursal_id = ? AND tipo_dte = ?
                ");
                $stCm->execute([$sucursalId, $tipoDte]);
                $cm = $stCm->fetch(PDO::FETCH_ASSOC);
                $cuota = $cm
                    ? max(50, (int) ceil(((float)$cm['folios_dia_prom']) * 30))
                    : $cuotaDefault;

                $disponiblesPool = (int)$pool['folio_hasta'] - (int)$pool['folio_actual'] + 1;
                $cuota = min($cuota, $disponiblesPool);
                if ($cuota <= 0) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'reason' => 'cuota_cero'];
                }

                $desde = (int)$pool['folio_actual'];
                $hasta = $desde + $cuota - 1;

                // Crear sub-CAF asignado a la sucursal
                $ins = $this->pdo->prepare("
                    INSERT INTO cafs
                      (tipo_dte, rut_emisor, razon_social, folio_desde, folio_hasta,
                       folio_actual, sucursal_id, xml_content, fecha_autorizacion, origen, observaciones)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'CENTRAL', ?)
                ");
                $ins->execute([
                    $tipoDte, $pool['rut_emisor'], $pool['razon_social'],
                    $desde, $hasta, $desde, $sucursalId,
                    $pool['xml_content'], $pool['fecha_autorizacion'],
                    "Auto-asignado desde pool #{$pool['id']} a $sucursalId"
                ]);
                $nuevoId = (int)$this->pdo->lastInsertId();

                // Avanzar el pool padre
                $nuevoActual = $hasta + 1;
                $agotado = $nuevoActual > (int)$pool['folio_hasta'] ? 1 : 0;
                $this->pdo->prepare("UPDATE cafs SET folio_actual=?, agotado=? WHERE id=?")
                    ->execute([min($nuevoActual, (int)$pool['folio_hasta']), $agotado, $pool['id']]);

                $this->pdo->commit();
                return [
                    'ok' => true,
                    'caf_id' => $nuevoId,
                    'desde' => $desde, 'hasta' => $hasta, 'cantidad' => $cuota,
                    'origen_pool' => (int)$pool['id']
                ];
            } else {
                // MyPOS SaaS mode
                // Obtener empresa de la sucursal
                $stmtSuc = $this->pdo->prepare("SELECT empresa_id FROM sucursales WHERE id = ? LIMIT 1");
                $stmtSuc->execute([$sucursalId]);
                $empresaId = (int)($stmtSuc->fetchColumn() ?: 0);
                if ($empresaId === 0) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'reason' => 'sucursal_invalida'];
                }

                $tipoEnum = $this->mapTipoDteToEnum($tipoDte);

                // Buscar CAF en pool con folios disponibles, filtrando por ambiente
                $st = $this->pdo->prepare("
                    SELECT ca.*,
                           COALESCE(MAX(fa.folio_hasta), ca.folio_desde - 1) AS max_asignado
                    FROM caf_archivos ca
                    LEFT JOIN folios_asignaciones fa ON fa.caf_id = ca.id
                    WHERE ca.empresa_id = ?
                      AND ca.tipo_documento = ?
                      AND ca.ambiente = ?
                      AND ca.estado = 'ACTIVO'
                    GROUP BY ca.id
                    HAVING max_asignado < ca.folio_hasta
                    ORDER BY ca.folio_desde ASC
                    LIMIT 1
                    FOR UPDATE
                ");
                $st->execute([$empresaId, $tipoEnum, $this->ambiente]);
                $pool = $st->fetch(PDO::FETCH_ASSOC);
                if (!$pool) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'reason' => 'pool_vacio',
                            'mensaje' => "Sin CAFs en pool para tipo $tipoDte"];
                }

                // Calcular cuota dinámicamente de folios_consumidos
                $stCons = $this->pdo->prepare("
                    SELECT COUNT(*) FROM folios_consumidos
                    WHERE sucursal_id = ? AND tipo_documento = ?
                      AND fecha_consumo >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ");
                $stCons->execute([$sucursalId, $tipoEnum]);
                $consumidos = (int)$stCons->fetchColumn();
                $prom = $consumidos / 30.0;

                $cuota = $prom > 0
                    ? max(50, (int) ceil($prom * 30))
                    : $cuotaDefault;

                $poolDesde = (int)$pool['max_asignado'] + 1;
                $disponiblesPool = (int)$pool['folio_hasta'] - $poolDesde + 1;
                $cuota = min($cuota, $disponiblesPool);
                if ($cuota <= 0) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'reason' => 'cuota_cero'];
                }

                $desde = $poolDesde;
                $hasta = $desde + $cuota - 1;

                // Crear asignación
                $ins = $this->pdo->prepare("
                    INSERT INTO folios_asignaciones (
                        empresa_id, sucursal_id, caf_id, tipo_documento,
                        folio_desde, folio_hasta, folio_actual, estado, asignado_at, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVA', NOW(), NOW())
                ");
                $ins->execute([
                    $empresaId, $sucursalId, $pool['id'], $tipoEnum,
                    $desde, $hasta, $desde
                ]);
                $nuevoId = (int)$this->pdo->lastInsertId();

                $this->pdo->commit();
                return [
                    'ok' => true,
                    'caf_id' => $nuevoId,
                    'desde' => $desde, 'hasta' => $hasta, 'cantidad' => $cuota,
                    'origen_pool' => (int)$pool['id']
                ];
            }
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['ok' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ASIGNACIÓN MANUAL — admin elige exactamente cuántos folios asigna
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Asigna una cantidad exacta de folios del pool central a una sucursal.
     */
    public function asignacionManual(string $sucursalId, int $tipoDte, int $cantidad): array
    {
        if ($cantidad <= 0) {
            return ['ok' => false, 'error' => 'La cantidad debe ser mayor a 0'];
        }

        if ($this->useLegacyTables) {
            $pendiente = $cantidad;
            $rangos    = [];

            try {
                $this->pdo->beginTransaction();

                while ($pendiente > 0) {
                    // Tomar el CAF del pool con más folios disponibles primero
                    $st = $this->pdo->prepare("
                        SELECT * FROM cafs
                        WHERE sucursal_id IS NULL
                          AND tipo_dte    = ?
                          AND agotado     = 0
                          AND folio_actual <= folio_hasta
                        ORDER BY (folio_hasta - folio_actual) DESC
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $st->execute([$tipoDte]);
                    $pool = $st->fetch(PDO::FETCH_ASSOC);

                    if (!$pool) {
                        if (empty($rangos)) {
                            $this->pdo->rollBack();
                            return ['ok' => false, 'error' => "Sin folios en pool central para tipo $tipoDte"];
                        }
                        break;
                    }

                    $disponiblePool = (int)$pool['folio_hasta'] - (int)$pool['folio_actual'] + 1;
                    $tomar          = min($pendiente, $disponiblePool);
                    $desde          = (int)$pool['folio_actual'];
                    $hasta          = $desde + $tomar - 1;

                    // Crear sub-CAF
                    $ins = $this->pdo->prepare("
                        INSERT INTO cafs
                          (tipo_dte, rut_emisor, razon_social, folio_desde, folio_hasta,
                           folio_actual, sucursal_id, xml_content, fecha_autorizacion, origen, observaciones)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'CENTRAL', ?)
                    ");
                    $ins->execute([
                        $tipoDte, $pool['rut_emisor'], $pool['razon_social'],
                        $desde, $hasta, $desde, $sucursalId,
                        $pool['xml_content'], $pool['fecha_autorizacion'],
                        "Asignación manual desde pool #{$pool['id']}"
                    ]);
                    $rangos[] = ['id' => (int)$this->pdo->lastInsertId(),
                                 'desde' => $desde, 'hasta' => $hasta, 'cantidad' => $tomar];

                    // Avanzar el pool padre
                    $nuevoActual = $hasta + 1;
                    $agotado     = $nuevoActual > (int)$pool['folio_hasta'] ? 1 : 0;
                    $this->pdo->prepare("UPDATE cafs SET folio_actual=?, agotado=? WHERE id=?")
                        ->execute([min($nuevoActual, (int)$pool['folio_hasta']), $agotado, $pool['id']]);

                    $pendiente -= $tomar;
                }

                $this->pdo->commit();

                $asignados = array_sum(array_column($rangos, 'cantidad'));
                return [
                    'ok'        => true,
                    'asignados' => $asignados,
                    'pedidos'   => $cantidad,
                    'faltaron'  => max(0, $cantidad - $asignados),
                    'rangos'    => $rangos,
                ];
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        } else {
            // MyPOS SaaS mode
            $stSuc = $this->pdo->prepare("SELECT empresa_id FROM sucursales WHERE id = ? LIMIT 1");
            $stSuc->execute([$sucursalId]);
            $empresaId = (int)($stSuc->fetchColumn() ?: 0);
            if ($empresaId === 0) {
                return ['ok' => false, 'error' => 'Sucursal no encontrada'];
            }

            $tipoEnum = $this->mapTipoDteToEnum($tipoDte);
            $pendiente = $cantidad;
            $rangos = [];

            try {
                $this->pdo->beginTransaction();

                while ($pendiente > 0) {
                    $st = $this->pdo->prepare("
                        SELECT ca.*, 
                               COALESCE(MAX(fa.folio_hasta), ca.folio_desde - 1) AS max_asignado
                        FROM caf_archivos ca
                        LEFT JOIN folios_asignaciones fa ON fa.caf_id = ca.id
                        WHERE ca.empresa_id = ?
                          AND ca.tipo_documento = ?
                          AND ca.estado = 'ACTIVO'
                        GROUP BY ca.id
                        HAVING max_asignado < ca.folio_hasta
                        ORDER BY ca.folio_desde ASC
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $st->execute([$empresaId, $tipoEnum]);
                    $pool = $st->fetch(PDO::FETCH_ASSOC);

                    if (!$pool) {
                        if (empty($rangos)) {
                            $this->pdo->rollBack();
                            return ['ok' => false, 'error' => "Sin folios en pool central para tipo $tipoDte"];
                        }
                        break;
                    }

                    $poolDesde = (int)$pool['max_asignado'] + 1;
                    $disponiblePool = (int)$pool['folio_hasta'] - $poolDesde + 1;
                    $tomar = min($pendiente, $disponiblePool);
                    $hasta = $poolDesde + $tomar - 1;

                    // Crear la asignación
                    $ins = $this->pdo->prepare("
                        INSERT INTO folios_asignaciones (
                            empresa_id, sucursal_id, caf_id, tipo_documento,
                            folio_desde, folio_hasta, folio_actual, estado, asignado_at, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVA', NOW(), NOW())
                    ");
                    $ins->execute([
                        $empresaId, $sucursalId, $pool['id'], $tipoEnum,
                        $poolDesde, $hasta, $poolDesde
                    ]);

                    $rangos[] = [
                        'id' => (int)$this->pdo->lastInsertId(),
                        'desde' => $poolDesde,
                        'hasta' => $hasta,
                        'cantidad' => $tomar
                    ];

                    $pendiente -= $tomar;
                }

                $this->pdo->commit();
                $asignados = array_sum(array_column($rangos, 'cantidad'));
                return [
                    'ok' => true,
                    'asignados' => $asignados,
                    'pedidos' => $cantidad,
                    'faltaron' => max(0, $cantidad - $asignados),
                    'rangos' => $rangos
                ];
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  STOCK POR SUCURSAL — para el dashboard visual
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Devuelve el stock actual agrupado por sucursal, con días estimados
     * basados en consumo medio diario (caf_consumo_medio).
     */
    public function stockPorSucursal(): array
    {
        if ($this->useLegacyTables) {
            $sql = "
                SELECT
                    c.sucursal_id,
                    COALESCE(ds.nombre, c.sucursal_id)              AS sucursal_nombre,
                    c.tipo_dte,
                    SUM(c.folio_hasta - c.folio_actual + 1)         AS folios_disponibles,
                    COUNT(*)                                         AS cafs_activos,
                    IFNULL(cm.folios_dia_prom, 0)                   AS consumo_dia_prom,
                    CASE
                        WHEN IFNULL(cm.folios_dia_prom,0) > 0
                        THEN ROUND(SUM(c.folio_hasta - c.folio_actual + 1) / cm.folios_dia_prom, 1)
                        ELSE NULL
                    END                                              AS dias_estimados
                FROM cafs c
                LEFT JOIN caf_consumo_medio cm
                       ON cm.sucursal_id = c.sucursal_id
                      AND cm.tipo_dte    = c.tipo_dte
                LEFT JOIN dim_sucursal ds
                       ON ds.id_sucursal = c.sucursal_id
                WHERE c.agotado      = 0
                  AND c.sucursal_id IS NOT NULL
                  AND c.folio_actual <= c.folio_hasta
                GROUP BY c.sucursal_id, c.tipo_dte, cm.folios_dia_prom, ds.nombre
                ORDER BY ds.nombre, c.tipo_dte
            ";

            $rows = [];
            try {
                $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // dim_sucursal podría no estar disponible; fallback sin JOIN
                $sqlFallback = "
                    SELECT
                        c.sucursal_id,
                        c.sucursal_id                                    AS sucursal_nombre,
                        c.tipo_dte,
                        SUM(c.folio_hasta - c.folio_actual + 1)         AS folios_disponibles,
                        COUNT(*)                                         AS cafs_activos,
                        IFNULL(cm.folios_dia_prom, 0)                   AS consumo_dia_prom,
                        CASE
                            WHEN IFNULL(cm.folios_dia_prom,0) > 0
                            THEN ROUND(SUM(c.folio_hasta - c.folio_actual + 1) / cm.folios_dia_prom, 1)
                            ELSE NULL
                        END                                              AS dias_estimados
                    FROM cafs c
                    LEFT JOIN caf_consumo_medio cm
                           ON cm.sucursal_id = c.sucursal_id
                          AND cm.tipo_dte    = c.tipo_dte
                    WHERE c.agotado      = 0
                      AND c.sucursal_id IS NOT NULL
                      AND c.folio_actual <= c.folio_hasta
                    GROUP BY c.sucursal_id, c.tipo_dte, cm.folios_dia_prom
                    ORDER BY c.sucursal_id, c.tipo_dte
                ";
                $rows = $this->pdo->query($sqlFallback)->fetchAll(PDO::FETCH_ASSOC);
            }

            // Agrupar por sucursal
            $porSucursal = [];
            foreach ($rows as $r) {
                $sid = $r['sucursal_id'];
                if (!isset($porSucursal[$sid])) {
                    $porSucursal[$sid] = [
                        'sucursal_id'     => $sid,
                        'sucursal_nombre' => $r['sucursal_nombre'],
                        'tipos'           => [],
                        'total_folios'    => 0,
                    ];
                }
                $dias = $r['dias_estimados'] !== null ? (float)$r['dias_estimados'] : null;
                $porSucursal[$sid]['tipos'][] = [
                    'tipo_dte'          => (int)$r['tipo_dte'],
                    'folios_disponibles'=> (int)$r['folios_disponibles'],
                    'cafs_activos'      => (int)$r['cafs_activos'],
                    'consumo_dia_prom'  => (float)$r['consumo_dia_prom'],
                    'dias_estimados'    => $dias,
                    'urgencia'          => $dias === null ? 'sin-dato'
                                            : ($dias < 7 ? 'critico'
                                            : ($dias < 21 ? 'bajo' : 'ok')),
                ];
                $porSucursal[$sid]['total_folios'] += (int)$r['folios_disponibles'];
            }

            return array_values($porSucursal);
        } else {
            // MyPOS SaaS mode
            $stmtSucs = $this->pdo->query("SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre");
            $sucs = $stmtSucs->fetchAll(PDO::FETCH_ASSOC);
            
            $porSucursal = [];
            foreach ($sucs as $s) {
                $sid = $s['id'];
                
                $stAsig = $this->pdo->prepare("
                    SELECT tipo_documento, SUM(folio_hasta - folio_actual + 1) as disponibles, COUNT(*) as activos
                    FROM folios_asignaciones
                    WHERE sucursal_id = ? AND estado = 'ACTIVA'
                    GROUP BY tipo_documento
                ");
                $stAsig->execute([$sid]);
                $asigs = $stAsig->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($asigs)) continue;
                
                $tipos = [];
                $totalFolios = 0;
                foreach ($asigs as $a) {
                    $tipoDoc = $a['tipo_documento'];
                    $tipoDte = $this->mapEnumToTipoDte($tipoDoc);
                    
                    // Consumo medio últimos 30 días
                    $stCons = $this->pdo->prepare("
                        SELECT COUNT(*) FROM folios_consumidos
                        WHERE sucursal_id = ? AND tipo_documento = ?
                          AND fecha_consumo >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    ");
                    $stCons->execute([$sid, $tipoDoc]);
                    $consumidos30d = (int)$stCons->fetchColumn();
                    $prom = round($consumidos30d / 30, 2);
                    
                    $disp = (int)$a['disponibles'];
                    $dias = ($prom > 0) ? round($disp / $prom, 1) : null;
                    
                    $tipos[] = [
                        'tipo_dte' => $tipoDte,
                        'folios_disponibles' => $disp,
                        'cafs_activos' => (int)$a['activos'],
                        'consumo_dia_prom' => $prom,
                        'dias_estimados' => $dias,
                        'urgencia' => $dias === null ? 'sin-dato'
                                        : ($dias < 7 ? 'critico'
                                        : ($dias < 21 ? 'bajo' : 'ok'))
                    ];
                    $totalFolios += $disp;
                }
                
                $porSucursal[] = [
                    'sucursal_id' => $sid,
                    'sucursal_nombre' => $s['nombre'],
                    'tipos' => $tipos,
                    'total_folios' => $totalFolios
                ];
            }
            return $porSucursal;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  HISTORIAL DE CONSUMO — para el panel de auditoría
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Devuelve registros de caf_consumos con filtros y paginación.
     */
    public function historialConsumo(array $filtros = []): array
    {
        if ($this->useLegacyTables) {
            $page    = max(1, (int)($filtros['page'] ?? 1));
            $perPage = 50;
            $offset  = ($page - 1) * $perPage;

            $where  = ['1=1'];
            $params = [];

            if (!empty($filtros['sucursal_id'])) {
                $where[]  = 'cc.sucursal_id = ?';
                $params[] = $filtros['sucursal_id'];
            }
            if (!empty($filtros['tipo_dte'])) {
                $where[]  = 'cc.tipo_dte = ?';
                $params[] = (int)$filtros['tipo_dte'];
            }
            if (!empty($filtros['desde'])) {
                $where[]  = 'DATE(cc.fecha_consumo) >= ?';
                $params[] = $filtros['desde'];
            }
            if (!empty($filtros['hasta'])) {
                $where[]  = 'DATE(cc.fecha_consumo) <= ?';
                $params[] = $filtros['hasta'];
            }

            $whereStr = implode(' AND ', $where);

            // Total para paginación
            $stCount = $this->pdo->prepare(
                "SELECT COUNT(*) FROM caf_consumos cc WHERE $whereStr"
            );
            $stCount->execute($params);
            $total = (int)$stCount->fetchColumn();

            // Filas
            $stRows = $this->pdo->prepare("
                SELECT cc.id, cc.tipo_dte, cc.folio, cc.sucursal_id,
                       cc.maquina_id, cc.fecha_consumo, cc.caf_id,
                       COALESCE(ds.nombre, cc.sucursal_id) AS sucursal_nombre
                  FROM caf_consumos cc
             LEFT JOIN dim_sucursal ds ON ds.id_sucursal = cc.sucursal_id
                 WHERE $whereStr
                 ORDER BY cc.fecha_consumo DESC, cc.id DESC
                 LIMIT $perPage OFFSET $offset
            ");
            $stRows->execute($params);
            $rows = $stRows->fetchAll(PDO::FETCH_ASSOC);

            return [
                'ok'      => true,
                'total'   => $total,
                'page'    => $page,
                'pages'   => (int) ceil($total / $perPage),
                'per_page'=> $perPage,
                'rows'    => $rows,
            ];
        } else {
            // MyPOS SaaS mode
            $page = max(1, (int)($filtros['page'] ?? 1));
            $perPage = 50;
            $offset = ($page - 1) * $perPage;

            $where = ['1=1'];
            $params = [];

            if (!empty($filtros['sucursal_id'])) {
                $where[] = 'fc.sucursal_id = ?';
                $params[] = $filtros['sucursal_id'];
            }
            if (!empty($filtros['tipo_dte'])) {
                $tipoEnum = $this->mapTipoDteToEnum((int)$filtros['tipo_dte']);
                $where[] = 'fc.tipo_documento = ?';
                $params[] = $tipoEnum;
            }
            if (!empty($filtros['desde'])) {
                $where[] = 'DATE(fc.fecha_consumo) >= ?';
                $params[] = $filtros['desde'];
            }
            if (!empty($filtros['hasta'])) {
                $where[] = 'DATE(fc.fecha_consumo) <= ?';
                $params[] = $filtros['hasta'];
            }

            $whereStr = implode(' AND ', $where);

            // Total
            $stCount = $this->pdo->prepare("
                SELECT COUNT(*) FROM folios_consumidos fc WHERE $whereStr
            ");
            $stCount->execute($params);
            $total = (int)$stCount->fetchColumn();

            // Filas
            $stRows = $this->pdo->prepare("
                SELECT fc.id, fc.tipo_documento, fc.folio, fc.sucursal_id,
                       NULL AS maquina_id, fc.fecha_consumo, fc.caf_archivo_id AS caf_id,
                       s.nombre AS sucursal_nombre
                FROM folios_consumidos fc
                LEFT JOIN sucursales s ON s.id = fc.sucursal_id
                WHERE $whereStr
                ORDER BY fc.fecha_consumo DESC, fc.id DESC
                LIMIT $perPage OFFSET $offset
            ");
            $stRows->execute($params);
            $rows = $stRows->fetchAll(PDO::FETCH_ASSOC);

            // Map tipo_documento to tipo_dte
            foreach ($rows as &$r) {
                $r['tipo_dte'] = $this->mapEnumToTipoDte($r['tipo_documento']);
                unset($r['tipo_documento']);
            }

            return [
                'ok' => true,
                'total' => $total,
                'page' => $page,
                'pages' => (int)ceil($total / $perPage),
                'per_page' => $perPage,
                'rows' => $rows
            ];
        }
    }

    /** Recalcula caf_consumo_medio para una sucursal o todas */
    public function recalcularConsumoMedio(?string $sucursalId = null): array
    {
        if ($this->useLegacyTables) {
            $where = $sucursalId ? "WHERE sucursal_id = ?" : "";
            $params = $sucursalId ? [$sucursalId] : [];

            $sql = "
                SELECT sucursal_id, tipo_dte, COUNT(*) as folios_30d
                FROM caf_consumos
                WHERE fecha_consumo >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  " . ($sucursalId ? "AND sucursal_id = ?" : "") . "
                GROUP BY sucursal_id, tipo_dte
            ";
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $upd = $this->pdo->prepare("
                INSERT INTO caf_consumo_medio (sucursal_id, tipo_dte, folios_30d, folios_dia_prom)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    folios_30d = VALUES(folios_30d),
                    folios_dia_prom = VALUES(folios_dia_prom)
            ");
            foreach ($rows as $r) {
                $prom = round($r['folios_30d'] / 30, 2);
                $upd->execute([$r['sucursal_id'], $r['tipo_dte'], $r['folios_30d'], $prom]);
            }
            return ['ok' => true, 'actualizados' => count($rows)];
        } else {
            // MyPOS SaaS mode: dynamic average is computed on-the-fly, so no persistent schema required
            return ['ok' => true, 'actualizados' => 0];
        }
    }
}
