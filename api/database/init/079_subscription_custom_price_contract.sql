-- Completa el contrato que AuthService y SuscripcionService requieren para
-- registrar y cobrar planes con precio personalizado. Es idempotente para
-- instalaciones que ya recibieron estas columnas mediante 075_promo_links.
ALTER TABLE empresas_suscripcion
    ADD COLUMN IF NOT EXISTS precio_especial_clp INT UNSIGNED NULL
        COMMENT 'Precio mensual especial (con IVA). NULL = usa precio de catalogo'
        AFTER plan_id;

ALTER TABLE empresas_suscripcion
    ADD COLUMN IF NOT EXISTS promo_codigo VARCHAR(60) NULL
        COMMENT 'Codigo del link promocional usado durante el registro'
        AFTER precio_especial_clp;

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('079_subscription_custom_price_contract');
