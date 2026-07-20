<?php

declare(strict_types=1);

namespace Mypos\Services\Agente;

use Mypos\Config\Database;
use PDO;

/**
 * Chequeos del motor de alertas proactivas. SOLO LECTURA sobre las tablas
 * del negocio (requisito 1 del plan): cada metodo ejecuta SELECTs filtrados
 * por empresa_id y devuelve items de alerta; jamas modifica datos.
 *
 * Cada item: ['clave' => string (dedupe), 'texto' => string (una linea),
 *            'detalle' => array (para detalle_json del log)].
 *
 * Los umbrales llegan desde AlertasConfigService::config()['alertas'][tipo].
 */
final class AlertasChequeosService
{
    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? Database::connection();
    }

    /** Productos activos cuyo precio de venta queda igual o bajo su costo. */
    public function precioPerdida(int $empresaId, array $params): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nombre, precio_venta,
                    COALESCE(NULLIF(costo_actual, 0), precio_costo) AS costo
             FROM productos
             WHERE empresa_id = :empresa_id
               AND activo = 1
               AND COALESCE(NULLIF(costo_actual, 0), precio_costo) > 0
               AND precio_venta <= COALESCE(NULLIF(costo_actual, 0), precio_costo)
             ORDER BY (COALESCE(NULLIF(costo_actual, 0), precio_costo) - precio_venta) DESC
             LIMIT 30'
        );
        $stmt->execute([':empresa_id' => $empresaId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $venta = (int) $row['precio_venta'];
            $costo = (int) $row['costo'];
            // Clave incluye el precio: si el precio cambia y sigue a perdida,
            // se vuelve a avisar; si no cambia, el dedupe lo silencia.
            $items[] = [
                'clave' => 'producto:' . $row['id'] . ':' . $venta,
                'texto' => sprintf(
                    '%s: venta $%s ≤ costo $%s (pierde $%s por unidad)',
                    $row['nombre'],
                    number_format($venta, 0, ',', '.'),
                    number_format($costo, 0, ',', '.'),
                    number_format($costo - $venta, 0, ',', '.')
                ),
                'detalle' => ['producto_id' => (int) $row['id'], 'precio_venta' => $venta, 'costo' => $costo],
            ];
        }
        return $items;
    }

    /**
     * Guardián del margen (P2): productos con margen objetivo configurado cuyo
     * margen REAL vigente cayó por debajo del objetivo (típicamente porque subió
     * el costo y no se actualizó el precio), pero que AÚN dan ganancia (la
     * pérdida total la cubre precioPerdida). Sugiere el precio corregido.
     *
     * Param: umbral_pp = caída mínima en puntos porcentuales para avisar (def 5).
     */
    public function margenComprometido(int $empresaId, array $params): array
    {
        $umbralPp = (float) ($params['umbral_pp'] ?? 5);
        if ($umbralPp <= 0) {
            $umbralPp = 5;
        }

        $stmt = $this->db->prepare(
            'SELECT id, nombre, precio_venta, tipo_margen,
                    margen_ganancia,
                    COALESCE(NULLIF(costo_actual, 0), precio_costo) AS costo
             FROM productos
             WHERE empresa_id = :empresa_id
               AND activo = 1
               AND margen_ganancia IS NOT NULL AND margen_ganancia > 0
               AND COALESCE(NULLIF(costo_actual, 0), precio_costo) > 0
               AND precio_venta > COALESCE(NULLIF(costo_actual, 0), precio_costo)'
        );
        $stmt->execute([':empresa_id' => $empresaId]);

        $candidatos = [];
        foreach ($stmt->fetchAll() as $row) {
            $costo  = (int) $row['costo'];
            $venta  = (int) $row['precio_venta'];
            $target = (float) $row['margen_ganancia'];
            $tipo   = \Mypos\Support\PrecioCalculator::normalizarTipo($row['tipo_margen'] ?? null);

            // Margen/markup REAL vigente, en la misma unidad que el objetivo.
            $actual = $tipo === 'margen'
                ? (($venta - $costo) / $venta) * 100   // margen bruto sobre venta
                : (($venta - $costo) / $costo) * 100;  // markup sobre costo

            $caida = $target - $actual;
            if ($caida < $umbralPp) {
                continue;
            }

            $sugerido = \Mypos\Support\PrecioCalculator::sugerido($costo, $target, $tipo);
            if ($sugerido <= $venta) {
                continue; // nada que subir
            }

            $candidatos[] = [
                'caida'    => $caida,
                'clave'    => 'margen:' . $row['id'] . ':' . $venta . ':' . $costo,
                'texto'    => sprintf(
                    '%s: %s objetivo %s%%, actual %s%% — sube a $%s (hoy $%s)',
                    $row['nombre'],
                    $tipo === 'margen' ? 'margen' : 'markup',
                    rtrim(rtrim(number_format($target, 1, ',', '.'), '0'), ','),
                    rtrim(rtrim(number_format($actual, 1, ',', '.'), '0'), ','),
                    number_format($sugerido, 0, ',', '.'),
                    number_format($venta, 0, ',', '.')
                ),
                'detalle'  => [
                    'producto_id'      => (int) $row['id'],
                    'tipo_margen'      => $tipo,
                    'margen_objetivo'  => $target,
                    'margen_actual'    => round($actual, 2),
                    'costo'            => $costo,
                    'precio_venta'     => $venta,
                    'precio_sugerido'  => $sugerido,
                ],
            ];
        }

        // Mayor caída primero; máximo 30 para no saturar el aviso.
        usort($candidatos, static fn ($a, $b) => $b['caida'] <=> $a['caida']);
        $candidatos = array_slice($candidatos, 0, 30);

        return array_map(static fn ($c) => [
            'clave'   => $c['clave'],
            'texto'   => $c['texto'],
            'detalle' => $c['detalle'],
        ], $candidatos);
    }

    /**
     * Lotes por vencer (P4): productos con fecha de vencimiento cercana y stock
     * disponible. Recomienda un descuento/promo para liquidarlos antes de que se
     * pierdan (merma). Param: dias_alerta = ventana en días (def 15).
     */
    public function lotesPorVencer(int $empresaId, array $params): array
    {
        $dias = (int) ($params['dias_alerta'] ?? 15);
        if ($dias <= 0 || $dias > 365) {
            $dias = 15;
        }

        $loteService = new \Mypos\Services\LoteService(new \Mypos\Repositories\LoteRepository($this->db));
        $alertas = $loteService->alertasVencimiento($empresaId, $dias)['alertas'];

        $items = [];
        foreach ($alertas as $row) {
            $pct = (int) ($row['descuento_sugerido_pct'] ?? 0);
            if ($pct <= 0) {
                continue; // aún no urgente; solo avisamos lo accionable
            }

            $diasVencer = (int) ($row['dias_para_vencer'] ?? 0);
            $stock      = rtrim(rtrim(number_format((float) $row['stock_total'], 2, ',', '.'), '0'), ',');
            $venceTexto = $diasVencer < 0
                ? 'VENCIDO hace ' . abs($diasVencer) . 'd'
                : 'vence en ' . $diasVencer . 'd';
            $promo = $row['precio_promo_sugerido'] !== null
                ? sprintf(' — promo -%d%% a $%s', $pct, number_format((int) $row['precio_promo_sugerido'], 0, ',', '.'))
                : sprintf(' — aplica -%d%%', $pct);

            $items[] = [
                'clave'   => 'lote:' . $row['lote_id'] . ':' . ($row['fecha_vencimiento'] ?? '') . ':' . $pct,
                'texto'   => sprintf(
                    '%s (lote %s): %s, %s u%s',
                    $row['producto_nombre'],
                    $row['numero_lote'],
                    $venceTexto,
                    $stock,
                    $promo
                ),
                'detalle' => [
                    'producto_id'            => (int) $row['producto_id'],
                    'lote_id'                => (int) $row['lote_id'],
                    'numero_lote'            => $row['numero_lote'],
                    'fecha_vencimiento'      => $row['fecha_vencimiento'],
                    'dias_para_vencer'       => $diasVencer,
                    'stock_total'            => (float) $row['stock_total'],
                    'descuento_sugerido_pct' => $pct,
                    'precio_promo_sugerido'  => $row['precio_promo_sugerido'],
                ],
            ];
        }

        return $items;
    }

    /**
     * Transferencias sugeridas (P7): productos con una ubicación en déficit
     * (bajo su punto de reorden/mínimo) mientras OTRA ubicación de la misma
     * empresa tiene excedente (sobre su stock máximo). Sugiere el traslado
     * interno para balancear sin comprar. Param: max_listado (def 15).
     */
    public function transferenciasSugeridas(int $empresaId, array $params): array
    {
        $max = max(1, (int) ($params['max_listado'] ?? 15));

        $stmt = $this->db->prepare(
            'SELECT p.nombre AS producto, sd.producto_id,
                    ud.id AS destino_id, ud.nombre AS destino,
                    us.id AS origen_id,  us.nombre AS origen,
                    (COALESCE(sd.punto_reorden, sd.stock_minimo) - (sd.cantidad - COALESCE(sd.reservado,0))) AS necesidad,
                    ((ss.cantidad - COALESCE(ss.reservado,0)) - COALESCE(ss.stock_maximo, ss.punto_reorden, ss.stock_minimo)) AS excedente
             FROM stock_ubicacion sd
             INNER JOIN stock_ubicacion ss
                     ON ss.empresa_id = sd.empresa_id
                    AND ss.producto_id = sd.producto_id
                    AND ss.ubicacion_id <> sd.ubicacion_id
             INNER JOIN productos p
                     ON p.id = sd.producto_id AND p.empresa_id = sd.empresa_id AND p.activo = 1
             INNER JOIN ubicaciones_stock ud
                     ON ud.id = sd.ubicacion_id AND ud.empresa_id = sd.empresa_id AND ud.activo = 1
             INNER JOIN ubicaciones_stock us
                     ON us.id = ss.ubicacion_id AND us.empresa_id = ss.empresa_id AND us.activo = 1
             WHERE sd.empresa_id = :empresa_id
               AND COALESCE(sd.punto_reorden, sd.stock_minimo) > 0
               AND (sd.cantidad - COALESCE(sd.reservado,0)) < COALESCE(sd.punto_reorden, sd.stock_minimo)
               AND ((ss.cantidad - COALESCE(ss.reservado,0)) - COALESCE(ss.stock_maximo, ss.punto_reorden, ss.stock_minimo)) > 0
             ORDER BY necesidad DESC, excedente DESC
             LIMIT 200'
        );
        $stmt->execute([':empresa_id' => $empresaId]);

        $items = [];
        $servidos = [];        // (producto:destino) que ya recibió su sugerencia
        $restanteOrigen = [];  // (producto:origen) => excedente aún disponible
        foreach ($stmt->fetchAll() as $r) {
            // Una sugerencia por (producto, destino): la de mayor excedente (orden).
            $destKey = $r['producto_id'] . ':' . $r['destino_id'];
            if (isset($servidos[$destKey])) {
                continue;
            }

            // El excedente de un origen no puede re-asignarse entre varios
            // destinos: se descuenta a medida que se sugiere mover desde él.
            $origKey = $r['producto_id'] . ':' . $r['origen_id'];
            if (!array_key_exists($origKey, $restanteOrigen)) {
                $restanteOrigen[$origKey] = (float) $r['excedente'];
            }

            $mover = min((float) $r['necesidad'], $restanteOrigen[$origKey]);
            if ($mover <= 0) {
                // Origen agotado: otro origen del mismo destino podría cubrirlo
                // en una fila posterior, así que NO marcamos el destino servido.
                continue;
            }
            $restanteOrigen[$origKey] -= $mover;
            $servidos[$destKey] = true;

            $moverTxt = rtrim(rtrim(number_format($mover, 2, ',', '.'), '0'), ',');

            $items[] = [
                'clave'   => 'transf:' . $destKey,
                'texto'   => sprintf(
                    '%s: mover %s u de %s → %s (déficit en destino, excedente en origen)',
                    $r['producto'],
                    $moverTxt,
                    $r['origen'],
                    $r['destino']
                ),
                'detalle' => [
                    'producto_id'   => (int) $r['producto_id'],
                    'origen_id'     => (int) $r['origen_id'],
                    'destino_id'    => (int) $r['destino_id'],
                    'cantidad'      => $mover,
                    'necesidad'     => (float) $r['necesidad'],
                    'excedente'     => (float) $r['excedente'],
                ],
            ];

            if (count($items) >= $max) {
                break;
            }
        }

        return $items;
    }

    /** Digest diario de productos en o bajo su stock minimo por ubicacion. */
    public function stockCritico(int $empresaId, array $params): array
    {
        $max = max(1, (int) ($params['max_listado'] ?? 15));
        $stmt = $this->db->prepare(
            'SELECT p.nombre, u.nombre AS ubicacion, su.cantidad, su.stock_minimo
             FROM stock_ubicacion su
             INNER JOIN productos p ON p.id = su.producto_id AND p.activo = 1
             INNER JOIN ubicaciones_stock u ON u.id = su.ubicacion_id AND u.activo = 1
             WHERE su.empresa_id = :empresa_id
               AND su.stock_minimo > 0
               AND su.cantidad <= su.stock_minimo
             ORDER BY (su.stock_minimo - su.cantidad) DESC
             LIMIT :max_listado'
        );
        $stmt->bindValue(':empresa_id', $empresaId, PDO::PARAM_INT);
        $stmt->bindValue(':max_listado', $max, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        $lineas = array_map(
            static fn (array $r): string => sprintf(
                '%s (%s): %s de mínimo %s',
                $r['nombre'], $r['ubicacion'],
                rtrim(rtrim(number_format((float) $r['cantidad'], 2, ',', '.'), '0'), ','),
                rtrim(rtrim(number_format((float) $r['stock_minimo'], 2, ',', '.'), '0'), ',')
            ),
            $rows
        );

        // Un solo item por dia (digest): clave = fecha.
        return [[
            'clave' => 'digest:' . date('Y-m-d'),
            'texto' => count($rows) . " producto(s) en stock crítico:\n  - " . implode("\n  - ", $lineas),
            'detalle' => ['productos' => $rows],
        ]];
    }

    /**
     * Sucursales con ventas en $fecha sin cierre diario CERRADO para esa fecha.
     * El runner lo invoca de noche con la fecha de hoy y en la manana
     * siguiente con la de ayer (refuerzo).
     */
    public function cierrePendiente(int $empresaId, array $params, string $fecha): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.nombre, COUNT(v.id) AS ventas
             FROM ventas v
             INNER JOIN sucursales s ON s.id = v.sucursal_id
             WHERE v.empresa_id = :empresa_id
               AND DATE(v.fecha_venta) = :fecha
               AND v.estado <> \'ANULADA\'
               AND NOT EXISTS (
                   SELECT 1 FROM cierres_diarios cd
                   WHERE cd.empresa_id = :empresa_id2
                     AND cd.sucursal_id = v.sucursal_id
                     AND cd.fecha_cierre = :fecha2
                     AND cd.estado = \'CERRADO\'
               )
             GROUP BY s.id, s.nombre'
        );
        $stmt->execute([
            ':empresa_id' => $empresaId,
            ':fecha' => $fecha,
            ':empresa_id2' => $empresaId,
            ':fecha2' => $fecha,
        ]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'clave' => $fecha . ':sucursal:' . $row['id'],
                'texto' => sprintf(
                    'Sucursal %s: %d venta(s) del %s sin cierre diario',
                    $row['nombre'], (int) $row['ventas'], $fecha
                ),
                'detalle' => ['sucursal_id' => (int) $row['id'], 'fecha' => $fecha, 'ventas' => (int) $row['ventas']],
            ];
        }
        return $items;
    }

    /** Cajas abiertas hace mas de N horas. */
    public function cajaAbierta(int $empresaId, array $params): array
    {
        $horas = max(1, (int) ($params['horas_max'] ?? 16));
        $stmt = $this->db->prepare(
            'SELECT ca.id, ca.fecha_apertura, c.nombre AS caja, s.nombre AS sucursal,
                    TIMESTAMPDIFF(HOUR, ca.fecha_apertura, NOW()) AS horas
             FROM caja_aperturas ca
             INNER JOIN cajas c ON c.id = ca.caja_id
             INNER JOIN sucursales s ON s.id = ca.sucursal_id
             WHERE ca.empresa_id = :empresa_id
               AND ca.estado = \'ABIERTA\'
               AND ca.fecha_apertura < DATE_SUB(NOW(), INTERVAL :horas_max HOUR)
             ORDER BY ca.fecha_apertura ASC
             LIMIT 20'
        );
        $stmt->bindValue(':empresa_id', $empresaId, PDO::PARAM_INT);
        $stmt->bindValue(':horas_max', $horas, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'clave' => 'apertura:' . $row['id'],
                'texto' => sprintf(
                    'Caja %s (%s) lleva %d horas abierta (desde %s)',
                    $row['caja'], $row['sucursal'], (int) $row['horas'], (string) $row['fecha_apertura']
                ),
                'detalle' => ['apertura_id' => (int) $row['id'], 'horas' => (int) $row['horas']],
            ];
        }
        return $items;
    }

    /**
     * Ventas de hoy (hasta esta hora) muy por debajo del promedio del mismo
     * dia de semana de las ultimas 4 semanas, comparando el mismo rango horario.
     */
    public function ventasCaida(int $empresaId, array $params): array
    {
        $umbralPct = max(1, min(99, (int) ($params['umbral_pct'] ?? 50)));
        $stmt = $this->db->prepare(
            'SELECT DATE(fecha_venta) AS dia, SUM(total) AS total
             FROM ventas
             WHERE empresa_id = :empresa_id
               AND estado <> \'ANULADA\'
               AND fecha_venta >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
               AND DAYOFWEEK(fecha_venta) = DAYOFWEEK(CURDATE())
               AND TIME(fecha_venta) <= CURTIME()
             GROUP BY DATE(fecha_venta)'
        );
        $stmt->execute([':empresa_id' => $empresaId]);

        $hoy = 0;
        $previos = [];
        $hoyStr = date('Y-m-d');
        foreach ($stmt->fetchAll() as $row) {
            if ((string) $row['dia'] === $hoyStr) {
                $hoy = (int) $row['total'];
            } else {
                $previos[] = (int) $row['total'];
            }
        }

        // Sin historial suficiente no hay linea base confiable: no alertar.
        if (count($previos) < 2) {
            return [];
        }
        $promedio = (int) (array_sum($previos) / count($previos));
        if ($promedio <= 0 || $hoy >= (int) ($promedio * $umbralPct / 100)) {
            return [];
        }

        return [[
            'clave' => $hoyStr,
            'texto' => sprintf(
                'Ventas de hoy hasta las %s: $%s — %d%% bajo el promedio de este día ($%s en las últimas 4 semanas)',
                date('H:i'),
                number_format($hoy, 0, ',', '.'),
                100 - (int) round($hoy * 100 / $promedio),
                number_format($promedio, 0, ',', '.')
            ),
            'detalle' => ['hoy' => $hoy, 'promedio' => $promedio, 'muestras' => count($previos)],
        ]];
    }

    /** Folios SII disponibles bajo umbral por tipo de documento (canal SaaS). */
    public function foliosBajos(int $empresaId, array $params): array
    {
        $stmt = $this->db->prepare(
            'SELECT tipo_documento,
                    SUM(CASE WHEN estado = \'ACTIVA\'
                        THEN GREATEST(folio_hasta - folio_actual + 1, 0) ELSE 0 END) AS disponibles
             FROM folios_asignaciones
             WHERE empresa_id = :empresa_id
             GROUP BY tipo_documento'
        );
        $stmt->execute([':empresa_id' => $empresaId]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return []; // la empresa no emite DTE: sin folios que vigilar
        }

        $umbrales = [
            'BOLETA' => max(1, (int) ($params['umbral_boleta'] ?? 150)),
            'FACTURA' => max(1, (int) ($params['umbral_factura'] ?? 20)),
        ];

        $items = [];
        foreach ($rows as $row) {
            $tipo = (string) $row['tipo_documento'];
            $umbral = $umbrales[$tipo] ?? 0;
            $disponibles = (int) $row['disponibles'];
            if ($umbral > 0 && $disponibles < $umbral) {
                // Clave por tramo de 10: re-avisa cuando siguen bajando.
                $items[] = [
                    'clave' => $tipo . ':' . intdiv($disponibles, 10),
                    'texto' => sprintf(
                        'Quedan %d folios de %s (umbral: %d). Solicitar CAF al SII pronto.',
                        $disponibles, $tipo, $umbral
                    ),
                    'detalle' => ['tipo' => $tipo, 'disponibles' => $disponibles, 'umbral' => $umbral],
                ];
            }
        }
        return $items;
    }

    /** Suscripcion vencida o por vencer dentro de N dias. */
    public function suscripcion(int $empresaId, array $params): array
    {
        $dias = max(1, (int) ($params['dias_aviso'] ?? 5));
        $stmt = $this->db->prepare(
            'SELECT plan_id, estado, fecha_fin FROM empresas_suscripcion WHERE empresa_id = :empresa_id'
        );
        $stmt->execute([':empresa_id' => $empresaId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return [];
        }

        $fechaFin = substr((string) $row['fecha_fin'], 0, 10);
        if ((string) $row['estado'] === 'vencida' || $fechaFin < date('Y-m-d')) {
            return [[
                'clave' => 'vencida:' . $fechaFin,
                'texto' => "Suscripción MyPOS vencida el $fechaFin: renueva para no perder acceso.",
                'detalle' => ['estado' => 'vencida', 'fecha_fin' => $fechaFin],
            ]];
        }
        if ((string) $row['estado'] === 'activa'
            && $fechaFin <= date('Y-m-d', strtotime("+$dias days"))
        ) {
            return [[
                'clave' => 'vence:' . $fechaFin,
                'texto' => "Suscripción MyPOS vence el $fechaFin: renueva a tiempo.",
                'detalle' => ['estado' => 'activa', 'fecha_fin' => $fechaFin],
            ]];
        }
        return [];
    }

    /** Ordenes de compra en borrador olvidadas hace mas de N dias. */
    public function comprasBorrador(int $empresaId, array $params): array
    {
        $dias = max(1, (int) ($params['dias'] ?? 3));
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS n, MIN(DATE(created_at)) AS mas_antigua
             FROM compras
             WHERE empresa_id = :empresa_id
               AND estado = \'BORRADOR\'
               AND created_at < DATE_SUB(NOW(), INTERVAL :dias DAY)'
        );
        $stmt->bindValue(':empresa_id', $empresaId, PDO::PARAM_INT);
        $stmt->bindValue(':dias', $dias, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        $n = (int) ($row['n'] ?? 0);
        if ($n === 0) {
            return [];
        }

        return [[
            'clave' => 'digest:' . date('Y-m-d'),
            'texto' => sprintf(
                '%d compra(s) en borrador hace más de %d días (la más antigua: %s). Confirmarlas o eliminarlas.',
                $n, $dias, (string) $row['mas_antigua']
            ),
            'detalle' => ['cantidad' => $n, 'mas_antigua' => (string) $row['mas_antigua']],
        ]];
    }

    /** Resumen del dia anterior (opt-in). Siempre genera 1 item si hubo actividad. */
    public function inventarioInmovilizado(int $empresaId, array $params): array
    {
        $dias = max(30, min(365, (int) ($params['dias'] ?? 90)));
        $limite = max(1, min(30, (int) ($params['max_listado'] ?? 10)));
        $stmt = $this->db->prepare(
            "SELECT p.id,p.nombre,p.costo_actual,SUM(su.cantidad) stock,
                    ROUND(SUM(su.cantidad)*p.costo_actual) valor,MAX(v.fecha_venta) ultima_venta
             FROM productos p
             INNER JOIN stock_ubicacion su ON su.empresa_id=p.empresa_id AND su.producto_id=p.id AND su.cantidad>0
             LEFT JOIN venta_detalles vd ON vd.empresa_id=p.empresa_id AND vd.producto_id=p.id
             LEFT JOIN ventas v ON v.id=vd.venta_id AND v.estado='REGISTRADA'
             WHERE p.empresa_id=:empresa_id AND p.activo=1
             GROUP BY p.id,p.nombre,p.costo_actual
             HAVING ultima_venta IS NULL OR ultima_venta<DATE_SUB(NOW(), INTERVAL {$dias} DAY)
             ORDER BY valor DESC LIMIT {$limite}"
        );
        $stmt->execute(['empresa_id' => $empresaId]);
        return array_map(static fn (array $r): array => [
            'clave' => 'inmovilizado:' . $r['id'] . ':' . $dias,
            'texto' => sprintf('%s mantiene %s unidades sin venta reciente; hay $%s inmovilizados.', $r['nombre'], (float) $r['stock'], number_format((int) $r['valor'], 0, ',', '.')),
            'detalle' => ['producto_id' => (int) $r['id'], 'stock' => (float) $r['stock'], 'valor' => (int) $r['valor'], 'dias' => $dias],
        ], $stmt->fetchAll());
    }

    public function anomaliasVentas(int $empresaId, array $params): array
    {
        $umbral = max(10, min(300, (int) ($params['umbral_pct'] ?? 50)));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) operaciones,
                    SUM(estado='ANULADA') anuladas,
                    COALESCE(SUM(descuento_total),0) descuentos,
                    COALESCE(SUM(CASE WHEN estado='REGISTRADA' THEN subtotal ELSE 0 END),0) subtotal
             FROM ventas WHERE empresa_id=:empresa_hoy AND fecha_venta>=CURRENT_DATE"
        );
        $stmt->execute(['empresa_hoy' => $empresaId]);
        $hoy = $stmt->fetch() ?: [];
        if ((int) ($hoy['operaciones'] ?? 0) < 5) return [];

        $stmt = $this->db->prepare(
            "SELECT AVG(tasa_anulacion) tasa_anulacion,AVG(tasa_descuento) tasa_descuento FROM (
                SELECT DATE(fecha_venta) fecha,100*SUM(estado='ANULADA')/GREATEST(COUNT(*),1) tasa_anulacion,
                       100*SUM(descuento_total)/GREATEST(SUM(CASE WHEN estado='REGISTRADA' THEN subtotal ELSE 0 END),1) tasa_descuento
                FROM ventas WHERE empresa_id=:empresa_historia AND fecha_venta>=DATE_SUB(CURRENT_DATE,INTERVAL 30 DAY) AND fecha_venta<CURRENT_DATE
                GROUP BY DATE(fecha_venta)
             ) historia"
        );
        $stmt->execute(['empresa_historia' => $empresaId]);
        $base = $stmt->fetch() ?: [];
        $tasaAnulacion = 100 * (int) $hoy['anuladas'] / max(1, (int) $hoy['operaciones']);
        $tasaDescuento = 100 * (int) $hoy['descuentos'] / max(1, (int) $hoy['subtotal']);
        $items = [];
        if ($tasaAnulacion > max(2.0, (float) ($base['tasa_anulacion'] ?? 0) * (1 + $umbral / 100))) {
            $items[] = ['clave' => 'anulaciones:' . date('Y-m-d'), 'texto' => sprintf('Las anulaciones de hoy alcanzan %.1f%%, por encima del comportamiento habitual.', $tasaAnulacion), 'detalle' => ['tasa_actual' => round($tasaAnulacion, 2), 'tasa_base' => round((float) ($base['tasa_anulacion'] ?? 0), 2)]];
        }
        if ($tasaDescuento > max(3.0, (float) ($base['tasa_descuento'] ?? 0) * (1 + $umbral / 100))) {
            $items[] = ['clave' => 'descuentos:' . date('Y-m-d'), 'texto' => sprintf('Los descuentos de hoy representan %.1f%% del subtotal, sobre el promedio reciente.', $tasaDescuento), 'detalle' => ['tasa_actual' => round($tasaDescuento, 2), 'tasa_base' => round((float) ($base['tasa_descuento'] ?? 0), 2)]];
        }
        return $items;
    }

    public function anomaliasCaja(int $empresaId, array $params): array
    {
        $repeticiones = max(2, min(20, (int) ($params['repeticiones'] ?? 3)));
        $stmt = $this->db->prepare(
            "SELECT cc.sucursal_id,s.nombre,COUNT(*) repeticiones,SUM(ABS(cc.diferencia)) diferencia
             FROM caja_cierres cc INNER JOIN sucursales s ON s.id=cc.sucursal_id AND s.empresa_id=cc.empresa_id
             WHERE cc.empresa_id=:empresa_id AND cc.fecha_cierre>=DATE_SUB(NOW(),INTERVAL 30 DAY) AND cc.diferencia<>0
             GROUP BY cc.sucursal_id,s.nombre HAVING COUNT(*)>=:repeticiones ORDER BY diferencia DESC"
        );
        $stmt->bindValue('empresa_id', $empresaId, PDO::PARAM_INT);
        $stmt->bindValue('repeticiones', $repeticiones, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static fn (array $r): array => ['clave' => 'caja:' . $r['sucursal_id'] . ':' . date('Y-m-d'), 'texto' => sprintf('%s acumula %d cierres con diferencias durante los últimos 30 días ($%s en valor absoluto).', $r['nombre'], $r['repeticiones'], number_format((int) $r['diferencia'], 0, ',', '.')), 'detalle' => $r], $stmt->fetchAll());
    }

    public function anomaliasStock(int $empresaId, array $params): array
    {
        $umbral = max(2, min(50, (int) ($params['ajustes_dia'] ?? 5)));
        $stmt = $this->db->prepare(
            "SELECT sm.usuario_id,sm.producto_id,p.nombre,COUNT(*) ajustes,SUM(ABS(sm.cantidad)) unidades
             FROM stock_movimientos sm INNER JOIN productos p ON p.id=sm.producto_id AND p.empresa_id=sm.empresa_id
             WHERE sm.empresa_id=:empresa_id AND sm.tipo_movimiento='AJUSTE' AND sm.created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)
             GROUP BY sm.usuario_id,sm.producto_id,p.nombre HAVING COUNT(*)>=:umbral ORDER BY ajustes DESC"
        );
        $stmt->bindValue('empresa_id', $empresaId, PDO::PARAM_INT);
        $stmt->bindValue('umbral', $umbral, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static fn (array $r): array => ['clave' => 'stock:' . $r['usuario_id'] . ':' . $r['producto_id'] . ':' . date('Y-m-d'), 'texto' => sprintf('%s recibió %d ajustes manuales en 24 horas (%s unidades involucradas).', $r['nombre'], $r['ajustes'], (float) $r['unidades']), 'detalle' => $r], $stmt->fetchAll());
    }

    public function proveedoresAtrasados(int $empresaId, array $params): array
    {
        $minimo = max(1, min(100, (int) ($params['cumplimiento_minimo_pct'] ?? 80)));
        $stmt = $this->db->prepare(
            'SELECT a.proveedor_id,p.razon_social,a.ordenes_recibidas,a.entregas_atrasadas,a.cumplimiento_pct,a.plazo_real_promedio
             FROM analytics_proveedor_desempeno a INNER JOIN proveedores p ON p.id=a.proveedor_id AND p.empresa_id=a.empresa_id
             WHERE a.empresa_id=:empresa_id AND a.fecha_calculo=(SELECT MAX(x.fecha_calculo) FROM analytics_proveedor_desempeno x WHERE x.empresa_id=a.empresa_id)
               AND a.cumplimiento_pct<:minimo ORDER BY a.cumplimiento_pct'
        );
        $stmt->bindValue('empresa_id', $empresaId, PDO::PARAM_INT);
        $stmt->bindValue('minimo', $minimo, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static fn (array $r): array => ['clave' => 'proveedor:' . $r['proveedor_id'] . ':' . date('Y-m-d'), 'texto' => sprintf('%s cumple solo %.1f%% de sus fechas prometidas; plazo real promedio %.1f días.', $r['razon_social'], $r['cumplimiento_pct'], $r['plazo_real_promedio']), 'detalle' => $r], $stmt->fetchAll());
    }

    public function resumenDiario(int $empresaId, array $params): array
    {
        $ayer = date('Y-m-d', strtotime('-1 day'));
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(margen_total), 0) AS margen
             FROM ventas
             WHERE empresa_id = :empresa_id
               AND DATE(fecha_venta) = :fecha
               AND estado <> \'ANULADA\''
        );
        $stmt->execute([':empresa_id' => $empresaId, ':fecha' => $ayer]);
        $row = $stmt->fetch();
        $n = (int) ($row['n'] ?? 0);
        if ($n === 0) {
            return []; // sin actividad, sin resumen
        }

        $semanaPasada = date('Y-m-d', strtotime('-8 days'));
        $stmtPrev = $this->db->prepare(
            'SELECT COALESCE(SUM(total), 0) AS total
             FROM ventas
             WHERE empresa_id = :empresa_id
               AND DATE(fecha_venta) = :fecha
               AND estado <> \'ANULADA\''
        );
        $stmtPrev->execute([':empresa_id' => $empresaId, ':fecha' => $semanaPasada]);
        $prevTotal = (int) ($stmtPrev->fetch()['total'] ?? 0);

        $total = (int) $row['total'];
        $comparacion = $prevTotal > 0
            ? sprintf(' (%+d%% vs mismo día de la semana pasada)', (int) round(($total - $prevTotal) * 100 / $prevTotal))
            : '';

        return [[
            'clave' => $ayer,
            'texto' => sprintf(
                "Resumen del %s: %d venta(s), total $%s%s, margen $%s",
                $ayer, $n,
                number_format($total, 0, ',', '.'),
                $comparacion,
                number_format((int) $row['margen'], 0, ',', '.')
            ),
            'detalle' => ['fecha' => $ayer, 'ventas' => $n, 'total' => $total, 'margen' => (int) $row['margen']],
        ]];
    }
}
