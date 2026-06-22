-- Módulo Formulario 29 SII — declaración mensual de IVA
CREATE TABLE IF NOT EXISTS f29_periodos (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id              INT UNSIGNED NOT NULL,
    periodo                 VARCHAR(7)   NOT NULL,  -- 'YYYY-MM'
    estado                  ENUM('BORRADOR','DECLARADO') NOT NULL DEFAULT 'BORRADOR',

    -- Configuración manual por período
    tasa_ppm                DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    remanente_cf_anterior   INT          NOT NULL DEFAULT 0,  -- Cód 534 arrastrado

    -- Sección B: Débito Fiscal
    cod_503                 INT          NOT NULL DEFAULT 0,  -- N° docs afectos
    cod_166                 INT          NOT NULL DEFAULT 0,  -- Neto ventas afectas
    cod_142                 INT          NOT NULL DEFAULT 0,  -- Ventas exentas
    cod_519                 INT          NOT NULL DEFAULT 0,  -- Total débito fiscal

    -- Sección C: Crédito Fiscal
    cod_520                 INT          NOT NULL DEFAULT 0,  -- N° docs crédito
    cod_180                 INT          NOT NULL DEFAULT 0,  -- Neto compras afectas
    cod_527                 INT          NOT NULL DEFAULT 0,  -- Total CF + remanente

    -- Sección D: Liquidación
    cod_547                 INT          NOT NULL DEFAULT 0,  -- IVA a pagar
    cod_544                 INT          NOT NULL DEFAULT 0,  -- Remanente siguiente período

    -- Sección E: PPM
    cod_63                  INT          NOT NULL DEFAULT 0,  -- PPM a pagar

    notas                   TEXT         NULL,
    created_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    declarado_at            TIMESTAMP    NULL DEFAULT NULL,

    UNIQUE KEY uk_empresa_periodo (empresa_id, periodo),
    INDEX idx_empresa_estado (empresa_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
