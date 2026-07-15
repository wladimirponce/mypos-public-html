-- Hardening de enlaces promocionales y tokens publicos de verificacion.
CREATE TABLE IF NOT EXISTS suscripcion_promo_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(60) NOT NULL,
    descripcion VARCHAR(180) NULL,
    plan_id VARCHAR(50) NOT NULL,
    precio_clp INT UNSIGNED NOT NULL,
    moneda VARCHAR(3) NOT NULL DEFAULT 'CLP',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_expiracion DATE NULL,
    usos INT UNSIGNED NOT NULL DEFAULT 0,
    max_usos INT UNSIGNED NULL,
    creado_por BIGINT UNSIGNED NULL,
    creado_el DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_el DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_promo_codigo (codigo),
    KEY idx_promo_activo (activo, fecha_expiracion),
    KEY idx_promo_capacidad (activo, max_usos, usos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE suscripcion_promo_links
    ADD COLUMN IF NOT EXISTS max_usos INT UNSIGNED NULL AFTER usos;

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS email_verification_token_hash CHAR(64) NULL AFTER email_verification_token;

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS email_verification_expires_at DATETIME NULL AFTER email_verification_token_hash;

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS email_verification_sent_at DATETIME NULL AFTER email_verification_expires_at;

-- Convierte tokens antiguos a hash antes de borrar el valor en claro.
UPDATE usuarios
SET email_verification_token_hash = SHA2(email_verification_token, 256),
    email_verification_expires_at = COALESCE(email_verification_expires_at, DATE_ADD(NOW(), INTERVAL 24 HOUR)),
    email_verification_sent_at = COALESCE(email_verification_sent_at, NOW())
WHERE email_verification_token IS NOT NULL
  AND email_verification_token <> ''
  AND email_verification_token_hash IS NULL;

UPDATE usuarios
SET email_verification_token = NULL
WHERE email_verification_token IS NOT NULL;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('077_auth_public_tokens_hardening');
