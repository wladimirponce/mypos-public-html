<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class ImportacionCatalogoRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function createImport(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO importaciones_catalogo (
                empresa_id, usuario_id, origen, tipo, estado, archivo_subido_id,
                total_items, metadata_json
             ) VALUES (
                :empresa_id, :usuario_id, :origen, :tipo, "CARGADO", :archivo_subido_id,
                :total_items, :metadata_json
             )'
        );
        $statement->execute([
            'empresa_id' => $data['empresa_id'],
            'usuario_id' => $data['usuario_id'],
            'origen' => $data['origen'],
            'tipo' => $data['tipo'],
            'archivo_subido_id' => $data['archivo_subido_id'] ?? null,
            'total_items' => $data['total_items'],
            'metadata_json' => $data['metadata_json'] ?? null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function insertItem(int $importId, int $empresaId, int $line, array $raw): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO importacion_catalogo_items (
                empresa_id, importacion_id, linea, raw_json, codigo_interno,
                codigo_barra, nombre, descripcion, precio_compra, precio_venta,
                proveedor_identificado
             ) VALUES (
                :empresa_id, :importacion_id, :linea, :raw_json, :codigo_interno,
                :codigo_barra, :nombre, :descripcion, :precio_compra, :precio_venta,
                :proveedor_identificado
             )'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'importacion_id' => $importId,
            'linea' => $line,
            'raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'codigo_interno' => $this->text($raw['codigo_interno'] ?? $raw['codigo'] ?? null),
            'codigo_barra' => $this->text($raw['codigo_barra'] ?? $raw['barra'] ?? null),
            'nombre' => $this->text($raw['nombre'] ?? $raw['descripcion'] ?? null),
            'descripcion' => $this->text($raw['descripcion_larga'] ?? $raw['detalle'] ?? null),
            'precio_compra' => $this->money($raw['precio_compra'] ?? $raw['valor_compra'] ?? $raw['costo'] ?? null),
            'precio_venta' => $this->money($raw['precio_venta'] ?? $raw['valor_venta'] ?? $raw['venta'] ?? null),
            'proveedor_identificado' => $this->text($raw['proveedor'] ?? $raw['proveedor_identificado'] ?? null),
        ]);
    }

    public function listImports(int $empresaId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, usuario_id, origen, tipo, estado, archivo_subido_id,
                    total_items, total_validos, total_errores, metadata_json, created_at,
                    updated_at, applied_at
             FROM importaciones_catalogo
             WHERE empresa_id = :empresa_id
             ORDER BY id DESC
             LIMIT 100'
        );
        $statement->execute(['empresa_id' => $empresaId]);

        return $statement->fetchAll();
    }

    public function findImport(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, usuario_id, origen, tipo, estado, archivo_subido_id,
                    total_items, total_validos, total_errores, metadata_json, created_at,
                    updated_at, applied_at
             FROM importaciones_catalogo
             WHERE empresa_id = :empresa_id AND id = :id
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function items(int $empresaId, int $importId, int $limit = 300): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, importacion_id, linea, raw_json, codigo_interno,
                    codigo_barra, nombre, descripcion, precio_compra, precio_venta,
                    proveedor_identificado, producto_id_detectado, accion_sugerida,
                    estado, errores_json
             FROM importacion_catalogo_items
             WHERE empresa_id = :empresa_id AND importacion_id = :importacion_id
             ORDER BY linea
             LIMIT ' . max(1, min($limit, 1000))
        );
        $statement->execute(['empresa_id' => $empresaId, 'importacion_id' => $importId]);

        return $statement->fetchAll();
    }

    public function allItemsForValidation(int $empresaId, int $importId): array
    {
        return $this->items($empresaId, $importId, 1000);
    }

    public function validItemsForApply(int $empresaId, int $importId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, importacion_id, linea, raw_json, codigo_interno,
                    codigo_barra, nombre, descripcion, precio_compra, precio_venta,
                    proveedor_identificado, producto_id_detectado, accion_sugerida,
                    estado, errores_json
             FROM importacion_catalogo_items
             WHERE empresa_id = :empresa_id
               AND importacion_id = :importacion_id
               AND estado = "VALIDO"
               AND accion_sugerida IN ("CREAR", "ACTUALIZAR", "ASOCIAR_CODIGO")
             ORDER BY linea'
        );
        $statement->execute(['empresa_id' => $empresaId, 'importacion_id' => $importId]);

        return $statement->fetchAll();
    }

    public function productByInternalCode(int $empresaId, string $code): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM productos
             WHERE empresa_id = :empresa_id AND activo = 1 AND (codigo = :code_codigo OR sku = :code_sku)
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'code_codigo' => $code, 'code_sku' => $code]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function productByBarcode(int $empresaId, string $barcode): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT producto_id FROM productos_codigos_barra
             WHERE empresa_id = :empresa_id AND codigo_barra = :barcode AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'barcode' => $barcode]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function updateItemValidation(int $id, int $empresaId, ?int $productId, string $action, string $status, array $errors): void
    {
        $statement = $this->connection->prepare(
            'UPDATE importacion_catalogo_items
             SET producto_id_detectado = :producto_id_detectado,
                 accion_sugerida = :accion_sugerida,
                 estado = :estado,
                 errores_json = :errores_json,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute([
            'id' => $id,
            'empresa_id' => $empresaId,
            'producto_id_detectado' => $productId,
            'accion_sugerida' => $action,
            'estado' => $status,
            'errores_json' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function updateImportTotals(int $id, int $empresaId, int $valid, int $errors): void
    {
        $statement = $this->connection->prepare(
            'UPDATE importaciones_catalogo
             SET total_validos = :total_validos,
                 total_errores = :total_errores,
                 estado = :estado,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute([
            'id' => $id,
            'empresa_id' => $empresaId,
            'total_validos' => $valid,
            'total_errores' => $errors,
            'estado' => $errors > 0 ? 'CON_ERRORES' : 'VALIDADO',
        ]);
    }

    public function markItemApplied(int $id, int $empresaId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE importacion_catalogo_items
             SET estado = "APLICADO",
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
    }

    public function markImportApplied(int $id, int $empresaId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE importaciones_catalogo
             SET estado = "APLICADO",
                 applied_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function money(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) round((float) $value) : null;
    }
}
