CREATE TABLE IF NOT EXISTS egreso_categorias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_egreso_categoria_empresa_nombre (empresa_id, nombre),
    CONSTRAINT fk_egreso_categoria_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS egresos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_id BIGINT UNSIGNED NOT NULL,
    sucursal_id BIGINT UNSIGNED NOT NULL,
    caja_apertura_id BIGINT UNSIGNED NULL,
    caja_movimiento_id BIGINT UNSIGNED NULL,
    categoria_id BIGINT UNSIGNED NOT NULL,
    proveedor_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    fecha_egreso DATE NOT NULL,
    descripcion VARCHAR(180) NOT NULL,
    monto BIGINT NOT NULL,
    metodo_pago_codigo VARCHAR(80) NOT NULL,
    comprobante_referencia VARCHAR(120) NULL,
    observacion VARCHAR(255) NULL,
    estado ENUM('REGISTRADO', 'ANULADO') NOT NULL DEFAULT 'REGISTRADO',
    anulacion_motivo VARCHAR(255) NULL,
    anulado_por_usuario_id BIGINT UNSIGNED NULL,
    anulado_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_egresos_empresa_fecha (empresa_id, sucursal_id, fecha_egreso),
    KEY idx_egresos_categoria (empresa_id, categoria_id, estado),
    CONSTRAINT fk_egresos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
    CONSTRAINT fk_egresos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
    CONSTRAINT fk_egresos_apertura FOREIGN KEY (caja_apertura_id) REFERENCES caja_aperturas (id),
    CONSTRAINT fk_egresos_categoria FOREIGN KEY (categoria_id) REFERENCES egreso_categorias (id),
    CONSTRAINT fk_egresos_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id),
    CONSTRAINT fk_egresos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_egresos_anulado_usuario FOREIGN KEY (anulado_por_usuario_id) REFERENCES usuarios (id),
    CONSTRAINT chk_egresos_monto CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE caja_movimientos
    ADD COLUMN IF NOT EXISTS referencia_tipo VARCHAR(40) NULL AFTER observacion,
    ADD COLUMN IF NOT EXISTS referencia_id BIGINT UNSIGNED NULL AFTER referencia_tipo;

ALTER TABLE caja_cierres
    ADD COLUMN IF NOT EXISTS total_retiros_operativos BIGINT NOT NULL DEFAULT 0 AFTER total_retiros,
    ADD COLUMN IF NOT EXISTS total_egresos_efectivo BIGINT NOT NULL DEFAULT 0 AFTER total_retiros_operativos;

INSERT IGNORE INTO egreso_categorias (empresa_id, nombre)
SELECT e.id, c.nombre
FROM empresas e
CROSS JOIN (
    SELECT 'Servicios basicos' AS nombre UNION ALL
    SELECT 'Insumos y materiales' UNION ALL
    SELECT 'Transporte' UNION ALL
    SELECT 'Mantencion' UNION ALL
    SELECT 'Honorarios' UNION ALL
    SELECT 'Otros'
) c;

INSERT INTO permisos (codigo, nombre, descripcion, activo)
SELECT x.codigo, x.nombre, x.descripcion, 1
FROM (
    SELECT 'egresos.ver' AS codigo, 'Ver egresos' AS nombre, 'Permite consultar egresos' AS descripcion UNION ALL
    SELECT 'egresos.crear', 'Crear egresos', 'Permite registrar egresos' UNION ALL
    SELECT 'egresos.anular', 'Anular egresos', 'Permite anular egresos'
) x
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.codigo = x.codigo);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('egresos.ver', 'egresos.crear', 'egresos.anular')
WHERE r.codigo IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo IN ('egresos.ver', 'egresos.crear')
WHERE r.codigo = 'CAJERO'
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p ON p.codigo = 'egresos.ver'
WHERE r.codigo IN ('CONTADOR', 'AUDITOR')
  AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT IGNORE INTO schema_migrations (migration) VALUES ('046_egresos_caja');
