-- ===========================================================================
-- 036_perfil_negocio.sql
--
-- Sistema de perfiles de negocio y capacidades por empresa.
--
-- DECISIÓN TÉCNICA:
--   Un "perfil" (FARMACIA, ZAPATERIA, MINIMARKET, GENERICO) es un conjunto
--   de "capacidades" que se activan en la empresa. Las capacidades son
--   features booleanas que controlan validaciones, campos visibles y reportes.
--
--   Al activar un perfil se siembra automáticamente en
--   producto_atributos_definicion los atributos estándar del rubro,
--   usando sistema_atributos_plantilla como catálogo de origen.
--
--   Empresas sin perfil asignado usan la base genérica de productos más
--   los atributos que cada empresa añada manualmente.
--
-- CONTENIDO:
--   1. productos.porcentaje_ganancia     — % objetivo sobre precio_costo
--   2. empresa_configuracion_operativa.perfil_negocio — etiqueta informativa
--   3. sistema_capacidades              — catálogo de features del sistema
--   4. empresa_capacidades              — features activas por empresa
--   5. sistema_atributos_plantilla      — atributos estándar por perfil
--   6. Seeds: capacidades + plantillas FARMACIA / ZAPATERIA / MINIMARKET
-- ===========================================================================

-- ── 1. productos: porcentaje de ganancia objetivo ────────────────────────────
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS porcentaje_ganancia DECIMAL(7,2) NOT NULL DEFAULT 0.00
    COMMENT 'Margen objetivo sobre precio_costo en porcentaje (ej: 30.00 = 30%)'
    AFTER precio_costo;

-- ── 2. empresa_configuracion_operativa: etiqueta de perfil ───────────────────
ALTER TABLE empresa_configuracion_operativa
    ADD COLUMN IF NOT EXISTS perfil_negocio VARCHAR(80) NULL
    COMMENT 'Perfil activo: FARMACIA, ZAPATERIA, MINIMARKET, GENERICO o NULL'
    AFTER documentos_tributarios_habilitados;

