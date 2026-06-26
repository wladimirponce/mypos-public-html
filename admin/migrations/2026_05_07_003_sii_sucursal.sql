-- =============================================================================
-- Migración 003 — Tabla sii_sucursal + sucursal_id en sii_dte
-- Fecha    : 2026-05-07
-- =============================================================================

SET NAMES utf8mb4;

-- Sucursales de la empresa emisora
CREATE TABLE IF NOT EXISTS sii_sucursal (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id  INT UNSIGNED    NOT NULL,
    nombre      VARCHAR(100)    NOT NULL COMMENT 'Nombre o código de la sucursal',
    direccion   VARCHAR(200)    NOT NULL,
    comuna      VARCHAR(100)    NOT NULL,
    ciudad      VARCHAR(100)             NULL,
    telefono    VARCHAR(30)              NULL,
    activa      TINYINT(1)      NOT NULL DEFAULT 1,
    creado_en   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_suc_empresa FOREIGN KEY (empresa_id) REFERENCES sii_empresa(id) ON DELETE RESTRICT,
    KEY idx_suc_empresa (empresa_id, activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Sucursales del emisor — aparecen en la representación impresa';

-- Agregar sucursal_id a sii_dte
ALTER TABLE sii_dte
    ADD COLUMN IF NOT EXISTS sucursal_id INT UNSIGNED NULL COMMENT 'Sucursal emisora (opcional)'
        AFTER empresa_id;
