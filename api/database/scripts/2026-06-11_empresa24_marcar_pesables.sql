-- Fase B: marcar productos pesables de Doña Elida (empresa 24)
-- Criterio: nombre termina en "kg" SIN número antes (ej: "Asado de Tira kg" sí;
-- "Manjar Colun 1 kg" no, porque es envasado).

-- 1. Verificación previa: debe devolver ~58 filas, todas cortes de carne
SELECT id, nombre, precio_venta
FROM productos
WHERE empresa_id = 24
  AND activo = 1
  AND nombre REGEXP '[[:space:]][Kk][Gg][[:space:]]*$'
  AND nombre NOT REGEXP '[0-9][.,]?[0-9]*[[:space:]]*[Kk][Gg][[:space:]]*$'
ORDER BY nombre;

-- 2. Si la lista se ve bien, ejecutar el UPDATE:
UPDATE productos
SET es_producto_peso = 1,
    precio_por_kg = precio_venta,
    unidad_medida = 'KG'
WHERE empresa_id = 24
  AND activo = 1
  AND nombre REGEXP '[[:space:]][Kk][Gg][[:space:]]*$'
  AND nombre NOT REGEXP '[0-9][.,]?[0-9]*[[:space:]]*[Kk][Gg][[:space:]]*$';

-- 3. Confirmar:
SELECT COUNT(*) AS pesables FROM productos
WHERE empresa_id = 24 AND es_producto_peso = 1;
