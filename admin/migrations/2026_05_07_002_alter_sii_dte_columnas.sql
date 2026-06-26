-- =============================================================================
-- Migración 002 — Columnas faltantes en sii_dte
-- Fecha    : 2026-05-07
-- Ejecutar : una sola vez sobre la BD de producción y certificación
-- =============================================================================

SET NAMES utf8mb4;

-- Agregar columnas que el repositorio usa pero no estaban en la migración 001

ALTER TABLE sii_dte
    ADD COLUMN IF NOT EXISTS giro_receptor      VARCHAR(200) NULL COMMENT 'Giro del receptor'
        AFTER razon_receptor,
    ADD COLUMN IF NOT EXISTS direccion_receptor VARCHAR(200) NULL COMMENT 'Dirección del receptor'
        AFTER giro_receptor,
    ADD COLUMN IF NOT EXISTS comuna_receptor    VARCHAR(100) NULL COMMENT 'Comuna del receptor'
        AFTER direccion_receptor,
    ADD COLUMN IF NOT EXISTS ciudad_receptor    VARCHAR(100) NULL COMMENT 'Ciudad del receptor'
        AFTER comuna_receptor;

-- Verificar resultado
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'sii_dte'
ORDER BY ORDINAL_POSITION;
