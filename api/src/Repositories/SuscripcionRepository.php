<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;
use Exception;

class SuscripcionRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function createOrder(
        string $ordenNumero,
        int $empresaId,
        int $usuarioId,
        string $gateway,
        string $planId,
        float $monto,
        string $moneda
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO suscripciones_ordenes (orden_numero, empresa_id, usuario_id, gateway, plan_id, monto, moneda, estado)
             VALUES (:orden_numero, :empresa_id, :usuario_id, :gateway, :plan_id, :monto, :moneda, "pendiente")'
        );

        $statement->execute([
            'orden_numero' => $ordenNumero,
            'empresa_id' => $empresaId,
            'usuario_id' => $usuarioId,
            'gateway' => $gateway,
            'plan_id' => $planId,
            'monto' => $monto,
            'moneda' => $moneda,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateOrderTokenFlow(int $ordenId, string $token): void
    {
        $statement = $this->connection->prepare(
            'UPDATE suscripciones_ordenes SET token_externo = :token WHERE id = :id'
        );
        $statement->execute(['token' => $token, 'id' => $ordenId]);
    }

    public function updateOrderTokenPayPal(int $ordenId, string $orderIdExterno): void
    {
        $statement = $this->connection->prepare(
            'UPDATE suscripciones_ordenes SET order_id_externo = :order_id_externo WHERE id = :id'
        );
        $statement->execute(['order_id_externo' => $orderIdExterno, 'id' => $ordenId]);
    }

    public function getOrderByFlowToken(string $token): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM suscripciones_ordenes WHERE token_externo = :token LIMIT 1'
        );
        $statement->execute(['token' => $token]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getOrderByPayPalOrderId(string $orderId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM suscripciones_ordenes WHERE order_id_externo = :order_id LIMIT 1'
        );
        $statement->execute(['order_id' => $orderId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function markOrderCompleted(int $ordenId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE suscripciones_ordenes SET estado = "completado" WHERE id = :id'
        );
        $statement->execute(['id' => $ordenId]);
    }

    public function updateOrActivateSubscription(int $empresaId, string $planId): void
    {
        // 1 month subscription
        $statement = $this->connection->prepare(
            'INSERT INTO empresas_suscripcion (empresa_id, plan_id, fecha_inicio, fecha_fin, estado)
             VALUES (:empresa_id, :plan_id, NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), "activa")
             ON DUPLICATE KEY UPDATE
                plan_id = VALUES(plan_id),
                fecha_fin = DATE_ADD(GREATEST(fecha_fin, NOW()), INTERVAL 1 MONTH),
                estado = "activa"'
        );

        $statement->execute([
            'empresa_id' => $empresaId,
            'plan_id' => $planId,
        ]);
    }

    public function getSubscriptionStatus(int $empresaId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM empresas_suscripcion WHERE empresa_id = :empresa_id LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
