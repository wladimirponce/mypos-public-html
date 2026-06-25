-- ============================================================
-- Migracion 074: Capa de sincronizacion local del correo
-- Mensajes IMAP persistidos en BD para habilitar paginacion,
-- busqueda en cuerpo, hilos, agrupacion CRM y resumenes IA.
-- ============================================================

CREATE TABLE IF NOT EXISTS correo_mensajes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    cuenta_id BIGINT UNSIGNED NOT NULL,
    uid INT UNSIGNED NOT NULL,
    carpeta ENUM('inbox','enviados','papelera') NOT NULL DEFAULT 'inbox',
    message_id VARCHAR(255) NULL,
    in_reply_to VARCHAR(255) NULL,
    referencias TEXT NULL,
    hilo_id BIGINT UNSIGNED NULL,
    remitente VARCHAR(320) NULL,
    remitente_nombre VARCHAR(190) NULL,
    destinatarios TEXT NULL,
    cc TEXT NULL,
    asunto VARCHAR(500) NULL,
    snippet VARCHAR(300) NULL,
    body_text MEDIUMTEXT NULL,
    body_html MEDIUMTEXT NULL,
    fecha DATETIME NULL,
    seen TINYINT(1) NOT NULL DEFAULT 0,
    flagged TINYINT(1) NOT NULL DEFAULT 0,
    tiene_adjuntos TINYINT(1) NOT NULL DEFAULT 0,
    tamano INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_msg_cuenta_carpeta_uid (cuenta_id, carpeta, uid),
    KEY idx_msg_empresa_carpeta_fecha (empresa_id, carpeta, fecha),
    KEY idx_msg_hilo (hilo_id),
    KEY idx_msg_remitente (empresa_id, remitente),
    FULLTEXT KEY ft_msg (asunto, body_text, remitente, destinatarios),
    CONSTRAINT fk_msg_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_msg_cuenta FOREIGN KEY (cuenta_id) REFERENCES correo_cuentas(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS correo_hilos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    cuenta_id BIGINT UNSIGNED NOT NULL,
    asunto_normalizado VARCHAR(500) NULL,
    root_message_id VARCHAR(255) NULL,
    ultimo_mensaje_fecha DATETIME NULL,
    total_mensajes INT UNSIGNED NOT NULL DEFAULT 1,
    no_leidos INT UNSIGNED NOT NULL DEFAULT 0,
    contacto_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_hilo_empresa_fecha (empresa_id, ultimo_mensaje_fecha),
    KEY idx_hilo_root (cuenta_id, root_message_id),
    CONSTRAINT fk_hilo_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS correo_contactos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(320) NOT NULL,
    nombre VARCHAR(190) NULL,
    tipo ENUM('proveedor','cliente','banco','otro') NOT NULL DEFAULT 'otro',
    proveedor_id BIGINT UNSIGNED NULL,
    cliente_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contacto_empresa_email (empresa_id, email),
    KEY idx_contacto_tipo (empresa_id, tipo),
    CONSTRAINT fk_contacto_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS correo_resumenes_ia (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    hilo_id BIGINT UNSIGNED NULL,
    mensaje_id BIGINT UNSIGNED NULL,
    resumen TEXT NOT NULL,
    modelo VARCHAR(60) NULL,
    hash_contenido CHAR(64) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_resumen_hilo (hilo_id),
    KEY idx_resumen_mensaje (mensaje_id),
    CONSTRAINT fk_resumen_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado de sincronizacion incremental por cuenta y carpeta.
CREATE TABLE IF NOT EXISTS correo_sync_estado (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cuenta_id BIGINT UNSIGNED NOT NULL,
    carpeta ENUM('inbox','enviados','papelera') NOT NULL DEFAULT 'inbox',
    uid_validity BIGINT UNSIGNED NULL,
    last_uid INT UNSIGNED NOT NULL DEFAULT 0,
    last_sync_at TIMESTAMP NULL,
    UNIQUE KEY uq_sync_cuenta_carpeta (cuenta_id, carpeta),
    CONSTRAINT fk_sync_cuenta FOREIGN KEY (cuenta_id) REFERENCES correo_cuentas(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('074_correo_sync');
