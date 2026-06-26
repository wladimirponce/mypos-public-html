-- =============================================================================
-- Migración 004 — Registrar ALCAINO Y ARAYA SPA como empresa de PRODUCCIÓN
-- Fecha    : 2026-05-17
-- Objetivo : Eliminar el concepto "Legacy" hardcodeado. ALCAINO pasa a ser
--            una empresa real en sii_empresa (producción). El selector dejará
--            de mostrar "Empresa Principal (Legacy)".
-- Ejecutar : UNA sola vez en el servidor (idempotente — re-ejecutable sin daño).
--            mysql -u USER -p DBNAME < 2026_05_17_004_registrar_alcaino_produccion.sql
-- Nota     : Los CAF, archivos y backfill de DTEs históricos los realiza el
--            script PHP complementario:
--            php migrations/2026_05_17_005_migrar_legacy_a_alcaino.php
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. ALCAINO Y ARAYA SPA — empresa de PRODUCCIÓN
--    UNIQUE KEY uq_empresa_rut (rut) garantiza idempotencia: si ya existe,
--    se actualizan los campos descriptivos sin duplicar la fila.
-- -----------------------------------------------------------------------------
INSERT INTO sii_empresa
    (rut, razon_social, giro, acteco, direccion_origen, comuna_origen,
     ciudad_origen, telefono, email_sii, fecha_resolucion, numero_resolucion,
     ambiente_default, activo)
VALUES
    ('77020050-4',
     'ALCAINO Y ARAYA SPA',
     'DROGUERIA, FARMACIA Y PERFUMERIA',
     '["477311"]',
     'Avda. Independencia 3879',
     'Conchalí',
     'Santiago',
     NULL,
     NULL,
     '2014-08-22',
     '80',
     'produccion',
     1)
ON DUPLICATE KEY UPDATE
    razon_social      = VALUES(razon_social),
    giro              = VALUES(giro),
    acteco            = VALUES(acteco),
    direccion_origen  = VALUES(direccion_origen),
    comuna_origen     = VALUES(comuna_origen),
    ciudad_origen     = VALUES(ciudad_origen),
    fecha_resolucion  = VALUES(fecha_resolucion),
    numero_resolucion = VALUES(numero_resolucion),
    ambiente_default  = VALUES(ambiente_default),
    activo            = 1;

-- -----------------------------------------------------------------------------
-- 2. Ambiente por defecto del sistema → producción
--    (ALCAINO ya opera en producción; la certificación usa su propia empresa)
-- -----------------------------------------------------------------------------
INSERT INTO sii_config (clave, valor, tipo, descripcion) VALUES
    ('ambiente_default', 'produccion', 'string', 'Ambiente por defecto: certificacion | produccion')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);

-- -----------------------------------------------------------------------------
-- Verificación (informativa — no falla si se ejecuta vía pipe)
-- -----------------------------------------------------------------------------
SELECT id, rut, razon_social, ambiente_default, activo
FROM   sii_empresa
WHERE  rut = '77020050-4';

-- =============================================================================
-- FIN
-- =============================================================================
