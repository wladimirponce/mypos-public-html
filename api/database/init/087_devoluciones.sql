-- ===========================================================================
-- 071_devoluciones.sql
--
-- Devoluciones parciales y cambios (Fase 1 del plan de capacidades).
--
-- DISEÑO: una devolución reingresa (o merma) líneas de una venta y reembolsa
-- con VALE de crédito o EFECTIVO (egreso de caja). El "cambio de producto"
-- se compone: devolución → vale → nueva venta pagada con el vale en el POS.
-- Así el cobro/vuelto de la diferencia lo maneja el flujo normal de venta.
--
-- NC electrónica: si la venta original tiene DTE emitido, la devolución queda
-- con nc_estado = PENDIENTE y los datos necesarios para emitir la NC 61
-- referenciada (emisión automática en una fase posterior; mientras tanto se
-- emite desde el admin).
-- ===========================================================================

CREATE TABLE IF NOT EXISTS devoluciones (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id        BIGINT UNSIGNED NOT NULL,
    sucursal_id       BIGINT UNSIGNED NOT NULL,
    venta_id          BIGINT UNSIGNED NOT NULL,
    usuario_id        BIGINT UNSIGNED NOT NULL,
    caja_apertura_id  BIGINT UNSIGNED NULL,       -- caja usada si el reembolso fue en efectivo
    tipo_reembolso    ENUM('VALE','EFECTIVO') NOT NULL DEFAULT 'VALE',
    vale_id           BIGINT UNSIGNED NULL,
    egreso_id         BIGINT UNSIGNED NULL,
    monto_total       INT             NOT NULL,
    motivo            VARCHAR(255)    NOT NULL,
    destino           ENUM('STOCK','MERMA') NOT NULL DEFAULT 'STOCK',
    nc_estado         ENUM('NO_APLICA','PENDIENTE','EMITIDA') NOT NULL DEFAULT 'NO_APLICA',
    nc_documento_id   BIGINT UNSIGNED NULL,       -- documento NC cuando se emita
    metadata_json     TEXT            NULL,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_devoluciones_venta   (empresa_id, venta_id),
    KEY idx_devoluciones_fecha   (empresa_id, created_at),
    CONSTRAINT fk_devoluciones_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_devoluciones_venta   FOREIGN KEY (venta_id)   REFERENCES ventas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devolucion_detalles (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id         BIGINT UNSIGNED NOT NULL,
    devolucion_id      BIGINT UNSIGNED NOT NULL,
    venta_detalle_id   BIGINT UNSIGNED NOT NULL,
    producto_id        BIGINT UNSIGNED NOT NULL,
    cantidad           DECIMAL(14,3)   NOT NULL,
    precio_unitario    INT             NOT NULL,
    total_linea        INT             NOT NULL,
    stock_movimiento_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_dev_det_devolucion (empresa_id, devolucion_id),
    KEY idx_dev_det_venta_detalle (empresa_id, venta_detalle_id),
    CONSTRAINT fk_dev_det_devolucion FOREIGN KEY (devolucion_id) REFERENCES devoluciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Permisos ────────────────────────────────────────────────────────────────
INSERT INTO permisos (codigo, nombre, descripcion, activo)
SELECT x.codigo, x.nombre, x.descripcion, 1
FROM (
    SELECT 'devoluciones.ver' codigo, 'Ver devoluciones' nombre, 'Permite listar y consultar devoluciones' descripcion UNION ALL
    SELECT 'devoluciones.crear', 'Registrar devoluciones', 'Permite registrar devoluciones parciales de ventas'
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('devoluciones.ver', 'devoluciones.crear')
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA', 'CAJERO')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('087_devoluciones');
