<?php

declare(strict_types=1);

namespace Mypos\Services\Agente;

use Mypos\Config\Database;
use Mypos\Services\MailService;
use PDO;

/**
 * Envio y registro de alertas proactivas. Aislamiento multiempresa estricto:
 * el destino SIEMPRE sale de los datos de LA empresa (correo de registro
 * empresas.email u override, y numero WhatsApp autorizado en su config) —
 * nunca de otra parte.
 *
 * Dedupe: una alerta (empresa, tipo, clave) enviada dentro de su periodo de
 * gracia no se repite (tabla agente_alertas_log).
 *
 * WhatsApp: reusa la linea Meta de la propia empresa (empresa_whatsapp_config,
 * mismo patron que NotificacionVentaService). OJO operacional: mensajes
 * iniciados por el negocio fuera de la ventana de 24 h requieren plantilla
 * aprobada por Meta; mientras no exista, el envio de texto libre puede ser
 * rechazado y el email queda como canal garantizado.
 */
final class AlertasNotifier
{
    /** Horas de gracia del dedupe por tipo de alerta. */
    private const GRACE_HORAS = [
        'precio_perdida'   => 20,
        'margen_comprometido' => 20,
        'stock_critico'    => 20,
        'lotes_por_vencer' => 20,
        'transferencias_sugeridas' => 20,
        'cierre_pendiente' => 8,   // permite el refuerzo de la manana siguiente
        'caja_abierta'     => 8,   // re-avisa cada 8 h mientras siga abierta
        'ventas_caida'     => 4,   // permite el segundo aviso de la tarde
        'folios_bajos'     => 20,
        'suscripcion'      => 20,
        'compras_borrador' => 20,
        'resumen_diario'   => 20,
    ];

    private const TITULOS = [
        'precio_perdida'   => '💸 Precios a pérdida',
        'margen_comprometido' => '📉 Margen bajo el objetivo',
        'stock_critico'    => '📦 Stock crítico',
        'lotes_por_vencer' => '⏳ Lotes por vencer (promo sugerida)',
        'transferencias_sugeridas' => '🔀 Transferencias entre sucursales sugeridas',
        'cierre_pendiente' => '🗓️ Cierres de caja pendientes',
        'caja_abierta'     => '🕐 Cajas abiertas demasiado tiempo',
        'ventas_caida'     => '📉 Ventas bajo lo normal',
        'folios_bajos'     => '🧾 Folios SII por agotarse',
        'suscripcion'      => '⚠️ Suscripción MyPOS',
        'compras_borrador' => '📝 Compras en borrador',
        'resumen_diario'   => '📊 Resumen diario',
    ];

    private PDO $db;
    private ?MailService $mailService;

    public function __construct(?PDO $connection = null, ?MailService $mailService = null)
    {
        $this->db = $connection ?? Database::connection();
        $this->mailService = $mailService;
    }

    /** Filtra los items ya avisados dentro del periodo de gracia del tipo. */
    public function filtrarDedupe(int $empresaId, string $tipo, array $items): array
    {
        $grace = self::GRACE_HORAS[$tipo] ?? 20;
        $stmt = $this->db->prepare(
            'SELECT 1 FROM agente_alertas_log
             WHERE empresa_id = :empresa_id
               AND tipo = :tipo
               AND clave = :clave
               AND estado = \'enviada\'
               AND created_at > DATE_SUB(NOW(), INTERVAL :grace HOUR)
             LIMIT 1'
        );

        $nuevos = [];
        foreach ($items as $item) {
            $stmt->bindValue(':empresa_id', $empresaId, PDO::PARAM_INT);
            $stmt->bindValue(':tipo', $tipo);
            $stmt->bindValue(':clave', (string) $item['clave']);
            $stmt->bindValue(':grace', $grace, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->fetch() === false) {
                $nuevos[] = $item;
            }
        }
        return $nuevos;
    }

