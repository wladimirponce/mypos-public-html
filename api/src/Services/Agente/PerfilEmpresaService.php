<?php

declare(strict_types=1);

namespace Mypos\Services\Agente;

use Mypos\Config\Database;
use PDO;

/**
 * Perfil compacto de la empresa para el prompt del agente IA (Fase 2).
 * SOLO LECTURA. Lo consume agent/empresa_profile.py (cacheado 10 min):
 * con esto el LLM sabe a quién le habla (nombre, rubro, sucursales,
 * ambiente SII, folios y estado de suscripción) sin gastar tools.
 */
final class PerfilEmpresaService
{
    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? Database::connection();
    }

    /** @return array<string, mixed> */
    public function perfil(int $empresaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT razon_social, nombre_fantasia, giro FROM empresas WHERE id = :empresa_id'
        );
        $stmt->execute([':empresa_id' => $empresaId]);
        $empresa = $stmt->fetch() ?: [];

        $stmt = $this->db->prepare(
            'SELECT nombre FROM sucursales WHERE empresa_id = :empresa_id AND activo = 1 ORDER BY id LIMIT 6'
        );
        $stmt->execute([':empresa_id' => $empresaId]);
        $sucursales = array_map(static fn (array $r): string => (string) $r['nombre'], $stmt->fetchAll());

        $suscripcion = [];
        try {
            $stmt = $this->db->prepare(
                'SELECT plan_id, estado, DATE(fecha_fin) AS fecha_fin
                 FROM empresas_suscripcion WHERE empresa_id = :empresa_id'
            );
            $stmt->execute([':empresa_id' => $empresaId]);
            $suscripcion = $stmt->fetch() ?: [];
        } catch (\Throwable) {
            // tabla opcional segun instalacion
        }

        $ambiente = '';
        try {
            $stmt = $this->db->prepare(
                'SELECT ambiente FROM dte_configuracion WHERE empresa_id = :empresa_id LIMIT 1'
            );
            $stmt->execute([':empresa_id' => $empresaId]);
            $ambiente = (string) ($stmt->fetch()['ambiente'] ?? '');
        } catch (\Throwable) {
            // sin DTE configurado
        }

        $folios = [];
        try {
            $stmt = $this->db->prepare(
                'SELECT tipo_documento,
                        SUM(CASE WHEN estado = \'ACTIVA\'
                            THEN GREATEST(folio_hasta - folio_actual + 1, 0) ELSE 0 END) AS disponibles
                 FROM folios_asignaciones
                 WHERE empresa_id = :empresa_id
                 GROUP BY tipo_documento'
            );
            $stmt->execute([':empresa_id' => $empresaId]);
            foreach ($stmt->fetchAll() as $row) {
                $folios[(string) $row['tipo_documento']] = (int) $row['disponibles'];
            }
        } catch (\Throwable) {
            // sin folios
        }

        return [
            'razon_social' => (string) ($empresa['razon_social'] ?? ''),
            'nombre_fantasia' => (string) ($empresa['nombre_fantasia'] ?? ''),
            'giro' => (string) ($empresa['giro'] ?? ''),
            'sucursales' => $sucursales,
            'suscripcion' => [
                'plan' => (string) ($suscripcion['plan_id'] ?? ''),
                'estado' => (string) ($suscripcion['estado'] ?? ''),
                'fecha_fin' => (string) ($suscripcion['fecha_fin'] ?? ''),
            ],
            'ambiente_sii' => $ambiente,
            'folios_disponibles' => $folios,
        ];
    }
}
