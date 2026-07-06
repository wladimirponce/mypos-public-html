<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Services\AuditoriaService;
use Mypos\Services\Agente\AlertasConfigService;
use Mypos\Services\ConsultaFlexibleService;
use Throwable;

/**
 * POST /api/v1/agente/consulta-adhoc — capa 2.5 del agente: ejecuta un
 * envelope sql_readonly generado EN LINEA por el LLM (sin aprobacion humana
 * previa). La cadena de confianza es la misma de las skills aprobadas:
 *
 *  - el envelope se valida contra agent/sql_whitelist_validator.php en CADA
 *    ejecucion (tablas/columnas whitelisted, SELECT unico, sin DML/UNION,
 *    tenant como placeholder obligatorio);
 *  - empresa_id/sucursal_id se inyectan del contexto autenticado
 *    (protectedRoute reportes.ver), nunca del envelope;
 *  - v1 NO acepta parametros "fuente: extraido" (texto libre del usuario en
 *    binds): esos casos siguen requiriendo aprobacion humana en la bandeja;
 *  - interruptor por empresa (OFF por defecto) + cuota diaria, en
 *    agente_alertas_config via AlertasConfigService;
 *  - el limite de fondo es el usuario MySQL de solo lectura (operacional).
 *
 * Toda ejecucion (exitosa o no) queda en auditoria_eventos; la cuota diaria
 * se cuenta sobre esa misma tabla.
 */
final class AgenteConsultaAdhocController
{
    public function ejecutar(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        $sucursalId = isset($_GET['sucursal_id']) ? (int) $_GET['sucursal_id'] : null;
        $consulta = '';

        try {
            $payload = Request::json();
            $consulta = mb_substr(trim((string) ($payload['consulta'] ?? '')), 0, 500);
            $skill = is_array($payload['skill'] ?? null) ? $payload['skill'] : [];
            if ($skill === []) {
                throw new HttpException('skill (envelope) obligatorio', 422);
            }

            // Interruptor por empresa + cuota diaria
            $config = (new AlertasConfigService())->config($empresaId);
            $adhoc = $config['alertas']['consulta_adhoc'] ?? ['activo' => false, 'max_dia' => 30];
            if (!(bool) ($adhoc['activo'] ?? false)) {
                throw new HttpException('Consultas dinamicas desactivadas para esta empresa', 403);
            }
            $maxDia = max(1, (int) ($adhoc['max_dia'] ?? 30));
            if ($this->ejecutadasHoy($empresaId) >= $maxDia) {
                throw new HttpException("Cuota diaria de consultas dinamicas alcanzada ($maxDia)", 429);
            }

            // v1: sin parametros extraidos del texto libre — defensa en el
            // servidor ademas del pre-chequeo del agente.
            foreach ((array) ($skill['params_permitidos'] ?? []) as $name => $def) {
                if (is_array($def) && ($def['fuente'] ?? '') === 'extraido') {
                    throw new HttpException(
                        "El parametro \"$name\" requiere texto del usuario: esta consulta necesita aprobacion humana (bandeja Consultas IA)",
                        422
                    );
                }
            }

            $resultado = (new ConsultaFlexibleService())->ejecutarEnvelope($empresaId, $sucursalId, $skill, []);

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'modulo' => 'agente_ia',
                'accion' => 'consulta_adhoc',
                'entidad' => 'consulta',
                'descripcion' => 'Consulta SQL dinamica ejecutada en linea',
                'metadata' => [
                    'consulta' => $consulta,
                    'sql_template' => (string) ($skill['sql_template'] ?? ''),
                    'row_count' => $resultado['row_count'],
                    'truncated' => $resultado['truncated'],
                ],
                'severidad' => 'INFO',
                'resultado' => 'OK',
            ]);

            Response::success($resultado);
        } catch (HttpException $exception) {
            // 403/429 son flujo normal (flag apagado/cuota): no ensuciar auditoria.
            if (!in_array($exception->statusCode(), [403, 429], true)) {
                AuditoriaService::registrarEvento([
                    'empresa_id' => $empresaId ?: null,
                    'modulo' => 'agente_ia',
                    'accion' => 'consulta_adhoc',
                    'entidad' => 'consulta',
                    'descripcion' => 'Consulta dinamica rechazada: ' . $exception->getMessage(),
                    'metadata' => ['consulta' => $consulta],
                    'severidad' => 'WARNING',
                    'resultado' => 'ERROR',
                ]);
            }
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('AgenteConsultaAdhocController: ' . $exception->getMessage());
            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId ?: null,
                'modulo' => 'agente_ia',
                'accion' => 'consulta_adhoc',
                'entidad' => 'consulta',
                'descripcion' => 'Error interno en consulta dinamica',
                'severidad' => 'ERROR',
                'resultado' => 'ERROR',
            ]);
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function ejecutadasHoy(int $empresaId): int
    {
        try {
            $stmt = Database::connection()->prepare(
                "SELECT COUNT(*) AS n
                 FROM auditoria_eventos
                 WHERE empresa_id = :empresa_id
                   AND modulo = 'agente_ia'
                   AND accion = 'consulta_adhoc'
                   AND resultado = 'OK'
                   AND created_at >= CURDATE()"
            );
            $stmt->execute([':empresa_id' => $empresaId]);
            return (int) ($stmt->fetch()['n'] ?? 0);
        } catch (Throwable $e) {
            error_log('AgenteConsultaAdhocController::ejecutadasHoy ' . $e->getMessage());
            return PHP_INT_MAX; // fallar cerrado: sin conteo no hay cuota verificable
        }
    }
}
