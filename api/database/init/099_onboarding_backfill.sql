-- Backfill de `empresas.onboarding_completado`.
--
-- Contexto: hasta esta version la bandera solo se encendia en el registro con
-- link de promocion (AuthService::register) y ningun endpoint publicado sabia
-- apagarla despues -- OnboardingController::saveOnboarding existia pero no
-- estaba ruteado. Resultado: toda empresa creada por el registro normal quedaba
-- con la bandera en 0 para siempre, y las guardias del frontend devolvian a su
-- SUPER_ADMIN al asistente de bienvenida en cada navegacion. Con rol
-- ADMIN_EMPRESA la misma cuenta funcionaba, porque las guardias solo filtran
-- SUPER_ADMIN.
--
-- Esta migracion libera a las empresas que ya estaban operando. NO marca a las
-- empresas recien registradas que todavia no han cargado nada: esas si deben
-- ver el asistente.
--
-- ANTES DE APLICAR EN PRODUCCION: correr el conteo de mypos-backend/scripts/
-- onboarding_backfill_check.sql para saber a cuantas empresas afecta y revisar
-- el listado.

UPDATE empresas e
SET e.onboarding_completado = 1
WHERE e.onboarding_completado = 0
  AND (
        -- Ya cargo catalogo.
        EXISTS (SELECT 1 FROM productos p WHERE p.empresa_id = e.id)
        -- Ya vendio.
     OR EXISTS (SELECT 1 FROM ventas v WHERE v.empresa_id = e.id)
        -- Ya declaro su giro: el asistente de bienvenida no tiene nada que pedirle.
     OR (e.giro IS NOT NULL AND e.giro <> '')
     OR EXISTS (
            SELECT 1 FROM empresa_configuracion ec
            WHERE ec.empresa_id = e.id
              AND ec.giro IS NOT NULL
              AND ec.giro <> ''
        )
        -- Ya abrio una sucursal propia ademas de la Casa Matriz automatica.
     OR (SELECT COUNT(*) FROM sucursales s WHERE s.empresa_id = e.id AND s.activo = 1) > 1
  );

INSERT IGNORE INTO schema_migrations (migration) VALUES ('099_onboarding_backfill');
