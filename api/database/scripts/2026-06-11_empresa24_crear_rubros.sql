-- Rubros para Doña Elida (empresa 24) + asignación por palabras clave del nombre.
-- La colación utf8mb4_unicode_ci es insensible a acentos: 'jamon' matchea 'jamón'.
-- Las UPDATE corren en orden y solo tocan productos sin rubro (rubro_id IS NULL),
-- por lo que la primera coincidencia gana.

INSERT IGNORE INTO rubros (empresa_id, nombre, activo) VALUES
(24, 'Carnes y Cecinas', 1),
(24, 'Lácteos y Huevos', 1),
(24, 'Bebidas y Licores', 1),
(24, 'Panadería', 1),
(24, 'Snacks y Dulces', 1),
(24, 'Abarrotes', 1),
(24, 'Frutas y Verduras', 1),
(24, 'Congelados y Helados', 1),
(24, 'Aseo y Hogar', 1),
(24, 'Cuidado Personal', 1),
(24, 'Mascotas', 1),
(24, 'Bebés', 1),
(24, 'Otros', 1);

-- 1. Abarrotes específicos que contienen palabras de carne (caldo de pollo, salsa, etc.)
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Abarrotes'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE '%caldo%' OR p.nombre LIKE '%sopa %' OR p.nombre LIKE '%salsa%'
  OR p.nombre LIKE '%pate %' OR p.nombre LIKE '%pate de%');

-- 2. Carnes y Cecinas
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Carnes y Cecinas'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE '%vacuno%' OR p.nombre LIKE '%cerdo%' OR p.nombre LIKE '%pollo%'
  OR p.nombre LIKE '%pavo%' OR p.nombre LIKE '%cordero%' OR p.nombre LIKE '%equino%'
  OR p.nombre LIKE '%pescado%' OR p.nombre LIKE '%marisco%' OR p.nombre LIKE '%salmon%'
  OR p.nombre LIKE '%atun fresco%' OR p.nombre LIKE '%merluza%' OR p.nombre LIKE '%reineta%'
  OR p.nombre LIKE '%cecina%' OR p.nombre LIKE '%chorizo%' OR p.nombre LIKE '%salchicha%'
  OR p.nombre LIKE '%vienesa%' OR p.nombre LIKE '%jamon%' OR p.nombre LIKE '%mortadela%'
  OR p.nombre LIKE '%longaniza%' OR p.nombre LIKE '%prieta%' OR p.nombre LIKE '%hamburguesa%'
  OR p.nombre LIKE '%tocino%' OR p.nombre LIKE '%costillar%' OR p.nombre LIKE '%chuleta%'
  OR p.nombre LIKE '%posta %' OR p.nombre LIKE '%asado%' OR p.nombre LIKE '%lomo %'
  OR p.nombre LIKE '%bistec%' OR p.nombre LIKE '%abastero%' OR p.nombre LIKE '%choclillo%'
  OR p.nombre LIKE '%huachalomo%' OR p.nombre LIKE '%sobrecostilla%' OR p.nombre LIKE '%tapapecho%'
  OR p.nombre LIKE '%pulpa %' OR p.nombre LIKE '%trutro%' OR p.nombre LIKE '%pechuga%'
  OR p.nombre LIKE '%carne molida%' OR p.nombre LIKE '%nugget%' OR p.nombre LIKE '%interiores%');

-- 3. Lácteos y Huevos
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Lácteos y Huevos'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE '%leche%' OR p.nombre LIKE '%yogur%' OR p.nombre LIKE '%yoghurt%'
  OR p.nombre LIKE '%queso%' OR p.nombre LIKE '%quesillo%' OR p.nombre LIKE '%mantequilla%'
  OR p.nombre LIKE '%margarina%' OR p.nombre LIKE '%crema %' OR p.nombre LIKE '%manjar%'
  OR p.nombre LIKE '%huevo%' OR p.nombre LIKE '%postre lacteo%' OR p.nombre LIKE '%lactea%');

-- 4. Bebidas y Licores
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Bebidas y Licores'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE '%bebida%' OR p.nombre LIKE '%jugo%' OR p.nombre LIKE '%nectar%'
  OR p.nombre LIKE '%agua %' OR p.nombre LIKE 'agua%' OR p.nombre LIKE '%gaseosa%'
  OR p.nombre LIKE '%energetica%' OR p.nombre LIKE '%isotonica%'
  OR p.nombre LIKE '%cerveza%' OR p.nombre LIKE '%vino %' OR p.nombre LIKE 'vino%'
  OR p.nombre LIKE '%pisco%' OR p.nombre LIKE '%ron %' OR p.nombre LIKE '%whisky%'
  OR p.nombre LIKE '%vodka%' OR p.nombre LIKE '%espumante%' OR p.nombre LIKE '%fernet%');

-- 5. Panadería
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Panadería'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE 'pan %' OR p.nombre LIKE '%hallulla%' OR p.nombre LIKE '%marraqueta%'
  OR p.nombre LIKE '%queque%' OR p.nombre LIKE '%bizcocho%' OR p.nombre LIKE '%torta%'
  OR p.nombre LIKE '%dobladita%' OR p.nombre LIKE '%empanada%' OR p.nombre LIKE '%tortilla%'
  OR p.nombre LIKE '%pan de%' OR p.nombre LIKE '%croissant%' OR p.nombre LIKE '%berlin%');

-- 6. Congelados y Helados
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Congelados y Helados'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE '%congelad%' OR p.nombre LIKE '%helado%' OR p.nombre LIKE '%paleta helada%'
  OR p.nombre LIKE '%prefrita%' OR p.nombre LIKE '%pizza%');

