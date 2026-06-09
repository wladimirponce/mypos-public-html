-- ===========================================================================
-- 040_perfiles_negocio_prioritarios.sql
--
-- Amplia las plantillas de perfil_negocio a los diez perfiles prioritarios.
-- Solo agrega atributos descriptivos y ejes de variante. No implementa
-- comportamientos nuevos ni activa capacidades que no existan.
-- ===========================================================================

ALTER TABLE empresa_configuracion_operativa
    MODIFY COLUMN perfil_negocio VARCHAR(80) NULL
    COMMENT 'Perfil informativo activo; la logica depende de empresa_capacidades';

INSERT IGNORE INTO sistema_atributos_plantilla
    (perfil, codigo, nombre, descripcion, tipo_dato, requerido, filtrable, visible_en_listado, visible_en_pos, orden, opciones_json)
VALUES
('BOTILLERIA', 'tipo_bebida', 'Tipo de bebida', 'Clasificacion comercial de la bebida', 'TEXTO', 0, 1, 1, 0, 10, NULL),
('BOTILLERIA', 'formato_contenido', 'Formato o contenido', 'Ej: 350 ml, 750 ml, 1 litro', 'TEXTO', 0, 1, 1, 1, 20, NULL),
('BOTILLERIA', 'graduacion_alcoholica', 'Graduacion alcoholica', 'Porcentaje de alcohol del producto', 'DECIMAL', 0, 1, 0, 0, 30, NULL),
('BOTILLERIA', 'es_retornable', 'Envase retornable', 'Indica si requiere gestionar envase retornable', 'BOOLEANO', 0, 1, 0, 1, 40, NULL),
('BOTILLERIA', 'requiere_control_edad', 'Requiere control de edad', 'Alerta comercial para venta restringida por edad', 'BOOLEANO', 0, 1, 0, 1, 50, NULL),
('BOTILLERIA', 'impuesto_adicional_tipo', 'Tipo de impuesto adicional', 'Clasificacion informativa del impuesto adicional aplicable', 'TEXTO', 0, 1, 0, 0, 60, NULL),

('ALMACEN', 'unidad_venta', 'Unidad de venta', 'Ej: unidad, kilo, gramo, litro', 'TEXTO', 0, 1, 1, 1, 10, NULL),
('ALMACEN', 'es_perecible', 'Es perecible', 'Indica si el producto tiene vencimiento', 'BOOLEANO', 0, 1, 0, 0, 20, NULL),
('ALMACEN', 'permite_fiado', 'Permite fiado', 'Indica si normalmente se permite vender este producto a credito', 'BOOLEANO', 0, 0, 0, 0, 30, NULL),

('FERRETERIA', 'marca', 'Marca', 'Marca comercial del producto', 'TEXTO', 0, 1, 1, 0, 10, NULL),
('FERRETERIA', 'medida', 'Medida', 'Dimension o calibre principal', 'TEXTO', 0, 1, 1, 1, 20, NULL),
('FERRETERIA', 'material', 'Material', 'Material principal del producto', 'TEXTO', 0, 1, 0, 0, 30, NULL),
('FERRETERIA', 'codigo_fabricante', 'Codigo fabricante', 'Codigo tecnico o numero de parte', 'TEXTO', 0, 1, 1, 0, 40, NULL),
('FERRETERIA', 'unidad_venta_tecnica', 'Unidad de venta tecnica', 'Ej: unidad, metro, rollo, kilo', 'TEXTO', 0, 1, 0, 1, 50, NULL),

('PANADERIA_PASTELERIA', 'tipo_producto_panaderia', 'Tipo de producto', 'Pan, pastel, torta, masa u otro', 'TEXTO', 0, 1, 1, 0, 10, NULL),
('PANADERIA_PASTELERIA', 'es_elaboracion_propia', 'Elaboracion propia', 'Indica si el producto se fabrica en el negocio', 'BOOLEANO', 0, 1, 0, 0, 20, NULL),
('PANADERIA_PASTELERIA', 'vida_util_dias', 'Vida util en dias', 'Duracion estimada del producto', 'NUMERO', 0, 0, 0, 0, 30, NULL),
('PANADERIA_PASTELERIA', 'requiere_refrigeracion', 'Requiere refrigeracion', 'Indica si debe mantenerse refrigerado', 'BOOLEANO', 0, 1, 0, 0, 40, NULL),

