-- =============================================================================
-- Migración 006 — API Key para el APK Android de Farmacias Belén (32 terminales)
-- Fecha    : 2026-05-19
-- Objetivo : Registrar la clave de acceso que usan los terminales POS Android
--            (fb-pos-android-2026) para autenticarse contra api.php y fb/index.php.
--
-- Ejecutar : UNA sola vez en producción (idempotente vía ON DUPLICATE KEY).
--            mysql -u USER -p DBNAME < 2026_05_19_006_api_key_farmacias_belen.sql
--
-- IMPORTANTE: La API key en texto plano es:
--             fb-pos-K9mXw2LpQnR7sT4vBcYhJdZeA1uN8gF3
--             Debe colocarse en el campo <auth> del emisor.xml de cada tablet.
--             Esta migración solo guarda el hash SHA-256 (la key plana NO se
--             almacena en la base de datos por seguridad).
-- =============================================================================

SET NAMES utf8mb4;

-- Obtener el empresa_id de Alcaino y Araya SPA
SET @empresa_id = (SELECT id FROM sii_empresa WHERE rut = '77020050-4' LIMIT 1);

-- Insertar API key (SHA-256 del valor en texto plano)
-- SHA2('fb-pos-K9mXw2LpQnR7sT4vBcYhJdZeA1uN8gF3', 256) se calcula en MySQL
INSERT INTO sii_api_key (empresa_id, nombre, clave_hash, permisos, activa)
VALUES (
    @empresa_id,
    'APK-POS-Android-32-terminales',
    SHA2('fb-pos-K9mXw2LpQnR7sT4vBcYhJdZeA1uN8gF3', 256),
    '["emitir","consultar","productos","sucursales"]',
    1
)
ON DUPLICATE KEY UPDATE
    nombre  = VALUES(nombre),
    permisos = VALUES(permisos),
    activa  = 1;

-- Verificación
SELECT ak.id, ak.nombre, ak.activa, e.rut, e.razon_social
FROM   sii_api_key ak
JOIN   sii_empresa e ON e.id = ak.empresa_id
WHERE  ak.clave_hash = SHA2('fb-pos-K9mXw2LpQnR7sT4vBcYhJdZeA1uN8gF3', 256);

-- =============================================================================
-- FIN
-- =============================================================================
