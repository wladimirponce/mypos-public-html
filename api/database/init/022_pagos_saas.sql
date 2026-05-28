-- Fase: Pagos SaaS (Suscripciones)
-- Descripción: Tablas para el manejo de suscripciones vía Flow y PayPal

-- Tabla para guardar el estado de la suscripción de la empresa
CREATE TABLE IF NOT EXISTS `empresas_suscripcion` (
    `empresa_id` BIGINT UNSIGNED NOT NULL,
    `plan_id` VARCHAR(50) NOT NULL,
    `fecha_inicio` DATETIME NOT NULL,
    `fecha_fin` DATETIME NOT NULL,
    `estado` ENUM('activa', 'vencida', 'cancelada') NOT NULL DEFAULT 'activa',
    `creado_el` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_el` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`empresa_id`),
    CONSTRAINT `fk_empresas_suscripcion_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla para guardar el historial de órdenes de pago
CREATE TABLE IF NOT EXISTS `suscripciones_ordenes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `orden_numero` VARCHAR(100) NOT NULL,
    `empresa_id` BIGINT UNSIGNED NOT NULL,
    `usuario_id` BIGINT UNSIGNED NOT NULL,
    `gateway` ENUM('flow', 'paypal') NOT NULL,
    `plan_id` VARCHAR(50) NOT NULL,
    `monto` DECIMAL(10, 2) NOT NULL,
    `moneda` VARCHAR(3) NOT NULL DEFAULT 'CLP',
    `estado` ENUM('pendiente', 'completado', 'rechazado', 'anulado') NOT NULL DEFAULT 'pendiente',
    `token_externo` VARCHAR(255) NULL,
    `order_id_externo` VARCHAR(255) NULL,
    `creado_el` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_el` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_orden_numero` (`orden_numero`),
    KEY `idx_suscripcion_token` (`token_externo`),
    KEY `idx_suscripcion_paypal_id` (`order_id_externo`),
    CONSTRAINT `fk_suscripcion_orden_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`),
    CONSTRAINT `fk_suscripcion_orden_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
