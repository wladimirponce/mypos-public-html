-- ===========================================================================
-- 034_farmacia_atributos_seed.sql
--
-- Sprint 13: Vertical Farmacia Opcional
--
-- DECISIÓN TÉCNICA:
--   El sistema de atributos personalizados (027_producto_atributos.sql) es
--   suficiente para modelar la vertical farmacia. No se crean tablas propias.
--
--   Razones:
--   1. Los campos de farmacia son datos descriptivos (texto, opción, decimal).
--   2. No hay lógica de negocio transaccional ligada a ellos (no afectan
--      stock, impuestos ni DTE de forma distinta al resto de productos).
--   3. El campo `filtrable = 1` permite búsqueda por principio activo, forma
--      farmacéutica, etc. sin tablas adicionales.
--   4. Agregar tablas estructuradas duplicaría datos sin beneficio real.
--
--   Si en el futuro se necesita lógica específica (ej: receta controlada
--   con flujo de autorización, o cruce con bases de datos de bioequivalencia),
--   se pueden crear tablas dedicadas sobre esta misma base.
--
-- CONTENIDO:
--   Seed de atributos estándar de farmacia para empresa_id = 1 (demo).
--   Otras empresas del rubro pueden usar el endpoint POST /api/v1/productos/atributos
--   para crear sus propios atributos, o se puede ejecutar un script similar
--   con su empresa_id.
--
--   Atributos creados:
--   - principio_activo    TEXTO,    filtrable, visible_pos
--   - laboratorio         TEXTO,    filtrable, visible_listado
--   - concentracion       TEXTO,    filtrable
--   - forma_farmaceutica  OPCION,   filtrable, visible_listado
--   - via_administracion  OPCION,   filtrable
--   - requiere_receta     BOOLEANO, filtrable, visible_pos
--   - condicion_venta     OPCION,   visible_pos
--   - temperatura_almac   OPCION
--   - registro_isp        TEXTO
-- ===========================================================================

-- Definiciones de atributos farmacia para empresa demo (id=1)
INSERT IGNORE INTO producto_atributos_definicion
    (empresa_id, codigo, nombre, descripcion, tipo_dato, requerido, filtrable,
     visible_en_listado, visible_en_pos, orden, activo)
VALUES
    (1, 'principio_activo',   'Principio activo',
     'Sustancia farmacológica activa del medicamento',
     'TEXTO', 0, 1, 0, 1, 10, 1),

    (1, 'laboratorio',        'Laboratorio',
     'Empresa fabricante o titular del registro',
     'TEXTO', 0, 1, 1, 0, 20, 1),

    (1, 'concentracion',      'Concentración',
     'Dosis o concentración por unidad (ej: 500mg, 10mg/5ml)',
     'TEXTO', 0, 1, 0, 0, 30, 1),

    (1, 'forma_farmaceutica', 'Forma farmacéutica',
     'Presentación del medicamento',
     'OPCION', 0, 1, 1, 0, 40, 1),

    (1, 'via_administracion', 'Vía de administración',
     'Ruta por la que se administra el medicamento',
     'OPCION', 0, 1, 0, 0, 50, 1),

    (1, 'requiere_receta',    'Requiere receta',
     'Indica si el medicamento exige receta médica para su dispensación',
     'BOOLEANO', 0, 1, 0, 1, 60, 1),

    (1, 'condicion_venta',    'Condición de venta',
     'Clasificación regulatoria de dispensación (OTC, receta retenida, etc.)',
     'OPCION', 0, 0, 0, 1, 70, 1),

    (1, 'temperatura_almac',  'Temperatura de almacenamiento',
     'Condiciones de temperatura requeridas para conservación',
     'OPCION', 0, 0, 0, 0, 80, 1),

    (1, 'registro_isp',       'Registro ISP',
     'Número de registro del Instituto de Salud Pública de Chile',
     'TEXTO', 0, 0, 0, 0, 90, 1);

-- Opciones para forma_farmaceutica
-- Primero obtenemos el id de la definición creada
INSERT IGNORE INTO producto_atributos_opciones
    (empresa_id, atributo_id, valor, etiqueta, orden, activo)
SELECT
    1,
    d.id,
    op.valor,
    op.etiqueta,
    op.orden,
    1
