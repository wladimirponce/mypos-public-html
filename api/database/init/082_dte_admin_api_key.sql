-- Clave API compartida admin <-> web backend, por empresa, autogestionada en BD.
-- Elimina la necesidad de editar .env: el web backend la lee/genera y la envia;
-- el admin la valida contra esta misma columna (misma base de datos).
-- Columna dedicada (NO metadata_json) porque metadata se expone al frontend y se
-- sobrescribe al guardar la configuracion DTE.
ALTER TABLE dte_configuracion
    ADD COLUMN IF NOT EXISTS admin_api_key VARCHAR(80) NULL
        COMMENT 'Clave compartida admin<->web para emision DTE. Autogenerada.'
    AFTER metadata_json;

INSERT IGNORE INTO schema_migrations (migration) VALUES ('082_dte_admin_api_key');
