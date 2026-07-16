-- ===========================================================================
-- 076_ocr_lote_vencimiento.sql
--
-- P5: cargar fecha de vencimiento (y lote) de forma automatizada en la
-- recepción a partir del OCR de la factura de proveedor.
--
-- El OCR (Gemini) ya extrae numero_lote y fecha_vencimiento por línea. Para que
-- ese dato sobreviva el flujo documento IA → compra BORRADOR → confirmación
-- (donde se crea el lote), hay que persistirlo en ambas tablas de detalle.
-- Antes, `compra_detalles` no guardaba lote: solo llegaba al lote si la compra
-- se creaba ya CONFIRMADA con el dato en memoria.
-- ===========================================================================

-- ── Detalle de compra: persistir lote/vencimiento del ítem ───────────────────
ALTER TABLE compra_detalles
    ADD COLUMN IF NOT EXISTS numero_lote VARCHAR(100) NULL AFTER total,
    ADD COLUMN IF NOT EXISTS fecha_vencimiento DATE NULL AFTER numero_lote,
    ADD COLUMN IF NOT EXISTS fecha_fabricacion DATE NULL AFTER fecha_vencimiento;

-- ── Detalle del documento IA: lo detectado por el OCR ────────────────────────
ALTER TABLE documentos_ia_detalles
    ADD COLUMN IF NOT EXISTS numero_lote_detectado VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS fecha_vencimiento_detectada DATE NULL;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('092_ocr_lote_vencimiento');