-- ── 3. sistema_capacidades ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sistema_capacidades (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo      VARCHAR(80)     NOT NULL,
    nombre      VARCHAR(160)    NOT NULL,
    descripcion VARCHAR(255)    NULL,
    activo      TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sistema_capacidades_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. empresa_capacidades ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS empresa_capacidades (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id    BIGINT UNSIGNED NOT NULL,
    capacidad_id  BIGINT UNSIGNED NOT NULL,
    activo        TINYINT(1)      NOT NULL DEFAULT 1,
    activado_por  BIGINT UNSIGNED NULL,
    activado_at   TIMESTAMP       NULL,
    metadata_json LONGTEXT        NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empresa_capacidades (empresa_id, capacidad_id),
    KEY idx_empresa_capacidades_empresa (empresa_id, activo),
    CONSTRAINT fk_ec_empresa    FOREIGN KEY (empresa_id)   REFERENCES empresas(id),
    CONSTRAINT fk_ec_capacidad  FOREIGN KEY (capacidad_id) REFERENCES sistema_capacidades(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. sistema_atributos_plantilla ───────────────────────────────────────────
-- Catálogo de atributos estándar por perfil. Sin empresa_id.
-- Al activar un perfil en una empresa, PerfilNegocioService siembra estas
-- definiciones en producto_atributos_definicion con INSERT IGNORE.
-- opciones_json: JSON array de {valor, etiqueta, orden} para tipo OPCION.
CREATE TABLE IF NOT EXISTS sistema_atributos_plantilla (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    perfil              VARCHAR(80)     NOT NULL,
    codigo              VARCHAR(80)     NOT NULL,
    nombre              VARCHAR(160)    NOT NULL,
    descripcion         VARCHAR(255)    NULL,
    tipo_dato           ENUM('TEXTO','NUMERO','DECIMAL','FECHA','BOOLEANO','OPCION','MULTIOPCION') NOT NULL,
    requerido           TINYINT(1)      NOT NULL DEFAULT 0,
    filtrable           TINYINT(1)      NOT NULL DEFAULT 0,
    visible_en_listado  TINYINT(1)      NOT NULL DEFAULT 0,
    visible_en_pos      TINYINT(1)      NOT NULL DEFAULT 0,
    orden               INT UNSIGNED    NOT NULL DEFAULT 1,
    opciones_json       LONGTEXT        NULL
    COMMENT 'JSON array [{valor, etiqueta, orden}] para tipo OPCION',
    PRIMARY KEY (id),
    UNIQUE KEY uq_sap_perfil_codigo (perfil, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6a. Seed: capacidades del sistema ────────────────────────────────────────
INSERT IGNORE INTO sistema_capacidades (codigo, nombre, descripcion) VALUES
    ('CONTROL_RECETAS',     'Control de recetas médicas',   'Exige registrar receta al vender productos marcados con requiere_receta'),
    ('LOTES_VENCIMIENTO',   'Lotes y vencimientos',         'Trazabilidad FIFO por número de lote y fecha de vencimiento'),
    ('VARIANTES',           'Variantes de producto',        'Permite talla/color/sabor con stock independiente por variante'),
    ('PRECIO_POR_PESO',     'Precio variable por peso',     'Precio calculado desde peso; integración con balanza'),
    ('MERMA_OPERATIVA',     'Merma operativa',              'Registro de mermas desde el POS y reportes de merma por categoría'),
    ('REPORTES_FARMACIA',   'Reportes farmacéuticos',       'Alertas vencimiento, rotación por principio activo, stock por lote'),
    ('REPORTES_ZAPATERIA',  'Reportes de zapatería',        'Análisis talla/color/temporada y ranking de colores'),
    ('REPORTES_MINIMARKET', 'Reportes de minimarket',       'Mermas, márgenes por categoría y rotación rápida');

-- ── 6b. Seed: plantillas FARMACIA ────────────────────────────────────────────
INSERT IGNORE INTO sistema_atributos_plantilla
    (perfil, codigo, nombre, descripcion, tipo_dato, requerido, filtrable, visible_en_listado, visible_en_pos, orden, opciones_json)
VALUES
('FARMACIA', 'principio_activo',   'Principio activo',
 'Sustancia farmacológica activa del medicamento',
 'TEXTO', 0, 1, 0, 1, 10, NULL),

('FARMACIA', 'laboratorio',        'Laboratorio',
 'Empresa fabricante o titular del registro',
 'TEXTO', 0, 1, 1, 0, 20, NULL),

('FARMACIA', 'concentracion',      'Concentración',
 'Dosis o concentración por unidad (ej: 500mg, 10mg/5ml)',
 'TEXTO', 0, 1, 0, 0, 30, NULL),

('FARMACIA', 'forma_farmaceutica', 'Forma farmacéutica',
 'Presentación del medicamento',
 'OPCION', 0, 1, 1, 0, 40,
 '[{"valor":"COMPRIMIDO","etiqueta":"Comprimido","orden":1},{"valor":"CAPSULA","etiqueta":"Cápsula","orden":2},{"valor":"JARABE","etiqueta":"Jarabe","orden":3},{"valor":"SUSPENSION","etiqueta":"Suspensión oral","orden":4},{"valor":"SOLUCION_INYECTABLE","etiqueta":"Solución inyectable","orden":5},{"valor":"CREMA","etiqueta":"Crema","orden":6},{"valor":"POMADA","etiqueta":"Pomada","orden":7},{"valor":"GEL","etiqueta":"Gel","orden":8},{"valor":"GOTAS_OFTALMICAS","etiqueta":"Gotas oftálmicas","orden":9},{"valor":"GOTAS_OTICAS","etiqueta":"Gotas óticas","orden":10},{"valor":"PARCHE","etiqueta":"Parche transdérmico","orden":11},{"valor":"SUPOSITORIO","etiqueta":"Supositorio","orden":12},{"valor":"POLVO","etiqueta":"Polvo para solución","orden":13},{"valor":"AEROSOL","etiqueta":"Aerosol","orden":14},{"valor":"OTRO","etiqueta":"Otro","orden":99}]'),

('FARMACIA', 'via_administracion', 'Vía de administración',
 'Ruta por la que se administra el medicamento',
 'OPCION', 0, 1, 0, 0, 50,
 '[{"valor":"ORAL","etiqueta":"Oral","orden":1},{"valor":"TOPICA","etiqueta":"Tópica","orden":2},{"valor":"OFTALMICA","etiqueta":"Oftálmica","orden":3},{"valor":"OTICA","etiqueta":"Ótica","orden":4},{"valor":"NASAL","etiqueta":"Nasal","orden":5},{"valor":"INHALADA","etiqueta":"Inhalada","orden":6},{"valor":"SUBCUTANEA","etiqueta":"Subcutánea","orden":7},{"valor":"INTRAMUSCULAR","etiqueta":"Intramuscular","orden":8},{"valor":"INTRAVENOSA","etiqueta":"Intravenosa","orden":9},{"valor":"RECTAL","etiqueta":"Rectal","orden":10},{"valor":"VAGINAL","etiqueta":"Vaginal","orden":11},{"valor":"OTRO","etiqueta":"Otro","orden":99}]'),

('FARMACIA', 'requiere_receta',    'Requiere receta',
 'Indica si el medicamento exige receta médica para su dispensación',
 'BOOLEANO', 0, 1, 0, 1, 60, NULL),

('FARMACIA', 'condicion_venta',    'Condición de venta',
 'Clasificación regulatoria de dispensación',
 'OPCION', 0, 0, 0, 1, 70,
 '[{"valor":"OTC","etiqueta":"Venta directa (OTC)","orden":1},{"valor":"RECETA_MEDICA","etiqueta":"Requiere receta médica","orden":2},{"valor":"RECETA_RETENIDA","etiqueta":"Receta médica retenida","orden":3},{"valor":"RECETA_CHEQUE","etiqueta":"Receta de urgencia (cheque)","orden":4},{"valor":"USO_HOSPITALARIO","etiqueta":"Uso hospitalario","orden":5}]'),

('FARMACIA', 'temperatura_almac',  'Temperatura de almacenamiento',
 'Condiciones de temperatura requeridas para conservación',
 'OPCION', 0, 0, 0, 0, 80,
 '[{"valor":"AMBIENTE","etiqueta":"Temperatura ambiente (15-25°C)","orden":1},{"valor":"REFRIGERADO","etiqueta":"Refrigerado (2-8°C)","orden":2},{"valor":"CONGELADO","etiqueta":"Congelado (-20°C o menos)","orden":3},{"valor":"PROTEGER_LUZ","etiqueta":"Proteger de la luz","orden":4}]'),

('FARMACIA', 'registro_isp',       'Registro ISP',
 'Número de registro del Instituto de Salud Pública de Chile',
 'TEXTO', 0, 0, 0, 0, 90, NULL);

-- ── 6c. Seed: plantillas ZAPATERIA ───────────────────────────────────────────
INSERT IGNORE INTO sistema_atributos_plantilla
    (perfil, codigo, nombre, descripcion, tipo_dato, requerido, filtrable, visible_en_listado, visible_en_pos, orden, opciones_json)
VALUES
('ZAPATERIA', 'genero', 'Género',
 'Público objetivo del calzado',
 'OPCION', 0, 1, 1, 0, 10,
 '[{"valor":"HOMBRE","etiqueta":"Hombre","orden":1},{"valor":"MUJER","etiqueta":"Mujer","orden":2},{"valor":"NINO","etiqueta":"Niño","orden":3},{"valor":"UNISEX","etiqueta":"Unisex","orden":4}]'),

('ZAPATERIA', 'temporada', 'Temporada',
 'Temporada para la que está diseñado el calzado',
 'OPCION', 0, 1, 1, 0, 20,
 '[{"valor":"VERANO","etiqueta":"Verano","orden":1},{"valor":"INVIERNO","etiqueta":"Invierno","orden":2},{"valor":"PRIMAVERA_OTONO","etiqueta":"Primavera-Otoño","orden":3},{"valor":"TODO_ANO","etiqueta":"Todo el año","orden":4}]'),

('ZAPATERIA', 'material', 'Material',
 'Material principal del calzado (ej: cuero, gamuza, tela)',
 'TEXTO', 0, 1, 0, 0, 30, NULL);

-- ── 6d. Seed: plantillas MINIMARKET ──────────────────────────────────────────
INSERT IGNORE INTO sistema_atributos_plantilla
    (perfil, codigo, nombre, descripcion, tipo_dato, requerido, filtrable, visible_en_listado, visible_en_pos, orden, opciones_json)
VALUES
('MINIMARKET', 'es_perecible', 'Es perecible',
 'Indica si el producto tiene fecha de vencimiento',
 'BOOLEANO', 0, 1, 1, 0, 10, NULL),

('MINIMARKET', 'temperatura_almac', 'Temperatura de almacenamiento',
 'Condiciones de temperatura requeridas para conservación',
 'OPCION', 0, 0, 0, 0, 20,
 '[{"valor":"AMBIENTE","etiqueta":"Temperatura ambiente","orden":1},{"valor":"REFRIGERADO","etiqueta":"Refrigerado (2-8°C)","orden":2},{"valor":"CONGELADO","etiqueta":"Congelado","orden":3}]');

INSERT IGNORE INTO schema_migrations (migration) VALUES ('036_perfil_negocio');
