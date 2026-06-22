<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use PDO;

/**
 * Envía notificaciones al cliente tras la creación de un documento tributario.
 * Diseñado para llamarse en fire-and-forget (envuelto en try/catch externo).
 */
final class NotificacionVentaService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Punto de entrada principal. Lee configuración, cliente y empresa,
     * y despacha email y/o WhatsApp según los flags activos.
     *
     * @return array{email: bool|null, whatsapp: bool|null}
     */
    public function notificar(int $ventaId, int $documentoEmitidoId, int $empresaId): array
    {
        $result = ['email' => null, 'whatsapp' => null];

        $config = $this->configNotificaciones($empresaId);
        if (!$config['notif_email_activo'] && !$config['notif_whatsapp_activo']) {
            return $result;
        }

        $data = $this->fetchData($ventaId, $documentoEmitidoId, $empresaId);
        if ($data === null) {
            return $result;
        }

        if ($config['notif_email_activo'] && !empty($data['cliente_email'])) {
            $result['email'] = $this->enviarEmail($data);
        }

        if ($config['notif_whatsapp_activo'] && !empty($data['cliente_telefono'])) {
            $result['whatsapp'] = $this->enviarWhatsApp($data, $empresaId);
        }

        return $result;
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    private function configNotificaciones(int $empresaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT notif_email_activo, notif_whatsapp_activo
             FROM empresa_configuracion_operativa
             WHERE empresa_id = :empresa_id
             LIMIT 1'
        );
        $stmt->execute(['empresa_id' => $empresaId]);
        $row = $stmt->fetch();

        return [
            'notif_email_activo'    => (bool) ($row['notif_email_activo'] ?? 0),
            'notif_whatsapp_activo' => (bool) ($row['notif_whatsapp_activo'] ?? 0),
        ];
    }

    private function fetchData(int $ventaId, int $documentoEmitidoId, int $empresaId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT
                v.id          AS venta_id,
                v.total       AS total,
                v.tipo_venta  AS tipo_venta,
                v.created_at  AS fecha_venta,
                de.folio             AS folio,
                de.tipo_documento    AS tipo_documento,
                de.rut_receptor      AS rut_receptor,
                de.razon_social_receptor AS razon_social_receptor,
                c.nombre      AS cliente_nombre,
                c.email       AS cliente_email,
                c.telefono    AS cliente_telefono,
                ec.razon_social  AS empresa_razon_social,
                ec.rut_empresa   AS empresa_rut,
                ec.email_contacto AS empresa_email,
                ec.telefono_contacto AS empresa_telefono,
                ec.logo_url   AS empresa_logo
             FROM ventas v
             JOIN documentos_emitidos de
                ON de.id = :doc_id AND de.empresa_id = :empresa_id
             LEFT JOIN clientes c
                ON c.id = v.cliente_id AND c.empresa_id = v.empresa_id
             LEFT JOIN empresa_configuracion ec
                ON ec.empresa_id = v.empresa_id
             WHERE v.id = :venta_id AND v.empresa_id = :empresa_id
             LIMIT 1'
        );
        $stmt->execute([
            'doc_id'     => $documentoEmitidoId,
            'empresa_id' => $empresaId,
            'venta_id'   => $ventaId,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function enviarEmail(array $data): bool
    {
        try {
            $mailService = new MailService();
            $mailService->enviarDte(
                toEmail:      $data['cliente_email'],
                nombreCliente: $data['cliente_nombre'] ?? $data['razon_social_receptor'] ?? 'Cliente',
                tipoDocumento: (string) $data['tipo_documento'],
                folio:         (int) ($data['folio'] ?? 0),
                razonSocialEmpresa: (string) ($data['empresa_razon_social'] ?? 'Empresa'),
                total:         (int) ($data['total'] ?? 0),
                fecha:         substr((string) $data['fecha_venta'], 0, 10),
                rutEmpresa:    (string) ($data['empresa_rut'] ?? ''),
            );
            return true;
        } catch (\Throwable $e) {
            error_log('[NotificacionVenta] Email error: ' . $e->getMessage());
            return false;
        }
    }

    private function enviarWhatsApp(array $data, int $empresaId): bool
    {
        try {
            $waConfig = $this->fetchWhatsAppConfig($empresaId);
            if ($waConfig === null) {
                return false;
            }

            $phone = $this->normalizePhone($data['cliente_telefono']);
            if ($phone === null) {
                return false;
            }

            $tipoLabel = match ((string) $data['tipo_documento']) {
                'BOLETA'  => 'Boleta',
                'FACTURA' => 'Factura',
                default   => (string) $data['tipo_documento'],
            };

            $folio     = (int) ($data['folio'] ?? 0);
            $total     = number_format((int) $data['total'], 0, ',', '.');
            $empresa   = $data['empresa_razon_social'] ?? 'Empresa';
            $fecha     = substr((string) $data['fecha_venta'], 0, 10);
            [$y, $m, $d] = explode('-', $fecha);
            $fechaLocal = "{$d}/{$m}/{$y}";

            $folioText = $folio > 0 ? "N° {$folio}" : '';
            $body = "🧾 *{$tipoLabel}* {$folioText}\n"
                  . "Empresa: {$empresa}\n"
                  . "Total: \${$total}\n"
                  . "Fecha: {$fechaLocal}\n\n"
                  . "Gracias por su compra.";

            $payload = json_encode([
                'messaging_product' => 'whatsapp',
                'to'                => $phone,
                'type'              => 'text',
                'text'              => ['body' => $body],
            ]);

            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$waConfig['access_token']}\r\n",
                'content'       => $payload,
                'timeout'       => 10,
                'ignore_errors' => true,
            ]]);

            set_error_handler(static fn() => true, E_WARNING);
            $resp = file_get_contents($waConfig['url'], false, $ctx);
            restore_error_handler();

            if ($resp === false) {
                error_log('[NotificacionVenta] WhatsApp: sin respuesta del servidor');
                return false;
            }

            $status = 0;
            if (isset($http_response_header[0])) {
                preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
                $status = (int) ($m[1] ?? 0);
            }

            if ($status >= 400) {
                error_log("[NotificacionVenta] WhatsApp error $status: " . substr((string) $resp, 0, 200));
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            error_log('[NotificacionVenta] WhatsApp error: ' . $e->getMessage());
            return false;
        }
    }

    private function fetchWhatsAppConfig(int $empresaId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT phone_number_id, access_token
             FROM empresa_whatsapp_config
             WHERE empresa_id = :empresa_id AND activo = 1
             LIMIT 1'
        );
        $stmt->execute(['empresa_id' => $empresaId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $phoneNumberId = $row['phone_number_id'] ?: (string) getenv('WHATSAPP_PHONE_NUMBER_ID');
        $accessToken   = $row['access_token']    ?: (string) getenv('WHATSAPP_ACCESS_TOKEN');

        if (!$phoneNumberId || !$accessToken) {
            return null;
        }

        return [
            'url'          => "https://graph.facebook.com/v25.0/{$phoneNumberId}/messages",
            'access_token' => $accessToken,
        ];
    }

    /**
     * Normaliza números chilenos al formato E.164 requerido por WhatsApp (569XXXXXXXX).
     */
    private function normalizePhone(string $raw): ?string
    {
        // Elimina todo excepto dígitos y el + inicial
        $digits = preg_replace('/[^\d]/', '', $raw);

        if ($digits === null || $digits === '') {
            return null;
        }

        // Ya viene con código de país
        if (str_starts_with($digits, '56') && strlen($digits) >= 11) {
            return $digits;
        }

        // Número chileno sin código de país: 9XXXXXXXX (9 dígitos)
        if (strlen($digits) === 9 && str_starts_with($digits, '9')) {
            return '56' . $digits;
        }

        // 8 dígitos (sin el 9 inicial): 2XXXXXXX (fijo Santiago)
        if (strlen($digits) === 8) {
            return '562' . $digits;
        }

        return null;
    }
}
