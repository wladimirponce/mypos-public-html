<?php

declare(strict_types=1);

namespace Mypos\Services\Agente;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use PDO;

/**
 * Exportaciones a Excel del agente IA ("envíame el maestro de productos al
 * correo"). SOLO LECTURA: registry FIJO de consultas por tipo, siempre
 * filtradas por empresa_id del contexto autenticado — el agente/LLM elige
 * el tipo, jamás el SQL. El archivo se envía únicamente al correo de la
 * empresa (ContactoEmpresaService), nunca a direcciones del chat.
 */
final class ExportacionService
{
    private const ROW_LIMIT = 20000;

    /** Tipos disponibles: etiqueta + si aceptan rango de fechas. */
    public const TIPOS = [
        'productos'   => ['titulo' => 'Maestro de productos', 'con_fechas' => false],
        'clientes'    => ['titulo' => 'Maestro de clientes', 'con_fechas' => false],
        'proveedores' => ['titulo' => 'Maestro de proveedores', 'con_fechas' => false],
        'stock'       => ['titulo' => 'Stock por ubicación', 'con_fechas' => false],
        'ventas'      => ['titulo' => 'Ventas del período', 'con_fechas' => true],
        'compras'     => ['titulo' => 'Compras del período', 'con_fechas' => true],
        'cierres'     => ['titulo' => 'Cierres diarios del período', 'con_fechas' => true],
    ];

    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? Database::connection();
    }

    /**
     * @return array{titulo: string, filename: string, contenido: string, filas: int}
     */
    public function generar(int $empresaId, string $tipo, string $fechaDesde, string $fechaHasta): array
    {
        if (!isset(self::TIPOS[$tipo])) {
            throw new HttpException(
                'Tipo de exportación no soportado: ' . $tipo
                . '. Disponibles: ' . implode(', ', array_keys(self::TIPOS)),
                422
            );
        }

        [$headers, $rows] = $this->dataset($empresaId, $tipo, $fechaDesde, $fechaHasta);

        $titulo = self::TIPOS[$tipo]['titulo'];
        if (self::TIPOS[$tipo]['con_fechas']) {
            $titulo .= " ($fechaDesde a $fechaHasta)";
        }

        return [
            'titulo' => $titulo,
            'filename' => 'mypos_' . $tipo . '_' . date('Ymd_His') . '.xlsx',
            'contenido' => XlsxBuilder::build($headers, $rows, self::TIPOS[$tipo]['titulo']),
            'filas' => count($rows),
        ];
    }

    /**
     * @return array{0: string[], 1: array<int, array<int, mixed>>}
     */
    private function dataset(int $empresaId, string $tipo, string $fechaDesde, string $fechaHasta): array
    {
        // Registry fijo: SQL escrito a mano, nunca generado. Columnas dentro
        // de la whitelist del agente (agent/sql_whitelist.json).
        switch ($tipo) {
            case 'productos':
                return $this->query(
                    ['SKU', 'Nombre', 'Unidad', 'Precio venta', 'Precio costo', 'Costo actual',
                     'Stock mínimo', 'Controla stock', 'Activo'],
                    'SELECT sku, nombre, unidad_medida, precio_venta, precio_costo, costo_actual,
                            stock_minimo, controla_stock, activo
                     FROM productos WHERE empresa_id = :empresa_id ORDER BY nombre',
                    [':empresa_id' => $empresaId]
                );

            case 'clientes':
                return $this->query(
                    ['RUT', 'Razón social', 'Nombre fantasía', 'Email', 'Teléfono',
                     'Comuna', 'Ciudad', 'Crédito habilitado', 'Límite crédito', 'Activo'],
                    'SELECT rut, razon_social, nombre_fantasia, email, telefono,
                            comuna, ciudad, credito_habilitado, limite_credito, activo
                     FROM clientes WHERE empresa_id = :empresa_id ORDER BY razon_social',
                    [':empresa_id' => $empresaId]
                );

            case 'proveedores':
                return $this->query(
                    ['RUT', 'Razón social', 'Nombre fantasía', 'Email', 'Teléfono',
                     'Comuna', 'Ciudad', 'Activo'],
                    'SELECT rut, razon_social, nombre_fantasia, email, telefono,
                            comuna, ciudad, activo
                     FROM proveedores WHERE empresa_id = :empresa_id ORDER BY razon_social',
                    [':empresa_id' => $empresaId]
                );

            case 'stock':
                return $this->query(
                    ['Producto', 'SKU', 'Ubicación', 'Cantidad', 'Reservado', 'Stock mínimo'],
                    'SELECT p.nombre, p.sku, u.nombre AS ubicacion, s.cantidad, s.reservado, s.stock_minimo
                     FROM stock_ubicacion s
                     INNER JOIN productos p ON p.id = s.producto_id
                     INNER JOIN ubicaciones_stock u ON u.id = s.ubicacion_id
                     WHERE s.empresa_id = :empresa_id
                     ORDER BY p.nombre, u.nombre',
                    [':empresa_id' => $empresaId]
                );

            case 'ventas':
                return $this->query(
                    ['Fecha', 'Folio', 'Tipo', 'Estado', 'Subtotal', 'Descuento',
                     'Impuesto', 'Total', 'Margen'],
                    'SELECT fecha_venta, folio, tipo_venta, estado, subtotal, descuento_total,
                            impuesto_total, total, margen_total
                     FROM ventas
                     WHERE empresa_id = :empresa_id
                       AND DATE(fecha_venta) BETWEEN :desde AND :hasta
                     ORDER BY fecha_venta',
                    [':empresa_id' => $empresaId, ':desde' => $fechaDesde, ':hasta' => $fechaHasta]
                );

            case 'compras':
                return $this->query(
                    ['Fecha documento', 'Folio', 'Tipo documento', 'Estado',
                     'Subtotal', 'Impuesto', 'Total'],
                    'SELECT fecha_documento, folio, tipo_documento, estado,
                            subtotal, impuesto_total, total
                     FROM compras
                     WHERE empresa_id = :empresa_id
                       AND created_at BETWEEN :desde AND DATE_ADD(:hasta, INTERVAL 1 DAY)
                     ORDER BY created_at',
                    [':empresa_id' => $empresaId, ':desde' => $fechaDesde, ':hasta' => $fechaHasta]
                );

            case 'cierres':
            default:
                return $this->query(
                    ['Fecha cierre', 'Estado', 'Total ventas', 'Descuentos',
                     'Impuestos', 'Margen', 'Cantidad ventas'],
                    'SELECT fecha_cierre, estado, total_ventas, total_descuentos,
                            total_impuestos, total_margen, cantidad_ventas
                     FROM cierres_diarios
                     WHERE empresa_id = :empresa_id
                       AND fecha_cierre BETWEEN :desde AND :hasta
                     ORDER BY fecha_cierre',
                    [':empresa_id' => $empresaId, ':desde' => $fechaDesde, ':hasta' => $fechaHasta]
                );
        }
    }

    /**
     * @param string[] $headers
     * @param array<string, mixed> $binds
     * @return array{0: string[], 1: array<int, array<int, mixed>>}
     */
    private function query(array $headers, string $sql, array $binds): array
    {
        $stmt = $this->db->prepare($sql . ' LIMIT ' . self::ROW_LIMIT);
        $stmt->execute($binds);
        $rows = array_map('array_values', $stmt->fetchAll(PDO::FETCH_ASSOC));
        return [$headers, $rows];
    }
}
