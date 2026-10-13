-- Transferencia bancaria y Convenio como medios de pago en el POS.
--
-- TRANSFERENCIA ya venia en 001_seed_base.sql y la caja ya separa
-- `total_ventas_transferencia`, pero el seed no siempre corre en produccion.
-- CONVENIO se ofrece en el POS (PaymentPanel) pero nunca se registro en
-- metodos_pago, por lo que VentaRepository::activePaymentMethod() rechazaba
-- esas ventas. Esta migracion garantiza que ambos existan y esten activos.
-- Idempotente: puede ejecutarse nuevamente sin efectos.

INSERT INTO metodos_pago (codigo, nombre, activo) VALUES
('TRANSFERENCIA', 'Transferencia', 1),
('CONVENIO', 'Convenio', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), activo = 1;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('102_metodo_pago_transferencia');
