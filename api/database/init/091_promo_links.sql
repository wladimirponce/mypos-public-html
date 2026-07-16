-- ===========================================================================
-- 075_promo_links.sql
--
-- Links de precio especial para el registro (onboarding de cero).
--
-- El operador de plataforma (dueño de MyPOS) genera un link con un precio
-- mensual custom. Se lo envía a un prospecto; éste se registra en
-- /register?plan=<plan>&promo=<codigo>, y al cobrar paga ese precio especial en
-- vez del de catálogo. El precio es RECURRENTE: se aplica cada mes hasta que se
-- cambie a mano. El link es REUTILIZABLE SIN LÍMITE (campaña abierta), con
-- fecha de expiración opcional.
-- ===========================================================================

-- ── 1. Catálogo de links de promoción ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `suscripcion_promo_links` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `codigo`            VARCHAR(60)  NOT NULL,
    `descripcion`       VARCHAR(180) NULL,
    `plan_id`           VARCHAR(50)  NOT NULL,
    -- Precio mensual especial CON IVA incluido (misma semántica que
    -- PlanCatalog::price_clp, que ya viene con IVA). NULL no se admite: un link
    -- de precio siempre define un monto.
    `precio_clp`        INT UNSIGNED NOT NULL,
    `moneda`            VARCHAR(3)   NOT NULL DEFAULT 'CLP',
    `activo`            TINYINT(1)   NOT NULL DEFAULT 1,
    `fecha_expiracion`  DATE         NULL,
    `usos`              INT UNSIGNED NOT NULL DEFAULT 0,
    `creado_por`        BIGINT UNSIGNED NULL,
    `creado_el`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_el`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_promo_codigo` (`codigo`),
    KEY `idx_promo_activo` (`activo`, `fecha_expiracion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Precio especial recurrente en la suscripción de la empresa ────────────
-- Cuando `precio_especial_clp` no es NULL, el cobro mensual usa ese monto en
-- lugar del precio de catálogo del plan, de forma indefinida.
ALTER TABLE `empresas_suscripcion`
    ADD COLUMN IF NOT EXISTS `precio_especial_clp` INT UNSIGNED NULL
        COMMENT 'Precio mensual especial (con IVA). NULL = usa precio de catálogo'
        AFTER `plan_id`;

ALTER TABLE `empresas_suscripcion`
    ADD COLUMN IF NOT EXISTS `promo_codigo` VARCHAR(60) NULL
        COMMENT 'Código del link de promoción con el que se registró la empresa'
        AFTER `precio_especial_clp`;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('091_promo_links');
