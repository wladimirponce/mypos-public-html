-- ===========================================================================
-- 069_venta_restringida.sql
--
-- Control de venta restringida (Fase 1 del plan de capacidades):
--   - Alcohol (Ley 19.925): exigir cédula en cada venta + horario legal de
--     expendio para llevar (09:00–01:00 semana, 09:00–03:00 sáb/festivos,
--     configurable por empresa porque cada municipalidad puede restringirlo).
--   - Tabaco/vapes (Ley 19.419 y 21.642): exigir cédula, sin horario.
--
-- CONTENIDO:
--   1. productos.venta_restringida — marca el producto (NINGUNA/ALCOHOL/TABACO)
--   2. ventas.edad_verificada — auditoría de que el cajero exigió cédula
--   3. empresa_configuracion_operativa — activación y horario de alcohol
-- ===========================================================================

-- ── 1. Marca de restricción por producto ────────────────────────────────────
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS venta_restringida ENUM('NINGUNA','ALCOHOL','TABACO')
    NOT NULL DEFAULT 'NINGUNA'
    COMMENT 'Restriccion legal de venta: exige cedula (ALCOHOL ademas respeta horario)'
    AFTER requiere_lote;

-- ── 2. Auditoría de verificación de edad en la venta ────────────────────────
ALTER TABLE ventas
    ADD COLUMN IF NOT EXISTS edad_verificada TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = el cajero confirmo cedula de identidad (venta con items restringidos)'
    AFTER condicion_pago;

-- ── 3. Configuración por empresa ─────────────────────────────────────────────
-- control_edad_activo: exige confirmación de cédula al vender restringidos.
-- control_horario_alcohol_activo: bloquea alcohol fuera del horario configurado.
-- Horario: inicio compartido; fin distinto para semana (lun-vie) y
-- sáb/dom (la ley extiende sábados y vísperas de festivo hasta las 03:00).
-- Un fin menor que el inicio significa que cruza medianoche (01:00 = 01:00 del
-- día siguiente).
ALTER TABLE empresa_configuracion_operativa
    ADD COLUMN IF NOT EXISTS control_edad_activo TINYINT(1) NOT NULL DEFAULT 1
    AFTER notif_whatsapp_activo;

ALTER TABLE empresa_configuracion_operativa
    ADD COLUMN IF NOT EXISTS control_horario_alcohol_activo TINYINT(1) NOT NULL DEFAULT 0
    AFTER control_edad_activo;

ALTER TABLE empresa_configuracion_operativa
    ADD COLUMN IF NOT EXISTS alcohol_hora_inicio TIME NOT NULL DEFAULT '09:00:00'
    AFTER control_horario_alcohol_activo;

ALTER TABLE empresa_configuracion_operativa
    ADD COLUMN IF NOT EXISTS alcohol_hora_fin TIME NOT NULL DEFAULT '01:00:00'
    AFTER alcohol_hora_inicio;

ALTER TABLE empresa_configuracion_operativa
    ADD COLUMN IF NOT EXISTS alcohol_hora_fin_finde TIME NOT NULL DEFAULT '03:00:00'
    AFTER alcohol_hora_fin;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('085_venta_restringida');
