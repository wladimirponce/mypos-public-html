-- 049_permiso_cierres_reabrir.sql
-- Permiso para reabrir un cierre diario ya cerrado. Acción sensible y auditada:
-- al reabrir, el día vuelve a estado ABIERTO (se desbloquean anulaciones y se
-- puede volver a cerrar). Idempotente.

INSERT INTO permisos (codigo, nombre, descripcion, activo)
SELECT 'cierres.reabrir', 'Reabrir cierre diario', 'Permite reabrir un cierre diario ya cerrado', 1
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE codigo = 'cierres.reabrir');

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo = 'cierres.reabrir'
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
  );
