-- Migración 058: Templates HSM de WhatsApp
-- Almacena los templates aprobados por Meta para comunicación fuera de la ventana de 24h.
-- El nombre del template (template_name) debe coincidir exactamente con el registrado en Meta.

CREATE TABLE IF NOT EXISTS whatsapp_templates (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    nombre          VARCHAR(100)    NOT NULL,
    template_name   VARCHAR(100)    NOT NULL,
    idioma          VARCHAR(10)     NOT NULL DEFAULT 'es',
    categoria       ENUM('MARKETING','UTILITY','AUTHENTICATION') NOT NULL DEFAULT 'UTILITY',
    header_texto    VARCHAR(255)    NULL DEFAULT NULL,
    body_texto      TEXT            NOT NULL,
    footer_texto    VARCHAR(255)    NULL DEFAULT NULL,
    variables       JSON            NULL DEFAULT NULL COMMENT 'Nombres de variables: ["nombre","producto"]',
    activo          TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wt_empresa_name (empresa_id, template_name),
    KEY idx_wt_empresa (empresa_id),
    CONSTRAINT fk_wt_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Template "hello_world" que Meta provee por defecto en toda cuenta nueva
-- Cada empresa debe agregar los suyos desde su panel de Meta Business Manager
INSERT IGNORE INTO whatsapp_templates
    (empresa_id, nombre, template_name, idioma, categoria, body_texto, variables)
SELECT
    e.id,
    'Saludo inicial',
    'hello_world',
    'en_US',
    'UTILITY',
    'Hello! How can we help you today?',
    '[]'
FROM empresas e
WHERE EXISTS (SELECT 1 FROM empresa_whatsapp_config ewc WHERE ewc.empresa_id = e.id AND ewc.activo = 1);
