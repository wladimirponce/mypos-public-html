-- =============================================================================
-- Migración 015 — MFA administrativo (segundo factor TOTP)
-- Fecha    : 2026-07-15
-- Sprint   : Seguridad 1 (panel administrativo)
-- Idempotente: usa IF NOT EXISTS; puede re-ejecutarse sin efectos.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. Secreto TOTP por operador. El secreto viaja CIFRADO (AES-256-GCM) con clave
--    independiente y versionada (ADMIN_MFA_KEY); nunca en claro. `confirmado`
--    solo pasa a 1 tras verificar un primer código válido (diseño, punto 5).
CREATE TABLE IF NOT EXISTS admin_mfa (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id       INT UNSIGNED NOT NULL,
    secret_cifrado TEXT         NOT NULL,
    confirmado     TINYINT(1)   NOT NULL DEFAULT 0,
    confirmado_en  DATETIME     NULL,
    creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_mfa_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Enrolamiento TOTP de operadores del panel (secreto cifrado en reposo)';

-- 2. Códigos de recuperación de un solo uso. Se guardan solo como hash SHA-256
--    (diseño, punto 4); el valor en claro se muestra una única vez al generarlos.
CREATE TABLE IF NOT EXISTS admin_mfa_recovery (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id  INT UNSIGNED NOT NULL,
    code_hash CHAR(64)     NOT NULL,
    usado_en  DATETIME     NULL,
    creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_mfa_recovery_admin (admin_id),
    UNIQUE KEY uq_admin_mfa_recovery_hash (code_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Códigos de recuperación MFA (hash, un solo uso)';
