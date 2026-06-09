-- ===========================================================================
-- 037_variantes_producto.sql
--
-- Phase B del plan multi-rubro: Variantes de producto (talla / color / sabor).
--
-- DECISIÓN TÉCNICA:
--   Cada variante es un producto hijo completo en la tabla `productos`.
--   Esto permite que StockService, VentaService y CompraService operen sin
--   cambios: ya trabajan sobre producto_id, y el hijo ES un producto válido.
--
--   El producto padre actúa como plantilla (precio base, rubro, descripción).
--   La venta siempre registra el producto_id del hijo (la variante específica).
--
-- CONTENIDO:
--   1. producto_atributos_definicion.es_eje_variante
--   2. sistema_atributos_plantilla.es_eje_variante
--   3. producto_variantes            — relación padre → hijo
--   4. producto_variante_atributos   — valores de ejes para cada variante
--   5. Seed: ejes talla y color para perfil ZAPATERIA
-- ===========================================================================

-- ── 1. Marcar atributos como "ejes de variante" ──────────────────────────────
ALTER TABLE producto_atributos_definicion
    ADD COLUMN IF NOT EXISTS es_eje_variante TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Si 1, este atributo define un eje de variación y genera SKU hijo'
    AFTER filtrable;

-- ── 2. Extender plantillas del sistema para soportar el flag ─────────────────
ALTER TABLE sistema_atributos_plantilla
    ADD COLUMN IF NOT EXISTS es_eje_variante TINYINT(1) NOT NULL DEFAULT 0
    AFTER filtrable;

-- ── 3. producto_variantes — relación padre → hijo ────────────────────────────
CREATE TABLE IF NOT EXISTS producto_variantes (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id        BIGINT UNSIGNED NOT NULL,
    producto_padre_id BIGINT UNSIGNED NOT NULL,
    producto_id       BIGINT UNSIGNED NOT NULL,   -- el producto hijo (SKU real)
    codigo_variante   VARCHAR(120)    NOT NULL,    -- ej: "38-NEGRO", "M-ROJO"
    activo            TINYINT(1)      NOT NULL DEFAULT 1,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP       NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pv_padre_codigo  (empresa_id, producto_padre_id, codigo_variante),
    UNIQUE KEY uq_pv_hijo          (empresa_id, producto_id),
    KEY idx_pv_padre               (empresa_id, producto_padre_id, activo),
    CONSTRAINT fk_pv_empresa FOREIGN KEY (empresa_id)        REFERENCES empresas(id),
    CONSTRAINT fk_pv_padre   FOREIGN KEY (producto_padre_id) REFERENCES productos(id),
    CONSTRAINT fk_pv_hijo    FOREIGN KEY (producto_id)       REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. producto_variante_atributos — valores de ejes por variante ─────────────
CREATE TABLE IF NOT EXISTS producto_variante_atributos (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id   BIGINT UNSIGNED NOT NULL,
    variante_id  BIGINT UNSIGNED NOT NULL,
    atributo_id  BIGINT UNSIGNED NOT NULL,  -- debe tener es_eje_variante = 1
    opcion_id    BIGINT UNSIGNED NULL,      -- para tipo OPCION
    valor_texto  VARCHAR(120)    NULL,      -- para tipo TEXTO libre (ej: color en hex)
    PRIMARY KEY (id),
    UNIQUE KEY uq_pva_variante_atributo (variante_id, atributo_id),
    KEY idx_pva_variante                (variante_id),
    CONSTRAINT fk_pva_empresa   FOREIGN KEY (empresa_id)  REFERENCES empresas(id),
    CONSTRAINT fk_pva_variante  FOREIGN KEY (variante_id) REFERENCES producto_variantes(id),
    CONSTRAINT fk_pva_atributo  FOREIGN KEY (atributo_id) REFERENCES producto_atributos_definicion(id),
    CONSTRAINT fk_pva_opcion    FOREIGN KEY (opcion_id)   REFERENCES producto_atributos_opciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Seed: ejes talla y color para perfil ZAPATERIA ────────────────────────
-- Estos son ejes de variante (es_eje_variante = 1), distintos de los atributos
-- descriptivos (genero, temporada, material) que ya existen en la plantilla.
INSERT IGNORE INTO sistema_atributos_plantilla
    (perfil, codigo, nombre, descripcion, tipo_dato, requerido, filtrable,
     es_eje_variante, visible_en_listado, visible_en_pos, orden, opciones_json)
VALUES
('ZAPATERIA', 'talla', 'Talla',
 'Talla del calzado en sistema europeo (EU)',
 'OPCION', 1, 1, 1, 1, 1, 5,
 '[{"valor":"35","etiqueta":"35","orden":1},{"valor":"36","etiqueta":"36","orden":2},{"valor":"37","etiqueta":"37","orden":3},{"valor":"38","etiqueta":"38","orden":4},{"valor":"39","etiqueta":"39","orden":5},{"valor":"40","etiqueta":"40","orden":6},{"valor":"41","etiqueta":"41","orden":7},{"valor":"42","etiqueta":"42","orden":8},{"valor":"43","etiqueta":"43","orden":9},{"valor":"44","etiqueta":"44","orden":10},{"valor":"45","etiqueta":"45","orden":11}]'),

('ZAPATERIA', 'color', 'Color',
 'Color principal del calzado',
 'OPCION', 1, 1, 1, 1, 1, 6,
 '[{"valor":"NEGRO","etiqueta":"Negro","orden":1},{"valor":"BLANCO","etiqueta":"Blanco","orden":2},{"valor":"CAFE","etiqueta":"Café","orden":3},{"valor":"GRIS","etiqueta":"Gris","orden":4},{"valor":"AZUL","etiqueta":"Azul","orden":5},{"valor":"ROJO","etiqueta":"Rojo","orden":6},{"valor":"BEIGE","etiqueta":"Beige","orden":7},{"valor":"VERDE","etiqueta":"Verde","orden":8},{"valor":"OTRO","etiqueta":"Otro","orden":99}]');

INSERT IGNORE INTO schema_migrations (migration) VALUES ('037_variantes_producto');
