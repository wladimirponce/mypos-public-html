<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class ProveedorRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function rutExists(int $empresaId, string $rut, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM proveedores WHERE empresa_id = :empresa_id AND rut = :rut AND deleted_at IS NULL';
        $params = ['empresa_id' => $empresaId, 'rut' => $rut];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return (bool) $statement->fetchColumn();
    }

    public function create(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO proveedores (
                empresa_id, rut, nombre, razon_social, nombre_fantasia, giro, email,
                telefono, direccion, comuna, ciudad, activo, observacion
             ) VALUES (
                :empresa_id, :rut, :nombre, :razon_social, :nombre_fantasia, :giro, :email,
                :telefono, :direccion, :comuna, :ciudad, :activo, :observacion
             )'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function list(int $empresaId, array $filters, int $limit, int $offset): array
    {
        $sql = 'SELECT id, empresa_id, rut, nombre, razon_social, giro, email, telefono,
                       direccion, comuna, ciudad, activo, observacion, created_at, updated_at
                FROM proveedores
                WHERE empresa_id = :empresa_id AND deleted_at IS NULL';
        $params = ['empresa_id' => $empresaId];

        if (!empty($filters['q'])) {
            $sql .= ' AND (nombre LIKE :q_nombre OR razon_social LIKE :q_razon OR rut LIKE :q_rut OR telefono LIKE :q_telefono OR email LIKE :q_email)';
            $query = '%' . (string) $filters['q'] . '%';
            $params['q_nombre'] = $query;
            $params['q_razon'] = $query;
            $params['q_rut'] = $query;
            $params['q_telefono'] = $query;
            $params['q_email'] = $query;
        }
        if (isset($filters['activo']) && $filters['activo'] !== '') {
            $sql .= ' AND activo = :activo';
            $params['activo'] = filter_var($filters['activo'], FILTER_VALIDATE_BOOL) ? 1 : 0;
        }

        $sql .= " ORDER BY nombre LIMIT {$limit} OFFSET {$offset}";
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function find(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, rut, nombre, razon_social, giro, email, telefono,
                    direccion, comuna, ciudad, activo, observacion, created_at, updated_at, deleted_at
             FROM proveedores
             WHERE id = :id AND empresa_id = :empresa_id AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function update(int $empresaId, int $id, array $data): void
    {
        $statement = $this->connection->prepare(
            'UPDATE proveedores
             SET rut = :rut,
                 nombre = :nombre,
                 razon_social = :razon_social,
                 nombre_fantasia = :nombre_fantasia,
                 giro = :giro,
                 email = :email,
                 telefono = :telefono,
                 direccion = :direccion,
                 comuna = :comuna,
                 ciudad = :ciudad,
                 activo = :activo,
                 observacion = :observacion,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id AND deleted_at IS NULL'
        );
        $data['id'] = $id;
        $data['empresa_id'] = $empresaId;
        $statement->execute($data);
    }

    public function softDelete(int $empresaId, int $id): void
    {
        $statement = $this->connection->prepare(
            'UPDATE proveedores
             SET activo = 0, deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
    }

    public function productExists(int $empresaId, int $productoId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM productos WHERE id = :producto_id AND empresa_id = :empresa_id AND activo = 1 LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'producto_id' => $productoId]);

        return (bool) $statement->fetchColumn();
    }

    public function providerExists(int $empresaId, int $proveedorId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM proveedores
             WHERE id = :proveedor_id AND empresa_id = :empresa_id AND activo = 1 AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'proveedor_id' => $proveedorId]);

        return (bool) $statement->fetchColumn();
    }

    public function listProductProviders(int $empresaId, int $productoId): array
    {
        $statement = $this->connection->prepare(
            'SELECT pp.id, pp.empresa_id, pp.proveedor_id, pp.producto_id,
                    p.nombre AS proveedor_nombre, p.rut AS proveedor_rut,
                    pp.codigo_proveedor, pp.codigo_barra_proveedor, pp.nombre_proveedor,
                    pp.unidad_compra, pp.factor_conversion, pp.costo_ultimo, pp.moneda,
                    pp.proveedor_preferido, pp.plazo_entrega_dias, pp.compra_minima,
                    pp.activo, pp.observacion, pp.created_at, pp.updated_at
             FROM proveedor_productos pp
             INNER JOIN proveedores p ON p.id = pp.proveedor_id AND p.empresa_id = pp.empresa_id
             WHERE pp.empresa_id = :empresa_id
               AND pp.producto_id = :producto_id
               AND pp.deleted_at IS NULL
             ORDER BY pp.proveedor_preferido DESC, p.nombre'
        );
        $statement->execute(['empresa_id' => $empresaId, 'producto_id' => $productoId]);

        return $statement->fetchAll();
    }

    public function listProviderProducts(int $empresaId, int $proveedorId): array
    {
        $statement = $this->connection->prepare(
            'SELECT pp.id, pp.empresa_id, pp.proveedor_id, pp.producto_id,
                    prod.codigo AS producto_codigo, prod.nombre AS producto_nombre,
                    pp.codigo_proveedor, pp.codigo_barra_proveedor, pp.nombre_proveedor,
                    pp.unidad_compra, pp.factor_conversion, pp.costo_ultimo, pp.moneda,
                    pp.proveedor_preferido, pp.plazo_entrega_dias, pp.compra_minima,
                    pp.activo, pp.observacion, pp.created_at, pp.updated_at
             FROM proveedor_productos pp
             INNER JOIN productos prod ON prod.id = pp.producto_id AND prod.empresa_id = pp.empresa_id
             WHERE pp.empresa_id = :empresa_id
               AND pp.proveedor_id = :proveedor_id
               AND pp.deleted_at IS NULL
             ORDER BY prod.nombre'
        );
        $statement->execute(['empresa_id' => $empresaId, 'proveedor_id' => $proveedorId]);

        return $statement->fetchAll();
    }

    public function findProviderProduct(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, proveedor_id, producto_id, codigo_proveedor,
                    codigo_barra_proveedor, nombre_proveedor, unidad_compra,
                    factor_conversion, costo_ultimo, moneda, proveedor_preferido,
                    plazo_entrega_dias, compra_minima, activo, observacion
             FROM proveedor_productos
             WHERE id = :id AND empresa_id = :empresa_id AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createProviderProduct(array $data): int
    {
        if ((int) ($data['proveedor_preferido'] ?? 0) === 1) {
            $this->clearPreferredProvider((int) $data['empresa_id'], (int) $data['producto_id']);
        }

        $statement = $this->connection->prepare(
            'INSERT INTO proveedor_productos (
                empresa_id, proveedor_id, producto_id, codigo_proveedor, codigo_barra_proveedor,
                nombre_proveedor, unidad_compra, factor_conversion, costo_ultimo, moneda,
                proveedor_preferido, plazo_entrega_dias, compra_minima, activo, observacion
             ) VALUES (
                :empresa_id, :proveedor_id, :producto_id, :codigo_proveedor, :codigo_barra_proveedor,
                :nombre_proveedor, :unidad_compra, :factor_conversion, :costo_ultimo, :moneda,
                :proveedor_preferido, :plazo_entrega_dias, :compra_minima, :activo, :observacion
             )'
        );
        $statement->execute($this->providerProductParams($data));

        return (int) $this->connection->lastInsertId();
    }

    public function updateProviderProduct(int $empresaId, int $id, array $data): bool
    {
        if ((int) ($data['proveedor_preferido'] ?? 0) === 1) {
            $this->clearPreferredProvider($empresaId, (int) $data['producto_id'], $id);
        }

        $params = $this->providerProductParams(['empresa_id' => $empresaId] + $data);
        $params['id'] = $id;
        $statement = $this->connection->prepare(
            'UPDATE proveedor_productos
             SET proveedor_id = :proveedor_id,
                 producto_id = :producto_id,
                 codigo_proveedor = :codigo_proveedor,
                 codigo_barra_proveedor = :codigo_barra_proveedor,
                 nombre_proveedor = :nombre_proveedor,
                 unidad_compra = :unidad_compra,
                 factor_conversion = :factor_conversion,
                 costo_ultimo = :costo_ultimo,
                 moneda = :moneda,
                 proveedor_preferido = :proveedor_preferido,
                 plazo_entrega_dias = :plazo_entrega_dias,
                 compra_minima = :compra_minima,
                 activo = :activo,
                 observacion = :observacion,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id AND deleted_at IS NULL'
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function deleteProviderProduct(int $empresaId, int $id): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE proveedor_productos
             SET activo = 0, deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    public function relationForProviderProduct(int $empresaId, int $proveedorId, int $productoId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, proveedor_id, producto_id, unidad_compra, factor_conversion, moneda
             FROM proveedor_productos
             WHERE empresa_id = :empresa_id
               AND proveedor_id = :proveedor_id
               AND producto_id = :producto_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'proveedor_id' => $proveedorId,
            'producto_id' => $productoId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createProviderPrice(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO proveedor_precios (
                empresa_id, proveedor_id, producto_id, proveedor_producto_id,
                precio_compra, moneda, unidad_compra, factor_conversion,
                fecha_precio, vigente_desde, vigente_hasta, origen, compra_id,
                observacion, activo
             ) VALUES (
                :empresa_id, :proveedor_id, :producto_id, :proveedor_producto_id,
                :precio_compra, :moneda, :unidad_compra, :factor_conversion,
                :fecha_precio, :vigente_desde, :vigente_hasta, :origen, :compra_id,
                :observacion, :activo
             )'
        );
        $statement->execute($this->providerPriceParams($data));

        return (int) $this->connection->lastInsertId();
    }

    public function listProductPrices(int $empresaId, int $productoId): array
    {
        $statement = $this->connection->prepare(
            'SELECT pp.id, pp.empresa_id, pp.proveedor_id, p.nombre AS proveedor_nombre,
                    pp.producto_id, pp.proveedor_producto_id, pp.precio_compra, pp.moneda,
                    pp.unidad_compra, pp.factor_conversion, pp.fecha_precio,
                    pp.vigente_desde, pp.vigente_hasta, pp.origen, pp.compra_id,
                    pp.observacion, pp.activo, pp.created_at
             FROM proveedor_precios pp
             INNER JOIN proveedores p ON p.id = pp.proveedor_id AND p.empresa_id = pp.empresa_id
             WHERE pp.empresa_id = :empresa_id
               AND pp.producto_id = :producto_id
               AND pp.activo = 1
             ORDER BY pp.fecha_precio DESC, pp.id DESC
             LIMIT 300'
        );
        $statement->execute(['empresa_id' => $empresaId, 'producto_id' => $productoId]);

        return $statement->fetchAll();
    }

    public function listProviderPrices(int $empresaId, int $proveedorId): array
    {
        $statement = $this->connection->prepare(
            'SELECT pp.id, pp.empresa_id, pp.proveedor_id, pp.producto_id,
                    prod.codigo AS producto_codigo, prod.nombre AS producto_nombre,
                    pp.proveedor_producto_id, pp.precio_compra, pp.moneda,
                    pp.unidad_compra, pp.factor_conversion, pp.fecha_precio,
                    pp.vigente_desde, pp.vigente_hasta, pp.origen, pp.compra_id,
                    pp.observacion, pp.activo, pp.created_at
             FROM proveedor_precios pp
             INNER JOIN productos prod ON prod.id = pp.producto_id AND prod.empresa_id = pp.empresa_id
             WHERE pp.empresa_id = :empresa_id
               AND pp.proveedor_id = :proveedor_id
               AND pp.activo = 1
             ORDER BY pp.fecha_precio DESC, pp.id DESC
             LIMIT 300'
        );
        $statement->execute(['empresa_id' => $empresaId, 'proveedor_id' => $proveedorId]);

        return $statement->fetchAll();
    }

    public function productPriceSummary(int $empresaId, int $productoId): array
    {
        $latest = $this->fetchPriceRow(
            'SELECT pp.*, p.nombre AS proveedor_nombre
             FROM proveedor_precios pp
             INNER JOIN proveedores p ON p.id = pp.proveedor_id AND p.empresa_id = pp.empresa_id
             WHERE pp.empresa_id = :empresa_id AND pp.producto_id = :producto_id AND pp.activo = 1
             ORDER BY pp.fecha_precio DESC, pp.id DESC
             LIMIT 1',
            ['empresa_id' => $empresaId, 'producto_id' => $productoId]
        );
        $best = $this->fetchPriceRow(
            'SELECT pp.*, p.nombre AS proveedor_nombre
             FROM proveedor_precios pp
             INNER JOIN proveedores p ON p.id = pp.proveedor_id AND p.empresa_id = pp.empresa_id
             WHERE pp.empresa_id = :empresa_id AND pp.producto_id = :producto_id AND pp.activo = 1
               AND (pp.vigente_desde IS NULL OR pp.vigente_desde <= CURRENT_DATE)
               AND (pp.vigente_hasta IS NULL OR pp.vigente_hasta >= CURRENT_DATE)
             ORDER BY pp.precio_compra ASC, pp.fecha_precio DESC, pp.id DESC
             LIMIT 1',
            ['empresa_id' => $empresaId, 'producto_id' => $productoId]
        );

        return ['ultimo_precio' => $latest, 'mejor_precio_vigente' => $best];
    }

    public function createPriceListImport(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO proveedor_listas_precios_importaciones (
                empresa_id, proveedor_id, usuario_id, origen, estado, total_items, metadata_json
             ) VALUES (
                :empresa_id, :proveedor_id, :usuario_id, :origen, "CARGADO", :total_items, :metadata_json
             )'
        );
        $statement->execute([
            'empresa_id' => $data['empresa_id'],
            'proveedor_id' => $data['proveedor_id'],
            'usuario_id' => $data['usuario_id'] ?? null,
            'origen' => $data['origen'] ?? 'manual',
            'total_items' => $data['total_items'],
            'metadata_json' => $data['metadata_json'] ?? null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function insertPriceListItem(int $empresaId, int $importId, int $line, array $raw): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO proveedor_listas_precios_items (
                empresa_id, importacion_id, linea, raw_json, codigo_proveedor,
                codigo_barra, codigo_interno, nombre_proveedor, precio_compra,
                unidad_compra, factor_conversion
             ) VALUES (
                :empresa_id, :importacion_id, :linea, :raw_json, :codigo_proveedor,
                :codigo_barra, :codigo_interno, :nombre_proveedor, :precio_compra,
                :unidad_compra, :factor_conversion
             )'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'importacion_id' => $importId,
            'linea' => $line,
            'raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'codigo_proveedor' => $this->nullable($raw['codigo_proveedor'] ?? $raw['codigo'] ?? null),
            'codigo_barra' => $this->nullable($raw['codigo_barra'] ?? $raw['barra'] ?? null),
            'codigo_interno' => $this->nullable($raw['codigo_interno'] ?? $raw['codigo_producto'] ?? null),
            'nombre_proveedor' => $this->nullable($raw['nombre_proveedor'] ?? $raw['descripcion'] ?? $raw['nombre'] ?? null),
            'precio_compra' => isset($raw['precio_compra']) && is_numeric($raw['precio_compra']) ? (int) round((float) $raw['precio_compra']) : null,
            'unidad_compra' => $this->nullable($raw['unidad_compra'] ?? null),
            'factor_conversion' => isset($raw['factor_conversion']) && is_numeric($raw['factor_conversion']) ? number_format((float) $raw['factor_conversion'], 4, '.', '') : null,
        ]);
    }

    public function findPriceListImport(int $empresaId, int $proveedorId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, proveedor_id, usuario_id, origen, estado,
                    total_items, total_validos, total_errores, metadata_json,
                    created_at, updated_at, applied_at
             FROM proveedor_listas_precios_importaciones
             WHERE id = :id AND empresa_id = :empresa_id AND proveedor_id = :proveedor_id
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId, 'proveedor_id' => $proveedorId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function priceListItems(int $empresaId, int $importId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, importacion_id, linea, raw_json, codigo_proveedor,
                    codigo_barra, codigo_interno, nombre_proveedor, precio_compra,
                    unidad_compra, factor_conversion, producto_id_detectado,
                    proveedor_producto_id_detectado, accion_sugerida, estado, errores_json
             FROM proveedor_listas_precios_items
             WHERE empresa_id = :empresa_id AND importacion_id = :importacion_id
             ORDER BY linea'
        );
        $statement->execute(['empresa_id' => $empresaId, 'importacion_id' => $importId]);

        return $statement->fetchAll();
    }

    public function relationByProviderCode(int $empresaId, int $proveedorId, string $code): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, producto_id, unidad_compra, factor_conversion
             FROM proveedor_productos
             WHERE empresa_id = :empresa_id AND proveedor_id = :proveedor_id
               AND codigo_proveedor = :codigo AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'proveedor_id' => $proveedorId, 'codigo' => $code]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function productByCodeOrBarcode(int $empresaId, ?string $code, ?string $barcode): ?int
    {
        if (($code === null || trim($code) === '') && ($barcode === null || trim($barcode) === '')) {
            return null;
        }

        $statement = $this->connection->prepare(
            'SELECT p.id
             FROM productos p
             LEFT JOIN productos_codigos_barra cb ON cb.producto_id = p.id
                AND cb.empresa_id = p.empresa_id AND cb.activo = 1
             WHERE p.empresa_id = :empresa_id AND p.activo = 1
               AND (
                    (:code_check IS NOT NULL AND (p.codigo = :code_codigo OR p.sku = :code_sku))
                    OR (:barcode_check IS NOT NULL AND cb.codigo_barra = :barcode_value)
               )
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'code_check' => $code,
            'code_codigo' => $code,
            'code_sku' => $code,
            'barcode_check' => $barcode,
            'barcode_value' => $barcode,
        ]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function updatePriceListItemValidation(int $id, int $empresaId, ?int $productoId, ?int $relationId, string $status, array $errors): void
    {
        $statement = $this->connection->prepare(
            'UPDATE proveedor_listas_precios_items
             SET producto_id_detectado = :producto_id,
                 proveedor_producto_id_detectado = :relation_id,
                 accion_sugerida = :accion,
                 estado = :estado,
                 errores_json = :errores,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute([
            'id' => $id,
            'empresa_id' => $empresaId,
            'producto_id' => $productoId,
            'relation_id' => $relationId,
            'accion' => $status === 'VALIDO' ? 'REGISTRAR_PRECIO' : 'ERROR',
            'estado' => $status,
            'errores' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function updatePriceListImportTotals(int $id, int $empresaId, int $valid, int $errors): void
    {
        $statement = $this->connection->prepare(
            'UPDATE proveedor_listas_precios_importaciones
             SET total_validos = :validos,
                 total_errores = :errores,
                 estado = :estado,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute([
            'id' => $id,
            'empresa_id' => $empresaId,
            'validos' => $valid,
            'errores' => $errors,
            'estado' => $errors > 0 ? 'CON_ERRORES' : 'VALIDADO',
        ]);
    }

    public function markPriceListItemApplied(int $id, int $empresaId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE proveedor_listas_precios_items
             SET estado = "APLICADO", updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
    }

    public function markPriceListImportApplied(int $id, int $empresaId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE proveedor_listas_precios_importaciones
             SET estado = "APLICADO", applied_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
    }

    private function clearPreferredProvider(int $empresaId, int $productoId, ?int $excludeId = null): void
    {
        $sql = 'UPDATE proveedor_productos
                SET proveedor_preferido = 0, updated_at = CURRENT_TIMESTAMP
                WHERE empresa_id = :empresa_id AND producto_id = :producto_id AND deleted_at IS NULL';
        $params = ['empresa_id' => $empresaId, 'producto_id' => $productoId];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excludeId;
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
    }

    private function providerProductParams(array $data): array
    {
        return [
            'empresa_id' => (int) $data['empresa_id'],
            'proveedor_id' => (int) $data['proveedor_id'],
            'producto_id' => (int) $data['producto_id'],
            'codigo_proveedor' => $this->nullable($data['codigo_proveedor'] ?? null),
            'codigo_barra_proveedor' => $this->nullable($data['codigo_barra_proveedor'] ?? null),
            'nombre_proveedor' => trim((string) $data['nombre_proveedor']),
            'unidad_compra' => trim((string) ($data['unidad_compra'] ?? 'UN')) ?: 'UN',
            'factor_conversion' => number_format((float) ($data['factor_conversion'] ?? 1), 4, '.', ''),
            'costo_ultimo' => isset($data['costo_ultimo']) && $data['costo_ultimo'] !== '' ? (int) $data['costo_ultimo'] : null,
            'moneda' => trim((string) ($data['moneda'] ?? 'CLP')) ?: 'CLP',
            'proveedor_preferido' => (int) ($data['proveedor_preferido'] ?? 0),
            'plazo_entrega_dias' => isset($data['plazo_entrega_dias']) && $data['plazo_entrega_dias'] !== '' ? (int) $data['plazo_entrega_dias'] : null,
            'compra_minima' => isset($data['compra_minima']) && $data['compra_minima'] !== '' ? number_format((float) $data['compra_minima'], 3, '.', '') : null,
            'activo' => (int) ($data['activo'] ?? 1),
            'observacion' => $this->nullable($data['observacion'] ?? null),
        ];
    }

    private function providerPriceParams(array $data): array
    {
        return [
            'empresa_id' => (int) $data['empresa_id'],
            'proveedor_id' => (int) $data['proveedor_id'],
            'producto_id' => (int) $data['producto_id'],
            'proveedor_producto_id' => $data['proveedor_producto_id'] ?? null,
            'precio_compra' => (int) $data['precio_compra'],
            'moneda' => trim((string) ($data['moneda'] ?? 'CLP')) ?: 'CLP',
            'unidad_compra' => trim((string) ($data['unidad_compra'] ?? 'UN')) ?: 'UN',
            'factor_conversion' => number_format((float) ($data['factor_conversion'] ?? 1), 4, '.', ''),
            'fecha_precio' => $data['fecha_precio'],
            'vigente_desde' => $data['vigente_desde'] ?? null,
            'vigente_hasta' => $data['vigente_hasta'] ?? null,
            'origen' => strtoupper((string) ($data['origen'] ?? 'MANUAL')),
            'compra_id' => $data['compra_id'] ?? null,
            'observacion' => $this->nullable($data['observacion'] ?? null),
            'activo' => (int) ($data['activo'] ?? 1),
        ];
    }

    private function fetchPriceRow(string $sql, array $params): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
