<?php

declare(strict_types=1);

namespace Mypos\Services\Agente;

use Mypos\Config\Database;
use PDO;

/**
 * Resuelve el correo de contacto de una empresa para el agente IA.
 *
 * Cadena de destinos (la misma para alertas y exportaciones):
 *   1. override en agente_alertas_config.email_alertas
 *   2. empresas.email (correo de registro)
 *   3. correo del primer usuario activo, priorizando SUPER_ADMIN
 *
 * REGLA DE SEGURIDAD: los archivos y avisos del agente salen SOLO a estos
 * destinos, nunca a una dirección dictada en el chat (evita exfiltración
 * de datos del negocio hacia correos arbitrarios).
 */
final class ContactoEmpresaService
{
    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? Database::connection();
    }

    /** Correo efectivo de la empresa, o '' si no hay ninguno válido. */
    public function email(int $empresaId): string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT email_alertas FROM agente_alertas_config WHERE empresa_id = :empresa_id'
            );
            $stmt->execute([':empresa_id' => $empresaId]);
            $email = trim((string) ($stmt->fetch()['email_alertas'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                return $email;
            }
        } catch (\Throwable) {
            // tabla de config aún no migrada: seguir con la cadena
        }

        $stmt = $this->db->prepare('SELECT email FROM empresas WHERE id = :empresa_id');
        $stmt->execute([':empresa_id' => $empresaId]);
        $email = trim((string) ($stmt->fetch()['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return $email;
        }

        return $this->emailSuperAdmin($empresaId);
    }

    private function emailSuperAdmin(int $empresaId): string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT u.email
                 FROM empresa_usuarios eu
                 INNER JOIN usuarios u ON u.id = eu.usuario_id
                 LEFT JOIN roles r ON r.id = eu.rol_id
                 WHERE eu.empresa_id = :empresa_id
                   AND eu.activo = 1
                   AND u.email IS NOT NULL AND u.email <> \'\'
                 ORDER BY (r.codigo = \'SUPER_ADMIN\') DESC, eu.id ASC
                 LIMIT 1'
            );
            $stmt->execute([':empresa_id' => $empresaId]);
            $email = trim((string) ($stmt->fetch()['email'] ?? ''));
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
        } catch (\Throwable $e) {
            error_log('[AgenteContacto] emailSuperAdmin: ' . $e->getMessage());
            return '';
        }
    }
}
