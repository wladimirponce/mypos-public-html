<?php
/**
 * Pruebas rapidas del validador de plantillas SQL (sin PHPUnit).
 * Ejecutar: php agent/tests/test_sql_whitelist_validator.php
 */

require_once __DIR__ . '/../sql_whitelist_validator.php';

$failures = 0;
$total = 0;

function check(string $label, bool $expected, string $sql, array $params = []): void
{
    global $failures, $total;
    $total++;
    $reason = null;
    $actual = SqlWhitelistValidator::validate($sql, $params, $reason);
    $ok = $actual === $expected;
    $status = $ok ? 'OK  ' : 'FAIL';
    if (!$ok) {
        $failures++;
    }
    $suffix = $reason !== null ? " (motivo: $reason)" : '';
    echo "[$status] $label$suffix\n";
}

// --- Casos validos ---

check(
    'producto mas economico (caso funcional real)',
    true,
    "SELECT nombre, precio_venta FROM productos WHERE empresa_id = :empresa_id AND activo = 1 ORDER BY precio_venta ASC LIMIT 1",
    ['empresa_id']
);

check(
    'ventas de hoy con parametro de fecha',
    true,
    "SELECT COUNT(*) AS total_ventas, SUM(total) AS monto FROM ventas WHERE empresa_id = :empresa_id AND fecha_venta >= :desde AND estado = 'REGISTRADA'",
    ['empresa_id', 'desde']
);

check(
    'stock de un producto por id, con join a productos',
    true,
    "SELECT p.nombre, s.cantidad FROM stock_ubicacion s JOIN productos p ON p.id = s.producto_id WHERE s.empresa_id = :empresa_id AND p.id = :producto_id",
    ['empresa_id', 'producto_id']
);

// --- Tablas ampliadas en Fase 3 (2026-07-02) ---

check(
    'clientes con mas credito usado (tabla nueva)',
    true,
    "SELECT razon_social, limite_credito FROM clientes WHERE empresa_id = :empresa_id AND credito_habilitado = 1 ORDER BY limite_credito DESC LIMIT 10",
    ['empresa_id']
);

check(
    'compras por proveedor (join tablas nuevas)',
    true,
    "SELECT pr.razon_social, COUNT(c.id) AS compras, SUM(c.total) AS total FROM compras c JOIN proveedores pr ON pr.id = c.proveedor_id WHERE c.empresa_id = :empresa_id AND c.estado = 'CONFIRMADA' GROUP BY pr.id, pr.razon_social ORDER BY total DESC LIMIT 10",
    ['empresa_id']
);

check(
    'detalle de lo mas vendido (venta_detalles)',
    true,
    "SELECT nombre_producto, SUM(cantidad) AS unidades FROM venta_detalles WHERE empresa_id = :empresa_id GROUP BY nombre_producto ORDER BY unidades DESC LIMIT 5",
    ['empresa_id']
);

check(
    'cierres del mes (cierres_diarios)',
    true,
    "SELECT fecha_cierre, total_ventas, cantidad_ventas FROM cierres_diarios WHERE empresa_id = :empresa_id AND estado = 'CERRADO' ORDER BY fecha_cierre DESC LIMIT 31",
    ['empresa_id']
);

check('rechaza columna inexistente en tabla nueva', false,
    "SELECT password FROM clientes WHERE empresa_id = :empresa_id",
    ['empresa_id']);

// --- Casos que deben fallar ---

check('rechaza multi-statement', false,
    "SELECT * FROM productos WHERE empresa_id = :empresa_id; DROP TABLE productos",
    ['empresa_id']);

check('rechaza DML (UPDATE)', false,
    "UPDATE productos SET precio_venta = 0 WHERE empresa_id = :empresa_id",
    ['empresa_id']);

check('rechaza tabla fuera de whitelist', false,
    "SELECT * FROM usuarios WHERE empresa_id = :empresa_id",
    ['empresa_id']);

check('rechaza empresa_id literal', false,
    "SELECT * FROM productos WHERE empresa_id = 24",
    []);

check('rechaza falta de filtro empresa_id', false,
    "SELECT * FROM productos WHERE activo = 1",
    []);

check('rechaza comentario para smuggling', false,
    "SELECT * FROM productos WHERE empresa_id = :empresa_id -- AND activo = 1",
    ['empresa_id']);

check('rechaza acceso a information_schema', false,
    "SELECT table_name FROM information_schema.tables WHERE empresa_id = :empresa_id",
    ['empresa_id']);

