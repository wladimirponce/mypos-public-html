<?php

declare(strict_types=1);

namespace Mypos\Services\Agente;

use Mypos\Config\Database;
use PDO;

/**
 * Preferencias de alertas proactivas por empresa (tabla agente_alertas_config).
 *
 * Sin fila en la tabla, la empresa recibe los DEFAULTS: alertas operativas y
 * criticas activas por email al correo de registro (empresas.email); WhatsApp
 * desactivado hasta que se autorice un numero; resumen diario opt-in.
 */
final class AlertasConfigService
{
    /** Tipos de alerta y sus parametros por defecto. */
    public const DEFAULTS = [
        'precio_perdida'   => ['activo' => true],
        'margen_comprometido' => ['activo' => true, 'umbral_pp' => 5],
        'stock_critico'    => ['activo' => true, 'max_listado' => 15],
        'lotes_por_vencer' => ['activo' => true, 'dias_alerta' => 15],
        'transferencias_sugeridas' => ['activo' => true, 'max_listado' => 15],
        'cierre_pendiente' => ['activo' => true],
        'caja_abierta'     => ['activo' => true, 'horas_max' => 16],
        'ventas_caida'     => ['activo' => true, 'umbral_pct' => 50],
        'folios_bajos'     => ['activo' => true, 'umbral_boleta' => 150, 'umbral_factura' => 20],
        'suscripcion'      => ['activo' => true, 'dias_aviso' => 5],
        'compras_borrador' => ['activo' => true, 'dias' => 3],
        'inventario_inmovilizado' => ['activo' => true, 'dias' => 90, 'max_listado' => 10],
        'anomalias_ventas' => ['activo' => true, 'umbral_pct' => 50],
        'anomalias_caja' => ['activo' => true, 'repeticiones' => 3],
        'anomalias_stock' => ['activo' => true, 'ajustes_dia' => 5],
        'proveedores_atrasados' => ['activo' => true, 'cumplimiento_minimo_pct' => 80],
        'resumen_diario'   => ['activo' => false],
        // No es una "alerta": interruptor de las consultas SQL dinamicas en
        // linea del chat (capa 2.5, /agente/consulta-adhoc). Vive aqui porque
        // esta tabla es de facto la config del agente por empresa. OFF por
        // defecto: requiere el usuario MySQL read-only aplicado en prod.
        'consulta_adhoc'   => ['activo' => false, 'max_dia' => 30],
    ];

    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? Database::connection();
    }

    /**
     * Config efectiva de la empresa (fila + defaults mergeados).
     *
     * @return array{
     *   empresa_id: int, activo: bool, canal_email: bool, canal_whatsapp: bool,
     *   whatsapp_numero: string, email_alertas: string, alertas: array<string, array>
     * }
     */
    public function config(int $empresaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM agente_alertas_config WHERE empresa_id = :empresa_id LIMIT 1'
        );
        $stmt->execute([':empresa_id' => $empresaId]);
        $row = $stmt->fetch() ?: [];

        $alertas = self::DEFAULTS;
        $custom = json_decode((string) ($row['config_json'] ?? ''), true);
        if (is_array($custom)) {
            foreach ($custom as $tipo => $params) {
                if (isset($alertas[$tipo]) && is_array($params)) {
                    $alertas[$tipo] = array_merge($alertas[$tipo], $params);
                }
            }
        }

        return [
            'empresa_id' => $empresaId,
            'activo' => (bool) (int) ($row['activo'] ?? 1),
            'canal_email' => (bool) (int) ($row['canal_email'] ?? 1),
            'canal_whatsapp' => (bool) (int) ($row['canal_whatsapp'] ?? 0),
            'whatsapp_numero' => trim((string) ($row['whatsapp_numero'] ?? '')),
            'email_alertas' => trim((string) ($row['email_alertas'] ?? '')),
            'alertas' => $alertas,
        ];
    }

    /**
     * Guarda las preferencias. Solo acepta tipos de alerta y parametros
     * conocidos (whitelist DEFAULTS): un payload no puede inventar claves.
     *
     * @param array<string, mixed> $payload
     */
    public function guardar(int $empresaId, array $payload): array
    {
        $alertas = [];
        $entrada = is_array($payload['alertas'] ?? null) ? $payload['alertas'] : [];
        foreach (self::DEFAULTS as $tipo => $defaults) {
            if (!isset($entrada[$tipo]) || !is_array($entrada[$tipo])) {
                continue;
            }
            $limpio = [];
            foreach ($defaults as $param => $defaultValue) {
                if (!array_key_exists($param, $entrada[$tipo])) {
                    continue;
                }
                $valor = $entrada[$tipo][$param];
                $limpio[$param] = is_bool($defaultValue)
                    ? (bool) $valor
                    : max(0, (int) $valor);
            }
            if ($limpio !== []) {
                $alertas[$tipo] = $limpio;
            }
        }

        $whatsapp = preg_replace('/[^\d]/', '', (string) ($payload['whatsapp_numero'] ?? ''));
        $email = trim((string) ($payload['email_alertas'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = '';
        }

        $stmt = $this->db->prepare(
            'INSERT INTO agente_alertas_config
                (empresa_id, activo, canal_email, canal_whatsapp, whatsapp_numero, email_alertas, config_json)
             VALUES
                (:empresa_id, :activo, :canal_email, :canal_whatsapp, :whatsapp_numero, :email_alertas, :config_json)
             ON DUPLICATE KEY UPDATE
                activo = VALUES(activo),
                canal_email = VALUES(canal_email),
                canal_whatsapp = VALUES(canal_whatsapp),
                whatsapp_numero = VALUES(whatsapp_numero),
                email_alertas = VALUES(email_alertas),
                config_json = VALUES(config_json)'
        );
        $stmt->execute([
            ':empresa_id' => $empresaId,
            ':activo' => (int) (bool) ($payload['activo'] ?? true),
            ':canal_email' => (int) (bool) ($payload['canal_email'] ?? true),
            ':canal_whatsapp' => (int) (bool) ($payload['canal_whatsapp'] ?? false),
            ':whatsapp_numero' => $whatsapp !== '' ? mb_substr($whatsapp, 0, 20) : null,
            ':email_alertas' => $email !== '' ? mb_substr($email, 0, 190) : null,
            ':config_json' => $alertas !== []
                ? json_encode($alertas, JSON_UNESCAPED_UNICODE)
                : null,
        ]);

        return $this->config($empresaId);
    }
}
