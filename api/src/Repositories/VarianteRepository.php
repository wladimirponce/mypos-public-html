<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class VarianteRepository
{
    public function __construct(private readonly PDO $db) {}

    // ── Consultas ─────────────────────────────────────────────────────────────

    /**
     * Retorna los ejes de variante (atributos con es_eje_variante=1) de una empresa
     * junto con sus opciones. Usado por el POS para construir el selector.
     */
    public function ejesConOpciones(int $empresaId, int $productoPadreId): array
    {
        // Eje puede ser global (empresa) o específico por si el padre tiene override;
        // por ahora devolvemos todos los ejes activos de la empresa.
        $stmt = $this->db->prepare('
            SELECT d.id AS atributo_id, d.codigo, d.nombre, d.tipo_dato, d.orden,
                   o.id AS opcion_id, o.valor, o.etiqueta, o.orden AS opcion_orden
            FROM producto_atributos_definicion d
            LEFT JOIN producto_atributos_opciones o
                   ON o.atributo_id = d.id AND o.empresa_id = d.empresa_id AND o.activo = 1
            WHERE d.empresa_id = :empresa_id
              AND d.es_eje_variante = 1
              AND d.activo = 1
            ORDER BY d.orden, o.orden
        ');
        $stmt->execute(['empresa_id' => $empresaId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Agrupar opciones por eje
        $ejes = [];
        foreach ($rows as $r) {
            $aid = (int) $r['atributo_id'];
            if (!isset($ejes[$aid])) {
                $ejes[$aid] = [
                    'atributo_id' => $aid,
                    'codigo'      => $r['codigo'],
                    'nombre'      => $r['nombre'],
                    'tipo_dato'   => $r['tipo_dato'],
                    'orden'       => (int) $r['orden'],
                    'opciones'    => [],
                ];
            }
            if ($r['opcion_id'] !== null) {
                $ejes[$aid]['opciones'][] = [
                    'opcion_id' => (int) $r['opcion_id'],
                    'valor'     => $r['valor'],
                    'etiqueta'  => $r['etiqueta'],
                    'orden'     => (int) $r['opcion_orden'],
                ];
            }
        }

        return array_values($ejes);
    }

    /**
     * Lista todas las variantes de un producto padre con su stock agregado.
     */
    public function listarPorPadre(int $empresaId, int $productoPadreId): array
    {
        $stmt = $this->db->prepare('
            SELECT pv.id AS variante_id, pv.producto_id, pv.codigo_variante, pv.activo,
                   p.nombre, p.precio_venta, p.codigo,
                   COALESCE(SUM(su.cantidad), 0) AS stock_total
            FROM producto_variantes pv
            JOIN productos p ON p.id = pv.producto_id
            LEFT JOIN stock_ubicacion su ON su.producto_id = pv.producto_id
                AND su.empresa_id = pv.empresa_id AND su.permite_venta = 1
            WHERE pv.empresa_id = :empresa_id
              AND pv.producto_padre_id = :padre_id
            GROUP BY pv.id, pv.producto_id, pv.codigo_variante, pv.activo,
                     p.nombre, p.precio_venta, p.codigo
            ORDER BY pv.codigo_variante
        ');
        $stmt->execute(['empresa_id' => $empresaId, 'padre_id' => $productoPadreId]);
        $variantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cargar atributos de cada variante
        foreach ($variantes as &$v) {
            $v['atributos'] = $this->atributosDeVariante($empresaId, (int) $v['variante_id']);
        }

        return $variantes;
    }

    public function atributosDeVariante(int $empresaId, int $varianteId): array
    {
        $stmt = $this->db->prepare('
            SELECT d.codigo, d.nombre AS eje, o.etiqueta AS valor, pva.valor_texto
            FROM producto_variante_atributos pva
            JOIN producto_atributos_definicion d ON d.id = pva.atributo_id
            LEFT JOIN producto_atributos_opciones o ON o.id = pva.opcion_id
            WHERE pva.variante_id = :variante_id AND pva.empresa_id = :empresa_id
            ORDER BY d.orden
        ');
        $stmt->execute(['variante_id' => $varianteId, 'empresa_id' => $empresaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function tieneVariantes(int $empresaId, int $productoId): bool
    {
        $stmt = $this->db->prepare('
            SELECT 1 FROM producto_variantes
            WHERE empresa_id = :empresa_id AND producto_padre_id = :producto_id AND activo = 1
            LIMIT 1
        ');
        $stmt->execute(['empresa_id' => $empresaId, 'producto_id' => $productoId]);
        return (bool) $stmt->fetchColumn();
    }

    public function esVarianteHijo(int $empresaId, int $productoId): ?int
    {
        $stmt = $this->db->prepare('
            SELECT producto_padre_id FROM producto_variantes
            WHERE empresa_id = :empresa_id AND producto_id = :producto_id AND activo = 1
            LIMIT 1
        ');
        $stmt->execute(['empresa_id' => $empresaId, 'producto_id' => $productoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['producto_padre_id'] : null;
    }

    public function codigoVarianteExiste(int $empresaId, int $productoPadreId, string $codigoVariante): bool
    {
        $stmt = $this->db->prepare('
            SELECT 1 FROM producto_variantes
            WHERE empresa_id = :e AND producto_padre_id = :p AND codigo_variante = :c
            LIMIT 1
        ');
        $stmt->execute(['e' => $empresaId, 'p' => $productoPadreId, 'c' => $codigoVariante]);
        return (bool) $stmt->fetchColumn();
    }

    public function findPadre(int $empresaId, int $productoId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT p.id, p.nombre, p.codigo, p.precio_venta, p.precio_costo,
                   p.rubro_id, p.centro_costo_id, p.unidad_medida,
                   p.controla_stock, p.permite_descuento, p.permite_comision, p.activo
            FROM productos p
            WHERE p.id = :id AND p.empresa_id = :empresa_id AND p.activo = 1
            LIMIT 1
        ');
        $stmt->execute(['id' => $productoId, 'empresa_id' => $empresaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findAtributo(int $empresaId, int $atributoId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, codigo, es_eje_variante, tipo_dato
            FROM producto_atributos_definicion
            WHERE id = :id AND empresa_id = :empresa_id AND activo = 1
            LIMIT 1
        ');
        $stmt->execute(['id' => $atributoId, 'empresa_id' => $empresaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findOpcion(int $empresaId, int $atributoId, int $opcionId): bool
    {
        $stmt = $this->db->prepare('
            SELECT 1 FROM producto_atributos_opciones
            WHERE id = :oid AND atributo_id = :aid AND empresa_id = :empresa_id AND activo = 1
            LIMIT 1
        ');
        $stmt->execute(['oid' => $opcionId, 'aid' => $atributoId, 'empresa_id' => $empresaId]);
        return (bool) $stmt->fetchColumn();
    }

    // ── Escritura ─────────────────────────────────────────────────────────────

    public function insertProductoHijo(int $empresaId, array $padre, string $codigoVariante, ?int $precioOverride): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO productos
                (empresa_id, rubro_id, centro_costo_id, codigo, sku, nombre,
                 unidad_medida, precio_costo, costo_actual, precio_venta,
                 controla_stock, stock_minimo, permite_descuento, permite_comision, activo)
            VALUES
                (:empresa_id, :rubro_id, :centro_costo_id, :codigo, :sku, :nombre,
                 :unidad_medida, :precio_costo, :costo_actual, :precio_venta,
                 :controla_stock, 0, :permite_descuento, :permite_comision, 1)
        ');
        $codigoHijo = $padre['codigo'] . '-' . $codigoVariante;
        $stmt->execute([
            'empresa_id'        => $empresaId,
            'rubro_id'          => $padre['rubro_id'],
            'centro_costo_id'   => $padre['centro_costo_id'],
            'codigo'            => $codigoHijo,
            'sku'               => $codigoHijo,
            'nombre'            => $padre['nombre'] . ' [' . $codigoVariante . ']',
            'unidad_medida'     => $padre['unidad_medida'] ?? 'UN',
            'precio_costo'      => (int) $padre['precio_costo'],
            'costo_actual'      => (int) $padre['precio_costo'],
            'precio_venta'      => $precioOverride ?? (int) $padre['precio_venta'],
            'controla_stock'    => (int) $padre['controla_stock'],
            'permite_descuento' => (int) $padre['permite_descuento'],
            'permite_comision'  => (int) $padre['permite_comision'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function insertVariante(int $empresaId, int $productoHijoId, int $productoPadreId, string $codigoVariante): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO producto_variantes (empresa_id, producto_padre_id, producto_id, codigo_variante)
            VALUES (:empresa_id, :padre_id, :producto_id, :codigo_variante)
        ');
        $stmt->execute([
            'empresa_id'     => $empresaId,
            'padre_id'       => $productoPadreId,
            'producto_id'    => $productoHijoId,
            'codigo_variante' => $codigoVariante,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function insertVarianteAtributo(int $empresaId, int $varianteId, int $atributoId, ?int $opcionId, ?string $valorTexto): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO producto_variante_atributos
                (empresa_id, variante_id, atributo_id, opcion_id, valor_texto)
            VALUES (:empresa_id, :variante_id, :atributo_id, :opcion_id, :valor_texto)
        ');
        $stmt->execute([
            'empresa_id'  => $empresaId,
            'variante_id' => $varianteId,
            'atributo_id' => $atributoId,
            'opcion_id'   => $opcionId,
            'valor_texto' => $valorTexto,
        ]);
    }
}