check('rechaza UNION', false,
    "SELECT nombre FROM productos WHERE empresa_id = :empresa_id UNION SELECT usuario FROM usuarios",
    ['empresa_id']);

check('rechaza columna no permitida', false,
    "SELECT nombre, clave_secreta FROM productos WHERE empresa_id = :empresa_id",
    ['empresa_id']);

check('rechaza placeholder no declarado', false,
    "SELECT * FROM productos WHERE empresa_id = :empresa_id AND sku = :sku_no_declarado",
    ['empresa_id']);

check('rechaza subquery derivada', false,
    "SELECT * FROM (SELECT * FROM productos) t WHERE t.empresa_id = :empresa_id",
    ['empresa_id']);

check('rechaza tabla calificada por schema', false,
    "SELECT * FROM otra_bd.productos WHERE empresa_id = :empresa_id",
    ['empresa_id']);

function checkEnvelope(string $label, bool $expected, array $skill): void
{
    global $failures, $total;
    $total++;
    $reason = null;
    $actual = SqlWhitelistValidator::validateSkillEnvelope($skill, $reason);
    $ok = $actual === $expected;
    $status = $ok ? 'OK  ' : 'FAIL';
    if (!$ok) {
        $failures++;
    }
    $suffix = $reason !== null ? " (motivo: $reason)" : '';
    echo "[$status] $label$suffix\n";
}

$skillValida = [
    'id' => 'producto_mas_economico_16dc6385',
    'status' => 'aprobada',
    'intent' => 'producto_mas_economico',
    'tipo' => 'sql_readonly',
    'patterns' => ['cual es el producto mas economico', 'producto mas barato'],
    'sql_template' => 'SELECT nombre, precio_venta FROM productos WHERE empresa_id = :empresa_id AND activo = 1 ORDER BY precio_venta ASC LIMIT 1',
    'tablas_referenciadas' => ['productos'],
    'params_permitidos' => [
        'empresa_id' => ['tipo' => 'int', 'fuente' => 'contexto', 'requerido' => true],
    ],
    'row_limit' => 1,
    'notes' => 'Caso funcional de referencia',
];

checkEnvelope('envelope valido (producto mas economico)', true, $skillValida);

$skillSinRowLimit = $skillValida;
unset($skillSinRowLimit['row_limit']);
checkEnvelope('envelope rechaza row_limit faltante', false, $skillSinRowLimit);

$skillRowLimitExcesivo = $skillValida;
$skillRowLimitExcesivo['row_limit'] = 5000;
checkEnvelope('envelope rechaza row_limit fuera de rango', false, $skillRowLimitExcesivo);

$skillEmpresaIdExtraido = $skillValida;
$skillEmpresaIdExtraido['params_permitidos']['empresa_id']['fuente'] = 'extraido';
checkEnvelope('envelope rechaza empresa_id con fuente extraido', false, $skillEmpresaIdExtraido);

$skillExtraidoSinValidar = $skillValida;
$skillExtraidoSinValidar['sql_template'] = 'SELECT nombre, precio_venta FROM productos WHERE empresa_id = :empresa_id AND nombre LIKE :producto_like';
$skillExtraidoSinValidar['params_permitidos']['producto_like'] = ['tipo' => 'string', 'fuente' => 'extraido'];
checkEnvelope('envelope rechaza parametro extraido sin regex/max_length/enum', false, $skillExtraidoSinValidar);

$skillExtraidoConValidacion = $skillValida;
$skillExtraidoConValidacion['sql_template'] = 'SELECT nombre, precio_venta FROM productos WHERE empresa_id = :empresa_id AND nombre LIKE :producto_like';
$skillExtraidoConValidacion['params_permitidos']['producto_like'] = ['tipo' => 'string', 'fuente' => 'extraido', 'max_length' => 60];
checkEnvelope('envelope acepta parametro extraido CON max_length', true, $skillExtraidoConValidacion);

$skillTablasDesincronizadas = $skillValida;
$skillTablasDesincronizadas['tablas_referenciadas'] = ['productos', 'ventas'];
checkEnvelope('envelope rechaza tablas_referenciadas desincronizada del SQL real', false, $skillTablasDesincronizadas);

$skillTipoIncorrecto = $skillValida;
$skillTipoIncorrecto['tipo'] = 'tool';
checkEnvelope('envelope rechaza tipo distinto de sql_readonly', false, $skillTipoIncorrecto);

echo "\n$total pruebas, " . ($total - $failures) . " OK, $failures FAIL\n";
exit($failures > 0 ? 1 : 0);
