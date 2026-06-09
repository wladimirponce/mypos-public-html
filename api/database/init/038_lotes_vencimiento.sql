-- =============================================================================
-- Fase C: Lotes y vencimientos FEFO
-- =============================================================================
-- Agrega soporte completo para control de lotes con trazabilidad FEFO
-- (First Expired First Out). Solo activo cuando la empresa tiene la
-- capacidad LOTES_VENCIMIENTO habilitada.
-- =============================================================================

-- Flag por producto: si 1, la empresa debe registrar lote al recibir este producto.
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS requiere_lote TINYINT(1) NOT NULL DEFAULT 0
    AFTER controla_stock;

-- Columna de trazabilidad en movimientos de stock.
ALTER TABLE stock_movimientos
    ADD COLUMN IF NOT EXISTS lote_id INT UNSIGNED NULL AFTER producto_id;

CREATE INDEX IF NOT EXISTS idx_lote_movimiento ON stock_movimientos (empresa_id, lote_id);

-- --------------------------------------------------------
-- stock_lotes: metadatos del lote (numero, vencimiento, origen)
-- Unico por empresa + producto + numero_lote.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_lotes (
    id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    empresa_id        INT UNSIGNED      NOT NULL,
    producto_id       INT UNSIGNED      NOT NULL,
    numero_lote       VARCHAR(100)      NOT NULL,
    fecha_vencimiento DATE              NULL,
    fecha_fabricacion DATE              NULL,
    proveedor_id      INT UNSIGNED      NULL,
    compra_id         INT UNSIGNED      NULL,
    cantidad_original DECIMAL(12, 3)    NOT NULL DEFAULT 0.000,
    activo            TINYINT(1)        NOT NULL DEFAULT 1,
    observacion       TEXT              NULL,
    created_at        TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lote              (empresa_id, producto_id, numero_lote),
    KEY        idx_empresa_prod     (empresa_id, producto_id),
    KEY        idx_vencimiento      (empresa_id, fecha_vencimiento)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- stock_lotes_ubicacion: cantidad disponible por lote y ubicacion.
-- Es el nivel de detalle bajo stock_ubicacion (fuente de verdad).
-- Invariante: SUM(cantidad) por (empresa, producto, ubicacion) <= stock_ubicacion.cantidad
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_lotes_ubicacion (
    id           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    empresa_id   INT UNSIGNED   NOT NULL,
    lote_id      INT UNSIGNED   NOT NULL,
    ubicacion_id INT UNSIGNED   NOT NULL,
    producto_id  INT UNSIGNED   NOT NULL,
    cantidad     DECIMAL(12, 3) NOT NULL DEFAULT 0.000,
    created_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lote_ubi          (empresa_id, lote_id, ubicacion_id),
    KEY        idx_empresa_prod_ubi (empresa_id, producto_id, ubicacion_id)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
