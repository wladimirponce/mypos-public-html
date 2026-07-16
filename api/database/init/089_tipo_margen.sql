-- ===========================================================================
-- 073_tipo_margen.sql
--
-- Guardián del Margen (P1): distinguir MARKUP vs MARGEN BRUTO REAL.
--
-- Hasta ahora `margen_ganancia` se interpretaba SIEMPRE como markup sobre el
-- costo:  precio = costo * (1 + margen/100).
-- El margen bruto REAL que el dueño percibe es distinto:
--   precio = costo / (1 - margen/100).
-- Ej.: costo 1000, "35%" markup => 1350 (margen real 25,9%);
--      costo 1000, "35%" margen real => 1538 (markup real 53,8%).
--
-- Para NO alterar los precios ya configurados por los clientes, agregamos una
-- bandera por producto. Los productos existentes quedan en 'markup' (su
-- comportamiento histórico); los nuevos pueden elegir 'margen'.
-- ===========================================================================

ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS tipo_margen ENUM('markup','margen')
    NOT NULL DEFAULT 'markup'
    COMMENT 'markup = costo*(1+m/100); margen = costo/(1-m/100). Interpreta margen_ganancia'
    AFTER margen_ganancia;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('089_tipo_margen');
