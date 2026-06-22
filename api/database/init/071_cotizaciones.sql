-- ============================================================
-- Migración 071: Cotizaciones
-- Flujo: BORRADOR → ENVIADA → APROBADA → CONVERTIDA
--        cualquier estado activo → RECHAZADA / VENCIDA (lazy)
-- ============================================================

CREATE TABLE IF NOT EXISTS cotizaciones (
    id                   BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    empresa_id           BIGINT UNSIGNED   NOT NULL,
    sucursal_id          BIGINT UNSIGNED   NOT NULL,
    cliente_id           BIGINT UNSIGNED   NULL,
    usuario_id           BIGINT UNSIGNED   NOT NULL,
    numero_cotizacion    VARCHAR(20)       NOT NULL,
    estado               VARCHAR(20)       NOT NULL DEFAULT 'BORRADOR',
    validez_dias         SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    validez_hasta        DATE              NOT NULL,
    fecha_emision        DATE              NOT NULL,
    subtotal             BIGINT            NOT NULL DEFAULT 0,
    descuento_total      BIGINT            NOT NULL DEFAULT 0,
    total                BIGINT            NOT NULL DEFAULT 0,
    moneda               VARCHAR(3)        NOT NULL DEFAULT 'CLP',
    observacion          TEXT              NULL,
    condiciones          TEXT              NULL,
    convertida_venta_id  BIGINT UNSIGNED   NULL,
    rechazada_at         DATETIME          NULL,
    motivo_rechazo       VARCHAR(255)      NULL,
    created_at           TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP         NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cotizacion_numero (empresa_id, numero_cotizacion),
    KEY idx_cot_empresa_estado  (empresa_id, estado),
    KEY idx_cot_cliente         (empresa_id, cliente_id),
    KEY idx_cot_sucursal        (empresa_id, sucursal_id),
    KEY idx_cot_validez         (empresa_id, estado, validez_hasta),
    CONSTRAINT chk_cot_estado CHECK (estado IN ('BORRADOR','ENVIADA','APROBADA','RECHAZADA','VENCIDA','CONVERTIDA')),
    CONSTRAINT fk_cot_empresa   FOREIGN KEY (empresa_id)  REFERENCES empresas (id),
    CONSTRAINT fk_cot_sucursal  FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_cot_usuario   FOREIGN KEY (usuario_id)  REFERENCES usuarios (id),
    CONSTRAINT fk_cot_cliente   FOREIGN KEY (cliente_id)  REFERENCES clientes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cotizaciones_items (
    id                    BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    empresa_id            BIGINT UNSIGNED   NOT NULL,
    cotizacion_id         BIGINT UNSIGNED   NOT NULL,
    linea                 SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    producto_id           BIGINT UNSIGNED   NULL,
    codigo                VARCHAR(60)       NULL,
    nombre                VARCHAR(255)      NOT NULL,
    cantidad              DECIMAL(12,3)     NOT NULL DEFAULT 1.000,
    precio_unitario       BIGINT            NOT NULL DEFAULT 0,
    descuento_porcentaje  DECIMAL(5,2)      NOT NULL DEFAULT 0.00,
    descuento_monto       BIGINT            NOT NULL DEFAULT 0,
    subtotal              BIGINT            NOT NULL DEFAULT 0,
    observacion           VARCHAR(255)      NULL,
    PRIMARY KEY (id),
    KEY idx_cot_items_cotizacion (cotizacion_id),
    CONSTRAINT fk_cot_items_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE CASCADE,
    CONSTRAINT fk_cot_items_producto   FOREIGN KEY (producto_id)   REFERENCES productos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vincular venta generada desde cotización (idempotente sin stored procedures)
ALTER TABLE ventas
    ADD COLUMN IF NOT EXISTS cotizacion_id BIGINT UNSIGNED NULL AFTER cliente_id;

ALTER TABLE ventas
    ADD INDEX IF NOT EXISTS idx_ventas_cotizacion (cotizacion_id);
