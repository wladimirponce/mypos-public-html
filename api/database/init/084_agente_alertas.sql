-- Motor de alertas proactivas del agente IA (Fase 1, docs/AGENTE_PLAN_MEJORA.md).
-- Tablas prefijo agente_: unica familia donde el agente/motor escribe.
-- Aislamiento multiempresa: cada aviso sale SOLO al correo de registro
-- (empresas.email) o al WhatsApp autorizado de ESA empresa.

-- Preferencias por empresa. Si no hay fila, aplican los defaults de
-- AlertasConfigService (todas las alertas criticas activas, canal email).
CREATE TABLE IF NOT EXISTS agente_alertas_config (
    empresa_id BIGINT UNSIGNED NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Interruptor maestro de alertas de la empresa',
    canal_email TINYINT(1) NOT NULL DEFAULT 1,
    canal_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
    whatsapp_numero VARCHAR(20) NULL COMMENT 'Numero autorizado E.164 (569XXXXXXXX); NULL = sin WhatsApp',
    email_alertas VARCHAR(190) NULL COMMENT 'Override; NULL = usar empresas.email (correo de registro)',
    config_json LONGTEXT NULL COMMENT 'JSON: activacion y umbrales por tipo de alerta',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (empresa_id)
    -- Sin FK a empresas(id) a proposito: en produccion el tipo/collation de
    -- empresas.id historico no calza y dispara errno 150. La integridad la
    -- garantiza el runner (itera solo empresas activas existentes).
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de avisos enviados. Base del anti-spam: una alerta con la misma
-- (empresa, tipo, clave) no se repite dentro de su periodo de gracia.
CREATE TABLE IF NOT EXISTS agente_alertas_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(40) NOT NULL COMMENT 'precio_perdida | stock_critico | cierre_pendiente | caja_abierta | ventas_caida | folios_bajos | suscripcion | compras_borrador | resumen_diario',
    clave VARCHAR(120) NOT NULL COMMENT 'Clave de dedupe (producto:precio, fecha, apertura_id...)',
    canal VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'email | whatsapp | email+whatsapp | ninguno',
    estado VARCHAR(20) NOT NULL DEFAULT 'enviada' COMMENT 'enviada | fallida',
    mensaje TEXT NULL,
    detalle_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_agente_alertas_log_dedupe (empresa_id, tipo, clave, created_at),
    KEY idx_agente_alertas_log_empresa (empresa_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ultima corrida de cada chequeo (global, el runner itera todas las empresas
-- en cada pasada). Permite un solo cron cada 15 min con programacion interna.
CREATE TABLE IF NOT EXISTS agente_alertas_estado (
    chequeo VARCHAR(40) NOT NULL,
    last_run_at DATETIME NULL,
    PRIMARY KEY (chequeo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('084_agente_alertas');
