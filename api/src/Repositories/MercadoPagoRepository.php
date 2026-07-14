<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

/**
 * Acceso a las tablas de la integracion MercadoPago Point:
 * mercadopago_config / _terminales / _intentos / _webhook_events.
 *
 * Se construye con una PDO concreta para poder participar en la transaccion de
 * la venta (consumo del intento con FOR UPDATE), igual que ValeCreditoRepository.
 */
final class MercadoPagoRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    // ── Config por empresa ──────────────────────────────────────────────────

    public function getConfig(int $empresaId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM mercadopago_config WHERE empresa_id = :empresa_id LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function upsertConfig(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO mercadopago_config
                (empresa_id, ambiente, access_token_cifrado, webhook_secret, user_id_mp, activo)
             VALUES
                (:empresa_id, :ambiente, :access_token_cifrado, :webhook_secret, :user_id_mp, :activo)
             ON DUPLICATE KEY UPDATE
                ambiente             = VALUES(ambiente),
                access_token_cifrado = COALESCE(VALUES(access_token_cifrado), access_token_cifrado),
                webhook_secret       = VALUES(webhook_secret),
                user_id_mp           = VALUES(user_id_mp),
                activo               = VALUES(activo),
                updated_at           = CURRENT_TIMESTAMP'
        );
        $statement->execute($data);
    }

    // ── Terminales ──────────────────────────────────────────────────────────

    public function listTerminales(int $empresaId, array $filters = []): array
    {
        $sql = 'SELECT t.*, s.nombre AS sucursal_nombre, c.nombre AS caja_nombre
                FROM mercadopago_terminales t
                LEFT JOIN sucursales s ON s.id = t.sucursal_id AND s.empresa_id = t.empresa_id
                LEFT JOIN cajas c ON c.id = t.caja_id AND c.empresa_id = t.empresa_id
                WHERE t.empresa_id = :empresa_id';
        $params = ['empresa_id' => $empresaId];

        if (!empty($filters['sucursal_id'])) {
            $sql .= ' AND t.sucursal_id = :sucursal_id';
            $params['sucursal_id'] = (int) $filters['sucursal_id'];
        }
        if (isset($filters['activo'])) {
            $sql .= ' AND t.activo = :activo';
            $params['activo'] = (int) $filters['activo'];
        }

        $sql .= ' ORDER BY t.sucursal_id, t.nombre';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function findTerminal(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM mercadopago_terminales WHERE empresa_id = :empresa_id AND id = :id LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function terminalIdExiste(int $empresaId, string $terminalId, ?int $excluirId = null): bool
    {
        $sql = 'SELECT 1 FROM mercadopago_terminales
                WHERE empresa_id = :empresa_id AND terminal_id = :terminal_id';
        $params = ['empresa_id' => $empresaId, 'terminal_id' => $terminalId];
        if ($excluirId !== null) {
            $sql .= ' AND id <> :excluir_id';
            $params['excluir_id'] = $excluirId;
        }
        $sql .= ' LIMIT 1';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return (bool) $statement->fetchColumn();
    }

    public function insertTerminal(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO mercadopago_terminales
                (empresa_id, sucursal_id, caja_id, terminal_id, nombre, mp_store_id, mp_pos_id, serial, activo)
             VALUES
                (:empresa_id, :sucursal_id, :caja_id, :terminal_id, :nombre, :mp_store_id, :mp_pos_id, :serial, :activo)'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function updateTerminal(int $empresaId, int $id, array $data): void
    {
        $data['empresa_id'] = $empresaId;
        $data['id'] = $id;
        $statement = $this->connection->prepare(
            'UPDATE mercadopago_terminales SET
                sucursal_id = :sucursal_id,
                caja_id     = :caja_id,
                terminal_id = :terminal_id,
                nombre      = :nombre,
                mp_store_id = :mp_store_id,
                mp_pos_id   = :mp_pos_id,
                serial      = :serial,
                activo      = :activo,
                updated_at  = CURRENT_TIMESTAMP
             WHERE empresa_id = :empresa_id AND id = :id'
        );
        $statement->execute($data);
    }

    public function setTerminalEstado(int $empresaId, int $id, int $activo): void
    {
        $statement = $this->connection->prepare(
            'UPDATE mercadopago_terminales SET activo = :activo, updated_at = CURRENT_TIMESTAMP
             WHERE empresa_id = :empresa_id AND id = :id'
        );
        $statement->execute(['activo' => $activo, 'empresa_id' => $empresaId, 'id' => $id]);
    }

    // ── Intentos de cobro ─────────────────────────────────────────────────────

    public function insertIntento(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO mercadopago_intentos
                (empresa_id, sucursal_id, terminal_id_ref, external_reference, provider_order_id,
                 idempotency_key, monto, estado, status_detail, raw_response_json, usuario_id)
             VALUES
                (:empresa_id, :sucursal_id, :terminal_id_ref, :external_reference, :provider_order_id,
                 :idempotency_key, :monto, :estado, :status_detail, :raw_response_json, :usuario_id)'
        );
        $statement->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function findIntento(int $empresaId, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM mercadopago_intentos WHERE empresa_id = :empresa_id AND id = :id LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** Busca por external_reference; opcionalmente FOR UPDATE (consumo en venta). */
    public function findIntentoByReference(int $empresaId, string $reference, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM mercadopago_intentos
                WHERE empresa_id = :empresa_id AND external_reference = :external_reference LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute(['empresa_id' => $empresaId, 'external_reference' => $reference]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** Resuelve el intento (y por lo tanto la empresa) desde el id de order de MP. */
    public function findIntentoByOrderId(string $orderId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM mercadopago_intentos WHERE provider_order_id = :order_id LIMIT 1'
        );
        $statement->execute(['order_id' => $orderId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function updateIntentoEstado(int $id, array $data): void
    {
        $data['id'] = $id;
        $statement = $this->connection->prepare(
            'UPDATE mercadopago_intentos SET
                estado            = :estado,
                status_detail     = :status_detail,
                provider_order_id = COALESCE(:provider_order_id, provider_order_id),
                raw_response_json = :raw_response_json,
                updated_at        = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute($data);
    }

    /** Marca el intento como consumido por una venta (dentro de la transaccion). */
    public function marcarConsumido(int $id, int $ventaId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE mercadopago_intentos SET venta_id = :venta_id, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute(['venta_id' => $ventaId, 'id' => $id]);
    }

    // ── Webhook events ────────────────────────────────────────────────────────

    /**
     * Inserta el evento de webhook de forma idempotente. Devuelve el id nuevo, o
     * null si ya existia (UNIQUE topic+resource+action) => reintento duplicado.
     */
    public function insertWebhookEvent(array $data): ?int
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO mercadopago_webhook_events
                (empresa_id, topic, resource_id, action, payload_json, x_signature)
             VALUES
                (:empresa_id, :topic, :resource_id, :action, :payload_json, :x_signature)'
        );
        $statement->execute($data);

        if ($statement->rowCount() === 0) {
            return null;
        }

        return (int) $this->connection->lastInsertId();
    }

    public function marcarWebhookProcesado(int $id, ?int $empresaId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE mercadopago_webhook_events
             SET procesado_at = CURRENT_TIMESTAMP,
                 empresa_id = COALESCE(:empresa_id, empresa_id)
             WHERE id = :id'
        );
        $statement->execute(['empresa_id' => $empresaId, 'id' => $id]);
    }

    // ── Helpers de validacion multiempresa ────────────────────────────────────

    public function sucursalExists(int $empresaId, int $sucursalId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM sucursales WHERE empresa_id = :empresa_id AND id = :id LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'id' => $sucursalId]);

        return (bool) $statement->fetchColumn();
    }

    public function cajaExists(int $empresaId, int $cajaId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM cajas WHERE empresa_id = :empresa_id AND id = :id LIMIT 1'
        );
        $statement->execute(['empresa_id' => $empresaId, 'id' => $cajaId]);

        return (bool) $statement->fetchColumn();
    }
}