    /**
     * Envia UN mensaje agrupado con todas las alertas del ciclo y registra
     * cada item en agente_alertas_log con el resultado.
     *
     * @param array $empresa  fila de empresas (id, razon_social, email)
     * @param array $config   AlertasConfigService::config()
     * @param array<string, array> $porTipo  tipo => items (ya filtrados por dedupe)
     * @return array{email: ?bool, whatsapp: ?bool}
     */
    public function enviar(array $empresa, array $config, array $porTipo): array
    {
        $empresaId = (int) $empresa['id'];
        $razonSocial = (string) ($empresa['razon_social'] ?? 'Empresa');

        [$texto, $html] = $this->componer($porTipo);

        $resultado = ['email' => null, 'whatsapp' => null];

        if ($config['canal_whatsapp'] && $config['whatsapp_numero'] !== '') {
            $resultado['whatsapp'] = $this->enviarWhatsApp(
                $empresaId,
                $config['whatsapp_numero'],
                "🔔 *Alertas MyPOS — {$razonSocial}*\n\n" . $texto
            );
        }

        // Cadena de destinos: override configurado → correo de registro de la
        // empresa → correo del SUPER_ADMIN (ninguna empresa queda inalcanzable).
        // La misma cadena que usan las exportaciones (ContactoEmpresaService).
        $email = $config['email_alertas'] !== ''
            ? $config['email_alertas']
            : trim((string) ($empresa['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = (new ContactoEmpresaService($this->db))->email($empresaId);
        }
        $emailRequerido = $config['canal_email']
            || $resultado['whatsapp'] === false   // fallback garantizado si WhatsApp fallo
            || $resultado['whatsapp'] === null;   // o si no hay WhatsApp configurado
        if ($emailRequerido && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $mailService = $this->mailService ?? new MailService();
            $resultado['email'] = $mailService->enviarAlertasAgente($email, $razonSocial, $html);
        }

        $enviado = ($resultado['email'] === true) || ($resultado['whatsapp'] === true);
        $canal = implode('+', array_keys(array_filter([
            'email' => $resultado['email'] === true,
            'whatsapp' => $resultado['whatsapp'] === true,
        ])));

        $stmt = $this->db->prepare(
            'INSERT INTO agente_alertas_log (empresa_id, tipo, clave, canal, estado, mensaje, detalle_json)
             VALUES (:empresa_id, :tipo, :clave, :canal, :estado, :mensaje, :detalle_json)'
        );
        foreach ($porTipo as $tipo => $items) {
            foreach ($items as $item) {
                $stmt->execute([
                    ':empresa_id' => $empresaId,
                    ':tipo' => $tipo,
                    ':clave' => mb_substr((string) $item['clave'], 0, 120),
                    ':canal' => $canal !== '' ? $canal : 'ninguno',
                    ':estado' => $enviado ? 'enviada' : 'fallida',
                    ':mensaje' => mb_substr((string) $item['texto'], 0, 2000),
                    ':detalle_json' => json_encode($item['detalle'] ?? [], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        return $resultado;
    }

    /** @return array{0: string, 1: string} [texto plano WhatsApp, HTML email] */
    private function componer(array $porTipo): array
    {
        $texto = '';
        $html = '';
        foreach ($porTipo as $tipo => $items) {
            if ($items === []) {
                continue;
            }
            $titulo = self::TITULOS[$tipo] ?? $tipo;
            $texto .= "*{$titulo}*\n";
            $html .= '<h3 style="margin-bottom:4px;">' . htmlspecialchars($titulo) . '</h3><ul style="margin-top:4px;">';
            foreach ($items as $item) {
                $linea = (string) $item['texto'];
                $texto .= "• {$linea}\n";
                $html .= '<li>' . nl2br(htmlspecialchars($linea)) . '</li>';
            }
            $texto .= "\n";
            $html .= '</ul>';
        }
        return [rtrim($texto), $html];
    }

    private function enviarWhatsApp(int $empresaId, string $numero, string $body): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT phone_number_id, access_token
                 FROM empresa_whatsapp_config
                 WHERE empresa_id = :empresa_id AND activo = 1
                 LIMIT 1'
            );
            $stmt->execute([':empresa_id' => $empresaId]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return false;
            }

            $phoneNumberId = $row['phone_number_id'] ?: (string) getenv('WHATSAPP_PHONE_NUMBER_ID');
            $accessToken = $row['access_token'] ?: (string) getenv('WHATSAPP_ACCESS_TOKEN');
            if (!$phoneNumberId || !$accessToken) {
                return false;
            }

            $payload = json_encode([
                'messaging_product' => 'whatsapp',
                'to' => $numero,
                'type' => 'text',
                'text' => ['body' => mb_substr($body, 0, 3800)],
            ], JSON_UNESCAPED_UNICODE);

            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$accessToken}\r\n",
                'content' => $payload,
                'timeout' => 10,
                'ignore_errors' => true,
            ]]);

            set_error_handler(static fn () => true, E_WARNING);
            $resp = file_get_contents("https://graph.facebook.com/v25.0/{$phoneNumberId}/messages", false, $ctx);
            restore_error_handler();

            if ($resp === false) {
                error_log('[AgenteAlertas] WhatsApp: sin respuesta del servidor');
                return false;
            }

            $status = 0;
            if (isset($http_response_header[0])) {
                preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
                $status = (int) ($m[1] ?? 0);
            }
            if ($status >= 400) {
                error_log("[AgenteAlertas] WhatsApp error $status: " . substr((string) $resp, 0, 200));
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[AgenteAlertas] WhatsApp error: ' . $e->getMessage());
            return false;
        }
    }
}
