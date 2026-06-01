<?php
declare(strict_types=1);

namespace App\Repositories;

class SaaSRepository extends BaseRepository
{
    public function getSuscripcion(int $empresaId): ?array
    {
        $sql = "SELECT * FROM saas_suscripcion WHERE empresa_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId]);
        return $stmt->fetch() ?: null;
    }

    public function actualizarSuscripcion(int $empresaId, array $data): void
    {
        // Upsert logic
        $sql = "INSERT INTO saas_suscripcion (empresa_id, plan, cuota_mensual, dia_corte, estado_pago)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                plan = VALUES(plan), cuota_mensual = VALUES(cuota_mensual), 
                dia_corte = VALUES(dia_corte), estado_pago = VALUES(estado_pago)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $empresaId,
            $data['plan'] ?? 'basico',
            $data['cuota_mensual'] ?? 0,
            $data['dia_corte'] ?? 5,
            $data['estado_pago'] ?? 'al_dia'
        ]);
    }

    public function getFeatureToggles(int $empresaId): array
    {
        $sql = "SELECT feature, valor FROM saas_feature_toggles WHERE empresa_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId]);
        $rows = $stmt->fetchAll();
        $toggles = [];
        foreach ($rows as $row) {
            $toggles[$row['feature']] = $row['valor'];
        }
        return $toggles;
    }

    public function setFeatureToggle(int $empresaId, string $feature, string $valor): void
    {
        $sql = "INSERT INTO saas_feature_toggles (empresa_id, feature, valor)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE valor = VALUES(valor)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId, $feature, $valor]);
    }

    public function getContacto(int $empresaId): ?array
    {
        $sql = "SELECT * FROM saas_contacto WHERE empresa_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId]);
        return $stmt->fetch() ?: null;
    }
}
