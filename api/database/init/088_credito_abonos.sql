-- ===========================================================================
-- 072_credito_abonos.sql
--
-- Abono libre de fiado (Fase 1 del plan de capacidades): el cliente abona un
-- monto sin referirse a un documento y el sistema lo distribuye FIFO entre
-- sus créditos pendientes (los más antiguos primero).
--
-- Los pagos reales siguen viviendo en creditos_pagos (uno por crédito
-- afectado) para que reportes y cierres no cambien; estas tablas agrupan esos
-- pagos bajo un abono con su desglose.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS credito_abonos (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id       BIGINT UNSIGNED NOT NULL,
    sucursal_id      BIGINT UNSIGNED NULL,
    cliente_id       BIGINT UNSIGNED NOT NULL,
    usuario_id       BIGINT UNSIGNED NOT NULL,
    caja_apertura_id BIGINT UNSIGNED NULL,
    metodo_pago_id   BIGINT UNSIGNED NOT NULL,
    monto            INT             NOT NULL,
    observacion      VARCHAR(255)    NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_credito_abonos_cliente (empresa_id, cliente_id),
    CONSTRAINT fk_credito_abonos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_credito_abonos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credito_abono_aplicaciones (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id         BIGINT UNSIGNED NOT NULL,
    abono_id           BIGINT UNSIGNED NOT NULL,
    credito_cliente_id BIGINT UNSIGNED NOT NULL,
    pago_id            BIGINT UNSIGNED NOT NULL,   -- fila creada en creditos_pagos
    monto              INT             NOT NULL,
    PRIMARY KEY (id),
    KEY idx_abono_apl_abono   (empresa_id, abono_id),
    KEY idx_abono_apl_credito (empresa_id, credito_cliente_id),
    CONSTRAINT fk_abono_apl_abono   FOREIGN KEY (abono_id)           REFERENCES credito_abonos(id),
    CONSTRAINT fk_abono_apl_credito FOREIGN KEY (credito_cliente_id) REFERENCES creditos_clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('088_credito_abonos');
