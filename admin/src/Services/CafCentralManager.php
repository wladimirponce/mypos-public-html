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

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
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
            $tiposEnPool = $this->pdo->query("
                SELECT DISTINCT tipo_dte FROM cafs
                WHERE sucursal_id IS NULL AND agotado = 0
            ")->fetchAll(PDO::FETCH_COLUMN);
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
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  DESCARGAR — XML completo de un CAF (con clave privada)
    // ─────────────────────────────────────────────────────────────────────────

    public function descargarCaf(int $cafId, string $sucursalId): array
    {
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
     *
     * Estrategia:
     *   - Total folios disponibles = (folio_hasta - folio_actual + 1)
     *   - Para cada sucursal con consumo medio > 0:
     *       cuota = round(total * (consumo_sucursal / sum(consumos)))
     *   - Si alguna sucursal nunca consumió, recibe min(50, total/N)
     *   - Inserta filas hijas con xml_content compartido, marca el original como agotado
     *
     * @param int $cafId         CAF padre (debe tener sucursal_id NULL)
     * @param array $sucursales  lista de IDs de sucursal a considerar
     * @return array{ok:bool, distribucion?:array, error?:string}
     */
    public function distribuirCaf(int $cafId, array $sucursales): array
    {
        try {
            $this->pdo->beginTransaction();

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
                // sumar/restar el delta a la sucursal con más consumo
                $maxKey = $sucursales[0];
                $maxV = -1;
                foreach ($sucursales as $s) {
                    $v = $consumos[$s] ?? 0;
                    if ($v > $maxV) { $maxV = $v; $maxKey = $s; }
                }
                $cuotas[$maxKey] += ($total - $asignado);
            }

            // Garantizar al menos 1 folio por sucursal que pidió (si hay total ≥ N sucursales)
            if ($total >= count($sucursales)) {
                foreach ($cuotas as $s => &$q) if ($q < 1) $q = 1;
                unset($q);
                $asignado = array_sum($cuotas);
                // si nos pasamos, recortamos al máximo
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

            // Marcar el padre como agotado (sirvió de pool)
            $this->pdo->prepare("UPDATE cafs SET agotado=1, observaciones=? WHERE id=?")
                ->execute(["Distribuido en " . count($distribucion) . " sub-CAFs", $cafId]);

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
        // (envolvemos en subquery para poder usar los alias en WHERE sin chocar
        //  con la restricción de MariaDB de referenciar agregados desde HAVING)
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
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CALCULADORA: cuántos folios pedir al SII
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Calcula la cantidad óptima de folios a pedir al SII para cada tipo de DTE.
     *
     * Algoritmo:
     *   1. Consumo histórico = COUNT(caf_consumos) últimos 90 días → divido / 90 = consumo/día
     *   2. Necesidad para próximos N días (default 60) = consumo/día * N
     *   3. Disponibles ahora = SUM(folio_hasta - folio_actual + 1) por tipo (no agotados)
     *   4. Faltante = max(0, necesidad - disponibles)
     *   5. Sugerido = faltante * factorSeguridad (default 1.3)
     *
     * @param int $diasProyeccion  Cuántos días debe cubrir el pedido
     * @param float $factorSeguridad  Multiplicador para colchón (1.3 = +30%)
     */
    public function calcularPedidoOptimo(int $diasProyeccion = 60, float $factorSeguridad = 1.3): array
    {
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
            // Redondear a múltiplos de 100 hacia arriba (los CAFs SII suelen pedirse así)
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
     * auto-asignarle una cuota del pool central (sucursal_id NULL).
     *
     * Cuota:
     *   - Si la sucursal tiene consumo medio registrado: 30 días de consumo
     *   - Si es sucursal nueva sin histórico: cuota_default (100 por defecto)
     *
     * Llamado internamente por cafsDisponibles cuando no hay asignados.
     */
    public function autoAsignarDesdePool(string $sucursalId, int $tipoDte, int $cuotaDefault = 100): array
    {
        try {
            $this->pdo->beginTransaction();

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
     * A diferencia de autoAsignar (basado en consumo medio), aquí el admin
     * indica exactamente cuántos folios quiere mover.
     *
     * Si la cantidad supera los disponibles en el primer CAF del pool, toma
     * del siguiente hasta cubrir el pedido o agotar el pool.
     *
     * @param string $sucursalId  sucursal destino
     * @param int    $tipoDte     tipo de documento
     * @param int    $cantidad    folios a asignar
     * @return array{ok:bool, asignados:int, rangos:array, error?:string}
     */
    public function asignacionManual(string $sucursalId, int $tipoDte, int $cantidad): array
    {
        if ($cantidad <= 0) {
            return ['ok' => false, 'error' => 'La cantidad debe ser mayor a 0'];
        }

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
                    // Sin más pool
                    if (empty($rangos)) {
                        $this->pdo->rollBack();
                        return ['ok' => false, 'error' => "Sin folios en pool central para tipo $tipoDte"];
                    }
                    // Asignamos lo que había, informamos cuánto faltó
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
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  STOCK POR SUCURSAL — para el dashboard visual
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Devuelve el stock actual agrupado por sucursal, con días estimados
     * basados en consumo medio diario (caf_consumo_medio).
     *
     * @return array  [ [ sucursal_id, sucursal_nombre, tipos => [...] ], ... ]
     */
    public function stockPorSucursal(): array
    {
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
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  HISTORIAL DE CONSUMO — para el panel de auditoría
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Devuelve registros de caf_consumos con filtros y paginación.
     *
     * @param array $filtros  ['sucursal_id'=>'', 'tipo_dte'=>0, 'desde'=>'', 'hasta'=>'', 'page'=>1]
     */
    public function historialConsumo(array $filtros = []): array
    {
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
    }

    /** Recalcula caf_consumo_medio para una sucursal o todas */
    public function recalcularConsumoMedio(?string $sucursalId = null): array
    {
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
    }
}
