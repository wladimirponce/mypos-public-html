-- Stock inicial y aviso de columnas ignoradas en la importacion de catalogo.
--
-- PROBLEMA QUE CIERRA
-- El importador nunca tuvo campo de existencias: `EXPECTED_FIELDS` solo incluia
-- `stock_minimo`, que es el umbral de reposicion, no la cantidad en bodega. Una
-- columna "Stock" en el Excel del cliente no coincidia con ningun campo
-- esperado, `detectMapping` no la mapeaba y el dato se descartaba EN SILENCIO:
-- la importacion se reportaba exitosa y todos los productos quedaban en cero.

ALTER TABLE importaciones_maestro_items
    ADD COLUMN IF NOT EXISTS stock_inicial DECIMAL(14,3) NULL DEFAULT NULL AFTER stock_minimo;

-- Sucursal a la que se cargan las existencias al aplicar. Se elige en el
-- dialogo de importacion; si viene NULL se usa la primera sucursal activa.
ALTER TABLE importaciones_maestro
    ADD COLUMN IF NOT EXISTS sucursal_id INT UNSIGNED NULL DEFAULT NULL AFTER usuario_id;

-- Cabeceras del archivo que no correspondieron a ningun campo conocido. Se
-- guardan en la previsualizacion para poder advertirle al usuario ANTES de
-- aplicar, en vez de perder los datos sin decir nada.
ALTER TABLE importaciones_maestro
    ADD COLUMN IF NOT EXISTS columnas_ignoradas_json TEXT NULL DEFAULT NULL AFTER filas_error;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('101_importacion_stock_inicial');
