-- =============================================================================
-- Migración 009 — admin_usuario (operadores del dashboard) + unidad_sii
-- Fecha    : 2026-05-25
-- =============================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- 1. Tabla de operadores del panel de administración central.
--    A diferencia de sii_usuario (usuarios POS por empresa), admin_usuario
--    son los superadmins que gestionan TODAS las empresas desde el dashboard.
--    NO tiene FK a sii_empresa porque su acceso es transversal.
CREATE TABLE IF NOT EXISTS admin_usuario (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nombre      VARCHAR(150)    NOT NULL,
    email       VARCHAR(150)    NOT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    rol         ENUM('superadmin','operador') NOT NULL DEFAULT 'operador',
    activo      TINYINT(1)      NOT NULL DEFAULT 1,
    ultimo_login DATETIME       NULL,
    creado_en   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Operadores del dashboard central (no ligados a una empresa específica)';

-- 2. Agregar columna unidad_sii a sii_empresa si no existe.
--    Permite almacenar la Dirección Regional / Unidad SII del emisor
--    (ej: "SII - SANTIAGO NORTE") sin depender de una constante hardcodeada.
ALTER TABLE sii_empresa
    ADD COLUMN IF NOT EXISTS unidad_sii VARCHAR(100) NULL
        COMMENT 'Dirección Regional / Unidad SII del emisor'
        AFTER ciudad_origen;

-- 3. Semilla: actualizar la empresa Alcaino y Araya SPA con su unidad SII.
UPDATE sii_empresa
   SET unidad_sii = 'SII - SANTIAGO NORTE'
 WHERE rut = '77020050-4';

SET foreign_key_checks = 1;
