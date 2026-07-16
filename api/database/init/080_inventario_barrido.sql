-- Inventario por barrido con lector de código de barras
-- Reutiliza la cabecera `inventarios_fisicos` (con un nuevo `tipo`) y agrega un
-- log append-only de lecturas. La consolidación aplica ajustes de stock por
-- diferencia (delta) una sola vez por producto, seguro con el local vendiendo.

-- 1) Cabecera: distinguir el flujo planilla del flujo barrido y sus estados.
--    PLANILLA: BORRADOR -> APLICADO (flujo existente, sin cambios de comportamiento).
--    BARRIDO : ABIERTA  -> CERRADA.
ALTER TABLE inventarios_fisicos
    ADD COLUMN tipo ENUM('PLANILLA','BARRIDO') NOT NULL DEFAULT 'PLANILLA' AFTER nombre;

ALTER TABLE inventarios_fisicos
    MODIFY COLUMN estado ENUM('BORRADOR','APLICADO','ABIERTA','CERRADA') NOT NULL DEFAULT 'BORRADOR';

-- 2) Log append-only de lecturas del lector.
--    Acumulado no consolidado de un producto:
--      SUM(cantidad) WHERE inventario_id=? AND producto_id=? AND consolidado_at IS NULL
--    Producto "bloqueado" (ya consolidado en esta sesión): existe >=1 fila con
--    consolidado_at IS NOT NULL.
CREATE TABLE IF NOT EXISTS inventario_barrido_scans (
    id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventario_id             INT UNSIGNED NOT NULL,
    empresa_id                INT UNSIGNED NOT NULL,
    producto_id               INT UNSIGNED NOT NULL,
    cantidad                  DECIMAL(10,3) NOT NULL DEFAULT 1.000,
    -- Stock de sistema al INICIAR el conteo del producto (primera lectura del lote).
    -- La consolidación aplica delta = contado - stock_snapshot (relativo), para que
    -- una venta ocurrida durante el barrido no se pierda. NULL en lecturas posteriores.
    stock_snapshot            DECIMAL(10,3) NULL DEFAULT NULL,
    consolidado_movimiento_id BIGINT UNSIGNED NULL DEFAULT NULL,
    consolidado_at            TIMESTAMP NULL DEFAULT NULL,
    usuario_id                INT UNSIGNED NULL DEFAULT NULL,
    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inv_prod (inventario_id, producto_id),
    INDEX idx_empresa_inventario (empresa_id, inventario_id),
    INDEX idx_pendientes (inventario_id, producto_id, consolidado_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('080_inventario_barrido');
