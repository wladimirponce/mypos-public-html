<?php
/**
 * Validador de plantillas SQL para skills tipo "sql_readonly".
 *
 * Se usa en DOS momentos distintos, por DOS codebases distintas que viven
 * como hermanas bajo el mismo public_html (admin/, api/) y por eso pueden
 * hacer require_once de este mismo archivo via ruta relativa:
 *   - admin/modules/agente_consultas.php, al aprobar una skill ("Crear skill").
 *   - el backend (web/mypos-backend/backend), al EJECUTAR la skill en cada
 *     request. Nunca confiar en que ya se valido al aprobar: el archivo
 *     agent/skills/{id}.json se puede editar a mano despues de aprobado.
 *
 * IMPORTANTE - limites reales de este validador:
 * Esto es un chequeo ligero basado en tokenizacion/regex, NO un parser SQL
 * completo. Atrapa la enorme mayoria de intentos de abuso (multi-statement,
 * DML/DDL, tablas fuera de whitelist, filtros de empresa_id hardcodeados o
 * ausentes, acceso a information_schema, etc.) pero no reemplaza el control
 * de fondo: en produccion, la query debe ejecutarse con un usuario de MySQL
 * que SOLO tenga permiso SELECT sobre las tablas de esta whitelist. Este
 * validador es defensa en profundidad / feedback rapido en la UI de admin,
 * no el unico limite de seguridad.
 */

final class SqlWhitelistValidationError extends RuntimeException
{
}

final class SqlWhitelistValidator
{
    /** @var array<string,mixed>|null */
    private static ?array $whitelist = null;

    private const KEYWORD_STOPLIST = [
        'SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'AS', 'ORDER', 'BY', 'GROUP',
        'LIMIT', 'OFFSET', 'ASC', 'DESC', 'NULL', 'IS', 'NOT', 'IN', 'LIKE',
        'BETWEEN', 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'DISTINCT', 'ON',
        'INNER', 'LEFT', 'RIGHT', 'JOIN', 'CASE', 'WHEN', 'THEN', 'ELSE',
        'END', 'HAVING', 'COALESCE', 'IFNULL', 'CAST', 'CONVERT', 'ROUND',
        'DATE', 'CURDATE', 'NOW', 'INTERVAL', 'DAY', 'MONTH', 'YEAR', 'TRUE',
        'FALSE', 'EXISTS', 'ALL', 'ANY', 'CONCAT',
    ];

