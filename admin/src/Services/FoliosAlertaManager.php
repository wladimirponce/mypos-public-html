<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Gestión de alertas de stock de folios reportadas por los APKs POS.
 *
 * Cada APK llama a api.php?action=folios_alerta cuando detecta:
 *   - Nivel BAJO     (≤100 folios o ≤3 días estimados)
 *   - Nivel CRÍTICO  (≤20 folios o ≤1 día estimado)
 * y también cuando el stock vuelve a ser OK (para limpiar la alerta).
 *
 * El servidor consolida estas señales y calcula una urgencia GLOBAL
 * (agregada de 1..N sucursales) para mostrar al administrador.
 */
class FoliosAlertaManager
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  REPORTAR — upsert desde un APK
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Inserta o actualiza el reporte de stock de una máquina.
     *
     * @param array $d  Campos esperados:
     *   sucursal_id (string), tipo_dte (int), folios_locales (int),
     *   nivel ('ok'|'bajo'|'critico'), dias_estimados? (float),
     *   maquina_id? (string)
     */
    public function reportar(array $d): array
    {
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

    // ─────────────────────────────────────────────────────────────────────────
    //  URGENCIA GLOBAL — consolida todas las sucursales / máquinas
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Calcula el nivel de urgencia del sistema completo.
     *
     * Reglas:
     *   - Si HAY algún reporte CRÍTICO   → urgencia = 'critico'
     *   - Else si HAY algún reporte BAJO → urgencia = 'bajo'
     *   - Else                           → urgencia = 'ok'
     *
     * También considera reportes "obsoletos" (sin actualización >6h) como
     * desconocidos: no los cuenta como OK pero tampoco como urgentes.
     *
     * @return array{
     *   ok: bool,
     *   urgencia_global: string,
     *   machines_critico: int,
     *   machines_bajo: int,
     *   machines_ok: int,
     *   machines_stale: int,
     *   min_dias_estimados: float|null,
     *   alertas: array,
     *   pedido_recomendado: array
     * }
     */
    public function urgenciaGlobal(): array
    {
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

        // Recomendación de pedido al SII (por tipo, suma de lo que reportaron)
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

    // ─────────────────────────────────────────────────────────────────────────
    //  LISTAR — tabla detallada para el dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function listarAlertas(?int $tipoDte = null): array
    {
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
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PRIVADO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Agrega por tipo_dte los folios que reportaron bajo/crítico
     * y suma cuánto habría que pedir al SII para cubrir 60 días de consumo.
     * Complementa (no reemplaza) la estimación de CafCentralManager::calcularPedidoOptimo().
     */
    private function calcularPedidoRecomendado(array $alertas): array
    {
        // Agrupar por tipo: contar máquinas en crítico y suma de folios actuales
        $porTipo = [];
        foreach ($alertas as $a) {
            if ((int)$a['obsoleto']) continue;
            $t = (int)$a['tipo_dte'];
            if (!isset($porTipo[$t])) {
                $porTipo[$t] = ['critico' => 0, 'bajo' => 0, 'total_folios' => 0, 'total_maquinas' => 0];
            }
            $porTipo[$t]['total_maquinas']++;
            $porTipo[$t]['total_folios'] += (int)$a['folios_locales'];
            if ($a['nivel'] === 'critico') $porTipo[$t]['critico']++;
            elseif ($a['nivel'] === 'bajo') $porTipo[$t]['bajo']++;
        }

        // Estimación simple: 200 folios por máquina en alerta, redondeado a múltiplos de 100
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
