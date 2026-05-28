-- Fase 23: Offline first real
-- Migracion incremental e idempotente.

SET @migration_name = '021_offline_first_real';

CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(190) NOT NULL UNIQUE,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE dispositivos
    ADD COLUMN IF NOT EXISTS uuid_dispositivo VARCHAR(100) NULL AFTER usuario_id,
    ADD COLUMN IF NOT EXISTS estado VARCHAR(30) NOT NULL DEFAULT 'ACTIVO' AFTER tipo,
    ADD COLUMN IF NOT EXISTS metadata_json JSON NULL AFTER ultimo_sync_at;

ALTER TABLE dispositivos
    MODIFY COLUMN tipo VARCHAR(30) NOT NULL DEFAULT 'POS';

UPDATE dispositivos
SET uuid_dispositivo = device_uuid
WHERE uuid_dispositivo IS NULL
  AND device_uuid IS NOT NULL;

UPDATE dispositivos
SET estado = CASE WHEN activo = 1 THEN 'ACTIVO' ELSE 'BLOQUEADO' END
WHERE estado IS NULL OR estado = '';

CREATE UNIQUE INDEX IF NOT EXISTS uq_dispositivos_empresa_uuid_dispositivo
    ON dispositivos (empresa_id, uuid_dispositivo);

CREATE INDEX IF NOT EXISTS idx_dispositivos_empresa_estado
    ON dispositivos (empresa_id, estado);

ALTER TABLE sync_eventos
    ADD COLUMN IF NOT EXISTS uuid_evento VARCHAR(100) NULL AFTER usuario_id,
    ADD COLUMN IF NOT EXISTS tipo_evento VARCHAR(50) NULL AFTER uuid_evento,
    ADD COLUMN IF NOT EXISTS payload_json JSON NULL AFTER estado,
    ADD COLUMN IF NOT EXISTS resultado_json JSON NULL AFTER payload_json,
    ADD COLUMN IF NOT EXISTS procesado_at TIMESTAMP NULL AFTER created_at;

ALTER TABLE sync_eventos
    MODIFY COLUMN estado VARCHAR(30) NOT NULL DEFAULT 'RECIBIDO';

UPDATE sync_eventos
SET uuid_evento = entidad_uuid
WHERE uuid_evento IS NULL
  AND entidad_uuid IS NOT NULL;

UPDATE sync_eventos
SET tipo_evento = operacion
WHERE tipo_evento IS NULL
  AND operacion IS NOT NULL;

UPDATE sync_eventos
SET payload_json = payload
WHERE payload_json IS NULL
  AND payload IS NOT NULL;

UPDATE sync_eventos
SET procesado_at = processed_at
WHERE procesado_at IS NULL
  AND processed_at IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_sync_eventos_empresa_uuid_evento
    ON sync_eventos (empresa_id, uuid_evento);

CREATE INDEX IF NOT EXISTS idx_sync_eventos_empresa_estado
    ON sync_eventos (empresa_id, estado, created_at);

CREATE INDEX IF NOT EXISTS idx_sync_eventos_dispositivo
    ON sync_eventos (dispositivo_id, created_at);

ALTER TABLE ventas
    ADD COLUMN IF NOT EXISTS uuid_offline VARCHAR(100) NULL AFTER uuid,
    ADD COLUMN IF NOT EXISTS sync_evento_id BIGINT UNSIGNED NULL AFTER dispositivo_id,
    ADD COLUMN IF NOT EXISTS origen VARCHAR(20) NOT NULL DEFAULT 'ONLINE' AFTER sync_evento_id,
    ADD COLUMN IF NOT EXISTS sync_estado VARCHAR(30) NOT NULL DEFAULT 'SYNC_OK' AFTER origen,
    ADD COLUMN IF NOT EXISTS created_offline_at DATETIME NULL AFTER sync_estado;

UPDATE ventas
SET uuid_offline = uuid
WHERE uuid_offline IS NULL
  AND uuid IS NOT NULL;

UPDATE ventas
SET origen = 'OFFLINE'
WHERE dispositivo_id IS NOT NULL
  AND origen = 'ONLINE';

CREATE UNIQUE INDEX IF NOT EXISTS uq_ventas_empresa_uuid_offline
    ON ventas (empresa_id, uuid_offline);

CREATE INDEX IF NOT EXISTS idx_ventas_offline_sync
    ON ventas (empresa_id, origen, sync_estado);

ALTER TABLE documentos_emitidos
    ADD COLUMN IF NOT EXISTS uuid_offline VARCHAR(100) NULL AFTER venta_id,
    ADD COLUMN IF NOT EXISTS origen VARCHAR(20) NOT NULL DEFAULT 'ONLINE' AFTER emision_origen,
    ADD COLUMN IF NOT EXISTS sync_estado VARCHAR(30) NULL AFTER origen;

