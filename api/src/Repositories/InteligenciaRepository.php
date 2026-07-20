<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class InteligenciaRepository
{
    public function __construct(private readonly PDO $db) {}

    public function registrarFeedback(int $empresaId, int $usuarioId, string $origen, int $referenciaId, string $valor, ?string $comentario): int
    {
        $stmt = $this->db->prepare('INSERT INTO inteligencia_feedback (empresa_id,usuario_id,origen,referencia_id,valor,comentario) VALUES (:empresa_id,:usuario_id,:origen,:referencia_id,:valor,:comentario)');
        $stmt->execute([
            'empresa_id' => $empresaId,
            'usuario_id' => $usuarioId,
            'origen' => $origen,
            'referencia_id' => $referenciaId,
            'valor' => $valor,
            'comentario' => $comentario,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function alertaPerteneceAEmpresa(int $empresaId, int $alertaId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM agente_alertas_log WHERE id=:id AND empresa_id=:empresa_id LIMIT 1');
        $stmt->execute(['id' => $alertaId, 'empresa_id' => $empresaId]);
        return $stmt->fetchColumn() !== false;
    }

    public function compraPerteneceAEmpresa(int $empresaId, int $compraId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM compras WHERE id=:id AND empresa_id=:empresa_id LIMIT 1');
        $stmt->execute(['id' => $compraId, 'empresa_id' => $empresaId]);
        return $stmt->fetchColumn() !== false;
    }

    public function alertas(int $empresaId, int $limit): array
    {
        $stmt = $this->db->prepare("SELECT l.id,l.tipo,l.estado,l.mensaje,l.detalle_json,l.created_at,
                    (SELECT f.valor FROM inteligencia_feedback f WHERE f.empresa_id=l.empresa_id AND f.origen='ALERTA' AND f.referencia_id=l.id ORDER BY f.id DESC LIMIT 1) feedback
             FROM agente_alertas_log l WHERE l.empresa_id=:empresa_id ORDER BY l.id DESC LIMIT {$limit}");
        $stmt->execute(['empresa_id' => $empresaId]);
        return $stmt->fetchAll();
    }

    public function impacto(int $empresaId, int $dias): array
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) alertas,SUM(estado='enviada') enviadas,SUM(estado='fallida') fallidas FROM agente_alertas_log WHERE empresa_id=:empresa_alertas AND created_at>=DATE_SUB(NOW(),INTERVAL {$dias} DAY)");
        $stmt->execute(['empresa_alertas' => $empresaId]);
        $alertas = $stmt->fetch() ?: [];
        $stmt = $this->db->prepare("SELECT valor,COUNT(*) cantidad FROM inteligencia_feedback WHERE empresa_id=:empresa_feedback AND created_at>=DATE_SUB(NOW(),INTERVAL {$dias} DAY) GROUP BY valor");
        $stmt->execute(['empresa_feedback' => $empresaId]);
        $feedback = [];
        foreach ($stmt->fetchAll() as $row) $feedback[(string) $row['valor']] = (int) $row['cantidad'];
        $stmt = $this->db->prepare("SELECT COUNT(*) borradores,COALESCE(SUM(total),0) total FROM compras WHERE empresa_id=:empresa_compras AND estado='BORRADOR' AND observacion='Borrador generado por compras inteligentes' AND created_at>=DATE_SUB(NOW(),INTERVAL {$dias} DAY)");
        $stmt->execute(['empresa_compras' => $empresaId]);
        return ['alertas' => $alertas, 'feedback' => $feedback, 'compras_inteligentes' => $stmt->fetch() ?: []];
    }
}
