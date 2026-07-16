-- Refinamiento de rubros para empresa 24: reclasifica productos que quedaron
-- en 'Otros' usando palabras detectadas en el análisis de frecuencia.
-- Solo toca productos cuyo rubro actual es 'Otros'.

-- Bebidas y Licores: licores, tragos preparados
UPDATE productos p
JOIN rubros ro ON ro.empresa_id = 24 AND ro.nombre = 'Otros'
JOIN rubros rd ON rd.empresa_id = 24 AND rd.nombre = 'Bebidas y Licores'
SET p.rubro_id = rd.id
WHERE p.empresa_id = 24 AND p.rubro_id = ro.id AND (
  p.nombre LIKE '%licor%' OR p.nombre LIKE '%sour%' OR p.nombre LIKE '%gin %'
  OR p.nombre LIKE 'gin%' OR p.nombre LIKE '%tequila%' OR p.nombre LIKE '%aperol%'
  OR p.nombre LIKE '%champa%' OR p.nombre LIKE '%sidra%' OR p.nombre LIKE '%mojito%'
  OR p.nombre LIKE '%amaretto%' OR p.nombre LIKE '%baileys%' OR p.nombre LIKE '%anis%');

-- Abarrotes: conservas, encurtidos, picoteo, repostería seca
UPDATE productos p
JOIN rubros ro ON ro.empresa_id = 24 AND ro.nombre = 'Otros'
JOIN rubros rd ON rd.empresa_id = 24 AND rd.nombre = 'Abarrotes'
SET p.rubro_id = rd.id
WHERE p.empresa_id = 24 AND p.rubro_id = ro.id AND (
  p.nombre LIKE '%aceituna%' OR p.nombre LIKE '%drenado%' OR p.nombre LIKE '%coctel%'
  OR p.nombre LIKE '%encurtido%' OR p.nombre LIKE '%pepinillo%' OR p.nombre LIKE '%alcaparra%'
  OR p.nombre LIKE '%pimiento%' OR p.nombre LIKE '%aji %' OR p.nombre LIKE 'aji%'
  OR p.nombre LIKE '%vainilla%' OR p.nombre LIKE '%polvo de hornear%' OR p.nombre LIKE '%maicena%'
  OR p.nombre LIKE '%surtido%' OR p.nombre LIKE '%mix %' OR p.nombre LIKE '%gourmet%'
  OR p.nombre LIKE '%almendra%' OR p.nombre LIKE '%nuez%' OR p.nombre LIKE '%pasas%'
  OR p.nombre LIKE '%fruto seco%' OR p.nombre LIKE '%ciruela%' OR p.nombre LIKE '%coco rallado%'
  OR p.nombre LIKE '%sopa%' OR p.nombre LIKE '%crema instant%' OR p.nombre LIKE '%gelatina%'
  OR p.nombre LIKE '%flan%' OR p.nombre LIKE '%bicarbonato%' OR p.nombre LIKE '%mostaza%');

-- Cuidado Personal: higiene femenina, tinturas, dental, cuidado de piel y cabello
UPDATE productos p
JOIN rubros ro ON ro.empresa_id = 24 AND ro.nombre = 'Otros'
JOIN rubros rd ON rd.empresa_id = 24 AND rd.nombre = 'Cuidado Personal'
SET p.rubro_id = rd.id
WHERE p.empresa_id = 24 AND p.rubro_id = ro.id AND (
  p.nombre LIKE '%toallas higi%' OR p.nombre LIKE '%toalla higi%' OR p.nombre LIKE '%nosotras%'
  OR p.nombre LIKE '%protector%diario%' OR p.nombre LIKE '%protectores%' OR p.nombre LIKE '%tampon%'
  OR p.nombre LIKE '%tintura%' OR p.nombre LIKE '%rubio%' OR p.nombre LIKE '%castano%'
  OR p.nombre LIKE '%cepillo%dient%' OR p.nombre LIKE '%dientes%' OR p.nombre LIKE '%dental%'
  OR p.nombre LIKE '%enjuague bucal%' OR p.nombre LIKE '%gel %' OR p.nombre LIKE '%crema corporal%'
  OR p.nombre LIKE '%crema facial%' OR p.nombre LIKE '%care%' OR p.nombre LIKE '%humedas%'
  OR p.nombre LIKE '%cotonitos%' OR p.nombre LIKE '%algodon%' OR p.nombre LIKE '%parche%'
  OR p.nombre LIKE '%preservativo%' OR p.nombre LIKE '%maquina afeitar%' OR p.nombre LIKE '%labial%'
  OR p.nombre LIKE '%nocturna%' OR p.nombre LIKE '%talla %');

-- Aseo y Hogar: lavandería, limpieza, desechables, ferretería ligera
UPDATE productos p
JOIN rubros ro ON ro.empresa_id = 24 AND ro.nombre = 'Otros'
JOIN rubros rd ON rd.empresa_id = 24 AND rd.nombre = 'Aseo y Hogar'
SET p.rubro_id = rd.id
WHERE p.empresa_id = 24 AND p.rubro_id = ro.id AND (
  p.nombre LIKE '%ropa%' OR p.nombre LIKE '%quitamanchas%' OR p.nombre LIKE '%multiuso%'
  OR p.nombre LIKE '%ablandador%' OR p.nombre LIKE '%abrillantador%' OR p.nombre LIKE '%antigrasa%'
  OR p.nombre LIKE '%bolsa%' OR p.nombre LIKE '%toalla%' OR p.nombre LIKE '%papel %'
  OR p.nombre LIKE '%hojas%' OR p.nombre LIKE '%desechable%' OR p.nombre LIKE '%vaso %'
  OR p.nombre LIKE '%plato %' OR p.nombre LIKE '%cubierto%' OR p.nombre LIKE '%pano %'
  OR p.nombre LIKE '%guante%' OR p.nombre LIKE '%escobill%' OR p.nombre LIKE '%pinza%'
  OR p.nombre LIKE '%home%' OR p.nombre LIKE '%aromatizante%' OR p.nombre LIKE '%difusor%'
  OR p.nombre LIKE '%pila %' OR p.nombre LIKE '%pilas %' OR p.nombre LIKE '%encendedor%');

-- Verificación: nueva distribución
SELECT r.nombre, COUNT(p.id) AS productos
FROM rubros r LEFT JOIN productos p ON p.rubro_id = r.id AND p.empresa_id = 24
WHERE r.empresa_id = 24
GROUP BY r.id, r.nombre
ORDER BY productos DESC;
