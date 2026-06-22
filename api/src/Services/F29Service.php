<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Repositories\F29Repository;
use PDO;

final class F29Service
{
    private F29Repository $repository;

    public function __construct()
    {
        $this->repository = new F29Repository(Database::connection());
    }

    /**
     * Calcula el F29 desde los libros sin persistir.
     * Si ya existe un borrador guardado para el período, retorna esos datos
     * combinados con el cálculo fresco para mostrar diferencias.
     */
    public function calcular(int $userId, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $periodo = $this->requirePeriodo($params);
        $this->tenant($userId, $empresaId);

        [$from, $to] = $this->periodoToRange($periodo);

        // Calcular desde libros
        $ventas = $this->repository->resumenVentas($empresaId, $from, $to);
        $compras = $this->repository->resumenCompras($empresaId, $from, $to);

        // Remanente: del período guardado si existe, si no del período anterior
        $saved = $this->repository->find($empresaId, $periodo);
        $remanenteCfAnterior = $saved
            ? (int) $saved['remanente_cf_anterior']
            : $this->repository->remanentePeriodoAnterior($empresaId, $periodo);

        $tasaPpm = $saved ? (float) $saved['tasa_ppm'] : 0.00;
        $notas = $saved['notas'] ?? null;
        $estado = $saved['estado'] ?? 'BORRADOR';

        return $this->buildResponse($ventas, $compras, $remanenteCfAnterior, $tasaPpm, $notas, $estado, $periodo, $empresaId);
    }

    /**
     * Guarda o actualiza el borrador del F29.
     */
    public function guardar(int $userId, array $body): array
    {
        $empresaId = $this->requireEmpresaId($body);
        $periodo = $this->requirePeriodo($body);
        $this->tenant($userId, $empresaId);

        $saved = $this->repository->find($empresaId, $periodo);
        if ($saved && $saved['estado'] === 'DECLARADO') {
            throw new HttpException('El período ya fue marcado como declarado y no puede modificarse', 409);
        }

        [$from, $to] = $this->periodoToRange($periodo);

        $ventas = $this->repository->resumenVentas($empresaId, $from, $to);
        $compras = $this->repository->resumenCompras($empresaId, $from, $to);

        $remanente = (int) ($body['remanente_cf_anterior'] ?? 0);
        $tasaPpm = max(0.0, min(100.0, (float) ($body['tasa_ppm'] ?? 0.00)));
        $notas = isset($body['notas']) ? trim((string) $body['notas']) : null;

        $calc = $this->buildCodes($ventas, $compras, $remanente, $tasaPpm);

        $this->repository->upsert($empresaId, $periodo, array_merge($calc, [
            'estado'                => 'BORRADOR',
            'tasa_ppm'              => round($tasaPpm, 2),
            'remanente_cf_anterior' => $remanente,
            'notas'                 => $notas ?: null,
        ]));

        return $this->buildResponse($ventas, $compras, $remanente, $tasaPpm, $notas, 'BORRADOR', $periodo, $empresaId);
    }

    /**
     * Marca el período como DECLARADO (acción irreversible).
     */
    public function declarar(int $userId, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $periodo = $this->requirePeriodo($params);
        $this->tenant($userId, $empresaId);

        $saved = $this->repository->find($empresaId, $periodo);
        if ($saved === null) {
            throw new HttpException('Debe guardar el borrador antes de marcarlo como declarado', 409);
        }
        if ($saved['estado'] === 'DECLARADO') {
            throw new HttpException('El período ya está marcado como declarado', 409);
        }

        $this->repository->marcarDeclarado($empresaId, $periodo);

        return ['periodo' => $periodo, 'estado' => 'DECLARADO'];
    }

    /**
     * Lista histórico de períodos F29 guardados.
     */
    public function historial(int $userId, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $this->tenant($userId, $empresaId);

        $rows = $this->repository->historial($empresaId, 24);

        return array_map(static fn (array $row): array => [
            'periodo'               => $row['periodo'],
            'estado'                => $row['estado'],
            'debito'                => (int) $row['cod_519'],
            'credito'               => (int) $row['cod_527'],
            'iva_pagar'             => (int) $row['cod_547'],
            'remanente'             => (int) $row['cod_544'],
            'ppm'                   => (int) $row['cod_63'],
            'tasa_ppm'              => (float) $row['tasa_ppm'],
            'remanente_cf_anterior' => (int) $row['remanente_cf_anterior'],
            'updated_at'            => $row['updated_at'],
            'declarado_at'          => $row['declarado_at'],
        ], $rows);
    }