    /**
     * @return array<string,mixed>
     */
    public static function loadWhitelist(): array
    {
        if (self::$whitelist !== null) {
            return self::$whitelist;
        }
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'sql_whitelist.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new SqlWhitelistValidationError('No se pudo leer sql_whitelist.json');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['tables']) || !is_array($data['tables'])) {
            throw new SqlWhitelistValidationError('sql_whitelist.json invalido o corrupto');
        }
        self::$whitelist = $data;
        return $data;
    }

    /**
     * Valida una plantilla SQL de solo lectura contra la whitelist maestra.
     *
     * @param string $sql SQL con placeholders con nombre (ej. :empresa_id, :producto_like)
     * @param string[] $declaredParams Nombres de parametros que la skill declara como permitidos
     * @param string|null $reason Si retorna false, aqui queda el motivo legible para mostrar en admin
     */
    public static function validate(string $sql, array $declaredParams, ?string &$reason = null): bool
    {
        $reason = null;
        $sql = trim($sql);

        if ($sql === '') {
            $reason = 'La plantilla SQL esta vacia.';
            return false;
        }

        // 1. Un unico SELECT, sin punto y coma extra (multi-statement).
        $body = rtrim($sql);
        if (substr($body, -1) === ';') {
            $body = substr($body, 0, -1);
        }
        if (strpos($body, ';') !== false) {
            $reason = 'Solo se permite un unico statement (se encontro ";" adicional).';
            return false;
        }
        if (!preg_match('/^select\b/i', $body)) {
            $reason = 'Solo se permiten consultas SELECT.';
            return false;
        }

        // 2. Sin comentarios (forma comun de "esconder" codigo malicioso).
        if (strpos($body, '--') !== false || strpos($body, '#') !== false || strpos($body, '/*') !== false) {
            $reason = 'No se permiten comentarios SQL en la plantilla.';
            return false;
        }

        // 3. Palabras clave prohibidas (DML/DDL, UNION, funciones peligrosas, etc.)
        $whitelist = self::loadWhitelist();
        $forbiddenKeywords = $whitelist['forbidden_keywords'] ?? [];
        foreach ($forbiddenKeywords as $keyword) {
            if (preg_match('/\b' . preg_quote((string)$keyword, '/') . '\b/i', $body)) {
                $reason = 'Palabra clave no permitida: ' . $keyword;
                return false;
            }
        }

        // 4. Esquemas prohibidos (information_schema, mysql, etc.)
        $forbiddenSchemas = $whitelist['forbidden_schemas'] ?? [];
        foreach ($forbiddenSchemas as $schema) {
            if (preg_match('/\b' . preg_quote((string)$schema, '/') . '\s*\./i', $body)) {
                $reason = 'No se permite acceder al esquema: ' . $schema;
                return false;
            }
        }

        // 5. Extraer tablas referenciadas (FROM / JOIN) y validarlas contra la whitelist.
        $tables = self::extractTables($body, $reason);
        if ($tables === null) {
            return false; // $reason ya seteado por extractTables
        }
        if (empty($tables)) {
            $reason = 'No se detecto ninguna tabla en la consulta (FROM/JOIN).';
            return false;
        }

        $allowedTables = $whitelist['tables'];
        $aliasToTable = [];
        $usedTableNames = [];
        foreach ($tables as $ref) {
            $tableName = strtolower($ref['table']);
            if (!isset($allowedTables[$tableName])) {
                $reason = 'Tabla no permitida: ' . $ref['table'];
                return false;
            }
            $usedTableNames[] = $tableName;
            $aliasToTable[strtolower($ref['alias'] ?? $ref['table'])] = $tableName;
        }

        // 6. Filtro de tenant obligatorio (empresa_id) para cada tabla usada que lo requiera.
        foreach (array_unique($usedTableNames) as $tableName) {
            $tenantColumn = $allowedTables[$tableName]['tenant_column'] ?? null;
            if (!$tenantColumn) {
                continue;
            }
            // No se permite un valor literal para la columna de tenant (ej. empresa_id = 24).
            if (preg_match('/\b' . preg_quote($tenantColumn, '/') . '\s*=\s*[\'"]?\d/i', $body)) {
                $reason = "La columna \"$tenantColumn\" no puede tener un valor literal; usa un placeholder con nombre (:$tenantColumn).";
                return false;
            }
            // Debe existir un placeholder con nombre para el filtro de tenant.
            if (strpos($body, ':' . $tenantColumn) === false) {
                $reason = "Falta el filtro obligatorio por \"$tenantColumn\" (agrega \"AND $tenantColumn = :$tenantColumn\").";
                return false;
            }
        }

        // 7. Placeholders: todo :param usado en el SQL debe estar declarado por la skill.
        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $body, $paramMatches);
        $usedParams = array_unique($paramMatches[1] ?? []);
        $declaredLower = array_map('strtolower', $declaredParams);
        foreach ($usedParams as $param) {
            if (!in_array(strtolower($param), $declaredLower, true)) {
                $reason = "El placeholder :$param no esta declarado en params_permitidos de la skill.";
                return false;
            }
        }

        // 8. Chequeo best-effort de columnas: cada identificador que no sea
        //    keyword/alias/tabla debe pertenecer a la lista de columnas
        //    permitidas de alguna de las tablas usadas. Esto es una capa
        //    adicional, no reemplaza el usuario de BD read-only (ver docblock).
        $allowedColumns = [];
        foreach (array_unique($usedTableNames) as $tableName) {
            foreach ($allowedTables[$tableName]['columns'] ?? [] as $col) {
                $allowedColumns[strtolower($col)] = true;
            }
        }

        $withoutAliasesDeclared = preg_replace('/\bAS\s+[a-zA-Z_][a-zA-Z0-9_]*/i', ' ', $body);
        $withoutStrings = preg_replace("/'[^']*'/", "''", (string)$withoutAliasesDeclared);
        $withoutPlaceholders = preg_replace('/:[a-zA-Z_][a-zA-Z0-9_]*/', ' ', (string)$withoutStrings);
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', (string)$withoutPlaceholders, $idMatches);
        $identifiers = $idMatches[1] ?? [];

        foreach ($identifiers as $identifier) {
            $lower = strtolower($identifier);
            if (in_array(strtoupper($identifier), self::KEYWORD_STOPLIST, true)) {
                continue;
            }
            if (isset($allowedTables[$lower])) {
                continue; // es un nombre de tabla
            }
            if (isset($aliasToTable[$lower])) {
                continue; // es un alias de tabla
            }
            if (isset($allowedColumns[$lower])) {
                continue; // es una columna permitida
            }
            if (is_numeric($identifier)) {
                continue;
            }
            $reason = "Identificador no reconocido (no es tabla, alias ni columna permitida): \"$identifier\".";
            return false;
        }

        return true;
    }

    /**
     * Valida el "envelope" (contenedor) de una skill tipo sql_readonly, es
     * decir la estructura del JSON completo -- no el SQL en si (eso lo hace
     * validate()). Formato canonico (schema_version 1):
     *
     * {
     *   "id": "slug",
     *   "status": "aprobada" | "activa" | "pendiente" | "descartada",
     *   "created_at": "2026-07-01T12:00:00-04:00",
     *   "source_entry_id": "id de la entry original en agent_unanswered.txt",
     *   "schema_version": 1,
     *   "tipo": "sql_readonly",
     *   "intent": "slug_del_intent",
     *   "patterns": ["frase 1", "frase 2"],
     *   "sql_template": "SELECT ... WHERE empresa_id = :empresa_id ...",
     *   "tablas_referenciadas": ["productos"],
     *   "params_permitidos": {
     *     "empresa_id": {"tipo": "int", "fuente": "contexto", "requerido": true},
     *     "producto_like": {"tipo": "string", "fuente": "extraido", "requerido": false, "max_length": 80, "regex": "^[a-zA-Z0-9 ]+$"}
     *   },
     *   "row_limit": 50,
     *   "notes": "texto libre"
     * }
     *
     * Reglas no negociables:
     *  - "fuente":"contexto" es OBLIGATORIO para empresa_id/sucursal_id/usuario_id
     *    (nunca se toman de texto libre del usuario ni de una skill "extraido").
     *  - todo parametro "fuente":"extraido" debe declarar al menos una
     *    restriccion (regex, max_length o enum) -- no se permite un string
     *    libre sin validar que termine interpolado en una consulta real.
     *  - "tablas_referenciadas" debe coincidir exactamente con las tablas que
     *    el validador de SQL extrae del propio sql_template (si no coinciden,
     *    se rechaza -- evita que la skill "declare" menos tablas de las que
     *    realmente toca, o que quede desactualizada tras una edicion manual).
     *  - row_limit es obligatorio y debe ser un entero positivo <= 200.
     */
    public static function validateSkillEnvelope(array $skill, ?string &$reason = null): bool
    {
        $reason = null;

        if (($skill['tipo'] ?? null) !== 'sql_readonly') {
            $reason = 'tipo debe ser "sql_readonly".';
            return false;
        }
        foreach (['id', 'status', 'intent', 'sql_template'] as $field) {
            if (!isset($skill[$field]) || $skill[$field] === '') {
                $reason = "Falta el campo obligatorio \"$field\".";
                return false;
            }
        }
        if (!isset($skill['patterns']) || !is_array($skill['patterns']) || empty($skill['patterns'])) {
            $reason = 'patterns debe ser un arreglo no vacio.';
            return false;
        }

        $rowLimit = $skill['row_limit'] ?? null;
        if (!is_int($rowLimit) || $rowLimit < 1 || $rowLimit > 200) {
            $reason = 'row_limit debe ser un entero entre 1 y 200.';
            return false;
        }

        $paramsPermitidos = $skill['params_permitidos'] ?? [];
        if (!is_array($paramsPermitidos)) {
            $reason = 'params_permitidos debe ser un objeto (mapa nombre->definicion).';
            return false;
        }
        $tenantLikeNames = ['empresa_id', 'sucursal_id', 'usuario_id'];
        foreach ($paramsPermitidos as $name => $def) {
            if (!is_array($def) || !isset($def['fuente'])) {
                $reason = "El parametro \"$name\" debe declarar \"fuente\" (contexto|extraido).";
                return false;
            }
            if (in_array($name, $tenantLikeNames, true) && $def['fuente'] !== 'contexto') {
                $reason = "El parametro \"$name\" debe tener fuente \"contexto\" (nunca extraido del mensaje del usuario).";
                return false;
            }
            if ($def['fuente'] === 'extraido') {
                $hasConstraint = isset($def['regex']) || isset($def['max_length']) || isset($def['enum']);
                if (!$hasConstraint) {
                    $reason = "El parametro \"$name\" (fuente extraido) debe declarar regex, max_length o enum.";
                    return false;
                }
            } elseif ($def['fuente'] !== 'contexto') {
                $reason = "El parametro \"$name\" tiene una fuente invalida: \"{$def['fuente']}\" (solo se permite contexto|extraido).";
                return false;
            }
        }

        // Validar el SQL en si contra la whitelist (tablas/columnas/tenant/placeholders).
        $declaredParams = array_keys($paramsPermitidos);
        if (!self::validate((string)$skill['sql_template'], $declaredParams, $sqlReason)) {
            $reason = $sqlReason;
            return false;
        }

        // tablas_referenciadas declaradas deben coincidir con lo que el SQL realmente usa.
        $extractedTables = self::extractTables((string)$skill['sql_template'], $extractReason);
        if ($extractedTables === null) {
            $reason = $extractReason;
            return false;
        }
        $extractedNames = array_unique(array_map(
            static fn (array $ref): string => strtolower($ref['table']),
            $extractedTables
        ));
        sort($extractedNames);
        $declaredNames = array_unique(array_map('strtolower', $skill['tablas_referenciadas'] ?? []));
        sort($declaredNames);
        if ($extractedNames !== $declaredNames) {
            $reason = 'tablas_referenciadas (' . implode(',', $declaredNames) . ') no coincide con las tablas reales del SQL (' . implode(',', $extractedNames) . ').';
            return false;
        }

        return true;
    }

    /**
     * Extrae pares {table, alias} de las clausulas FROM/JOIN.
     * Rechaza (retorna null + $reason) subqueries derivadas y tablas
     * calificadas por base de datos (schema.tabla).
     *
     * @return array<int,array{table:string,alias:?string}>|null
     */
    private static function extractTables(string $sql, ?string &$reason): ?array
    {
        if (preg_match('/\b(?:FROM|JOIN)\s*\(/i', $sql)) {
            $reason = 'No se permiten subqueries derivadas (FROM/JOIN seguido de subconsulta) en la version actual.';
            return null;
        }

        $pattern = '/\b(?:FROM|JOIN)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?(?:\s*\.\s*`?[a-zA-Z_][a-zA-Z0-9_]*`?)?(?:\s+(?:AS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?)?/i';
        if (!preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $result = [];
        foreach ($matches as $match) {
            $table = $match[1];
            $alias = isset($match[2]) && $match[2] !== '' ? $match[2] : null;
            if ($alias !== null && in_array(strtoupper($alias), self::KEYWORD_STOPLIST, true)) {
                $alias = null;
            }
            $result[] = ['table' => $table, 'alias' => $alias];
        }

        // Detectar tabla calificada por base de datos (schema.tabla) de forma explicita.
        if (preg_match('/\b(?:FROM|JOIN)\s+`?[a-zA-Z_][a-zA-Z0-9_]*`?\s*\.\s*`?[a-zA-Z_][a-zA-Z0-9_]*`?/i', $sql)) {
            $reason = 'No se permite calificar tablas con base de datos (schema.tabla).';
            return null;
        }

        return $result;
    }
}