('CARNICERIA', 'tipo_carne', 'Tipo de carne', 'Vacuno, cerdo, pollo u otro', 'TEXTO', 0, 1, 1, 0, 10, NULL),
('CARNICERIA', 'corte', 'Corte', 'Nombre comercial del corte', 'TEXTO', 0, 1, 1, 1, 20, NULL),
('CARNICERIA', 'origen', 'Origen', 'Pais, proveedor o procedencia', 'TEXTO', 0, 1, 0, 0, 30, NULL),
('CARNICERIA', 'requiere_refrigeracion', 'Requiere refrigeracion', 'Indica si debe mantenerse refrigerado', 'BOOLEANO', 0, 1, 0, 0, 40, NULL),

('VERDULERIA', 'tipo_producto_fresco', 'Tipo de producto', 'Fruta, verdura, hortaliza u otro', 'TEXTO', 0, 1, 1, 0, 10, NULL),
('VERDULERIA', 'origen', 'Origen', 'Zona o proveedor de origen', 'TEXTO', 0, 1, 0, 0, 20, NULL),
('VERDULERIA', 'temporada', 'Temporada', 'Temporada comercial del producto', 'TEXTO', 0, 1, 0, 0, 30, NULL),

('ROPA_CALZADO', 'genero', 'Genero', 'Publico objetivo de la prenda o calzado', 'TEXTO', 0, 1, 1, 0, 10, NULL),
('ROPA_CALZADO', 'temporada', 'Temporada', 'Temporada o coleccion comercial', 'TEXTO', 0, 1, 1, 0, 20, NULL),
('ROPA_CALZADO', 'material', 'Material', 'Material principal', 'TEXTO', 0, 1, 0, 0, 30, NULL),
('ROPA_CALZADO', 'marca', 'Marca', 'Marca comercial', 'TEXTO', 0, 1, 1, 0, 40, NULL),

('DISTRIBUIDORA_MAYORISTA', 'formato_venta', 'Formato de venta', 'Unidad, caja, pallet u otro formato', 'TEXTO', 0, 1, 1, 1, 10, NULL),
('DISTRIBUIDORA_MAYORISTA', 'unidades_por_formato', 'Unidades por formato', 'Cantidad de unidades contenidas en el formato', 'NUMERO', 0, 0, 0, 0, 20, NULL),
('DISTRIBUIDORA_MAYORISTA', 'marca', 'Marca', 'Marca comercial del producto', 'TEXTO', 0, 1, 1, 0, 30, NULL),
('DISTRIBUIDORA_MAYORISTA', 'es_perecible', 'Es perecible', 'Indica si requiere controlar vencimiento', 'BOOLEANO', 0, 1, 0, 0, 40, NULL);

-- Ejes de variante reutilizables.
INSERT IGNORE INTO sistema_atributos_plantilla
    (perfil, codigo, nombre, descripcion, tipo_dato, requerido, filtrable, es_eje_variante, visible_en_listado, visible_en_pos, orden, opciones_json)
VALUES
('BOTILLERIA', 'formato_pack', 'Formato pack', 'Presentacion comercial del producto', 'TEXTO', 0, 1, 1, 1, 1, 70, NULL),
('FERRETERIA', 'variante_tecnica', 'Variante tecnica', 'Medida, color o presentacion que cambia el SKU', 'TEXTO', 0, 1, 1, 1, 1, 60, NULL),
('ROPA_CALZADO', 'talla', 'Talla', 'Talla de la prenda o calzado', 'TEXTO', 0, 1, 1, 1, 1, 50, NULL),
('ROPA_CALZADO', 'color', 'Color', 'Color de la prenda o calzado', 'TEXTO', 0, 1, 1, 1, 1, 60, NULL),
('DISTRIBUIDORA_MAYORISTA', 'presentacion', 'Presentacion', 'Presentacion comercial con SKU independiente', 'TEXTO', 0, 1, 1, 1, 1, 50, NULL);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('040_perfiles_negocio_prioritarios');