-- 7. Snacks y Dulces
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Snacks y Dulces'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE '%galleta%' OR p.nombre LIKE '%chocolate%' OR p.nombre LIKE '%caramelo%'
  OR p.nombre LIKE '%snack%' OR p.nombre LIKE '%papas fritas%' OR p.nombre LIKE '%popcorn%'
  OR p.nombre LIKE '%bombon%' OR p.nombre LIKE '%chicle%' OR p.nombre LIKE '%alfajor%'
  OR p.nombre LIKE '%gomitas%' OR p.nombre LIKE '%mani %' OR p.nombre LIKE '%cabritas%'
  OR p.nombre LIKE '%barra de cereal%' OR p.nombre LIKE '%wafer%' OR p.nombre LIKE '%ramitas%');

-- 8. Frutas y Verduras
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Frutas y Verduras'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE '%tomate%' OR p.nombre LIKE '%lechuga%' OR p.nombre LIKE '%cebolla%'
  OR p.nombre LIKE '%palta%' OR p.nombre LIKE '%limon%' OR p.nombre LIKE '%manzana%'
  OR p.nombre LIKE '%platano%' OR p.nombre LIKE '%naranja%' OR p.nombre LIKE '%zanahoria%'
  OR p.nombre LIKE '%papa %' OR p.nombre LIKE 'papas %' OR p.nombre LIKE '%choclo%'
  OR p.nombre LIKE '%verdura%' OR p.nombre LIKE '%fruta%' OR p.nombre LIKE '%ensalada%');

-- 9. Aseo y Hogar
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Aseo y Hogar'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE '%cloro%' OR p.nombre LIKE '%detergente%' OR p.nombre LIKE '%lavaloza%'
  OR p.nombre LIKE '%papel higienico%' OR p.nombre LIKE '%servilleta%' OR p.nombre LIKE '%toalla de papel%'
  OR p.nombre LIKE '%limpiador%' OR p.nombre LIKE '%desinfectante%' OR p.nombre LIKE '%insecticida%'
  OR p.nombre LIKE '%esponja%' OR p.nombre LIKE '%bolsa%basura%' OR p.nombre LIKE '%suavizante%'
  OR p.nombre LIKE '%cera %' OR p.nombre LIKE '%ampolleta%' OR p.nombre LIKE '%fosforo%'
  OR p.nombre LIKE '%vela%' OR p.nombre LIKE '%traperos%' OR p.nombre LIKE '%alusa%');

-- 10. Cuidado Personal
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Cuidado Personal'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE '%shampoo%' OR p.nombre LIKE '%jabon%' OR p.nombre LIKE '%desodorante%'
  OR p.nombre LIKE '%pasta dental%' OR p.nombre LIKE '%cepillo dental%' OR p.nombre LIKE '%crema dental%'
  OR p.nombre LIKE '%toalla higienica%' OR p.nombre LIKE '%protector diario%' OR p.nombre LIKE '%aposito%'
  OR p.nombre LIKE '%acondicionador%' OR p.nombre LIKE '%bálsamo%' OR p.nombre LIKE '%afeitar%'
  OR p.nombre LIKE '%colonia%' OR p.nombre LIKE '%cotidian%' OR p.nombre LIKE '%bloqueador%');

-- 11. Mascotas
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Mascotas'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE '%perro%' OR p.nombre LIKE '%gato%' OR p.nombre LIKE '%mascota%'
  OR p.nombre LIKE '%arena sanitaria%');

-- 12. Bebés
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Bebés'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE '%panal%' OR p.nombre LIKE '%bebe%' OR p.nombre LIKE '%toallitas humedas%');

-- 13. Abarrotes (resto de despensa)
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Abarrotes'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL AND (
  p.nombre LIKE '%arroz%' OR p.nombre LIKE '%fideo%' OR p.nombre LIKE '%pasta%'
  OR p.nombre LIKE '%harina%' OR p.nombre LIKE '%azucar%' OR p.nombre LIKE '%aceite%'
  OR p.nombre LIKE '%sal %' OR p.nombre LIKE '%vinagre%' OR p.nombre LIKE '%conserva%'
  OR p.nombre LIKE '%atun%' OR p.nombre LIKE '%jurel%' OR p.nombre LIKE '%sardina%'
  OR p.nombre LIKE '%poroto%' OR p.nombre LIKE '%lenteja%' OR p.nombre LIKE '%garbanzo%'
  OR p.nombre LIKE '%cafe%' OR p.nombre LIKE '%te %' OR p.nombre LIKE '%hierba%'
  OR p.nombre LIKE '%mermelada%' OR p.nombre LIKE '%cereal%' OR p.nombre LIKE '%avena%'
  OR p.nombre LIKE '%mayonesa%' OR p.nombre LIKE '%ketchup%' OR p.nombre LIKE '%mostaza%'
  OR p.nombre LIKE '%condimento%' OR p.nombre LIKE '%oregano%' OR p.nombre LIKE '%comino%'
  OR p.nombre LIKE '%pure %' OR p.nombre LIKE '%smetana%' OR p.nombre LIKE '%levadura%'
  OR p.nombre LIKE '%endulzante%' OR p.nombre LIKE '%miel%' OR p.nombre LIKE '%semola%');

-- 14. Todo lo restante a 'Otros'
UPDATE productos p JOIN rubros r ON r.empresa_id = 24 AND r.nombre = 'Otros'
SET p.rubro_id = r.id
WHERE p.empresa_id = 24 AND p.rubro_id IS NULL;

-- Verificación: distribución por rubro
SELECT r.nombre, COUNT(p.id) AS productos
FROM rubros r LEFT JOIN productos p ON p.rubro_id = r.id AND p.empresa_id = 24
WHERE r.empresa_id = 24
GROUP BY r.id, r.nombre
ORDER BY productos DESC;
