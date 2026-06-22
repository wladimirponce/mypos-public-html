<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class CotizacionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cotizaciones
             (empresa_id, sucursal_id, cliente_id, usuario_id, numero_cotizacion,
              estado, validez_dias, validez_hasta, fecha_emision,
              subtotal, descuento_total, total, moneda, observacion, condiciones)
             VALUES
             (:empresa_id, :sucursal_id, :cliente_id, :usuario_id, :numero_cotizacion,
              :estado, :validez_dias, :validez_hasta, :fecha_emision,
              0, 0, 0, :moneda, :observacion, :condiciones)'
        );
        $stmt->execute([
            ':empresa_id'         => $data['empresa_id'],
            ':sucursal_id'        => $data['sucursal_id'],
            ':cliente_id'         => $data['cliente_id'] ?? null,
            ':usuario_id'         => $data['usuario_id'],
            ':numero_cotizacion'  => 'TEMP',
            ':estado'             => 'BORRADOR',
            ':validez_dias'       => $data['validez_dias'] ?? 30,
            ':validez_hasta'      => $data['validez_hasta'],
            ':fecha_emision'      => $data['fecha_emision'],
            ':moneda'             => $data['moneda'] ?? 'CLP',
            ':observacion'        => $data['observacion'] ?? null,
            ':condiciones'        => $data['condiciones'] ?? null,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        // Asignar número definitivo
        $numero = 'COT-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $this->pdo->prepare('UPDATE cotizaciones SET numero_cotizacion = ? WHERE id = ?')
            ->execute([$numero, $id]);
        return $id;
    }

    public function insertItem(array $data): int
    {
        $descMonto = (int) round(
            (float) $data['cantidad'] * (float) $data['precio_unitario'] * ((float) $data['descuento_porcentaje'] / 100)
        );
        $subtotal  = (int) round(
            (float) $data['cantidad'] * (float) $data['precio_unitario'] - $descMonto
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO cotizaciones_items
             (empresa_id, cotizacion_id, linea, producto_id, codigo, nombre,
              cantidad, precio_unitario, descuento_porcentaje, descuento_monto, subtotal, observacion)
             VALUES
             (:empresa_id, :cotizacion_id, :linea, :producto_id, :codigo, :nombre,
              :cantidad, :precio_unitario, :descuento_porcentaje, :descuento_monto, :subtotal, :observacion)'
        );
        $stmt->execute([
            ':empresa_id'          => $data['empresa_id'],
            ':cotizacion_id'       => $data['cotizacion_id'],
            ':linea'               => $data['linea'],
            ':producto_id'         => $data['producto_id'] ?? null,
            ':codigo'              => $data['codigo'] ?? null,
            ':nombre'              => $data['nombre'],
            ':cantidad'            => $data['cantidad'],
            ':precio_unitario'     => $data['precio_unitario'],
            ':descuento_porcentaje'=> $data['descuento_porcentaje'] ?? 0,
            ':descuento_monto'     => $descMonto,
            ':subtotal'            => $subtotal,
            ':observacion'         => $data['observacion'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function refreshTotales(int $cotizacionId): void
    {
        $this->pdo->prepare(
            'UPDATE cotizaciones c
             SET c.subtotal       = (SELECT COALESCE(SUM(i.cantidad * i.precio_unitario), 0) FROM cotizaciones_items i WHERE i.cotizacion_id = c.id),
                 c.descuento_total = (SELECT COALESCE(SUM(i.descuento_monto), 0) FROM cotizaciones_items i WHERE i.cotizacion_id = c.id),
                 c.total          = (SELECT COALESCE(SUM(i.subtotal), 0) FROM cotizaciones_items i WHERE i.cotizacion_id = c.id)
             WHERE c.id = ?'
        )->execute([$cotizacionId]);
    }

    public function find(int $empresaId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*,
                    cl.nombre   AS cliente_nombre,
                    cl.rut      AS cliente_rut,
                    cl.email    AS cliente_email,
                    cl.telefono AS cliente_telefono,
                    cl.direccion AS cliente_direccion,
                    s.nombre    AS sucursal_nombre,
                    u.nombre    AS usuario_nombre,
                    v.id        AS venta_id,
                    v.folio     AS venta_folio,
                    v.tipo_venta AS venta_tipo
             FROM cotizaciones c
             LEFT JOIN clientes  cl ON cl.id = c.cliente_id
             LEFT JOIN sucursales s ON s.id  = c.sucursal_id
             LEFT JOIN usuarios   u ON u.id  = c.usuario_id
             LEFT JOIN ventas     v ON v.id  = c.convertida_venta_id
             WHERE c.empresa_id = ? AND c.id = ?'
        );
        $stmt->execute([$empresaId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findForUpdate(int $empresaId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, estado, validez_hasta, cliente_id, sucursal_id
             FROM cotizaciones WHERE empresa_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$empresaId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function list(int $empresaId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($empresaId, $filters);
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.numero_cotizacion, c.estado, c.total, c.fecha_emision,
                    c.validez_hasta, c.moneda,
                    cl.nombre AS cliente_nombre,
                    s.nombre  AS sucursal_nombre,
                    u.nombre  AS usuario_nombre,
                    (SELECT COUNT(*) FROM cotizaciones_items i WHERE i.cotizacion_id = c.id) AS total_items
             FROM cotizaciones c
             LEFT JOIN clientes   cl ON cl.id = c.cliente_id
             LEFT JOIN sucursales s  ON s.id  = c.sucursal_id
             LEFT JOIN usuarios   u  ON u.id  = c.usuario_id
             WHERE {$where}
             ORDER BY c.id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countList(int $empresaId, array $filters): int
    {
        [$where, $params] = $this->buildWhere($empresaId, $filters);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM cotizaciones c WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function items(int $cotizacionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ci.*, p.nombre AS nombre_actual
             FROM cotizaciones_items ci
             LEFT JOIN productos p ON p.id = ci.producto_id
             WHERE ci.cotizacion_id = ?
             ORDER BY ci.linea'
        );
        $stmt->execute([$cotizacionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateEstado(int $id, string $estado, array $extra = []): void
    {
        $sets = ['estado = :estado'];
        $params = [':estado' => $estado, ':id' => $id];

        if (!empty($extra['rechazada_at'])) {
            $sets[] = 'rechazada_at = NOW()';
        }
        if (array_key_exists('motivo_rechazo', $extra)) {
            $sets[] = 'motivo_rechazo = :motivo_rechazo';
            $params[':motivo_rechazo'] = $extra['motivo_rechazo'];
        }
        if (!empty($extra['convertida_venta_id'])) {
            $sets[] = 'convertida_venta_id = :venta_id';
            $params[':venta_id'] = $extra['convertida_venta_id'];
        }

        $this->pdo->prepare(
            'UPDATE cotizaciones SET ' . implode(', ', $sets) . ' WHERE id = :id'
        )->execute($params);
    }

    public function markVencidas(int $empresaId): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE cotizaciones
             SET estado = 'VENCIDA'
             WHERE empresa_id = ?
               AND estado IN ('ENVIADA', 'APROBADA')
               AND validez_hasta < CURDATE()"
        );
        $stmt->execute([$empresaId]);
        return $stmt->rowCount();
    }

    public function linkVenta(int $cotizacionId, int $ventaId): void
    {
        $this->pdo->prepare(
            "UPDATE cotizaciones
             SET estado = 'CONVERTIDA', convertida_venta_id = ?
             WHERE id = ?"
        )->execute([$ventaId, $cotizacionId]);
    }

    /** @return array{0: string, 1: array<string,mixed>} */
    private function buildWhere(int $empresaId, array $filters): array
    {
        $where  = ['c.empresa_id = :empresa_id'];
        $params = [':empresa_id' => $empresaId];

        if (!empty($filters['estado'])) {
            $where[]               = 'c.estado = :estado';
            $params[':estado']     = $filters['estado'];
        }
        if (!empty($filters['cliente_id'])) {
            $where[]               = 'c.cliente_id = :cliente_id';
            $params[':cliente_id'] = (int) $filters['cliente_id'];
        }
        if (!empty($filters['sucursal_id'])) {
            $where[]               = 'c.sucursal_id = :sucursal_id';
            $params[':sucursal_id']= (int) $filters['sucursal_id'];
        }
        if (!empty($filters['fecha_desde'])) {
            $where[]               = 'c.fecha_emision >= :fecha_desde';
            $params[':fecha_desde']= $filters['fecha_desde'];
        }
        if (!empty($filters['fecha_hasta'])) {
            $where[]               = 'c.fecha_emision <= :fecha_hasta';
            $params[':fecha_hasta']= $filters['fecha_hasta'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(c.numero_cotizacion LIKE :q OR cl.nombre LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    public function empresaInfo(int $empresaId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.razon_social, e.rut, e.giro, e.direccion, e.comuna, e.telefono, e.email
             FROM empresas e WHERE e.id = ?'
        );
        $stmt->execute([$empresaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