    // ─────────────── Privados ───────────────

    private function buildCodes(array $v, array $c, int $remanente, float $tasaPpm): array
    {
        // DÉBITO
        $netoAfectas = (int) ($v['neto_afectas'] ?? 0);
        $netoNc      = (int) ($v['neto_nc'] ?? 0);
        $ivaAfectas  = (int) ($v['iva_afectas'] ?? 0);
        $ivaNc       = (int) ($v['iva_nc'] ?? 0);
        $nDocsAfecto = (int) ($v['n_docs_afectos'] ?? 0);
        $nNc         = (int) ($v['n_nc'] ?? 0);

        $cod166 = $netoAfectas - $netoNc;                  // Neto afecto neto
        $cod142 = (int) ($v['total_exento'] ?? 0) + (int) ($v['neto_exento_docs'] ?? 0);
        $cod503 = max(0, $nDocsAfecto - $nNc);
        $cod519 = $ivaAfectas - $ivaNc;                    // Total débito fiscal

        // CRÉDITO
        $cod180 = (int) ($c['neto'] ?? 0);
        $cod520 = (int) ($c['n_docs'] ?? 0);
        $ivaCredito = (int) ($c['iva_credito'] ?? 0);
        $cod527 = $ivaCredito + $remanente;                // CF + remanente anterior

        // LIQUIDACIÓN
        $diferencia = $cod519 - $cod527;
        $cod547 = max(0, $diferencia);   // IVA a pagar
        $cod544 = $diferencia < 0 ? abs($diferencia) : 0; // Remanente para siguiente período

        // PPM
        $cod63 = $tasaPpm > 0 ? (int) round(($cod166 + $cod142) * $tasaPpm / 100) : 0;

        return [
            'cod_503' => $cod503,
            'cod_166' => $cod166,
            'cod_142' => $cod142,
            'cod_519' => $cod519,
            'cod_520' => $cod520,
            'cod_180' => $cod180,
            'cod_527' => $cod527,
            'cod_547' => $cod547,
            'cod_544' => $cod544,
            'cod_63'  => $cod63,
        ];
    }

    private function buildResponse(
        array $ventas, array $compras,
        int $remanente, float $tasaPpm,
        ?string $notas, string $estado,
        string $periodo, int $empresaId
    ): array {
        $codes = $this->buildCodes($ventas, $compras, $remanente, $tasaPpm);

        return [
            'periodo'    => $periodo,
            'empresa_id' => $empresaId,
            'estado'     => $estado,
            'tasa_ppm'   => round($tasaPpm, 2),
            'notas'      => $notas,

            // Sección B — Débito Fiscal
            'cod_503'  => $codes['cod_503'],
            'cod_166'  => $codes['cod_166'],
            'cod_142'  => $codes['cod_142'],
            'cod_519'  => $codes['cod_519'],

            // Sección C — Crédito Fiscal
            'cod_520'                => $codes['cod_520'],
            'cod_180'                => $codes['cod_180'],
            'remanente_cf_anterior'  => $remanente,
            'cod_527'                => $codes['cod_527'],

            // Sección D — Liquidación
            'cod_547' => $codes['cod_547'],
            'cod_544' => $codes['cod_544'],

            // Sección E — PPM
            'cod_63'  => $codes['cod_63'],

            // Totales auxiliares para UI
            'total_a_pagar' => $codes['cod_547'] + $codes['cod_63'],
        ];
    }

    private function periodoToRange(string $periodo): array
    {
        $from = $periodo . '-01';
        $dt = new \DateTimeImmutable($from);
        $to = $dt->format('Y-m-t');  // último día del mes

        return [$from, $to];
    }

    private function requireEmpresaId(array $params): int
    {
        $id = (int) ($params['empresa_id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        return $id;
    }

    private function requirePeriodo(array $params): string
    {
        $periodo = trim((string) ($params['periodo'] ?? ''));
        if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $periodo)) {
            throw new HttpException('periodo debe tener formato YYYY-MM (ej: 2026-06)', 422);
        }

        return $periodo;
    }

    private function tenant(int $userId, int $empresaId): void
    {
        (new TenantMiddleware())->handle($userId, $empresaId);
    }
}
