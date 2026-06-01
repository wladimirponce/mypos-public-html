<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

/**
 * Repositorio de consultas específicas para la vertical farmacia.
 *
 * Toda la información farmacéutica se almacena en el sistema de atributos
 * personalizados (producto_atributos_definicion / producto_atributos_valores).
 * Este repositorio centraliza las queries que requieren filtrar o buscar
 * por dichos atributos con semántica farmacéutica.
 *
 * No se crean tablas adicionales (Decisión técnica Sprint 13).
 */
final class FarmaciaRepository
{
    /** Códigos de atributo estándar del seed de farmacia */
    public const CODIGOS = [
        'principio_activo',
        'laboratorio',
        'concentracion',
        'forma_farmaceutica',
        'via_administracion',
        'requiere_receta',
        'condicion_venta',
        'temperatura_almac',
        'registro_isp',
    ];

    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * Busca productos por principio activo (búsqueda parcial, case-insensitive).
     *
     * Retorna productos de la empresa que tengan el atributo `principio_activo`
     * con un valor que contenga el texto dado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscarPorPrincipioActivo(int $empresaId, string $termino, int $limite = 50): array
    {
        $limiteSafe = max(1, min(200, $limite));
        $like       = '%' . $termino . '%';

        $statement = $this->connection->prepare(
            "SELECT p.id                  AS producto_id,
                    p.sku,
                    p.nombre              AS producto_nombre,
                    p.precio_venta,
                    p.costo_actual,
                    p.activo,
                    v.valor_texto         AS principio_activo
             FROM productos p
             INNER JOIN producto_atributos_valores v
                     ON v.producto_id = p.id
                    AND v.empresa_id  = p.empresa_id
             INNER JOIN producto_atributos_definicion d
                     ON d.id         = v.atributo_id
                    AND d.empresa_id = p.empresa_id
                    AND d.codigo     = 'principio_activo'
                    AND d.activo     = 1
             WHERE p.empresa_id = :empresa_id
               AND p.activo     = 1
               AND v.valor_texto LIKE :like
             ORDER BY v.valor_texto ASC, p.nombre ASC
             LIMIT {$limiteSafe}"
        );
        $statement->execute(['empresa_id' => $empresaId, 'like' => $like]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna la ficha farmacéutica completa de un producto:
     * todos los atributos con código en self::CODIGOS con sus valores.
     *
     * @return array<string, mixed>  Mapa codigo => valor (ya tipado)
     */
    public function fichaFarmaceutica(int $empresaId, int $productoId): array
    {
        $codigos       = self::CODIGOS;
        $placeholders  = implode(',', array_fill(0, count($codigos), '?'));

        $statement = $this->connection->prepare(
            "SELECT d.codigo,
                    d.nombre,
                    d.tipo_dato,
                    v.valor_texto,
                    v.valor_numero,
                    v.valor_decimal,
                    v.valor_fecha,
                    v.valor_booleano,
                    v.valor_json
             FROM producto_atributos_definicion d
             LEFT JOIN producto_atributos_valores v
                    ON v.atributo_id = d.id
                   AND v.producto_id = ?
                   AND v.empresa_id  = d.empresa_id
             WHERE d.empresa_id = ?
               AND d.activo     = 1
               AND d.codigo     IN ({$placeholders})
             ORDER BY d.orden"
        );
        $statement->execute([$productoId, $empresaId, ...$codigos]);

        $ficha = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ficha[$row['codigo']] = [
                'nombre'    => $row['nombre'],
                'tipo_dato' => $row['tipo_dato'],
                'valor'     => $this->resolverValor($row),
            ];
        }

        return $ficha;
    }

    /**
     * Retorna listado de productos que requieren receta (requiere_receta = 1).
     *
     * @return array<int, array<string, mixed>>
     */
    public function productosConReceta(int $empresaId, int $limite = 200): array
    {
        $limiteSafe = max(1, min(500, $limite));

        $statement = $this->connection->prepare(
            "SELECT p.id AS producto_id, p.sku, p.nombre AS producto_nombre,
                    p.precio_venta, p.activo
             FROM productos p
             INNER JOIN producto_atributos_valores v
                     ON v.producto_id = p.id
                    AND v.empresa_id  = p.empresa_id
             INNER JOIN producto_atributos_definicion d
                     ON d.id         = v.atributo_id
                    AND d.empresa_id = p.empresa_id
                    AND d.codigo     = 'requiere_receta'
                    AND d.activo     = 1
             WHERE p.empresa_id    = :empresa_id
               AND p.activo        = 1
               AND v.valor_booleano = 1
             ORDER BY p.nombre ASC
             LIMIT {$limiteSafe}"
        );
        $statement->execute(['empresa_id' => $empresaId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna el resumen de atributos farmacéuticos disponibles en la empresa.
     * Útil para construir filtros en el frontend.
     */
    public function atributosFarmacia(int $empresaId): array
    {
        $codigos      = self::CODIGOS;
        $placeholders = implode(',', array_fill(0, count($codigos), '?'));

        $statement = $this->connection->prepare(
            "SELECT d.id, d.codigo, d.nombre, d.tipo_dato, d.filtrable,
                    d.visible_en_listado, d.visible_en_pos, d.orden
             FROM producto_atributos_definicion d
             WHERE d.empresa_id = ?
               AND d.activo     = 1
               AND d.codigo     IN ({$placeholders})
             ORDER BY d.orden"
        );
        $statement->execute([$empresaId, ...$codigos]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolverValor(array $row): mixed
    {
        return match ($row['tipo_dato']) {
            'NUMERO'      => $row['valor_numero']   !== null ? (int)   $row['valor_numero']   : null,
            'DECIMAL'     => $row['valor_decimal']  !== null ? (float) $row['valor_decimal']  : null,
            'FECHA'       => $row['valor_fecha'],
            'BOOLEANO'    => $row['valor_booleano'] !== null ? (bool)  $row['valor_booleano'] : null,
            'MULTIOPCION' => $row['valor_json']     !== null ? json_decode((string) $row['valor_json'], true) : null,
            default       => $row['valor_texto'],
        };
    }
}
