# Scripts de datos (one-off)

Scripts SQL de datos ejecutados a mano en producción (phpMyAdmin), por fecha y empresa.
A diferencia de `../init/` (migraciones de esquema, numeradas y registradas en
`schema_migrations`), estos scripts modifican DATOS de una empresa específica y
no se vuelven a ejecutar.

| Fecha | Script | Empresa | Qué hace | Ejecutado |
|---|---|---|---|---|
| 2026-06-11 | 2026-06-11_empresa24_marcar_pesables.sql | 24 Doña Elida | Marca 58 cortes pesables: es_producto_peso=1, precio_por_kg=precio_venta, unidad KG (criterio: nombre termina en " kg" sin número antes) | Sí |
| 2026-06-11 | 2026-06-11_empresa24_crear_rubros.sql | 24 Doña Elida | Crea 13 rubros y asigna los 8.493 productos por palabras clave del nombre; lo no clasificado queda en "Otros" | Sí |
| 2026-06-11 | 2026-06-11_empresa24_refinar_rubros.sql | 24 Doña Elida | Reclasifica ~1.000 productos desde 'Otros' (aceitunas/conservas→Abarrotes, higiene femenina/tinturas→Cuidado Personal, lavandería/desechables→Aseo, licores→Bebidas) | Sí |

Contexto del setup de la empresa 24 (catálogo desde scraping Santa Isabel,
importaciones ids 1-9, fotos, sucursal Casa Matriz): ver scripts Python en
el repositorio de trabajo y la documentación del módulo de importaciones.
