-- Módulo Órdenes de Compra (OC)
-- Flujo: BORRADOR → ENVIADA → RECIBIDA_PARCIAL → CERRADA
--        Cualquier estado activo → CANCELADA
-- Cada recepción parcial genera una compra BORRADOR que el usuario confirma para sumar stock.

CREATE TABLE IF NOT EXISTS ordenes_compra (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id     BIGINT UNSIGNED NOT NULL,
    sucursal_id    BIGINT UNSIGNED NOT NULL,
    proveedor_id   BIGINT UNSIGNED NOT NULL,
    usuario_id     BIGINT UNSIGNED NOT NULL,
    numero_oc      VARCHAR(20)     NOT NULL,
    estado         VARCHAR(30)     NOT NULL DEFAULT 'BORRADOR',
    fecha_emision          DATE        NOT NULL,
    fecha_entrega_esperada DATE        NULL,
    observacion    VARCHAR(500)    NULL,
    total_referencia BIGINT        NOT NULL DEFAULT 0,
    total_recibido   BIGINT        NOT NULL DEFAULT 0,
    cancelada_at   TIMESTAMP       NULL DEFAULT NULL,
    motivo_cancelacion VARCHAR(255) NULL,
    created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP       NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oc_numero   (empresa_id, numero_oc),
    KEY idx_oc_empresa_estado (empresa_id, estado, created_at),
    KEY idx_oc_proveedor      (empresa_id, proveedor_id),
    CONSTRAINT chk_oc_estado CHECK (estado IN ('BORRADOR','ENVIADA','RECIBIDA_PARCIAL','CERRADA','CANCELADA')),
    CONSTRAINT fk_oc_empresa  FOREIGN KEY (empresa_id)  REFERENCES empresas   (id),
    CONSTRAINT fk_oc_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_oc_prov     FOREIGN KEY (proveedor_id) REFERENCES proveedores (id),
    CONSTRAINT fk_oc_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios   (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ordenes_compra_items (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    empresa_id          BIGINT UNSIGNED  NOT NULL,
    orden_id            BIGINT UNSIGNED  NOT NULL,
    producto_id         BIGINT UNSIGNED  NOT NULL,
    linea               SMALLINT UNSIGNED NOT NULL,
    codigo_producto     VARCHAR(80)      NULL,
    nombre_producto     VARCHAR(190)     NOT NULL,
    cantidad_solicitada DECIMAL(14,3)    NOT NULL,
    cantidad_recibida   DECIMAL(14,3)    NOT NULL DEFAULT 0.000,
    costo_referencia    BIGINT           NOT NULL DEFAULT 0,
    unidad              VARCHAR(30)      NOT NULL DEFAULT 'UN',
    observacion         VARCHAR(255)     NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oci_linea    (orden_id, linea),
    UNIQUE KEY uq_oci_producto (orden_id, producto_id),
    KEY idx_oci_producto (empresa_id, producto_id),
    CONSTRAINT fk_oci_empresa  FOREIGN KEY (empresa_id) REFERENCES empresas        (id),
    CONSTRAINT fk_oci_orden    FOREIGN KEY (orden_id)   REFERENCES ordenes_compra  (id),
    CONSTRAINT fk_oci_producto FOREIGN KEY (producto_id) REFERENCES productos      (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ordenes_compra_recepciones (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    orden_id   BIGINT UNSIGNED NOT NULL,
    compra_id  BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    fecha      DATE            NOT NULL,
    notas      VARCHAR(500)    NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ocr_orden  (orden_id),
    KEY idx_ocr_compra (compra_id),
    CONSTRAINT fk_ocr_empresa  FOREIGN KEY (empresa_id) REFERENCES empresas        (id),
    CONSTRAINT fk_ocr_orden    FOREIGN KEY (orden_id)   REFERENCES ordenes_compra  (id),
    CONSTRAINT fk_ocr_compra   FOREIGN KEY (compra_id)  REFERENCES compras         (id),
    CONSTRAINT fk_ocr_usuario  FOREIGN KEY (usuario_id) REFERENCES usuarios        (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ordenes_compra_recepcion_items (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id      BIGINT UNSIGNED NOT NULL,
    recepcion_id    BIGINT UNSIGNED NOT NULL,
    orden_item_id   BIGINT UNSIGNED NOT NULL,
    producto_id     BIGINT UNSIGNED NOT NULL,
    cantidad_recibida DECIMAL(14,3) NOT NULL,
    costo_unitario  BIGINT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_ocri_orden_item (orden_item_id),
    CONSTRAINT fk_ocri_empresa     FOREIGN KEY (empresa_id)    REFERENCES empresas                    (id),
    CONSTRAINT fk_ocri_recepcion   FOREIGN KEY (recepcion_id)  REFERENCES ordenes_compra_recepciones  (id),
    CONSTRAINT fk_ocri_orden_item  FOREIGN KEY (orden_item_id) REFERENCES ordenes_compra_items        (id),
    CONSTRAINT fk_ocri_producto    FOREIGN KEY (producto_id)   REFERENCES productos                   (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('070_ordenes_compra');
