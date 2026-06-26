-- =============================================================================
-- Migración 007 — Módulo SaaS (Usuarios, CRM, Tickets, Suscripción)
-- Fecha    : 2026-05-25
-- =============================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- 1. sii_usuario (Usuarios del sistema)
CREATE TABLE IF NOT EXISTS sii_usuario (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id INT UNSIGNED NOT NULL,
    sucursal_id INT UNSIGNED NULL,
    nombre VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol VARCHAR(50) NOT NULL DEFAULT 'cajero' COMMENT 'admin, cajero, supervisor',
    pin_caja VARCHAR(10) NULL COMMENT 'PIN para login rápido en POS',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuario_email (email),
    CONSTRAINT fk_usu_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT,
    CONSTRAINT fk_usu_sucursal FOREIGN KEY (sucursal_id) REFERENCES sii_sucursal(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. saas_suscripcion (Suscripciones)
CREATE TABLE IF NOT EXISTS saas_suscripcion (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id INT UNSIGNED NOT NULL,
    plan VARCHAR(50) NOT NULL DEFAULT 'basico' COMMENT 'basico, comercial, empresa',
    cuota_mensual DECIMAL(10,0) NOT NULL DEFAULT 0,
    dia_corte TINYINT NOT NULL DEFAULT 5,
    estado_pago ENUM('al_dia', 'moroso', 'suspendido') NOT NULL DEFAULT 'al_dia',
    fecha_ultimo_pago DATE NULL,
    notas TEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suscripcion_empresa (empresa_id),
    CONSTRAINT fk_susc_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. saas_feature_toggles (Overrides y módulos)
CREATE TABLE IF NOT EXISTS saas_feature_toggles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id INT UNSIGNED NOT NULL,
    feature VARCHAR(100) NOT NULL COMMENT 'ej: compras_ia, multisuursal, pos_offline',
    valor VARCHAR(255) NOT NULL DEFAULT '1',
    PRIMARY KEY (id),
    UNIQUE KEY uq_toggle_empresa_feature (empresa_id, feature),
    CONSTRAINT fk_feat_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. saas_contacto (CRM)
CREATE TABLE IF NOT EXISTS saas_contacto (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    telefono VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    es_tecnico TINYINT(1) NOT NULL DEFAULT 0,
    notas_internas TEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_cont_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. saas_ticket_soporte
CREATE TABLE IF NOT EXISTS saas_ticket_soporte (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NULL,
    asunto VARCHAR(200) NOT NULL,
    estado ENUM('abierto', 'en_progreso', 'resuelto', 'cerrado') NOT NULL DEFAULT 'abierto',
    prioridad ENUM('baja', 'media', 'alta', 'critica') NOT NULL DEFAULT 'media',
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_tkt_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE CASCADE,
    CONSTRAINT fk_tkt_usuario FOREIGN KEY (usuario_id) REFERENCES sii_usuario(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. saas_ticket_mensaje
CREATE TABLE IF NOT EXISTS saas_ticket_mensaje (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NULL COMMENT 'Null si el mensaje es del equipo central',
    mensaje TEXT NOT NULL,
    adjunto_url VARCHAR(500) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_msg_ticket FOREIGN KEY (ticket_id) REFERENCES saas_ticket_soporte(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_usuario FOREIGN KEY (usuario_id) REFERENCES sii_usuario(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;
