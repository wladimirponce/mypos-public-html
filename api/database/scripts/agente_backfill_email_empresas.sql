-- Backfill UNA VEZ: empresas.email para empresas registradas antes del fix
-- de AuthService::register (que ahora copia el correo de registro a la
-- empresa). Toma el correo del primer usuario activo, priorizando SUPER_ADMIN.
-- Ejecutar en zylajdcb_mypos. Idempotente: solo toca filas sin email.
--
-- Verificacion previa (cuantas empresas estan sin correo):
--   SELECT id, razon_social FROM empresas WHERE email IS NULL OR email = '';

UPDATE empresas e
SET e.email = (
    SELECT u.email
    FROM empresa_usuarios eu
    INNER JOIN usuarios u ON u.id = eu.usuario_id
    LEFT JOIN roles r ON r.id = eu.rol_id
    WHERE eu.empresa_id = e.id
      AND eu.activo = 1
      AND u.email IS NOT NULL AND u.email <> ''
    ORDER BY (r.codigo = 'SUPER_ADMIN') DESC, eu.id ASC
    LIMIT 1
)
WHERE (e.email IS NULL OR e.email = '');

-- Verificacion posterior (deberia devolver 0 filas, salvo empresas sin
-- ningun usuario activo con correo):
--   SELECT id, razon_social FROM empresas WHERE email IS NULL OR email = '';
