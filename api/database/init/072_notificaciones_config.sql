-- ============================================================
-- Migración 072: Flags de notificación DTE + permisos cotizaciones
-- ============================================================

-- 1) Columnas de notificación por empresa
ALTER TABLE empresa_configuracion_operativa
    ADD COLUMN IF NOT EXISTS notif_email_activo      TINYINT(1) NOT NULL DEFAULT 0 AFTER modo_offline_habilitado,
    ADD COLUMN IF NOT EXISTS notif_whatsapp_activo   TINYINT(1) NOT NULL DEFAULT 0 AFTER notif_email_activo;

-- 2) Permisos del módulo cotizaciones (idempotente)
INSERT INTO permisos (codigo, nombre, descripcion)
SELECT x.codigo, x.nombre, x.descripcion
FROM (
    SELECT 'cotizaciones.ver'       AS codigo, 'Ver cotizaciones'                     AS nombre, 'Permite consultar la lista y detalle de cotizaciones'    AS descripcion UNION ALL
    SELECT 'cotizaciones.crear',              'Crear cotizaciones',                    'Permite crear y editar cotizaciones'                              UNION ALL
    SELECT 'cotizaciones.aprobar',            'Aprobar/rechazar cotizaciones',          'Permite cambiar el estado de una cotización (aprobar/rechazar)'   UNION ALL
    SELECT 'cotizaciones.convertir',          'Convertir cotización a venta',           'Permite convertir una cotización aprobada en venta'
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

-- 3) Otorgar todos los permisos a SUPER_ADMIN y ADMIN_EMPRESA (idempotente)
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp
      WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
  );

INSERT IGNORE INTO schema_migrations (migration) VALUES ('072_notificaciones_config');