FROM producto_atributos_definicion d
CROSS JOIN (
    SELECT 'COMPRIMIDO'        AS valor, 'Comprimido'           AS etiqueta, 1  AS orden UNION ALL
    SELECT 'CAPSULA',                    'Cápsula',                          2  UNION ALL
    SELECT 'JARABE',                     'Jarabe',                           3  UNION ALL
    SELECT 'SUSPENSION',                 'Suspensión oral',                  4  UNION ALL
    SELECT 'SOLUCION_INYECTABLE',        'Solución inyectable',              5  UNION ALL
    SELECT 'CREMA',                      'Crema',                            6  UNION ALL
    SELECT 'POMADA',                     'Pomada',                           7  UNION ALL
    SELECT 'GEL',                        'Gel',                              8  UNION ALL
    SELECT 'GOTAS_OFTALMICAS',           'Gotas oftálmicas',                 9  UNION ALL
    SELECT 'GOTAS_OTICAS',              'Gotas óticas',                     10 UNION ALL
    SELECT 'PARCHE',                     'Parche transdérmico',              11 UNION ALL
    SELECT 'SUPOSITORIO',                'Supositorio',                      12 UNION ALL
    SELECT 'POLVO',                      'Polvo para solución',              13 UNION ALL
    SELECT 'AEROSOL',                    'Aerosol',                          14 UNION ALL
    SELECT 'OTRO',                       'Otro',                             99
) op
WHERE d.empresa_id = 1
  AND d.codigo = 'forma_farmaceutica';

-- Opciones para via_administracion
INSERT IGNORE INTO producto_atributos_opciones
    (empresa_id, atributo_id, valor, etiqueta, orden, activo)
SELECT
    1,
    d.id,
    op.valor,
    op.etiqueta,
    op.orden,
    1
FROM producto_atributos_definicion d
CROSS JOIN (
    SELECT 'ORAL'           AS valor, 'Oral'              AS etiqueta, 1 AS orden UNION ALL
    SELECT 'TOPICA',                  'Tópica',                        2          UNION ALL
    SELECT 'OFTALMICA',               'Oftálmica',                     3          UNION ALL
    SELECT 'OTICA',                   'Ótica',                         4          UNION ALL
    SELECT 'NASAL',                   'Nasal',                         5          UNION ALL
    SELECT 'INHALADA',                'Inhalada',                      6          UNION ALL
    SELECT 'SUBCUTANEA',              'Subcutánea',                    7          UNION ALL
    SELECT 'INTRAMUSCULAR',           'Intramuscular',                 8          UNION ALL
    SELECT 'INTRAVENOSA',             'Intravenosa',                   9          UNION ALL
    SELECT 'RECTAL',                  'Rectal',                        10         UNION ALL
    SELECT 'VAGINAL',                 'Vaginal',                       11         UNION ALL
    SELECT 'OTRO',                    'Otro',                          99
) op
WHERE d.empresa_id = 1
  AND d.codigo = 'via_administracion';

-- Opciones para condicion_venta
INSERT IGNORE INTO producto_atributos_opciones
    (empresa_id, atributo_id, valor, etiqueta, orden, activo)
SELECT
    1,
    d.id,
    op.valor,
    op.etiqueta,
    op.orden,
    1
FROM producto_atributos_definicion d
CROSS JOIN (
    SELECT 'OTC'             AS valor, 'Venta directa (OTC)'              AS etiqueta, 1 AS orden UNION ALL
    SELECT 'RECETA_MEDICA',            'Requiere receta médica',                        2          UNION ALL
    SELECT 'RECETA_RETENIDA',          'Receta médica retenida',                        3          UNION ALL
    SELECT 'RECETA_CHEQUE',            'Receta de urgencia (cheque)',                   4          UNION ALL
    SELECT 'USO_HOSPITALARIO',         'Uso hospitalario',                              5
) op
WHERE d.empresa_id = 1
  AND d.codigo = 'condicion_venta';

-- Opciones para temperatura_almac
INSERT IGNORE INTO producto_atributos_opciones
    (empresa_id, atributo_id, valor, etiqueta, orden, activo)
SELECT
    1,
    d.id,
    op.valor,
    op.etiqueta,
    op.orden,
    1
FROM producto_atributos_definicion d
CROSS JOIN (
    SELECT 'AMBIENTE'       AS valor, 'Temperatura ambiente (15-25°C)'  AS etiqueta, 1 AS orden UNION ALL
    SELECT 'REFRIGERADO',             'Refrigerado (2-8°C)',                          2          UNION ALL
    SELECT 'CONGELADO',               'Congelado (-20°C o menos)',                    3          UNION ALL
    SELECT 'PROTEGER_LUZ',            'Proteger de la luz',                           4
) op
WHERE d.empresa_id = 1
  AND d.codigo = 'temperatura_almac';

INSERT IGNORE INTO schema_migrations (migration) VALUES ('034_farmacia_atributos_seed');
