<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Exception;

/**
 * Gestión de la cola de DTEs generados localmente en los POS Android.
 */
class DteLocalQueueManager
{
    private PDO $pdo;
    private bool $useLegacyTable = false;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        try {
            $stmt = $this->pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_local_queue' LIMIT 1");
            $this->useLegacyTable = (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            $this->useLegacyTable = false;
        }
    }

    private function mapTipoDteToEnum(int $tipo): string
    {
        switch ($tipo) {
            case 33:
            case 34:
                return 'FACTURA';
            case 39:
            case 41:
                return 'BOLETA';
            case 52:
                return 'GUIA_DESPACHO';
            case 61:
                return 'NOTA_CREDITO';
            case 56:
                return 'NOTA_DEBITO';
            default:
                return 'BOLETA';
        }
    }

    private function mapEnumToTipoDte(string $enum): int
    {
        switch (strtoupper($enum)) {
            case 'FACTURA':
                return 33;
            case 'BOLETA':
                return 39;
            case 'GUIA_DESPACHO':
                return 52;
            case 'NOTA_CREDITO':
                return 61;
            case 'NOTA_DEBITO':
                return 56;
            default:
                return 39;
        }
    }

    public function recibirDte(array $d): array
    {
        if (!$this->useLegacyTable) {
            return ['ok' => false, 'error' => 'API de cola local deshabilitada en modo SaaS'];
        }

        $tipo       = (int)($d['tipo']         ?? 0);
        $folio      = (int)($d['folio']        ?? 0);
        $sucursal   = trim((string)($d['sucursal_id']   ?? ''));
        $fecha      = trim((string)($d['fecha_emision'] ?? ''));
        $rutRec     = trim((string)($d['rut_receptor']  ?? '66666666-6'));
        $mntTotal   = (int)($d['mnt_total']    ?? 0);
        $tedXml     = trim((string)($d['ted_xml']       ?? ''));
        $items      = $d['items'] ?? [];
        $maquinaId  = trim((string)($d['maquina_id']    ?? '')) ?: null;

        if ($tipo <= 0 || $folio <= 0 || $sucursal === '' || $tedXml === '') {
            return ['ok' => false, 'error' => 'Faltan campos obligatorios: tipo, folio, sucursal_id, ted_xml'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return ['ok' => false, 'error' => 'fecha_emision debe ser YYYY-MM-DD'];
        }

        $mntNeto = 0;
        $mntIva  = 0;
        if (in_array($tipo, [39, 33, 34], true) && $mntTotal > 0) {
            $mntNeto = (int) round($mntTotal / 1.19);
            $mntIva  = $mntTotal - $mntNeto;
        }

        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        try {
            $this->pdo->beginTransaction();

            $st = $this->pdo->prepare("
                INSERT INTO dte_local_queue
                  (tipo_dte, folio, sucursal_id, maquina_id, fecha_emision,
                   rut_receptor, mnt_total, mnt_neto, mnt_iva,
                   ted_xml, items_json, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')
            ");
            $st->execute([
                $tipo, $folio, $sucursal, $maquinaId, $fecha,
                $rutRec, $mntTotal, $mntNeto, $mntIva,
                $tedXml, $itemsJson
            ]);
            $queueId = (int)$this->pdo->lastInsertId();

            $this->aplicarVenta($tipo, $folio, $sucursal, $items);

            $this->pdo->commit();
            return ['ok' => true, 'id' => $queueId];

        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();

            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $row = $this->pdo->prepare(
                    "SELECT id FROM dte_local_queue WHERE tipo_dte=? AND folio=? LIMIT 1"
                );
                $row->execute([$tipo, $folio]);
                $existing = $row->fetch(PDO::FETCH_ASSOC);
                return ['ok' => true, 'id' => (int)($existing['id'] ?? 0), 'duplicado' => true];
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function aplicarVenta(int $tipo, int $folio, string $sucursal, array $items): void
    {
        if (empty($items)) return;

        $stmtDesc = $this->pdo->prepare("
            UPDATE stock_actual
            SET    stock_actual        = GREATEST(0, stock_actual - :qty),
                   fecha_actualizacion = NOW()
            WHERE  id_producto_fk = :id AND id_sucursal_fk = :suc
        ");
        $stmtVenta = $this->pdo->prepare("
            INSERT INTO hechos_ventas
                (id_sucursal_fk, id_producto_fk, id_fecha_fk,
                 nrodocto, precio_unitario, cantidad, total_linea,
                 ventas_boleta, fecha_hora_trx, estado_caja, estado_venta)
            VALUES
                (:suc, :id, :fecha_fk, :folio, :precio, :qty, :total,
                 :total_doc, NOW(), 'COBRADA', 'ACTIVA')
        ");

        $fechaFk  = (int)date('Ymd');
        $totalDoc = 0.0;
        foreach ($items as $it) {
            $totalDoc += (float)($it['PrcItem'] ?? 0) * (float)($it['QtyItem'] ?? 1);
        }

        foreach ($items as $it) {
            $idProd = trim((string)($it['IdProducto'] ?? ''));
            $qty    = max(0.0, (float)($it['QtyItem'] ?? 1));
            $precio = max(0.0, (float)($it['PrcItem']  ?? 0));
            if ($idProd === '' || $qty <= 0) continue;
            $total  = round($precio * $qty);

            try {
                $stmtDesc->execute([':qty' => $qty, ':id' => $idProd, ':suc' => $sucursal]);
                $stmtVenta->execute([
                    ':suc'       => $sucursal,
                    ':id'        => $idProd,
                    ':fecha_fk'  => $fechaFk,
                    ':folio'     => "DTE-{$tipo}-{$folio}",
                    ':precio'    => $precio,
                    ':qty'       => $qty,
                    ':total'     => $total,
                    ':total_doc' => $totalDoc,
                ]);
            } catch (\PDOException $e) {
                error_log("DteLocalQueueManager::aplicarVenta DTE-{$tipo}-{$folio} "
                    . "item {$idProd}: " . $e->getMessage());
            }
        }
    }

    public function estadoCola(?string $sucursalId = null): array
    {
        if ($this->useLegacyTable) {
            $where  = $sucursalId ? "WHERE sucursal_id = ?" : "";
            $params = $sucursalId ? [$sucursalId] : [];

            $st = $this->pdo->prepare("
                SELECT estado, COUNT(*) AS cantidad,
                       MAX(fecha_creacion) AS ultimo,
                       SUM(mnt_total) AS monto_total
                FROM dte_local_queue $where
                GROUP BY estado
            ");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $resumen = ['pendiente' => 0, 'procesando' => 0,
                        'enviado' => 0, 'error' => 0, 'dropped' => 0];
            foreach ($rows as $r) {
                $resumen[$r['estado']] = (int)$r['cantidad'];
            }

            $errSt = $this->pdo->prepare("
                SELECT id, tipo_dte, folio, sucursal_id, ultimo_error, intentos, estado, fecha_proceso
                FROM dte_local_queue
                WHERE ultimo_error IS NOT NULL AND ultimo_error <> ''
                  " . ($sucursalId ? "AND sucursal_id = ?" : "") . "
                ORDER BY fecha_proceso DESC
                LIMIT 5
            ");
            $errSt->execute($sucursalId ? [$sucursalId] : []);
            $ultErrores = $errSt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'ok'         => true,
                'resumen'    => $resumen,
                'ult_errores'=> $ultErrores,
            ];
        } else {
            // MyPOS SaaS mode: map to folios_consumidos
            $where = $sucursalId ? "WHERE fc.sucursal_id = ?" : "";
            $params = $sucursalId ? [$sucursalId] : [];

            $stPend = $this->pdo->prepare("SELECT COUNT(*) FROM folios_consumidos fc WHERE fc.sync_status = 'PENDING' " . ($sucursalId ? "AND fc.sucursal_id = ?" : ""));
            $stPend->execute($params);
            $pendiente = (int)$stPend->fetchColumn();

            $stEnv = $this->pdo->prepare("SELECT COUNT(*) FROM folios_consumidos fc WHERE fc.sync_status = 'SYNCED' AND fc.estado IN ('ENVIADO_SII', 'ACEPTADO_SII') " . ($sucursalId ? "AND fc.sucursal_id = ?" : ""));
            $stEnv->execute($params);
            $enviado = (int)$stEnv->fetchColumn();

            $stErr = $this->pdo->prepare("SELECT COUNT(*) FROM folios_consumidos fc WHERE fc.sync_status = 'ERROR' " . ($sucursalId ? "AND fc.sucursal_id = ?" : ""));
            $stErr->execute($params);
            $error = (int)$stErr->fetchColumn();

            $stDrop = $this->pdo->prepare("SELECT COUNT(*) FROM folios_consumidos fc WHERE fc.estado = 'ANULADO' " . ($sucursalId ? "AND fc.sucursal_id = ?" : ""));
            $stDrop->execute($params);
            $dropped = (int)$stDrop->fetchColumn();

            $resumen = [
                'pendiente' => $pendiente,
                'procesando' => 0,
                'enviado' => $enviado,
                'error' => $error,
                'dropped' => $dropped
            ];

            $ultErrores = [];
            try {
                $st = $this->pdo->prepare("
                    SELECT fc.id,
                           CASE fc.tipo_documento
                               WHEN 'FACTURA' THEN 33
                               WHEN 'BOLETA' THEN 39
                               WHEN 'GUIA_DESPACHO' THEN 52
                               WHEN 'NOTA_CREDITO' THEN 61
                               WHEN 'NOTA_DEBITO' THEN 56
                               ELSE 39
                           END AS tipo_dte,
                           fc.folio, fc.sucursal_id, 'Error de sincronización con SII' AS ultimo_error, 
                           1 AS intentos, 'error' AS estado, fc.updated_at AS fecha_proceso
                    FROM folios_consumidos fc
                    WHERE fc.sync_status = 'ERROR'
                      " . ($sucursalId ? "AND fc.sucursal_id = ?" : "") . "
                    ORDER BY fc.updated_at DESC LIMIT 5
                ");
                $st->execute($params);
                $ultErrores = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $_) {}

            return [
                'ok' => true,
                'resumen' => $resumen,
                'ult_errores' => $ultErrores
            ];
        }
    }

    public function getPendientes(int $max = 20): array
    {
        if (!$this->useLegacyTable) return [];

        $st = $this->pdo->prepare("
            SELECT * FROM dte_local_queue
            WHERE estado = 'pendiente' AND intentos < 5
            ORDER BY fecha_creacion ASC
            LIMIT ?
        ");
        $st->execute([$max]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function marcarProcesando(int $id): void
    {
        if (!$this->useLegacyTable) return;
        $this->pdo->prepare(
            "UPDATE dte_local_queue SET estado='procesando', fecha_proceso=NOW() WHERE id=?"
        )->execute([$id]);
    }

    public function marcarEnviado(int $id, string $trackId): void
    {
        if (!$this->useLegacyTable) return;
        $this->pdo->prepare(
            "UPDATE dte_local_queue SET estado='enviado', track_id=?, fecha_proceso=NOW() WHERE id=?"
        )->execute([$trackId, $id]);
    }

    public function marcarError(int $id, string $error): void
    {
        if (!$this->useLegacyTable) return;
        $this->pdo->prepare("
            UPDATE dte_local_queue
            SET estado     = IF(intentos >= 4, 'dropped', 'pendiente'),
                intentos   = intentos + 1,
                ultimo_error = ?,
                fecha_proceso = NOW()
            WHERE id = ?
        ")->execute([$error, $id]);
    }

    public function getById(int $id): ?array
    {
        if (!$this->useLegacyTable) return null;
        $st = $this->pdo->prepare("SELECT * FROM dte_local_queue WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function resetParaReintento(int $id): void
    {
        if (!$this->useLegacyTable) return;
        $this->pdo->prepare("
            UPDATE dte_local_queue
            SET estado='pendiente', intentos=0, ultimo_error=NULL, fecha_proceso=NULL
            WHERE id = ?
        ")->execute([$id]);
    }

    public function resumenGlobal(): array
    {
        if ($this->useLegacyTable) {
            $estado = $this->estadoCola();

            $porSucursal = $this->pdo->query("
                SELECT sucursal_id,
                       SUM(CASE WHEN estado='pendiente'   THEN 1 ELSE 0 END) AS pendiente,
                       SUM(CASE WHEN estado='error'       THEN 1 ELSE 0 END) AS con_error,
                       SUM(CASE WHEN estado='dropped'     THEN 1 ELSE 0 END) AS dropped,
                       SUM(CASE WHEN estado='enviado'     THEN 1 ELSE 0 END) AS enviado,
                       COUNT(*) AS total,
                       MAX(fecha_creacion) AS ultimo_recibido
                FROM dte_local_queue
                GROUP BY sucursal_id
                ORDER BY sucursal_id
            ")->fetchAll(PDO::FETCH_ASSOC);

            return [
                'ok'          => true,
                'resumen'     => $estado['resumen'],
                'por_sucursal'=> $porSucursal,
                'ult_errores' => $estado['ult_errores'],
            ];
        } else {
            $estado = $this->estadoCola();
            
            // Group by sucursal
            $porSucursal = $this->pdo->query("
                SELECT fc.sucursal_id,
                       SUM(CASE WHEN fc.sync_status='PENDING' THEN 1 ELSE 0 END) AS pendiente,
                       SUM(CASE WHEN fc.sync_status='ERROR'   THEN 1 ELSE 0 END) AS con_error,
                       SUM(CASE WHEN fc.estado='ANULADO'      THEN 1 ELSE 0 END) AS dropped,
                       SUM(CASE WHEN fc.sync_status='SYNCED' AND fc.estado IN ('ENVIADO_SII', 'ACEPTADO_SII') THEN 1 ELSE 0 END) AS enviado,
                       COUNT(*) AS total,
                       MAX(fc.created_at) AS ultimo_recibido
                FROM folios_consumidos fc
                GROUP BY fc.sucursal_id
                ORDER BY fc.sucursal_id
            ")->fetchAll(PDO::FETCH_ASSOC);

            return [
                'ok'          => true,
                'resumen'     => $estado['resumen'],
                'por_sucursal'=> $porSucursal,
                'ult_errores' => $estado['ult_errores'],
            ];
        }
    }
}
