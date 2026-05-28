-- Añadir columna onboarding_completado a empresas
ALTER TABLE `empresas`
ADD COLUMN `onboarding_completado` TINYINT(1) NOT NULL DEFAULT 0 AFTER `activo`;
