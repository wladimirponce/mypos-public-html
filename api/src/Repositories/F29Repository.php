<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class F29Repository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * Agrega ventas del período por tipo de documento.
     * Clasifica: AFECTAS (genera débito), NOTA_CREDITO (reduce débito), EXENTAS.
     */
    public function resumenVentas(int $empresaId, string $from, string $to): array
    {
        $statement = $this->connection->prepare(
            "SELECT
                -- Documentos que generan débito (BOLETA, FACTURA, NOTA_DEBITO y similares)
                SUM(CASE
                    WHEN d.tipo_documento NOT IN ('NOTA_CREDITO','NOTA_CREDITO_NO_AFECTA','GUIA_DESPACHO',
                                                   'BOLETA_NO_AFECTA','FACTURA_NO_AFECTA')
                    THEN COALESCE(d.neto, 0) ELSE 0 END) AS neto_afectas,

                SUM(CASE
                    WHEN d.tipo_documento NOT IN ('NOTA_CREDITO','NOTA_CREDITO_NO_AFECTA','GUIA_DESPACHO',
                                                   'BOLETA_NO_AFECTA','FACTURA_NO_AFECTA')
                    THEN COALESCE(d.impuestos, 0) ELSE 0 END) AS iva_afectas,

                COUNT(CASE
                    WHEN d.tipo_documento NOT IN ('NOTA_CREDITO','NOTA_CREDITO_NO_AFECTA','GUIA_DESPACHO',
                                                   'BOLETA_NO_AFECTA','FACTURA_NO_AFECTA')
                    THEN 1 END) AS n_docs_afectos,

                -- Notas de crédito emitidas (reducen débito)
                SUM(CASE
                    WHEN d.tipo_documento IN ('NOTA_CREDITO')
                    THEN COALESCE(d.neto, 0) ELSE 0 END) AS neto_nc,

                SUM(CASE
                    WHEN d.tipo_documento IN ('NOTA_CREDITO')
                    THEN COALESCE(d.impuestos, 0) ELSE 0 END) AS iva_nc,

                COUNT(CASE WHEN d.tipo_documento IN ('NOTA_CREDITO') THEN 1 END) AS n_nc,

                -- Exentos (columna exento de todos los docs + neto de boletas/facturas exentas)
                SUM(COALESCE(d.exento, 0)) AS total_exento,
                SUM(CASE
                    WHEN d.tipo_documento IN ('BOLETA_NO_AFECTA','FACTURA_NO_AFECTA')
                    THEN COALESCE(d.neto, 0) ELSE 0 END) AS neto_exento_docs

             FROM documentos_emitidos d
             WHERE d.empresa_id = :empresa_id
               AND DATE(d.fecha_emision) >= :fecha_desde
               AND DATE(d.fecha_emision) <= :fecha_hasta
               AND d.estado NOT IN ('ANULADO')"
        );
        $statement->execute(['empresa_id' => $empresaId, 'fecha_desde' => $from, 'fecha_hasta' => $to]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * Agrega compras del período (solo CONFIRMADAS, no anuladas ni reversadas).
     */
    public function resumenCompras(int $empresaId, string $from, string $to): array
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(c.id) AS n_docs,
                    COALESCE(SUM(c.subtotal), 0)        AS neto,
                    COALESCE(SUM(c.impuesto_total), 0)  AS iva_credito,
                    COALESCE(SUM(c.total), 0)            AS total
             FROM compras c
             WHERE c.empresa_id = :empresa_id
               AND c.fecha_documento >= :fecha_desde
               AND c.fecha_documento <= :fecha_hasta
               AND c.estado = 'CONFIRMADA'"
        );
        $statement->execute(['empresa_id' => $empresaId, 'fecha_desde' => $from, 'fecha_hasta' => $to]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * Devuelve el remanente (cod_544) del período anterior al dado.
     */
    public function remanentePeriodoAnterior(int $empresaId, string $periodo): int
    {
        [$year, $month] = explode('-', $periodo);
        $prevDate = new \DateTimeImmutable("{$year}-{$month}-01");
        $prevDate = $prevDate->modify('-1 month');
        $prevPeriodo = $prevDate->format('Y-m');

        $statement = $this->connection->prepare(
            "SELECT cod_544 FROM f29_periodos
             WHERE empresa_id = :empresa_id AND periodo = :periodo
             LIMIT 1"
        );
        $statement->execute(['empresa_id' => $empresaId, 'periodo' => $prevPeriodo]);
        $val = $statement->fetchColumn();

        return $val !== false ? (int) $val : 0;
    }

    public function find(int $empresaId, string $periodo): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM f29_periodos WHERE empresa_id = :empresa_id AND periodo = :periodo LIMIT 1"
        );
        $statement->execute(['empresa_id' => $empresaId, 'periodo' => $periodo]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function upsert(int $empresaId, string $periodo, array $data): int
    {
        $existing = $this->find($empresaId, $periodo);

        if ($existing !== null) {
            $this->connection->prepare(
                "UPDATE f29_periodos SET
                    estado = :estado, tasa_ppm = :tasa_ppm,
                    remanente_cf_anterior = :remanente_cf_anterior,
                    cod_503 = :cod_503, cod_166 = :cod_166, cod_142 = :cod_142, cod_519 = :cod_519,
                    cod_520 = :cod_520, cod_180 = :cod_180, cod_527 = :cod_527,
                    cod_547 = :cod_547, cod_544 = :cod_544, cod_63 = :cod_63,
                    notas = :notas
                 WHERE empresa_id = :empresa_id AND periodo = :periodo"
            )->execute(array_merge($data, ['empresa_id' => $empresaId, 'periodo' => $periodo]));

            return (int) $existing['id'];
        }

        $this->connection->prepare(
            "INSERT INTO f29_periodos
                (empresa_id, periodo, estado, tasa_ppm, remanente_cf_anterior,
                 cod_503, cod_166, cod_142, cod_519,
                 cod_520, cod_180, cod_527,
                 cod_547, cod_544, cod_63, notas)
             VALUES
                (:empresa_id, :periodo, :estado, :tasa_ppm, :remanente_cf_anterior,
                 :cod_503, :cod_166, :cod_142, :cod_519,
                 :cod_520, :cod_180, :cod_527,
                 :cod_547, :cod_544, :cod_63, :notas)"
        )->execute(array_merge($data, ['empresa_id' => $empresaId, 'periodo' => $periodo]));

        return (int) $this->connection->lastInsertId();
    }

    public function marcarDeclarado(int $empresaId, string $periodo): bool
    {
        $statement = $this->connection->prepare(
            "UPDATE f29_periodos
             SET estado = 'DECLARADO', declarado_at = CURRENT_TIMESTAMP
             WHERE empresa_id = :empresa_id AND periodo = :periodo AND estado = 'BORRADOR'"
        );
        $statement->execute(['empresa_id' => $empresaId, 'periodo' => $periodo]);

        return $statement->rowCount() > 0;
    }

    public function historial(int $empresaId, int $limit = 24): array
    {
        $statement = $this->connection->prepare(
            "SELECT periodo, estado, cod_519, cod_527, cod_547, cod_544, cod_63,
                    remanente_cf_anterior, tasa_ppm, updated_at, declarado_at
             FROM f29_periodos
             WHERE empresa_id = :empresa_id
             ORDER BY periodo DESC
             LIMIT {$limit}"
        );
        $statement->execute(['empresa_id' => $empresaId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
