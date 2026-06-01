<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class ProductoAtributoRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function listDefinitions(int $empresaId, bool $includeInactive = false): array
    {
        $sql = 'SELECT id, empresa_id, codigo, nombre, descripcion, tipo_dato, requerido,
                       filtrable, visible_en_listado, visible_en_pos, orden, activo
                FROM producto_atributos_definicion
                WHERE empresa_id = :empresa_id';

        if (!$includeInactive) {
            $sql .= ' AND activo = 1';
        }

        $sql .= ' ORDER BY orden, nombre';
        $statement = $this->connection->prepare($sql);
        $statement->execute(['empresa_id' => $empresaId]);
        $definitions = $statement->fetchAll();

        if ($definitions === []) {
            return [];
        }

        $options = $this->optionsByAttribute($empresaId, array_map(static fn (array $row): int => (int) $row['id'], $definitions));

        foreach ($definitions as &$definition) {
            $definition['opciones'] = $options[(int) $definition['id']] ?? [];
        }

        return $definitions;
    }

    public function createDefinition(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO producto_atributos_definicion (
                empresa_id, codigo, nombre, descripcion, tipo_dato, requerido,
                filtrable, visible_en_listado, visible_en_pos, orden, activo
             ) VALUES (
                :empresa_id, :codigo, :nombre, :descripcion, :tipo_dato, :requerido,
                :filtrable, :visible_en_listado, :visible_en_pos, :orden, :activo
             )'
        );
        $statement->execute($this->definitionParams($data));

        $id = (int) $this->connection->lastInsertId();
        $this->replaceOptions($id, (int) $data['empresa_id'], $data['opciones'] ?? []);

        return $id;
    }

    public function updateDefinition(int $id, int $empresaId, array $data): bool
    {
        $params = $this->definitionParams(['empresa_id' => $empresaId] + $data);
        $params['id'] = $id;
        $statement = $this->connection->prepare(
            'UPDATE producto_atributos_definicion
             SET codigo = :codigo,
                 nombre = :nombre,
                 descripcion = :descripcion,
                 tipo_dato = :tipo_dato,
                 requerido = :requerido,
                 filtrable = :filtrable,
                 visible_en_listado = :visible_en_listado,
                 visible_en_pos = :visible_en_pos,
                 orden = :orden,
                 activo = :activo,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute($params);
        $updated = $statement->rowCount() > 0;

        if (array_key_exists('opciones', $data)) {
            $this->replaceOptions($id, $empresaId, $data['opciones']);
            $updated = true;
        }

        return $updated;
    }

    public function deactivateDefinition(int $id, int $empresaId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE producto_atributos_definicion
             SET activo = 0, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $id, 'empresa_id' => $empresaId]);

        return $statement->rowCount() > 0;
    }

    public function productExists(int $productoId, int $empresaId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM productos WHERE id = :id AND empresa_id = :empresa_id LIMIT 1');
        $statement->execute(['id' => $productoId, 'empresa_id' => $empresaId]);

        return (bool) $statement->fetchColumn();
    }

    public function definitionsByIds(int $empresaId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->connection->prepare(
            'SELECT id, empresa_id, codigo, nombre, tipo_dato, requerido, activo
             FROM producto_atributos_definicion
             WHERE empresa_id = ? AND id IN (' . $placeholders . ')'
        );
        $statement->execute([$empresaId, ...$ids]);
        $definitions = [];

        foreach ($statement->fetchAll() as $row) {
            $definitions[(int) $row['id']] = $row;
        }

        return $definitions;
    }

    public function valuesForProduct(int $productoId, int $empresaId): array
    {
        $statement = $this->connection->prepare(
            'SELECT d.id AS atributo_id, d.codigo, d.nombre, d.descripcion, d.tipo_dato,
                    d.requerido, d.filtrable, d.visible_en_listado, d.visible_en_pos,
                    v.valor_texto, v.valor_numero, v.valor_decimal, v.valor_fecha,
                    v.valor_booleano, v.valor_json
             FROM producto_atributos_definicion d
             LEFT JOIN producto_atributos_valores v ON v.atributo_id = d.id
                AND v.producto_id = :producto_id AND v.empresa_id = d.empresa_id
             WHERE d.empresa_id = :empresa_id AND d.activo = 1
             ORDER BY d.orden, d.nombre'
        );
        $statement->execute(['producto_id' => $productoId, 'empresa_id' => $empresaId]);
        $rows = $statement->fetchAll();
        $options = $this->optionsByAttribute($empresaId, array_map(static fn (array $row): int => (int) $row['atributo_id'], $rows));

        foreach ($rows as &$row) {
            $row['valor'] = $this->valueFromRow($row);
            $row['opciones'] = $options[(int) $row['atributo_id']] ?? [];
        }

        return $rows;
    }

    public function replaceValues(int $productoId, int $empresaId, array $valuesByAttribute): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO producto_atributos_valores (
                empresa_id, producto_id, atributo_id, valor_texto, valor_numero,
                valor_decimal, valor_fecha, valor_booleano, valor_json
             ) VALUES (
                :empresa_id, :producto_id, :atributo_id, :valor_texto, :valor_numero,
                :valor_decimal, :valor_fecha, :valor_booleano, :valor_json
             )
             ON DUPLICATE KEY UPDATE
                valor_texto = VALUES(valor_texto),
                valor_numero = VALUES(valor_numero),
                valor_decimal = VALUES(valor_decimal),
                valor_fecha = VALUES(valor_fecha),
                valor_booleano = VALUES(valor_booleano),
                valor_json = VALUES(valor_json),
                updated_at = CURRENT_TIMESTAMP'
        );

        foreach ($valuesByAttribute as $attributeId => $params) {
            $statement->execute([
                'empresa_id' => $empresaId,
                'producto_id' => $productoId,
                'atributo_id' => $attributeId,
                'valor_texto' => $params['valor_texto'] ?? null,
                'valor_numero' => $params['valor_numero'] ?? null,
                'valor_decimal' => $params['valor_decimal'] ?? null,
                'valor_fecha' => $params['valor_fecha'] ?? null,
                'valor_booleano' => $params['valor_booleano'] ?? null,
                'valor_json' => $params['valor_json'] ?? null,
            ]);
        }
    }

    private function replaceOptions(int $attributeId, int $empresaId, mixed $options): void
    {
        $this->connection->prepare(
            'UPDATE producto_atributos_opciones
             SET activo = 0, updated_at = CURRENT_TIMESTAMP
             WHERE atributo_id = :atributo_id AND empresa_id = :empresa_id'
        )->execute(['atributo_id' => $attributeId, 'empresa_id' => $empresaId]);

        if (!is_array($options)) {
            return;
        }

        $statement = $this->connection->prepare(
            'INSERT INTO producto_atributos_opciones (empresa_id, atributo_id, valor, etiqueta, orden, activo)
             VALUES (:empresa_id, :atributo_id, :valor, :etiqueta, :orden, 1)
             ON DUPLICATE KEY UPDATE
                etiqueta = VALUES(etiqueta),
                orden = VALUES(orden),
                activo = 1,
                updated_at = CURRENT_TIMESTAMP'
        );

        foreach (array_values($options) as $index => $option) {
            if (!is_array($option)) {
                continue;
            }

            $value = trim((string) ($option['valor'] ?? ''));
            $label = trim((string) ($option['etiqueta'] ?? $value));

            if ($value === '') {
                continue;
            }

            $statement->execute([
                'empresa_id' => $empresaId,
                'atributo_id' => $attributeId,
                'valor' => $value,
                'etiqueta' => $label !== '' ? $label : $value,
                'orden' => (int) ($option['orden'] ?? ($index + 1)),
            ]);
        }
    }

    private function optionsByAttribute(int $empresaId, array $attributeIds): array
    {
        $attributeIds = array_values(array_unique(array_filter($attributeIds)));

        if ($attributeIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($attributeIds), '?'));
        $statement = $this->connection->prepare(
            'SELECT id, atributo_id, valor, etiqueta, orden, activo
             FROM producto_atributos_opciones
             WHERE empresa_id = ? AND activo = 1 AND atributo_id IN (' . $placeholders . ')
             ORDER BY atributo_id, orden, etiqueta'
        );
        $statement->execute([$empresaId, ...$attributeIds]);
        $options = [];

        foreach ($statement->fetchAll() as $row) {
            $options[(int) $row['atributo_id']][] = $row;
        }

        return $options;
    }

    private function definitionParams(array $data): array
    {
        return [
            'empresa_id' => (int) $data['empresa_id'],
            'codigo' => $data['codigo'],
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'tipo_dato' => $data['tipo_dato'],
            'requerido' => (int) ($data['requerido'] ?? 0),
            'filtrable' => (int) ($data['filtrable'] ?? 0),
            'visible_en_listado' => (int) ($data['visible_en_listado'] ?? 0),
            'visible_en_pos' => (int) ($data['visible_en_pos'] ?? 0),
            'orden' => (int) ($data['orden'] ?? 1),
            'activo' => (int) ($data['activo'] ?? 1),
        ];
    }

    private function valueFromRow(array $row): mixed
    {
        return match ($row['tipo_dato']) {
            'NUMERO' => $row['valor_numero'] !== null ? (int) $row['valor_numero'] : null,
            'DECIMAL' => $row['valor_decimal'] !== null ? (float) $row['valor_decimal'] : null,
            'FECHA' => $row['valor_fecha'],
            'BOOLEANO' => $row['valor_booleano'] !== null ? (bool) $row['valor_booleano'] : null,
            'MULTIOPCION' => $row['valor_json'] !== null ? json_decode((string) $row['valor_json'], true) : null,
            default => $row['valor_texto'],
        };
    }
}
