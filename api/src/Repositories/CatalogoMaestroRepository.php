<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class CatalogoMaestroRepository
{
    public function __construct(private readonly PDO $db) {}

    public function upsertCatalogo(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO catalogos_maestros (codigo, nombre, perfil, version, estado, descripcion)
             VALUES (:codigo, :nombre, :perfil, :version, :estado, :descripcion)
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), perfil = VALUES(perfil),
                 version = VALUES(version), estado = VALUES(estado), descripcion = VALUES(descripcion)'
        );
        $stmt->execute($data);

        $find = $this->db->prepare('SELECT id FROM catalogos_maestros WHERE codigo = :codigo LIMIT 1');
        $find->execute(['codigo' => $data['codigo']]);
        return (int) $find->fetchColumn();
    }

    public function productId(int $catalogoId, string $externalId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM catalogo_maestro_productos
             WHERE catalogo_id = :catalogo_id AND producto_externo_id = :external_id LIMIT 1'
        );
        $stmt->execute(['catalogo_id' => $catalogoId, 'external_id' => $externalId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function upsertProduct(int $catalogoId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO catalogo_maestro_productos (
                catalogo_id, producto_externo_id, codigo_referencia, nombre, descripcion,
                unidad_medida, categoria, familia, laboratorio, principio_activo, dosis,
                titular, presentacion, bioequivalente, cenabast, estado_revision,
                metadata_json, source_hash
             ) VALUES (
                :catalogo_id, :producto_externo_id, :codigo_referencia, :nombre, :descripcion,
                :unidad_medida, :categoria, :familia, :laboratorio, :principio_activo, :dosis,
                :titular, :presentacion, :bioequivalente, :cenabast, :estado_revision,
                :metadata_json, :source_hash
             )
             ON DUPLICATE KEY UPDATE codigo_referencia = VALUES(codigo_referencia),
                id = LAST_INSERT_ID(id),
                nombre = VALUES(nombre), descripcion = VALUES(descripcion),
                unidad_medida = VALUES(unidad_medida), categoria = VALUES(categoria),
                familia = VALUES(familia), laboratorio = VALUES(laboratorio),
                principio_activo = VALUES(principio_activo), dosis = VALUES(dosis),
                titular = VALUES(titular), presentacion = VALUES(presentacion),
                bioequivalente = VALUES(bioequivalente), cenabast = VALUES(cenabast),
                estado_revision = VALUES(estado_revision), metadata_json = VALUES(metadata_json),
                source_hash = VALUES(source_hash)'
        );
        $stmt->execute(['catalogo_id' => $catalogoId, ...$data]);
        return (int) $this->db->lastInsertId();
    }

    public function barcodeProductId(int $catalogoId, string $barcode): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT catalogo_producto_id FROM catalogo_maestro_codigos_barra
             WHERE catalogo_id = :catalogo_id AND codigo_barra = :codigo_barra LIMIT 1'
        );
        $stmt->execute(['catalogo_id' => $catalogoId, 'codigo_barra' => $barcode]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function productExternalIds(int $catalogoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT producto_externo_id, id FROM catalogo_maestro_productos WHERE catalogo_id = :catalogo_id'
        );
        $stmt->execute(['catalogo_id' => $catalogoId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['producto_externo_id']] = (int) $row['id'];
        }
        return $map;
    }

    public function barcodeProductMap(int $catalogoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT codigo_barra, catalogo_producto_id FROM catalogo_maestro_codigos_barra WHERE catalogo_id = :catalogo_id'
        );
        $stmt->execute(['catalogo_id' => $catalogoId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['codigo_barra']] = (int) $row['catalogo_producto_id'];
        }
        return $map;
    }

    public function upsertBarcode(int $catalogoId, int $productId, array $data): void
    {
        if ((int) $data['principal'] === 1) {
            $stmt = $this->db->prepare(
                'UPDATE catalogo_maestro_codigos_barra
                 SET principal = 0
                 WHERE catalogo_id = :catalogo_id AND catalogo_producto_id = :producto_id'
            );
            $stmt->execute(['catalogo_id' => $catalogoId, 'producto_id' => $productId]);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO catalogo_maestro_codigos_barra (
                catalogo_id, catalogo_producto_id, codigo_barra, tipo_codigo,
                principal, activo, descripcion, source_hash
             ) VALUES (
                :catalogo_id, :catalogo_producto_id, :codigo_barra, :tipo_codigo,
                :principal, :activo, :descripcion, :source_hash
             )
             ON DUPLICATE KEY UPDATE tipo_codigo = VALUES(tipo_codigo),
                principal = VALUES(principal), activo = VALUES(activo),
                descripcion = VALUES(descripcion), source_hash = VALUES(source_hash)'
        );
        $stmt->execute([
            'catalogo_id' => $catalogoId,
            'catalogo_producto_id' => $productId,
            ...$data,
        ]);
    }

    public function createImport(int $catalogoId, string $origin, string $hash, array $metadata): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO catalogo_maestro_importaciones
                (catalogo_id, origen, source_hash, metadata_json)
             VALUES (:catalogo_id, :origen, :source_hash, :metadata_json)'
        );
        $stmt->execute([
            'catalogo_id' => $catalogoId,
            'origen' => $origin,
            'source_hash' => $hash,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function finishImport(int $id, array $metrics): void
    {
        $stmt = $this->db->prepare(
            'UPDATE catalogo_maestro_importaciones
             SET estado = :estado, productos_procesados = :productos_procesados,
                 productos_creados = :productos_creados, productos_actualizados = :productos_actualizados,
                 codigos_procesados = :codigos_procesados, codigos_creados = :codigos_creados,
                 codigos_actualizados = :codigos_actualizados, errores = :errores,
                 applied_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id, ...$metrics]);
    }

    public function search(int $catalogoId, string $query, int $limit): array
    {
        $like = '%' . $query . '%';
        $stmt = $this->db->prepare(
            "SELECT p.id, p.producto_externo_id, p.codigo_referencia, p.nombre, p.categoria,
                    p.laboratorio, p.principio_activo, p.dosis, p.titular, p.presentacion,
                    p.bioequivalente, p.cenabast, p.estado_revision, p.metadata_json
             FROM catalogo_maestro_productos p
             WHERE p.catalogo_id = :catalogo_id
               AND p.estado_revision <> 'INACTIVO'
               AND (p.nombre LIKE :q_nombre OR p.principio_activo LIKE :q_principio
                    OR p.producto_externo_id = :q_exact OR p.codigo_referencia = :q_exact2
                    OR EXISTS (
                        SELECT 1 FROM catalogo_maestro_codigos_barra cb
                        WHERE cb.catalogo_id = p.catalogo_id
                          AND cb.catalogo_producto_id = p.id
                          AND cb.activo = 1
                          AND cb.codigo_barra = :q_barra
                    ))
             ORDER BY p.nombre
             LIMIT {$limit}"
        );
        $stmt->execute([
            'catalogo_id' => $catalogoId,
            'q_nombre' => $like,
            'q_principio' => $like,
            'q_exact' => $query,
            'q_exact2' => $query,
            'q_barra' => $query,
        ]);
        return $stmt->fetchAll();
    }

    public function findByBarcode(int $catalogoId, string $barcode): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.producto_externo_id, p.codigo_referencia, p.nombre, p.categoria,
                    p.laboratorio, p.principio_activo, p.dosis, p.titular, p.presentacion,
                    p.bioequivalente, p.cenabast, p.estado_revision, p.metadata_json,
                    c.codigo_barra, c.tipo_codigo, c.principal
             FROM catalogo_maestro_codigos_barra c
             INNER JOIN catalogo_maestro_productos p ON p.id = c.catalogo_producto_id
             WHERE c.catalogo_id = :catalogo_id AND c.codigo_barra = :codigo_barra AND c.activo = 1
             LIMIT 1'
        );
        $stmt->execute(['catalogo_id' => $catalogoId, 'codigo_barra' => $barcode]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function findProduct(int $catalogoId, int $productId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, catalogo_id, producto_externo_id, codigo_referencia, nombre, descripcion,
                    unidad_medida, categoria, familia, laboratorio, principio_activo, dosis,
                    titular, presentacion, bioequivalente, cenabast, estado_revision, metadata_json
             FROM catalogo_maestro_productos
             WHERE id = :id AND catalogo_id = :catalogo_id AND estado_revision <> "INACTIVO"
             LIMIT 1'
        );
        $stmt->execute(['id' => $productId, 'catalogo_id' => $catalogoId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function productBarcodes(int $catalogoId, int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT codigo_barra, tipo_codigo, principal, descripcion
             FROM catalogo_maestro_codigos_barra
             WHERE catalogo_id = :catalogo_id AND catalogo_producto_id = :producto_id AND activo = 1
             ORDER BY principal DESC, id'
        );
        $stmt->execute(['catalogo_id' => $catalogoId, 'producto_id' => $productId]);
        return $stmt->fetchAll();
    }

    public function findCompanyLink(int $empresaId, int $catalogProductId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT v.id, v.empresa_id, v.catalogo_id, v.catalogo_producto_id, v.producto_id,
                    p.codigo, p.nombre, p.precio_costo, p.precio_venta, p.activo
             FROM catalogo_maestro_productos_empresa v
             INNER JOIN productos p ON p.id = v.producto_id AND p.empresa_id = v.empresa_id
             WHERE v.empresa_id = :empresa_id AND v.catalogo_producto_id = :catalogo_producto_id
             LIMIT 1'
        );
        $stmt->execute(['empresa_id' => $empresaId, 'catalogo_producto_id' => $catalogProductId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function createCompanyLink(
        int $empresaId,
        int $catalogId,
        int $catalogProductId,
        int $productId,
        int $userId
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO catalogo_maestro_productos_empresa
                (empresa_id, catalogo_id, catalogo_producto_id, producto_id, created_by)
             VALUES (:empresa_id, :catalogo_id, :catalogo_producto_id, :producto_id, :created_by)'
        );
        $stmt->execute([
            'empresa_id' => $empresaId,
            'catalogo_id' => $catalogId,
            'catalogo_producto_id' => $catalogProductId,
            'producto_id' => $productId,
            'created_by' => $userId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function metrics(int $catalogoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                (SELECT COUNT(*) FROM catalogo_maestro_productos WHERE catalogo_id = :catalogo_productos) AS productos,
                (SELECT COUNT(*) FROM catalogo_maestro_codigos_barra WHERE catalogo_id = :catalogo_codigos AND activo = 1) AS codigos,
                (SELECT COUNT(*) FROM catalogo_maestro_productos WHERE catalogo_id = :catalogo_principio AND principio_activo IS NOT NULL AND principio_activo <> "") AS con_principio_activo,
                (SELECT COUNT(*) FROM catalogo_maestro_productos WHERE catalogo_id = :catalogo_observados AND estado_revision = "OBSERVADO") AS observados'
        );
        $stmt->execute([
            'catalogo_productos' => $catalogoId,
            'catalogo_codigos' => $catalogoId,
            'catalogo_principio' => $catalogoId,
            'catalogo_observados' => $catalogoId,
        ]);
        return $stmt->fetch() ?: [];
    }

    public function catalogId(string $code): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM catalogos_maestros WHERE codigo = :codigo LIMIT 1');
        $stmt->execute(['codigo' => $code]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
