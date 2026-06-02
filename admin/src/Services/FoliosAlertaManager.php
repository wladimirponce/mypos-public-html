<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Exception;

/**
 * Gestión de alertas de stock de folios reportadas por los APKs POS.
 */
class FoliosAlertaManager
{
    private PDO $pdo;
    private bool $useLegacyTable = false;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        try {
            $stmt = $this->pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'folios_alerta' LIMIT 1");
            $this->useLegacyTable = (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            $this->useLegacyTable = false;
        }
    }

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

    public function reportar(array $d): array
    {
        if (!$this->useLegacyTable) {
            return ['ok' => true];
        }

        $sucursal   = trim((string)($d['sucursal_id']   ?? ''));
        $tipo       = (int)($d['tipo_dte']    ?? 39);
        $folios     = (int)($d['folios_locales'] ?? 0);
        $nivel      = in_array($d['nivel'] ?? '', ['ok','bajo','critico'], true)
                        ? $d['nivel'] : 'ok';
        $dias       = isset($d['dias_estimados']) ? (float)$d['dias_estimados'] : null;
        $maquina    = trim((string)($d['maquina_id'] ?? ''));

        if ($sucursal === '') {
            return ['ok' => false, 'error' => 'Falta sucursal_id'];
        }

        $st = $this->pdo->prepare("
            INSERT INTO folios_alerta
              (sucursal_id, maquina_id, tipo_dte, folios_locales, dias_estimados, nivel)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                folios_locales  = VALUES(folios_locales),
                dias_estimados  = VALUES(dias_estimados),
                nivel           = VALUES(nivel),
                actualizado     = NOW()
        ");
        $st->execute([$sucursal, $maquina, $tipo, $folios, $dias, $nivel]);

        return ['ok' => true];
    }

    public function urgenciaGlobal(): array
    {
        if ($this->useLegacyTable) {
            $umbralStale = 6; // horas sin actualización = desconocido

            $alertas = $this->pdo->query("
                SELECT
                    fa.sucursal_id, fa.maquina_id, fa.tipo_dte,
                    fa.folios_locales, fa.dias_estimados, fa.nivel, fa.actualizado,
                    CASE WHEN fa.actualizado < DATE_SUB(NOW(), INTERVAL $umbralStale HOUR)
                         THEN 1 ELSE 0 END AS obsoleto
                FROM folios_alerta fa
                ORDER BY fa.nivel DESC, fa.dias_estimados ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $critico = 0; $bajo = 0; $ok = 0; $stale = 0;
            $minDias = null;

            foreach ($alertas as $a) {
                if ((int)$a['obsoleto'] === 1) { $stale++; continue; }
                switch ($a['nivel']) {
                    case 'critico': $critico++; break;
                    case 'bajo':    $bajo++;    break;
                    default:        $ok++;
                }
                if ($a['dias_estimados'] !== null) {
                    $d = (float)$a['dias_estimados'];
                    if ($minDias === null || $d < $minDias) $minDias = $d;
                }
            }

            $urgenciaGlobal = match(true) {
                $critico > 0 => 'critico',
                $bajo    > 0 => 'bajo',
                default      => 'ok',
            };

            $pedido = $this->calcularPedidoRecomendado($alertas);

            return [
                'ok'                  => true,
                'urgencia_global'     => $urgenciaGlobal,
                'machines_critico'    => $critico,
                'machines_bajo'       => $bajo,
                'machines_ok'         => $ok,
                'machines_stale'      => $stale,
                'min_dias_estimados'  => $minDias,
                'alertas'             => $alertas,
                'pedido_recomendado'  => $pedido,
            ];
        } else {
            // MyPOS SaaS mode: build alerts from folios_asignaciones and folios_consumidos
            $stAsig = $this->pdo->query("
                SELECT fa.id, fa.sucursal_id, fa.caja_id, fa.dispositivo_id, 
                       fa.tipo_documento, fa.folio_desde, fa.folio_hasta, fa.folio_actual,
                       fa.alerta_minimo, fa.created_at AS actualizado,
                       s.nombre AS sucursal_nombre
                FROM folios_asignaciones fa
                LEFT JOIN sucursales s ON s.id = fa.sucursal_id
                WHERE fa.estado = 'ACTIVA'
                ORDER BY fa.id DESC
            ");
            $asigs = $stAsig->fetchAll(PDO::FETCH_ASSOC);

            $alertas = [];
            $critico = 0; $bajo = 0; $ok = 0; $stale = 0;
            $minDias = null;

            foreach ($asigs as $fa) {
                $sucId = $fa['sucursal_id'];
                $sucName = $fa['sucursal_nombre'] ?? ('Sucursal ' . $sucId);
                $tipoDoc = $fa['tipo_documento'];
                $tipoDte = $this->mapEnumToTipoDte($tipoDoc);
                $disponibles = (int)$fa['folio_hasta'] - (int)$fa['folio_actual'] + 1;

                // Consumo medio últimos 30 días
                $stCons = $this->pdo->prepare("
                    SELECT COUNT(*) FROM folios_consumidos
                    WHERE sucursal_id = ? AND tipo_documento = ?
                      AND fecha_consumo >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ");
                $stCons->execute([$sucId, $tipoDoc]);
                $consumidos30d = (int)$stCons->fetchColumn();
                $prom = $consumidos30d / 30.0;

                $dias = ($prom > 0) ? ($disponibles / $prom) : null;
                
                // Determine level
                // critico: <= 20 folios or <= 1 day
                // bajo: <= 100 folios or <= 3 days
                if ($disponibles <= 20 || ($dias !== null && $dias <= 1.0)) {
                    $nivel = 'critico';
                    $critico++;
                } elseif ($disponibles <= 100 || ($dias !== null && $dias <= 3.0)) {
                    $nivel = 'bajo';
                    $bajo++;
                } else {
                    $nivel = 'ok';
                    $ok++;
                }

                if ($dias !== null) {
                    if ($minDias === null || $dias < $minDias) {
                        $minDias = $dias;
                     }
                }

                $alertas[] = [
                    'sucursal_id' => $sucName,
                    'maquina_id' => $fa['dispositivo_id'] ? ('POS-' . $fa['dispositivo_id']) : ($fa['caja_id'] ? ('Caja-' . $fa['caja_id']) : ''),
                    'tipo_dte' => $tipoDte,
                    'folios_locales' => $disponibles,
                    'dias_estimados' => $dias,
                    'nivel' => $nivel,
                    'actualizado' => $fa['actualizado'],
                    'obsoleto' => 0
                ];
            }

            $urgenciaGlobal = match(true) {
                $critico > 0 => 'critico',
                $bajo    > 0 => 'bajo',
                default      => 'ok',
            };

            $pedido = $this->calcularPedidoRecomendado($alertas);

            return [
                'ok'                  => true,
                'urgencia_global'     => $urgenciaGlobal,
                'machines_critico'    => $critico,
                'machines_bajo'       => $bajo,
                'machines_ok'         => $ok,
                'machines_stale'      => $stale,
                'min_dias_estimados'  => $minDias,
                'alertas'             => $alertas,
                'pedido_recomendado'  => $pedido,
            ];
        }
    }

    public function listarAlertas(?int $tipoDte = null): array
    {
        if ($this->useLegacyTable) {
            $where  = $tipoDte ? "WHERE tipo_dte = ?" : "";
            $params = $tipoDte ? [$tipoDte] : [];
            $st = $this->pdo->prepare("
                SELECT * FROM folios_alerta $where
                ORDER BY
                    FIELD(nivel,'critico','bajo','ok'),
                    dias_estimados ASC
            ");
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $data = $this->urgenciaGlobal();
            $alertas = $data['alertas'] ?? [];
            if ($tipoDte !== null) {
                $alertas = array_filter($alertas, fn($a) => (int)$a['tipo_dte'] === $tipoDte);
            }
            // Sort by level and dias_estimados
            usort($alertas, function($a, $b) {
                $levelOrder = ['critico' => 0, 'bajo' => 1, 'ok' => 2];
                $la = $levelOrder[$a['nivel']] ?? 3;
                $lb = $levelOrder[$b['nivel']] ?? 3;
                if ($la !== $lb) return $la <=> $lb;
                return ($a['dias_estimados'] ?? 999) <=> ($b['dias_estimados'] ?? 999);
            });
            return $alertas;
        }
    }

    private function calcularPedidoRecomendado(array $alertas): array
    {
        $porTipo = [];
        foreach ($alertas as $a) {
            if ((int)($a['obsoleto'] ?? 0)) continue;
            $t = (int)$a['tipo_dte'];
            if (!isset($porTipo[$t])) {
                $porTipo[$t] = ['critico' => 0, 'bajo' => 0, 'total_folios' => 0, 'total_maquinas' => 0];
            }
            $porTipo[$t]['total_maquinas']++;
            $porTipo[$t]['total_folios'] += (int)$a['folios_locales'];
            if ($a['nivel'] === 'critico') $porTipo[$t]['critico']++;
            elseif ($a['nivel'] === 'bajo') $porTipo[$t]['bajo']++;
        }

        $recomendaciones = [];
        foreach ($porTipo as $tipo => $datos) {
            $maquinasUrgentes = $datos['critico'] + $datos['bajo'];
            if ($maquinasUrgentes === 0) continue;
            $sugerido = (int)(ceil($maquinasUrgentes * 200 / 100) * 100);
            $recomendaciones[] = [
                'tipo_dte'         => $tipo,
                'maquinas_urgentes'=> $maquinasUrgentes,
                'maquinas_total'   => $datos['total_maquinas'],
                'folios_actuales'  => $datos['total_folios'],
                'sugerido_pedir'   => $sugerido,
            ];
        }
        return $recomendaciones;
    }
}
