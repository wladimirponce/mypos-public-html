-- Permisos explicitos para el CRM heredado. Solo administradores reciben
-- acceso por defecto; los roles personalizados deben concederlos de forma
-- deliberada desde la administracion de permisos.
INSERT INTO permisos (codigo, nombre, descripcion)
SELECT x.codigo, x.nombre, x.descripcion
FROM (
    SELECT 'crm.ver' AS codigo, 'Ver CRM' AS nombre,
           'Permite consultar leads, conversaciones, tareas, etapas y campanas' AS descripcion
    UNION ALL
    SELECT 'crm.gestionar', 'Gestionar CRM',
           'Permite editar leads, tareas, etapas, plantillas y conversiones'
    UNION ALL
    SELECT 'crm.enviar', 'Enviar comunicaciones CRM',
           'Permite responder conversaciones y enviar plantillas o campanas'
) x
WHERE NOT EXISTS (
    SELECT 1 FROM permisos p WHERE p.codigo = x.codigo
);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('crm.ver', 'crm.gestionar', 'crm.enviar')
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (
      SELECT 1
      FROM rol_permisos rp
      WHERE rp.rol_id = r.id
        AND rp.permiso_id = p.id
  );

INSERT IGNORE INTO schema_migrations (migration) VALUES ('078_crm_authorization');
