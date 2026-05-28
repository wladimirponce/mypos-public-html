ALTER TABLE rubros
    ADD COLUMN IF NOT EXISTS descripcion VARCHAR(255) NULL AFTER nombre;

ALTER TABLE centros_costo
    ADD COLUMN IF NOT EXISTS descripcion VARCHAR(255) NULL AFTER nombre;

ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS codigo VARCHAR(80) NULL AFTER centro_costo_id,
    ADD COLUMN IF NOT EXISTS precio_costo BIGINT NOT NULL DEFAULT 0 AFTER unidad_medida,
    ADD COLUMN IF NOT EXISTS stock_minimo DECIMAL(14,3) NOT NULL DEFAULT 0.000 AFTER permite_fraccion,
    ADD COLUMN IF NOT EXISTS permite_descuento TINYINT(1) NOT NULL DEFAULT 1 AFTER stock_minimo,
    ADD COLUMN IF NOT EXISTS permite_comision TINYINT(1) NOT NULL DEFAULT 1 AFTER permite_descuento;

UPDATE productos
SET codigo = COALESCE(codigo, sku),
    precio_costo = CASE WHEN precio_costo = 0 THEN costo_actual ELSE precio_costo END
WHERE codigo IS NULL OR precio_costo = 0;

ALTER TABLE productos_codigos_barra
    ADD COLUMN IF NOT EXISTS tipo_codigo VARCHAR(30) NOT NULL DEFAULT 'BARRA' AFTER codigo_barra;

ALTER TABLE productos_imagenes
    ADD COLUMN IF NOT EXISTS codigo_barra_id BIGINT UNSIGNED NULL AFTER producto_codigo_barra_id,
    ADD COLUMN IF NOT EXISTS imagen_url VARCHAR(255) NULL AFTER codigo_barra_id,
    ADD COLUMN IF NOT EXISTS titulo VARCHAR(160) NULL AFTER imagen_url,
    ADD COLUMN IF NOT EXISTS descripcion VARCHAR(255) NULL AFTER titulo,
    ADD COLUMN IF NOT EXISTS orden INT UNSIGNED NOT NULL DEFAULT 1 AFTER principal;

UPDATE productos_imagenes
SET codigo_barra_id = COALESCE(codigo_barra_id, producto_codigo_barra_id),
    imagen_url = COALESCE(imagen_url, ruta)
WHERE imagen_url IS NULL OR codigo_barra_id IS NULL;

ALTER TABLE producto_impuestos
    ADD COLUMN IF NOT EXISTS orden_aplicacion INT UNSIGNED NOT NULL DEFAULT 1 AFTER impuesto_id,
    ADD COLUMN IF NOT EXISTS incluido_en_precio TINYINT(1) NOT NULL DEFAULT 1 AFTER orden_aplicacion;

ALTER TABLE productos_descuentos
    ADD COLUMN IF NOT EXISTS sucursal_id BIGINT UNSIGNED NULL AFTER producto_id,
    ADD COLUMN IF NOT EXISTS nombre VARCHAR(160) NULL AFTER sucursal_id,
    ADD COLUMN IF NOT EXISTS tipo_descuento VARCHAR(20) NULL AFTER nombre,
    ADD COLUMN IF NOT EXISTS valor_descuento BIGINT NULL AFTER tipo_descuento,
    ADD COLUMN IF NOT EXISTS hora_inicio TIME NULL AFTER fecha_fin,
    ADD COLUMN IF NOT EXISTS hora_fin TIME NULL AFTER hora_inicio,
    ADD COLUMN IF NOT EXISTS cantidad_minima DECIMAL(14,3) NULL AFTER hora_fin,
    ADD COLUMN IF NOT EXISTS acumulable TINYINT(1) NOT NULL DEFAULT 0 AFTER cantidad_minima;

UPDATE productos_descuentos
SET tipo_descuento = COALESCE(tipo_descuento, tipo),
    valor_descuento = COALESCE(valor_descuento, valor)
WHERE tipo_descuento IS NULL OR valor_descuento IS NULL;

ALTER TABLE productos_comisiones
    ADD COLUMN IF NOT EXISTS sucursal_id BIGINT UNSIGNED NULL AFTER producto_id,
    ADD COLUMN IF NOT EXISTS nombre VARCHAR(160) NULL AFTER sucursal_id,
    ADD COLUMN IF NOT EXISTS tipo_comision VARCHAR(30) NULL AFTER nombre,
    ADD COLUMN IF NOT EXISTS valor_comision BIGINT NULL AFTER tipo_comision;

UPDATE productos_comisiones
SET tipo_comision = COALESCE(tipo_comision, tipo),
    valor_comision = COALESCE(valor_comision, valor)
WHERE tipo_comision IS NULL OR valor_comision IS NULL;

DELIMITER //

CREATE OR REPLACE PROCEDURE mypos_add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
          AND index_name = p_index_name
    ) THEN
        SET @ddl = p_ddl;
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

CREATE OR REPLACE PROCEDURE mypos_add_fk_if_missing(
    IN p_constraint_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE()
          AND constraint_name = p_constraint_name
    ) THEN
        SET @ddl = p_ddl;
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

DELIMITER ;

CALL mypos_add_index_if_missing('productos', 'uq_productos_empresa_codigo', 'ALTER TABLE productos ADD UNIQUE INDEX uq_productos_empresa_codigo (empresa_id, codigo)');
CALL mypos_add_index_if_missing('productos', 'idx_productos_empresa_codigo', 'ALTER TABLE productos ADD INDEX idx_productos_empresa_codigo (empresa_id, codigo)');
CALL mypos_add_index_if_missing('productos_imagenes', 'idx_productos_imagenes_codigo_barra_id', 'ALTER TABLE productos_imagenes ADD INDEX idx_productos_imagenes_codigo_barra_id (codigo_barra_id)');
CALL mypos_add_index_if_missing('productos_descuentos', 'idx_productos_descuentos_sucursal', 'ALTER TABLE productos_descuentos ADD INDEX idx_productos_descuentos_sucursal (sucursal_id)');
CALL mypos_add_index_if_missing('productos_comisiones', 'idx_productos_comisiones_sucursal', 'ALTER TABLE productos_comisiones ADD INDEX idx_productos_comisiones_sucursal (sucursal_id)');

CALL mypos_add_fk_if_missing('fk_productos_imagenes_codigo_barra_id', 'ALTER TABLE productos_imagenes ADD CONSTRAINT fk_productos_imagenes_codigo_barra_id FOREIGN KEY (codigo_barra_id) REFERENCES productos_codigos_barra (id)');
CALL mypos_add_fk_if_missing('fk_productos_descuentos_sucursal', 'ALTER TABLE productos_descuentos ADD CONSTRAINT fk_productos_descuentos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id)');
CALL mypos_add_fk_if_missing('fk_productos_comisiones_sucursal', 'ALTER TABLE productos_comisiones ADD CONSTRAINT fk_productos_comisiones_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id)');

DROP PROCEDURE IF EXISTS mypos_add_index_if_missing;
DROP PROCEDURE IF EXISTS mypos_add_fk_if_missing;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('003_catalogo_productos');
