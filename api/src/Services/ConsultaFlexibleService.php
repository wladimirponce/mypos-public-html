<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use PDO;
use PDOException;
use Throwable;

/**
 * Ejecuta skills tipo "sql_readonly" aprobadas desde admin (modulo Consultas IA).
 *
 * Cadena de confianza (leer antes de tocar este archivo):
 *  - empresa_id/sucursal_id llegan como argumentos YA validados por
 *    AuthMiddleware + TenantMiddleware en protectedRoute() (public/index.php) --
 *    este servicio NUNCA debe aceptar empresa_id desde el body/params del
 *    agente, solo desde lo que el router ya verifico contra el JWT.
 *  - la skill (agent/skills/{id}.json) fue aprobada por un humano en admin,
 *    pero NUNCA se confia en que sigue siendo valida: se re-valida aqui con
 *    el MISMO validador (agent/sql_whitelist_validator.php) que usa admin al
 *    crearla, por si el archivo fue editado a mano despues de aprobado.
 *  - el filtro de tenant (empresa_id) SIEMPRE lo inyecta este servicio a
 *    partir del contexto autenticado, nunca del valor que pudiera venir en
 *    params_permitidos como si fuera "extraido".
 *  - el limite de fondo real es un usuario de MySQL de solo SELECT sobre las
 *    tablas de la whitelist (ver docs/AGENTE_SQL_READONLY.md) -- este
 *    servicio es defensa en profundidad, no el unico control.
 */
final class ConsultaFlexibleService
{
    private const MAX_ROW_LIMIT = 200;

