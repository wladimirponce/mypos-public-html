<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class ProductoRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function list(int $empresaId, ?string $q = null, int $limit = 200, int $offset = 0): array
    {
        $sql = 'SELECT p.id, p.empresa_id, p.rubro_id, p.centro_costo_id, p.codigo, p.sku,
                       p.nombre, p.descripcion, p.unidad_medida, p.precio_costo,
                       p.costo_actual, p.precio_venta, p.margen_ganancia, p.tipo_margen, p.controla_stock, p.stock_minimo,
                       p.es_producto_peso, p.precio_por_kg, p.venta_restringida,
                       p.permite_descuento, p.permite_comision, p.activo,
                       r.nombre AS rubro, cc.nombre AS centro_costo,
                       pi.id AS imagen_principal_id,
                       pi.imagen_url AS imagen_principal,
                       a.id AS imagen_principal_archivo_id,
                       (
                           SELECT pcb.codigo_barra
                           FROM productos_codigos_barra pcb
                           WHERE pcb.producto_id = p.id
                             AND pcb.empresa_id = p.empresa_id
                             AND pcb.activo = 1
                           ORDER BY pcb.principal DESC, pcb.id
                           LIMIT 1
                       ) AS codigo_barra_principal,
                       (
                           SELECT pcb.imagen_url
                           FROM productos_codigos_barra pcb
                           WHERE pcb.producto_id = p.id
                             AND pcb.empresa_id = p.empresa_id
                             AND pcb.activo = 1
                           ORDER BY pcb.principal DESC, pcb.id
                           LIMIT 1
                       ) AS codigo_barra_imagen_url,
                       (
                           SELECT COALESCE(SUM(ss.cantidad), 0.000)
                           FROM stock_sucursal ss
                           WHERE ss.producto_id = p.id
                             AND ss.empresa_id = p.empresa_id
                       ) AS stock_total
                FROM productos p
                LEFT JOIN rubros r ON r.id = p.rubro_id
                LEFT JOIN centros_costo cc ON cc.id = p.centro_costo_id
                LEFT JOIN productos_imagenes pi ON pi.producto_id = p.id AND pi.empresa_id = p.empresa_id AND pi.principal = 1
                LEFT JOIN archivos_subidos a ON a.empresa_id = p.empresa_id AND a.ruta_relativa = pi.imagen_url AND a.estado = \'ACTIVO\'
                WHERE p.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        if ($q !== null && trim($q) !== '') {
            $sql .= ' AND (p.nombre LIKE :q_nombre OR p.codigo LIKE :q_codigo OR p.sku LIKE :q_sku
                        OR EXISTS (
                            SELECT 1 FROM productos_codigos_barra pcb_q
                            WHERE pcb_q.producto_id = p.id
                              AND pcb_q.empresa_id = p.empresa_id
                              AND pcb_q.activo = 1
                              AND pcb_q.codigo_barra LIKE :q_codigo_barra
                        ))';
            $term = '%' . trim($q) . '%';
            $params['q_nombre'] = $term;
            $params['q_codigo'] = $term;
            $params['q_sku'] = $term;
            $params['q_codigo_barra'] = $term;
        }

        $sql .= ' ORDER BY p.nombre LIMIT ' . max(1, min($limit, 500)) . ' OFFSET ' . max(0, $offset);
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function countList(int $empresaId, ?string $q = null): int
    {
        $sql = 'SELECT COUNT(*) FROM productos p WHERE p.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        if ($q !== null && trim($q) !== '') {
            $sql .= ' AND (p.nombre LIKE :q_nombre OR p.codigo LIKE :q_codigo OR p.sku LIKE :q_sku
                        OR EXISTS (
                            SELECT 1 FROM productos_codigos_barra pcb_q
                            WHERE pcb_q.producto_id = p.id
                              AND pcb_q.empresa_id = p.empresa_id
                              AND pcb_q.activo = 1
                              AND pcb_q.codigo_barra LIKE :q_codigo_barra
                        ))';
            $term = '%' . trim($q) . '%';
            $params['q_nombre'] = $term;
            $params['q_codigo'] = $term;
            $params['q_sku'] = $term;
            $params['q_codigo_barra'] = $term;
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    public function find(int $id, int $empresaId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT p.id, p.empresa_id, p.rubro_id, p.centro_costo_id, p.codigo, p.sku,
                    p.nombre, p.descripcion, p.unidad_medida, p.precio_costo,
                    p.costo_actual, p.precio_venta, p.margen_ganancia, p.tipo_margen, p.controla_stock, p.stock_minimo,
                    p.es_producto_peso, p.precio_por_kg, p.venta_restringida,
                    p.permite_descuento, p.permite_comision, p.activo,
                    r.nombre AS rubro, cc.nombre AS centro_costo,
                    pi.id AS imagen_principal_id,
                    pi.imagen_url AS imagen_principal,
                    a.id AS imagen_principal_archivo_id,
                    (
                        SELECT pcb.codigo_barra
                        FROM productos_codigos_barra pcb
                        WHERE pcb.producto_id = p.id
                          AND pcb.empresa_id = p.empresa_id
                          AND pcb.activo = 1
                        ORDER BY pcb.principal DESC, pcb.id
                        LIMIT 1
                    ) AS codigo_barra_principal,
                    (
                        SELECT pcb.imagen_url
                        FROM productos_codigos_barra pcb
                        WHERE pcb.producto_id = p.id
                          AND pcb.empresa_id = p.empresa_id
                          AND pcb.activo = 1
                        ORDER BY pcb.principal DESC, pcb.id
                        LIMIT 1
                    ) AS codigo_barra_imagen_url
             FROM productos p
             LEFT JOIN rubros r ON r.id = p.rubro_id
             LEFT JOIN centros_costo cc ON cc.id = p.centro_costo_id
             LEFT JOIN productos_imagenes pi ON pi.producto_id = p.id AND pi.empresa_id = p.empresa_id AND pi.principal = 1
             LEFT JOIN archivos_subidos a ON a.empresa_id = p.empresa_id AND a.ruta_relativa = pi.imagen_url AND a.estado = \'ACTIVO\'
             WHERE p.id = :id AND p.empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Resuelve un producto por código EXACTO para lectura de lector de barras.
     * Busca en p.codigo, p.sku y productos_codigos_barra.codigo_barra (activo=1).
     * Prioriza productos que controlan stock. Devuelve null si no hay coincidencia.
     *
     * @return array{id: string, empresa_id: string, codigo: string|null, sku: string|null, nombre: string, controla_stock: string, activo: string}|null
     */
    public function findByCodigoExacto(int $empresaId, string $codigo): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT p.id, p.empresa_id, p.codigo, p.sku, p.nombre,
                    p.controla_stock, p.activo
             FROM productos p
             WHERE p.empresa_id = :empresa_id
               AND p.activo = 1
               AND (
                    p.codigo = :codigo_c
                    OR p.sku = :codigo_s
                    OR EXISTS (
                        SELECT 1 FROM productos_codigos_barra pcb
                        WHERE pcb.producto_id = p.id
                          AND pcb.empresa_id = p.empresa_id
                          AND pcb.activo = 1
                          AND pcb.codigo_barra = :codigo_b
                    )
               )
             ORDER BY p.controla_stock DESC, p.id
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'codigo_c'   => $codigo,
            'codigo_s'   => $codigo,
            'codigo_b'   => $codigo,
        ]);
        /** @var array{id: string, empresa_id: string, codigo: string|null, sku: string|null, nombre: string, controla_stock: string, activo: string}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function create(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO productos (
                empresa_id, rubro_id, centro_costo_id, codigo, sku, nombre, descripcion,
                unidad_medida, precio_costo, costo_actual, precio_venta, margen_ganancia, tipo_margen, controla_stock,
                stock_minimo, permite_descuento, permite_comision, venta_restringida, activo
             ) VALUES (
                :empresa_id, :rubro_id, :centro_costo_id, :codigo, :sku, :nombre, :descripcion,
                :unidad_medida, :precio_costo, :costo_actual, :precio_venta, :margen_ganancia, :tipo_margen, :controla_stock,
                :stock_minimo, :permite_descuento, :permite_comision, :venta_restringida, :activo
             )'
        );
        $statement->execute($this->productParams($data));

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $id, int $empresaId, array $data): bool
    {
        $params = $this->productParams($data);
        $params['id'] = $id;
        $params['empresa_id'] = $empresaId;
        $statement = $this->connection->prepare(
            'UPDATE productos
             SET rubro_id = :rubro_id,
                 centro_costo_id = :centro_costo_id,
                 codigo = :codigo,
                 sku = :sku,
                 nombre = :nombre,
                 descripcion = :descripcion,
                 unidad_medida = :unidad_medida,
                 precio_costo = :precio_costo,
                 costo_actual = :costo_actual,
                 precio_venta = :precio_venta,
                 margen_ganancia = :margen_ganancia,
                 tipo_margen = :tipo_margen,
                 controla_stock = :controla_stock,
                 stock_minimo = :stock_minimo,
                 permite_descuento = :permite_descuento,
                 permite_comision = :permite_comision,
                 venta_restringida = :venta_restringida,
                 activo = :activo,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function deactivate(int $id, int $empresaId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE productos
             SET activo = 0, deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    public function activate(int $id, int $empresaId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE productos
             SET activo = 1, deleted_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    public function hardDelete(int $id, int $empresaId): bool
    {
        $statement = $this->connection->prepare(
            'DELETE FROM productos WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    public function productExists(int $id, int $empresaId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM productos WHERE id = :id AND empresa_id = :empresa_id LIMIT 1');
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function listBarcodes(int $productoId, int $empresaId): array
    {
        return $this->fetchAll(
            'SELECT id, empresa_id, producto_id, codigo_barra, tipo_codigo, descripcion, principal, activo, imagen_url
             FROM productos_codigos_barra
             WHERE producto_id = :producto_id AND empresa_id = :empresa_id
             ORDER BY principal DESC, id',
            ['producto_id' => $productoId, 'empresa_id' => $empresaId]
        );
    }

    public function createBarcode(int $productoId, array $data): int
    {
        if ((int) ($data['principal'] ?? 0) === 1) {
            $this->clearBarcodePrincipal($productoId, (int) $data['empresa_id']);
        }

        $statement = $this->connection->prepare(
            'INSERT INTO productos_codigos_barra (empresa_id, producto_id, codigo_barra, tipo_codigo, descripcion, principal, activo, imagen_url)
             VALUES (:empresa_id, :producto_id, :codigo_barra, :tipo_codigo, :descripcion, :principal, 1, :imagen_url)'
        );
        $statement->execute([
            'empresa_id' => $data['empresa_id'],
            'producto_id' => $productoId,
            'codigo_barra' => $data['codigo_barra'],
            'tipo_codigo' => $data['tipo_codigo'] ?? 'BARRA',
            'descripcion' => $data['descripcion'] ?? null,
            'principal' => (int) ($data['principal'] ?? 0),
            'imagen_url' => $data['imagen_url'] ?? null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function deleteBarcode(int $id, int $productoId, int $empresaId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE productos_codigos_barra SET activo = 0, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND producto_id = :producto_id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'producto_id' => $productoId, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    public function listImages(int $productoId, int $empresaId): array
    {
        return $this->fetchAll(
            'SELECT id, empresa_id, producto_id, codigo_barra_id, producto_codigo_barra_id, imagen_url, ruta,
                    titulo, descripcion, principal, orden, created_at
             FROM productos_imagenes
             WHERE producto_id = :producto_id AND empresa_id = :empresa_id
             ORDER BY principal DESC, orden, id',
            ['producto_id' => $productoId, 'empresa_id' => $empresaId]
        );
    }

    public function createImage(int $productoId, array $data): int
    {
        if ((int) ($data['principal'] ?? 0) === 1) {
            $this->connection->prepare(
                'UPDATE productos_imagenes SET principal = 0 WHERE producto_id = :producto_id AND empresa_id = :empresa_id'
            )->execute(['producto_id' => $productoId, 'empresa_id' => $data['empresa_id']]);
        }

        $statement = $this->connection->prepare(
            'INSERT INTO productos_imagenes (
                empresa_id, producto_id, producto_codigo_barra_id, codigo_barra_id, ruta, imagen_url,
                titulo, descripcion, principal, orden
             ) VALUES (
                :empresa_id, :producto_id, :producto_codigo_barra_id, :codigo_barra_id, :ruta, :imagen_url,
                :titulo, :descripcion, :principal, :orden
             )'
        );
        $url = (string) $data['imagen_url'];
        $codigoBarraId = $data['codigo_barra_id'] ?? null;
        $statement->execute([
            'empresa_id' => $data['empresa_id'],
            'producto_id' => $productoId,
            'producto_codigo_barra_id' => $codigoBarraId,
            'codigo_barra_id' => $codigoBarraId,
            'ruta' => $url,
            'imagen_url' => $url,
            'titulo' => $data['titulo'] ?? null,
            'descripcion' => $data['descripcion'] ?? null,
            'principal' => (int) ($data['principal'] ?? 0),
            'orden' => (int) ($data['orden'] ?? 1),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function deleteImage(int $id, int $productoId, int $empresaId): bool
    {
        $statement = $this->connection->prepare(
            'DELETE FROM productos_imagenes WHERE id = :id AND producto_id = :producto_id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'producto_id' => $productoId, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    public function listTaxes(int $productoId, int $empresaId): array
    {
        return $this->fetchAll(
            'SELECT pi.id, pi.empresa_id, pi.producto_id, pi.impuesto_id, i.codigo, i.nombre,
                    i.tipo, i.porcentaje, i.monto_fijo, pi.orden_aplicacion, pi.incluido_en_precio, pi.activo
             FROM producto_impuestos pi
             INNER JOIN impuestos i ON i.id = pi.impuesto_id
             WHERE pi.producto_id = :producto_id AND pi.empresa_id = :empresa_id
             ORDER BY pi.orden_aplicacion, pi.id',
            ['producto_id' => $productoId, 'empresa_id' => $empresaId]
        );
    }

    public function activeTaxExists(int $impuestoId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM impuestos WHERE id = :id AND activo = 1 LIMIT 1');
        $statement->execute(['id' => $impuestoId]);

        return (bool) $statement->fetchColumn();
    }

    public function createTax(int $productoId, array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO producto_impuestos (empresa_id, producto_id, impuesto_id, orden_aplicacion, incluido_en_precio, activo)
             VALUES (:empresa_id, :producto_id, :impuesto_id, :orden_aplicacion, :incluido_en_precio, 1)
             ON DUPLICATE KEY UPDATE
                orden_aplicacion = VALUES(orden_aplicacion),
                incluido_en_precio = VALUES(incluido_en_precio),
                activo = 1,
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'empresa_id' => $data['empresa_id'],
            'producto_id' => $productoId,
            'impuesto_id' => $data['impuesto_id'],
            'orden_aplicacion' => (int) ($data['orden_aplicacion'] ?? 1),
            'incluido_en_precio' => (int) ($data['incluido_en_precio'] ?? 1),
        ]);

        return (int) ($this->connection->lastInsertId() ?: $data['impuesto_id']);
    }

    public function deleteTax(int $id, int $productoId, int $empresaId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE producto_impuestos SET activo = 0, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND producto_id = :producto_id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'producto_id' => $productoId, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    public function listDiscounts(int $productoId, int $empresaId, bool $onlyActive = false): array
    {
        $sql = 'SELECT id, empresa_id, producto_id, sucursal_id, nombre, tipo_descuento, valor_descuento,
                       tipo, valor, fecha_inicio, fecha_fin, hora_inicio, hora_fin, cantidad_minima, acumulable, activo
                FROM productos_descuentos
                WHERE producto_id = :producto_id AND empresa_id = :empresa_id';
        if ($onlyActive) {
            $sql .= ' AND activo = 1 AND (fecha_inicio IS NULL OR fecha_inicio <= NOW()) AND (fecha_fin IS NULL OR fecha_fin >= NOW())';
        }

        return $this->fetchAll($sql . ' ORDER BY id DESC', ['producto_id' => $productoId, 'empresa_id' => $empresaId]);
    }

    public function createDiscount(int $productoId, array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO productos_descuentos (
                empresa_id, producto_id, sucursal_id, nombre, tipo, valor, tipo_descuento, valor_descuento,
                fecha_inicio, fecha_fin, hora_inicio, hora_fin, cantidad_minima, acumulable, activo
             ) VALUES (
                :empresa_id, :producto_id, :sucursal_id, :nombre, :tipo, :valor, :tipo_descuento, :valor_descuento,
                :fecha_inicio, :fecha_fin, :hora_inicio, :hora_fin, :cantidad_minima, :acumulable, :activo
             )'
        );
        $statement->execute($this->discountParams($productoId, $data));

        return (int) $this->connection->lastInsertId();
    }

    public function updateDiscount(int $id, int $productoId, int $empresaId, array $data): bool
    {
        $params = $this->discountParams($productoId, ['empresa_id' => $empresaId] + $data);
        $params['id'] = $id;
        $statement = $this->connection->prepare(
            'UPDATE productos_descuentos
             SET sucursal_id = :sucursal_id, nombre = :nombre, tipo = :tipo, valor = :valor,
                 tipo_descuento = :tipo_descuento, valor_descuento = :valor_descuento,
                 fecha_inicio = :fecha_inicio, fecha_fin = :fecha_fin, hora_inicio = :hora_inicio,
                 hora_fin = :hora_fin, cantidad_minima = :cantidad_minima, acumulable = :acumulable,
                 activo = :activo, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND producto_id = :producto_id AND empresa_id = :empresa_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function deleteDiscount(int $id, int $productoId, int $empresaId): bool
    {
        return $this->deactivateRelated('productos_descuentos', $id, $productoId, $empresaId);
    }

    public function listCommissions(int $productoId, int $empresaId, bool $onlyActive = false): array
    {
        $sql = 'SELECT id, empresa_id, producto_id, sucursal_id, nombre, tipo_comision, valor_comision,
                       tipo, valor, fecha_inicio, fecha_fin, activo
                FROM productos_comisiones
                WHERE producto_id = :producto_id AND empresa_id = :empresa_id';
        if ($onlyActive) {
            $sql .= ' AND activo = 1 AND (fecha_inicio IS NULL OR fecha_inicio <= NOW()) AND (fecha_fin IS NULL OR fecha_fin >= NOW())';
        }

        return $this->fetchAll($sql . ' ORDER BY id DESC', ['producto_id' => $productoId, 'empresa_id' => $empresaId]);
    }

    public function createCommission(int $productoId, array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO productos_comisiones (
                empresa_id, producto_id, sucursal_id, nombre, tipo, valor, tipo_comision, valor_comision,
                fecha_inicio, fecha_fin, activo
             ) VALUES (
                :empresa_id, :producto_id, :sucursal_id, :nombre, :tipo, :valor, :tipo_comision, :valor_comision,
                :fecha_inicio, :fecha_fin, :activo
             )'
        );
        $statement->execute($this->commissionParams($productoId, $data));

        return (int) $this->connection->lastInsertId();
    }

    public function updateCommission(int $id, int $productoId, int $empresaId, array $data): bool
    {
        $params = $this->commissionParams($productoId, ['empresa_id' => $empresaId] + $data);
        $params['id'] = $id;
        $statement = $this->connection->prepare(
            'UPDATE productos_comisiones
             SET sucursal_id = :sucursal_id, nombre = :nombre, tipo = :tipo, valor = :valor,
                 tipo_comision = :tipo_comision, valor_comision = :valor_comision,
                 fecha_inicio = :fecha_inicio, fecha_fin = :fecha_fin, activo = :activo,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND producto_id = :producto_id AND empresa_id = :empresa_id'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function deleteCommission(int $id, int $productoId, int $empresaId): bool
    {
        return $this->deactivateRelated('productos_comisiones', $id, $productoId, $empresaId);
    }

    public function searchByCode(int $empresaId, string $code): ?array
    {
        $row = $this->queryByCode($empresaId, $code);
        if ($row !== null) {
            return $row;
        }

        // Fallback: EAN-13 de peso variable (prefijo "2", 13 dígitos) — balanzas de carnicería
        $parsed = $this->parseVariableWeightEan13($code);
        if ($parsed === null) {
            return null;
        }

        foreach ($parsed['plu_candidates'] as $plu) {
            $row = $this->queryByCode($empresaId, $plu);
            if ($row !== null) {
                // El precio del ticket ya incluye el total; no tratar como producto por peso
                $row['precio_balanza']   = $parsed['precio_total'];
                $row['es_producto_peso'] = 0;
                return $row;
            }
        }

        // Fallback: la balanza de carnicería codifica el TOTAL del recibo, no un producto.
        // Cuando ningún PLU coincide, devolvemos el producto contenedor genérico cuya
        // descripción es exactamente "CARNICERIA" con el total embebido como precio.
        $contenedor = $this->queryByDescripcion($empresaId, 'CARNICERIA');
        if ($contenedor !== null) {
            $contenedor['precio_balanza']   = $parsed['precio_total'];
            $contenedor['es_producto_peso'] = 0;
            return $contenedor;
        }

        return null;
    }

    private function queryByCode(int $empresaId, string $code): ?array
    {
        return $this->fetchProductoBase(
            $empresaId,
            '(p.codigo = :codigo OR p.sku = :sku OR pcb.codigo_barra = :codigo_barra)',
            [
                'codigo'            => $code,
                'sku'               => $code,
                'codigo_barra'      => $code,
                'codigo_barra_join' => $code,
            ]
        );
    }

    private function queryByDescripcion(int $empresaId, string $descripcion): ?array
    {
        return $this->fetchProductoBase(
            $empresaId,
            'p.descripcion = :descripcion',
            ['descripcion' => $descripcion]
        );
    }

    /**
     * SELECT base de un producto para POS. La cláusula WHERE adicional y sus
     * parámetros se inyectan; :codigo_barra_join alimenta el LEFT JOIN del código
     * de barra (vacío cuando no se busca por barra, p.ej. al buscar por descripción).
     */
    private function fetchProductoBase(int $empresaId, string $whereClause, array $params): ?array
    {
        $codigoBarraJoin = $params['codigo_barra_join'] ?? '';
        unset($params['codigo_barra_join']);

        $statement = $this->connection->prepare(
            'SELECT p.id, p.empresa_id, p.codigo, p.nombre, p.descripcion, p.precio_venta, p.controla_stock,
                    p.es_producto_peso, p.precio_por_kg, p.venta_restringida,
                    r.nombre AS rubro, cc.nombre AS centro_costo, pcb.codigo_barra AS codigo_barra_usado,
                    pi.imagen_url AS imagen_principal,
                    a.id AS imagen_principal_archivo_id,
                    EXISTS(
                        SELECT 1 FROM producto_variantes pv
                        WHERE pv.producto_padre_id = p.id AND pv.empresa_id = p.empresa_id AND pv.activo = 1
                    ) AS tiene_variantes,
                    EXISTS(
                        SELECT 1 FROM producto_variantes pv2
                        WHERE pv2.producto_id = p.id AND pv2.empresa_id = p.empresa_id AND pv2.activo = 1
                    ) AS es_variante_hijo
             FROM productos p
             LEFT JOIN productos_codigos_barra pcb ON pcb.producto_id = p.id
                AND pcb.empresa_id = p.empresa_id AND pcb.codigo_barra = :codigo_barra_join AND pcb.activo = 1
             LEFT JOIN rubros r ON r.id = p.rubro_id
             LEFT JOIN centros_costo cc ON cc.id = p.centro_costo_id
             LEFT JOIN productos_imagenes pi ON pi.producto_id = p.id AND pi.empresa_id = p.empresa_id AND pi.principal = 1
             LEFT JOIN archivos_subidos a ON a.empresa_id = p.empresa_id AND a.ruta_relativa = pi.imagen_url AND a.estado = \'ACTIVO\'
             WHERE p.empresa_id = :empresa_id
               AND p.activo = 1
               AND ' . $whereClause . '
             LIMIT 1'
        );
        $statement->execute(array_merge(
            ['empresa_id' => $empresaId, 'codigo_barra_join' => $codigoBarraJoin],
            $params
        ));
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['tiene_variantes']  = (bool) $row['tiene_variantes'];
        $row['es_variante_hijo'] = (bool) $row['es_variante_hijo'];
        return $row;
    }

    /**
     * Parsea EAN-13 de peso variable (prefijo 2).
     * Formato: [2][id_balanza:1][plu:5][precio:5][verificador:1]
     */
    private function parseVariableWeightEan13(string $code): ?array
    {
        if (strlen($code) !== 13 || $code[0] !== '2' || !ctype_digit($code)) {
            return null;
        }

        $pluField   = substr($code, 2, 5);  // p.ej. "00170"
        $priceField = substr($code, 7, 5);  // p.ej. "29630" → $29.630 sin decimales

        // Candidatos en orden de especificidad: campo completo, primeros 4, sin ceros iniciales
        $pluFour   = substr($pluField, 0, 4);
        $stripped5 = ltrim($pluField, '0') ?: '0';
        $stripped4 = ltrim($pluFour, '0') ?: '0';

        return [
            'plu_candidates' => array_unique([$pluField, $pluFour, $stripped5, $stripped4]),
            'precio_total'   => (int) $priceField,
        ];
    }

    public function actualizarCostoActual(int $empresaId, int $productoId, int $costoNuevo): void
    {
        // Placeholders distintos a proposito: la conexion usa PDO::ATTR_EMULATE_PREPARES = false,
        // y en ese modo PDO no permite reusar el mismo placeholder con nombre mas de una vez
        // (lanza SQLSTATE[HY093]). Antes :costo aparecia 3 veces y reventaba la confirmacion
        // de compra, dejandola en BORRADOR.
        $this->connection->prepare(
            'UPDATE productos
             SET costo_actual = :costo, precio_costo = :costo_precio, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id AND costo_actual != :costo_filtro'
        )->execute([
            'costo' => $costoNuevo,
            'costo_precio' => $costoNuevo,
            'costo_filtro' => $costoNuevo,
            'id' => $productoId,
            'empresa_id' => $empresaId,
        ]);
    }

    public function actualizarPrecioVenta(int $empresaId, int $productoId, int $precioVenta): void
    {
        $this->connection->prepare(
            'UPDATE productos
             SET precio_venta = :precio_venta, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        )->execute(['precio_venta' => $precioVenta, 'id' => $productoId, 'empresa_id' => $empresaId]);
    }

    public function findParaMargen(int $empresaId, int $productoId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, nombre, costo_actual, precio_venta, margen_ganancia, tipo_margen
             FROM productos
             WHERE id = :id AND empresa_id = :empresa_id
             LIMIT 1'
        );
        $statement->execute(['id' => $productoId, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function clearBarcodePrincipal(int $productoId, int $empresaId): void
    {
        $this->connection->prepare(
            'UPDATE productos_codigos_barra SET principal = 0 WHERE producto_id = :producto_id AND empresa_id = :empresa_id'
        )->execute(['producto_id' => $productoId, 'empresa_id' => $empresaId]);
    }

    private function deactivateRelated(string $table, int $id, int $productoId, int $empresaId): bool
    {
        $statement = $this->connection->prepare(
            "UPDATE {$table} SET activo = 0, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND producto_id = :producto_id AND empresa_id = :empresa_id"
        );
        $statement->execute(['id' => $id, 'producto_id' => $productoId, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    private function productParams(array $data): array
    {
        $precioCosto = (int) ($data['precio_costo'] ?? $data['costo_actual'] ?? 0);

        return [
            'empresa_id' => (int) $data['empresa_id'],
            'rubro_id' => $data['rubro_id'] ?? null,
            'centro_costo_id' => $data['centro_costo_id'] ?? null,
            'codigo' => $data['codigo'],
            'sku' => $data['codigo'],
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'unidad_medida' => $data['unidad_medida'] ?? 'UN',
            'precio_costo' => $precioCosto,
            'costo_actual' => $precioCosto,
            'precio_venta' => (int) ($data['precio_venta'] ?? 0),
            'margen_ganancia' => isset($data['margen_ganancia']) && $data['margen_ganancia'] !== '' && $data['margen_ganancia'] !== null
                ? round((float) $data['margen_ganancia'], 2)
                : null,
            'tipo_margen' => \Mypos\Support\PrecioCalculator::normalizarTipo($data['tipo_margen'] ?? null),
            'controla_stock' => (int) ($data['controla_stock'] ?? 1),
            'stock_minimo' => (string) ($data['stock_minimo'] ?? '0.000'),
            'permite_descuento' => (int) ($data['permite_descuento'] ?? 1),
            'permite_comision' => (int) ($data['permite_comision'] ?? 1),
            'venta_restringida' => $this->ventaRestringida($data['venta_restringida'] ?? null),
            'activo' => (int) ($data['activo'] ?? 1),
        ];
    }

    private function ventaRestringida(mixed $value): string
    {
        $restriccion = strtoupper(trim((string) $value));

        return in_array($restriccion, ['ALCOHOL', 'TABACO'], true) ? $restriccion : 'NINGUNA';
    }

    private function discountParams(int $productoId, array $data): array
    {
        return [
            'empresa_id' => (int) $data['empresa_id'],
            'producto_id' => $productoId,
            'sucursal_id' => $data['sucursal_id'] ?? null,
            'nombre' => $data['nombre'] ?? null,
            'tipo' => $data['tipo_descuento'],
            'valor' => (int) $data['valor_descuento'],
            'tipo_descuento' => $data['tipo_descuento'],
            'valor_descuento' => (int) $data['valor_descuento'],
            'fecha_inicio' => $data['fecha_inicio'] ?? null,
            'fecha_fin' => $data['fecha_fin'] ?? null,
            'hora_inicio' => $data['hora_inicio'] ?? null,
            'hora_fin' => $data['hora_fin'] ?? null,
            'cantidad_minima' => $data['cantidad_minima'] ?? null,
            'acumulable' => (int) ($data['acumulable'] ?? 0),
            'activo' => (int) ($data['activo'] ?? 1),
        ];
    }

    private function commissionParams(int $productoId, array $data): array
    {
        $legacyType = $data['tipo_comision'] === 'MONTO_FIJO' ? 'MONTO' : 'PORCENTAJE';

        return [
            'empresa_id' => (int) $data['empresa_id'],
            'producto_id' => $productoId,
            'sucursal_id' => $data['sucursal_id'] ?? null,
            'nombre' => $data['nombre'] ?? null,
            'tipo' => $legacyType,
            'valor' => (int) $data['valor_comision'],
            'tipo_comision' => $data['tipo_comision'],
            'valor_comision' => (int) $data['valor_comision'],
            'fecha_inicio' => $data['fecha_inicio'] ?? null,
            'fecha_fin' => $data['fecha_fin'] ?? null,
            'activo' => (int) ($data['activo'] ?? 1),
        ];
    }

    private function fetchAll(string $sql, array $params): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }
}
