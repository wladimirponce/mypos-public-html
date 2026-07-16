-- ============================================================================
-- Usuario MySQL de SOLO LECTURA para el agente IA de MyPOS
-- ============================================================================
-- Garantía de infraestructura del principio "el agente jamás escribe ni borra
-- en las tablas del negocio" (docs/AGENTE_PLAN_MEJORA.md, requisito 1).
--
-- APLICACIÓN MANUAL EN PRODUCCIÓN (no automatizada a propósito):
--   1. Reemplazar CAMBIAR_ESTA_PASSWORD por una contraseña fuerte.
--   2. Ajustar el nombre de la base si no es `mypos` (buscar/reemplazar).
--   3. Ejecutar en phpMyAdmin/cPanel o CLI mysql como root.
--   4. Apuntar ConsultaFlexibleService (y el futuro motor de alertas) a esta
--      conexión vía variables de entorno (AGENTE_DB_USER / AGENTE_DB_PASS).
--
-- MANTENIMIENTO: cada tabla nueva en agent/sql_whitelist.json necesita su
-- GRANT SELECT aquí; si no, la query fallará por permisos aunque el
-- validador la acepte (ver docs/AGENTE_SQL_READONLY.md).
-- ============================================================================

CREATE USER IF NOT EXISTS 'mypos_agente_ro'@'127.0.0.1'
    IDENTIFIED BY 'CAMBIAR_ESTA_PASSWORD';

-- ── Tablas del negocio: SOLO SELECT ─────────────────────────────────────────
-- Whitelist actual de agent/sql_whitelist.json (ampliada Fase 3, 2026-07-02)
GRANT SELECT ON mypos.productos          TO 'mypos_agente_ro'@'127.0.0.1';
GRANT SELECT ON mypos.stock_ubicacion    TO 'mypos_agente_ro'@'127.0.0.1';
GRANT SELECT ON mypos.ubicaciones_stock  TO 'mypos_agente_ro'@'127.0.0.1';
GRANT SELECT ON mypos.ventas             TO 'mypos_agente_ro'@'127.0.0.1';
GRANT SELECT ON mypos.venta_detalles     TO 'mypos_agente_ro'@'127.0.0.1';
GRANT SELECT ON mypos.proveedores        TO 'mypos_agente_ro'@'127.0.0.1';

-- Tablas que el motor de alertas proactivas (Fase 1) necesitará leer.
-- Si alguna no existe aún en tu esquema, comenta la línea y re-ejecuta.
GRANT SELECT ON mypos.empresas             TO 'mypos_agente_ro'@'127.0.0.1';
GRANT SELECT ON mypos.sucursales           TO 'mypos_agente_ro'@'127.0.0.1';
GRANT SELECT ON mypos.cajas                TO 'mypos_agente_ro'@'127.0.0.1';
GRANT SELECT ON mypos.caja_aperturas       TO 'mypos_agente_ro'@'127.0.0.1';
GRANT SELECT ON mypos.cierres_diarios      TO 'mypos_agente_ro'@'127.0.0.1';
GRANT SELECT ON mypos.compras              TO 'mypos_agente_ro'@'127.0.0.1';
GRANT SELECT ON mypos.clientes             TO 'mypos_agente_ro'@'127.0.0.1';
GRANT SELECT ON mypos.empresas_suscripcion TO 'mypos_agente_ro'@'127.0.0.1';

-- ── Tablas PROPIAS del agente (prefijo agente_): lectura y escritura ────────
-- Únicas tablas donde el agente escribe. Se crean con la migración
-- 067_agente_consultas_log.sql (y siguientes de la Fase 1).
GRANT SELECT, INSERT, UPDATE ON mypos.agente_consultas_log TO 'mypos_agente_ro'@'127.0.0.1';

-- NOTA: deliberadamente NO se otorga DELETE en ninguna tabla, ni permisos
-- sobre tablas no listadas, ni CREATE/ALTER/DROP. El borrado de entradas de
-- la bandeja lo hace admin con su propia conexión.

FLUSH PRIVILEGES;

-- Verificación rápida (ejecutar conectado como mypos_agente_ro):
--   SELECT COUNT(*) FROM mypos.ventas;          -- debe funcionar
--   DELETE FROM mypos.ventas WHERE id = 0;      -- debe fallar (sin permiso)
--   UPDATE mypos.productos SET precio_venta = 0 WHERE id = 0;  -- debe fallar
