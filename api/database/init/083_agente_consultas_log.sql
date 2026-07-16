-- Bandeja de consultas del agente IA en MySQL (antes: agent/tmp/agent_unanswered.txt).
-- El archivo plano lo reescribian completo DOS procesos (agente Python sin lock,
-- admin PHP con LOCK_EX que Python ignoraba): riesgo real de perdida/corrupcion.
-- El agente INSERTA via POST /api/v1/agente/consultas-log (cuenta de servicio);
-- admin (modulo Consultas IA) lee y actualiza directamente (misma BD).
-- Tabla con prefijo agente_: unica familia donde el agente puede escribir
-- (ver database/scripts/agente_usuario_readonly.sql y docs/AGENTE_PLAN_MEJORA.md).

CREATE TABLE IF NOT EXISTS agente_consultas_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid VARCHAR(64) NOT NULL COMMENT 'Identificador hex generado por el agente (compat con entries legacy)',
    empresa_id BIGINT UNSIGNED NULL,
    sucursal_id BIGINT UNSIGNED NULL,
    thread_id VARCHAR(200) NULL,
    operador VARCHAR(200) NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'agent' COMMENT 'agent | legacy_txt',
    status VARCHAR(20) NOT NULL DEFAULT 'pendiente' COMMENT 'pendiente | seleccionada | aprobada | creada | descartada',
    consulta TEXT NULL,
    respuesta TEXT NULL,
    respuesta_ia TEXT NULL,
    respuesta_ia_tipo VARCHAR(40) NULL,
    propuesta LONGTEXT NULL COMMENT 'JSON: propuesta de skill (intent/tool o sql_readonly) para revision en admin',
    skill_path VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agente_consultas_uid (uid),
    KEY idx_agente_consultas_status (status),
    KEY idx_agente_consultas_empresa (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('083_agente_consultas_log');
