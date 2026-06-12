UPDATE empresas_suscripcion SET plan_id = 'mypos-start' WHERE plan_id = 'pos';
UPDATE empresas_suscripcion SET plan_id = 'mypos-pro' WHERE plan_id = 'multisucursal';
UPDATE suscripciones_ordenes SET plan_id = 'mypos-start' WHERE plan_id = 'pos';
UPDATE suscripciones_ordenes SET plan_id = 'mypos-pro' WHERE plan_id = 'multisucursal';

INSERT IGNORE INTO schema_migrations (migration) VALUES ('048_normalizar_planes');
