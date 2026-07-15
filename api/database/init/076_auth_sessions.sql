-- Sesiones revocables para access/refresh token SaaS.
CREATE TABLE IF NOT EXISTS auth_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    family_id CHAR(36) NOT NULL,
    refresh_token_hash CHAR(64) NOT NULL,
    replaced_by_hash CHAR(64) NULL,
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoke_reason VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_sessions_refresh_hash (refresh_token_hash),
    KEY idx_auth_sessions_user_active (usuario_id, revoked_at, expires_at),
    KEY idx_auth_sessions_family (family_id),
    CONSTRAINT fk_auth_sessions_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('076_auth_sessions');