ALTER TABLE folios_consumidos
    ADD COLUMN IF NOT EXISTS uuid_offline VARCHAR(100) NULL AFTER folio,
    ADD COLUMN IF NOT EXISTS folio_asignacion_id BIGINT UNSIGNED NULL AFTER asignacion_id;

UPDATE folios_consumidos
SET folio_asignacion_id = asignacion_id
WHERE folio_asignacion_id IS NULL
  AND asignacion_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_folios_consumidos_uuid_offline
    ON folios_consumidos (empresa_id, tipo_documento, uuid_offline);

ALTER TABLE stock_movimientos
    ADD COLUMN IF NOT EXISTS uuid_offline VARCHAR(100) NULL AFTER uuid,
    ADD COLUMN IF NOT EXISTS origen VARCHAR(20) NOT NULL DEFAULT 'ONLINE' AFTER dispositivo_id;

CREATE TABLE IF NOT EXISTS sync_conflictos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NULL,
    dispositivo_id BIGINT UNSIGNED NULL,
    sync_evento_id BIGINT UNSIGNED NOT NULL,
    tipo_conflicto VARCHAR(60) NOT NULL,
    entidad VARCHAR(100) NOT NULL,
    entidad_uuid VARCHAR(100) NULL,
    entidad_id BIGINT UNSIGNED NULL,
    descripcion TEXT NOT NULL,
    payload_json JSON NULL,
    resolucion VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
    comentario_resolucion TEXT NULL,
    resuelto_por_usuario_id BIGINT UNSIGNED NULL,
    resuelto_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sync_conflictos_empresa_resolucion (empresa_id, resolucion, created_at),
    INDEX idx_sync_conflictos_evento (sync_evento_id),
    INDEX idx_sync_conflictos_dispositivo (dispositivo_id, created_at)
);

INSERT INTO permisos (codigo, nombre, descripcion, activo, created_at)
SELECT p.codigo, p.nombre, p.descripcion, 1, CURRENT_TIMESTAMP
FROM (
    SELECT 'dispositivos.ver' AS codigo, 'Ver dispositivos' AS nombre, 'Consultar dispositivos offline' AS descripcion
    UNION ALL SELECT 'dispositivos.registrar', 'Registrar dispositivos', 'Registrar dispositivos offline'
    UNION ALL SELECT 'dispositivos.editar', 'Editar dispositivos', 'Editar dispositivos offline'
    UNION ALL SELECT 'dispositivos.bloquear', 'Bloquear dispositivos', 'Bloquear o revocar dispositivos'
    UNION ALL SELECT 'sync.enviar', 'Enviar sincronizacion', 'Enviar eventos offline al backend'
    UNION ALL SELECT 'sync.ver', 'Ver sincronizacion', 'Consultar estado y eventos de sincronizacion'
    UNION ALL SELECT 'sync.conflictos.ver', 'Ver conflictos sync', 'Consultar conflictos de sincronizacion'
    UNION ALL SELECT 'sync.conflictos.resolver', 'Resolver conflictos sync', 'Marcar conflictos de sincronizacion como resueltos'
) p
WHERE NOT EXISTS (
    SELECT 1 FROM permisos existing WHERE existing.codigo = p.codigo
);

INSERT INTO rol_permisos (rol_id, permiso_id, created_at)
SELECT r.id, p.id, CURRENT_TIMESTAMP
FROM roles r
INNER JOIN permisos p ON p.codigo IN (
    'dispositivos.ver',
    'dispositivos.registrar',
    'dispositivos.editar',
    'dispositivos.bloquear',
    'sync.enviar',
    'sync.ver',
    'sync.conflictos.ver',
    'sync.conflictos.resolver'
)
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
  );

INSERT INTO rol_permisos (rol_id, permiso_id, created_at)
SELECT r.id, p.id, CURRENT_TIMESTAMP
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('sync.enviar', 'sync.ver', 'dispositivos.ver')
WHERE r.codigo IN ('CAJERO', 'VENDEDOR', 'BODEGUERO')
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
  );

INSERT INTO rol_permisos (rol_id, permiso_id, created_at)
SELECT r.id, p.id, CURRENT_TIMESTAMP
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('sync.ver', 'sync.conflictos.ver')
WHERE r.codigo IN ('AUDITOR')
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
  );

INSERT INTO rol_permisos (rol_id, permiso_id, created_at)
SELECT r.id, p.id, CURRENT_TIMESTAMP
FROM roles r
INNER JOIN permisos p ON p.codigo = 'sync.ver'
WHERE r.codigo IN ('CONTADOR')
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
  );

INSERT IGNORE INTO schema_migrations (migration) VALUES (@migration_name);
