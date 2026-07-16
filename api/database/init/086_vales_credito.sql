-- ===========================================================================
-- 070_vales_credito.sql
--
-- Vale de crédito interno (Fase 1 del plan de capacidades).
-- Pieza compartida: lo emiten los cambios/devoluciones (diferencia a favor del
-- cliente), la futura devolución de envases retornables, o manualmente un
-- administrador. Se canjea como medio de pago "VALE" en el POS (canje parcial
-- permitido: el saldo restante queda en el vale).
--
-- CONTENIDO:
--   1. vales_credito             — el vale con su saldo vigente
--   2. vale_credito_movimientos  — trazabilidad (emisión, canjes, anulación)
--   3. metodo de pago VALE
--   4. permisos vales.ver / vales.emitir / vales.anular
-- ===========================================================================

CREATE TABLE IF NOT EXISTS vales_credito (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id        BIGINT UNSIGNED NOT NULL,
    sucursal_id       BIGINT UNSIGNED NULL,
    cliente_id        BIGINT UNSIGNED NULL,
    codigo            VARCHAR(20)     NOT NULL,       -- código imprimible que digita el cajero
    monto_original    INT             NOT NULL,
    saldo             INT             NOT NULL,
    estado            ENUM('ACTIVO','CANJEADO','ANULADO','VENCIDO') NOT NULL DEFAULT 'ACTIVO',
    origen            ENUM('CAMBIO','ENVASE','MANUAL') NOT NULL DEFAULT 'MANUAL',
    referencia_tipo   VARCHAR(40)     NULL,           -- ej: 'CAMBIO', 'VENTA'
    referencia_id     BIGINT UNSIGNED NULL,
    fecha_vencimiento DATE            NULL,
    observacion       VARCHAR(255)    NULL,
    created_by        BIGINT UNSIGNED NOT NULL,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP       NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vales_codigo (empresa_id, codigo),
    KEY idx_vales_estado   (empresa_id, estado),
    KEY idx_vales_cliente  (empresa_id, cliente_id),
    CONSTRAINT fk_vales_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_vales_usuario FOREIGN KEY (created_by) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vale_credito_movimientos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    vale_id         BIGINT UNSIGNED NOT NULL,
    tipo            ENUM('EMISION','CANJE','ANULACION','VENCIMIENTO') NOT NULL,
    monto           INT             NOT NULL,          -- positivo: emisión; negativo: canje
    saldo_resultante INT            NOT NULL,
    venta_id        BIGINT UNSIGNED NULL,              -- venta donde se canjeó
    usuario_id      BIGINT UNSIGNED NOT NULL,
    observacion     VARCHAR(255)    NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vale_mov_vale (empresa_id, vale_id),
    CONSTRAINT fk_vale_mov_vale FOREIGN KEY (vale_id) REFERENCES vales_credito(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Medio de pago VALE ──────────────────────────────────────────────────────
INSERT INTO metodos_pago (codigo, nombre) VALUES ('VALE', 'Vale de crédito')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), activo = 1;

-- ── Permisos ────────────────────────────────────────────────────────────────
INSERT INTO permisos (codigo, nombre, descripcion, activo)
SELECT x.codigo, x.nombre, x.descripcion, 1
FROM (
    SELECT 'vales.ver' codigo, 'Ver vales de credito' nombre, 'Permite listar y consultar vales de credito' descripcion UNION ALL
    SELECT 'vales.emitir', 'Emitir vales de credito', 'Permite emitir vales de credito manuales' UNION ALL
    SELECT 'vales.anular', 'Anular vales de credito', 'Permite anular un vale de credito vigente'
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

-- Admin: todo. Cajero/Vendedor: ver (el canje ocurre dentro de la venta).
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('vales.ver', 'vales.emitir', 'vales.anular')
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo = 'vales.ver'
WHERE r.codigo IN ('CAJERO', 'VENDEDOR')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('086_vales_credito');