    private ?PDO $connection;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<string, mixed> $paramsExtraidos Parametros extraidos del mensaje del usuario (NO confiables)
     * @return array{columns: array<int,string>, rows: array<int,array<string,mixed>>, row_count: int, truncated: bool}
     */
    public function ejecutar(int $empresaId, ?int $sucursalId, string $skillId, array $paramsExtraidos): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id invalido', 422);
        }
        if ($skillId === '' || !preg_match('/^[a-z0-9_]+$/', $skillId)) {
            throw new HttpException('skill_id invalido', 422);
        }

        $skill = $this->loadSkill($skillId);
        $this->requireValidator();

        $reason = null;
        if (!\SqlWhitelistValidator::validateSkillEnvelope($skill, $reason)) {
            // Si esto dispara en produccion, alguien edito el archivo a mano
            // despues de aprobado y quedo invalido -- no ejecutar bajo ninguna circunstancia.
            throw new HttpException('La skill no paso la validacion de seguridad: ' . $reason, 500);
        }
        if (($skill['status'] ?? '') !== 'aprobada' && ($skill['status'] ?? '') !== 'activa') {
            throw new HttpException('La skill no esta aprobada/activa', 403);
        }

        $binds = $this->buildBinds($skill, $empresaId, $sucursalId, $paramsExtraidos);
        $rowLimit = min((int) $skill['row_limit'], self::MAX_ROW_LIMIT);

        [$columns, $rows, $truncated] = $this->execute((string) $skill['sql_template'], $binds, $rowLimit);

        return [
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => count($rows),
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSkill(string $skillId): array
    {
        $skillsDir = $this->agentPath('skills');
        $path = $skillsDir . DIRECTORY_SEPARATOR . $skillId . '.json';

        if (!is_file($path)) {
            throw new HttpException('Skill no encontrada', 404);
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new HttpException('No se pudo leer la skill', 500);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new HttpException('Skill corrupta (JSON invalido)', 500);
        }
        if (($data['tipo'] ?? '') !== 'sql_readonly') {
            throw new HttpException('Esta skill no es de tipo sql_readonly', 422);
        }

        return $data;
    }

    private function requireValidator(): void
    {
        if (class_exists('SqlWhitelistValidator', false)) {
            return;
        }
        $path = $this->agentPath('sql_whitelist_validator.php');
        if (!is_file($path)) {
            throw new HttpException('No se encontro el validador de whitelist SQL', 500);
        }
        require_once $path;
    }

    /**
     * Resuelve la carpeta agent/ como hermana de api/ bajo el mismo public_html
     * (mismo patron que ya usa admin/modules/agente_consultas.php). Se puede
     * sobreescribir con AGENT_BASE_PATH para entornos donde no aplique esa
     * estructura de carpetas (ej. desarrollo local).
     */
    private function agentPath(string $relative): string
    {
        $override = $_ENV['AGENT_BASE_PATH'] ?? getenv('AGENT_BASE_PATH');
        $base = $override !== false && $override !== null && $override !== ''
            ? rtrim((string) $override, '/\\')
            : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'agent';

        return $base . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * Arma el arreglo de binds para PDO a partir de params_permitidos,
     * inyectando SIEMPRE empresa_id/sucursal_id desde el contexto
     * autenticado (nunca desde $paramsExtraidos) y validando cada parametro
     * "extraido" contra su regex/max_length/enum declarado.
     *
     * @param array<string, mixed> $skill
     * @param array<string, mixed> $paramsExtraidos
     * @return array<string, mixed>
     */
    private function buildBinds(array $skill, int $empresaId, ?int $sucursalId, array $paramsExtraidos): array
    {
        $contexto = ['empresa_id' => $empresaId, 'sucursal_id' => $sucursalId];
        $paramsPermitidos = is_array($skill['params_permitidos'] ?? null) ? $skill['params_permitidos'] : [];
        $binds = [];

        foreach ($paramsPermitidos as $name => $def) {
            if (!is_array($def)) {
                continue;
            }
            $fuente = (string) ($def['fuente'] ?? '');

            if ($fuente === 'contexto') {
                if (!array_key_exists($name, $contexto)) {
                    throw new HttpException("Parametro de contexto desconocido: $name", 500);
                }
                if ($contexto[$name] === null) {
                    if (!empty($def['requerido'])) {
                        throw new HttpException("Falta el parametro de contexto requerido: $name", 422);
                    }
                    continue;
                }
                $binds[$name] = $contexto[$name];
                continue;
            }

            if ($fuente === 'extraido') {
                $value = $paramsExtraidos[$name] ?? null;
                if ($value === null || $value === '') {
                    if (!empty($def['requerido'])) {
                        throw new HttpException("Falta el parametro requerido: $name", 422);
                    }
                    continue;
                }
                $value = $this->validateExtraido($name, (string) $value, $def);
                $binds[$name] = $value;
                continue;
            }
        }

        return $binds;
    }

    /**
     * @param array<string, mixed> $def
     */
    private function validateExtraido(string $name, string $value, array $def): string
    {
        if (isset($def['max_length']) && mb_strlen($value) > (int) $def['max_length']) {
            throw new HttpException("El parametro $name excede el largo maximo permitido", 422);
        }
        if (isset($def['enum']) && is_array($def['enum']) && !in_array($value, $def['enum'], true)) {
            throw new HttpException("El parametro $name no es un valor permitido", 422);
        }
        if (isset($def['regex']) && @preg_match('/' . $def['regex'] . '/u', '') === false) {
            throw new HttpException("La skill tiene un regex invalido para $name", 500);
        }
        if (isset($def['regex']) && preg_match('/' . $def['regex'] . '/u', $value) !== 1) {
            throw new HttpException("El parametro $name no cumple el formato esperado", 422);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $binds
     * @return array{0: array<int,string>, 1: array<int,array<string,mixed>>, 2: bool}
     */
    private function execute(string $sqlTemplate, array $binds, int $rowLimit): array
    {
        $pdo = $this->connection ?? Database::connection();

        try {
            // Limite de tiempo de ejecucion best-effort (MySQL 5.7.8+); si el
            // servidor no lo soporta, seguimos igual -- no es el unico control
            // (el hosting/servicio de BD deberia tener su propio timeout).
            $pdo->exec('SET SESSION MAX_EXECUTION_TIME=5000');
        } catch (Throwable) {
            // ignorar, best-effort
        }

        try {
            $stmt = $pdo->prepare($sqlTemplate);
            foreach ($binds as $name => $value) {
                $stmt->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
        } catch (PDOException $exception) {
            error_log('ConsultaFlexibleService: error ejecutando skill: ' . $exception->getMessage());
            throw new HttpException('No se pudo ejecutar la consulta', 500);
        }

        $rows = [];
        $columns = [];
        $truncated = false;
        $i = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($i === 0) {
                $columns = array_keys($row);
            }
            if ($i >= $rowLimit) {
                $truncated = true;
                break;
            }
            $rows[] = $row;
            $i++;
        }
        $stmt->closeCursor();

        return [$columns, $rows, $truncated];
    }
}
