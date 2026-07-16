-- Unifica en el instalador oficial campos que antes existian solo en la rama
-- historica de migraciones 069/073. Es idempotente para produccion.
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS venta_restringida ENUM('NINGUNA','ALCOHOL','TABACO')
        NOT NULL DEFAULT 'NINGUNA'
        COMMENT 'Restriccion legal: exige cedula; alcohol puede exigir horario'
        AFTER requiere_lote;

ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS tipo_margen ENUM('markup','margen')
        NOT NULL DEFAULT 'markup'
        COMMENT 'Interpretacion de margen_ganancia'
        AFTER margen_ganancia;

ALTER TABLE ventas
    ADD COLUMN IF NOT EXISTS edad_verificada TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Confirma control de identidad en venta restringida'
        AFTER condicion_pago;

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

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('081_product_pricing_restricted_contract');
