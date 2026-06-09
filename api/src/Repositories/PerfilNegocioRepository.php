<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class PerfilNegocioRepository
{
    public function __construct(private readonly PDO $db) {}

    // ── Capacidades ──────────────────────────────────────────────────────────

    public function capacidadesActivas(int $empresaId): array
    {
        $sql = '
            SELECT sc.codigo, sc.nombre, ec.activo
            FROM empresa_capacidades ec
            JOIN sistema_capacidades sc ON sc.id = ec.capacidad_id
            WHERE ec.empresa_id = :empresa_id
              AND sc.activo = 1
        ';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['empresa_id' => $empresaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsertCapacidad(int $empresaId, int $capacidadId, int $usuarioId): void
    {
        $sql = '
            INSERT INTO empresa_capacidades (empresa_id, capacidad_id, activo, activado_por, activado_at)
            VALUES (:empresa_id, :capacidad_id, 1, :usuario_id, NOW())
            ON DUPLICATE KEY UPDATE activo = 1, activado_por = :usuario_id, activado_at = NOW()
        ';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'empresa_id'   => $empresaId,
            'capacidad_id' => $capacidadId,
            'usuario_id'   => $usuarioId,
        ]);
    }

    public function findCapacidadesByPerfil(string $perfil): array
    {
        // Mapeo fijo de perfil → codigos de capacidades que activa
        $map = [
            'FARMACIA'    => ['CONTROL_RECETAS', 'LOTES_VENCIMIENTO', 'REPORTES_FARMACIA'],
            'ZAPATERIA'   => ['VARIANTES', 'REPORTES_ZAPATERIA'],
            'MINIMARKET'  => ['PRECIO_POR_PESO', 'MERMA_OPERATIVA', 'REPORTES_MINIMARKET'],
            'GENERICO'    => [],
        ];

        $codigos = $map[$perfil] ?? [];
        if ($codigos === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($codigos), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, codigo FROM sistema_capacidades WHERE codigo IN ($placeholders) AND activo = 1"
        );
        $stmt->execute($codigos);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findAllCapacidades(): array
    {
        $stmt = $this->db->query('SELECT id, codigo, nombre, descripcion FROM sistema_capacidades WHERE activo = 1 ORDER BY codigo');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca una capacidad del sistema por su código.
     * Retorna null si no existe o está inactiva en el catálogo.
     */
    public function findCapacidadByCode(string $codigo): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, codigo, nombre FROM sistema_capacidades WHERE codigo = :codigo AND activo = 1'
        );
        $stmt->execute(['codigo' => $codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Activa o desactiva una capacidad individual para la empresa.
     * Usa UPSERT: inserta si no existe, actualiza si ya estaba registrada.
     */
    public function toggleCapacidad(int $empresaId, int $capacidadId, bool $activo, int $usuarioId): void
    {
        $sql = '
            INSERT INTO empresa_capacidades (empresa_id, capacidad_id, activo, activado_por, activado_at)
            VALUES (:empresa_id, :capacidad_id, :activo, :usuario_id, NOW())
            ON DUPLICATE KEY UPDATE activo = :activo, activado_por = :usuario_id, activado_at = NOW()
        ';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'empresa_id'   => $empresaId,
            'capacidad_id' => $capacidadId,
            'activo'       => $activo ? 1 : 0,
            'usuario_id'   => $usuarioId,
        ]);
    }

    // ── Perfil ───────────────────────────────────────────────────────────────

    public function setPerfilNegocio(int $empresaId, string $perfil): void
    {
        $sql = '
            UPDATE empresa_configuracion_operativa
            SET perfil_negocio = :perfil
            WHERE empresa_id = :empresa_id
        ';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['perfil' => $perfil, 'empresa_id' => $empresaId]);
    }

    public function getPerfilNegocio(int $empresaId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT perfil_negocio FROM empresa_configuracion_operativa WHERE empresa_id = :empresa_id'
        );
        $stmt->execute(['empresa_id' => $empresaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['perfil_negocio'] : null;
    }

    // ── Plantillas de atributos ───────────────────────────────────────────────

    public function plantillasByPerfil(string $perfil): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sistema_atributos_plantilla WHERE perfil = :perfil ORDER BY orden'
        );
        $stmt->execute(['perfil' => $perfil]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertAtributoDefinicion(int $empresaId, array $plantilla): int
    {
        $sql = '
            INSERT IGNORE INTO producto_atributos_definicion
                (empresa_id, codigo, nombre, descripcion, tipo_dato, requerido,
                 filtrable, es_eje_variante, visible_en_listado, visible_en_pos, orden, activo)
            VALUES
                (:empresa_id, :codigo, :nombre, :descripcion, :tipo_dato, :requerido,
                 :filtrable, :es_eje_variante, :visible_en_listado, :visible_en_pos, :orden, 1)
        ';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'empresa_id'         => $empresaId,
            'codigo'             => $plantilla['codigo'],
            'nombre'             => $plantilla['nombre'],
            'descripcion'        => $plantilla['descripcion'],
            'tipo_dato'          => $plantilla['tipo_dato'],
            'requerido'          => $plantilla['requerido'],
            'filtrable'          => $plantilla['filtrable'],
            'es_eje_variante'    => (int) ($plantilla['es_eje_variante'] ?? 0),
            'visible_en_listado' => $plantilla['visible_en_listado'],
            'visible_en_pos'     => $plantilla['visible_en_pos'],
            'orden'              => $plantilla['orden'],
        ]);

        // Si INSERT IGNORE saltó por duplicado, buscar el id existente
        $stmt2 = $this->db->prepare(
            'SELECT id FROM producto_atributos_definicion WHERE empresa_id = :e AND codigo = :c'
        );
        $stmt2->execute(['e' => $empresaId, 'c' => $plantilla['codigo']]);
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['id'] : 0;
    }

    public function insertAtributoOpcion(int $empresaId, int $atributoId, array $opcion): void
    {
        $sql = '
            INSERT IGNORE INTO producto_atributos_opciones
                (empresa_id, atributo_id, valor, etiqueta, orden, activo)
            VALUES
                (:empresa_id, :atributo_id, :valor, :etiqueta, :orden, 1)
        ';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'empresa_id'  => $empresaId,
            'atributo_id' => $atributoId,
            'valor'       => $opcion['valor'],
            'etiqueta'    => $opcion['etiqueta'],
            'orden'       => $opcion['orden'],
        ]);
    }

    public function perfilesDisponibles(): array
    {
        return [
            ['codigo' => 'FARMACIA',   'nombre' => 'Farmacia',   'descripcion' => 'Medicamentos, principio activo, recetas, lotes y vencimientos'],
            ['codigo' => 'ZAPATERIA',  'nombre' => 'Zapatería',  'descripcion' => 'Calzado y vestuario con variantes de talla, color y temporada'],
            ['codigo' => 'MINIMARKET', 'nombre' => 'Minimarket', 'descripcion' => 'Abarrotes, perecibles, precio por peso y control de mermas'],
            ['codigo' => 'GENERICO',   'nombre' => 'Genérico',   'descripcion' => 'Catálogo base sin atributos predefinidos; agrega los que necesites'],
        ];
    }
}
