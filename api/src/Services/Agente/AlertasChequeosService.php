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
