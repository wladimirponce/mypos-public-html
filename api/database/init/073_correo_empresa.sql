-- ============================================================
-- Migracion 073: Mini sistema de correo por empresa
-- ============================================================

CREATE TABLE IF NOT EXISTS correo_cuentas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    nombre VARCHAR(190) NULL,
    username VARCHAR(190) NOT NULL,
    password_encrypted TEXT NULL,
    imap_host VARCHAR(190) NOT NULL DEFAULT 'mail.mypos.cl',
    imap_port INT NOT NULL DEFAULT 993,
    imap_encryption ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
    smtp_host VARCHAR(190) NOT NULL DEFAULT 'mail.mypos.cl',
    smtp_port INT NOT NULL DEFAULT 465,
    smtp_encryption ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_correo_cuenta_empresa_email (empresa_id, email),
    KEY idx_correo_cuentas_empresa (empresa_id, activo),
    CONSTRAINT fk_correo_cuentas_empresa
        FOREIGN KEY (empresa_id) REFERENCES empresas(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permisos (codigo, nombre, descripcion)
SELECT x.codigo, x.nombre, x.descripcion
FROM (
    SELECT 'correo.ver' AS codigo, 'Ver correo empresa' AS nombre, 'Permite consultar bandejas y mensajes del correo de la empresa' AS descripcion UNION ALL
    SELECT 'correo.enviar', 'Enviar correo empresa', 'Permite enviar y responder correos desde la cuenta configurada' UNION ALL
    SELECT 'correo.configurar', 'Configurar correo empresa', 'Permite configurar credenciales IMAP/SMTP del correo de la empresa'
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp
      WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
  );

INSERT IGNORE INTO schema_migrations (migration) VALUES ('073_correo_empresa');
