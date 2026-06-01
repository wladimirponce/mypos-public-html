<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Exception;

/**
 * Gestión de la cola de DTEs generados localmente en los POS Android.
 *
 * Flujo:
 *   1. APK genera TED + imprime boleta SIN internet (acción local)
 *   2. APK llama api.php?action=dte_recibir → recibirDte() inserta en cola
 *   3. Cron / api?action=dte_cola_procesar → procesarPendientes() para cada
 *      item llama generateDTE() + sendDTE() en api.php y actualiza estado
 *
 * El servidor usa el mismo CAF (guardado en tabla `cafs`) para reconstruir
 * el DTE XML completo con el folio ya consumido, y lo transmite al SII.
 */
class DteLocalQueueManager
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  RECIBIR — persiste un DTE enviado por un APK
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array $d  Campos del DteLocalRequest del APK:
     *   tipo (int), folio (int), sucursal_id, fecha_emision, rut_receptor,
     *   mnt_total (int), ted_xml (string), items (array), maquina_id? (string)
     * @return array{ok:bool, id?:int, duplicado?:bool, error?:string}
     */
    public function recibirDte(array $d): array
    {
        $tipo       = (int)($d['tipo']         ?? 0);
        $folio      = (int)($d['folio']        ?? 0);
        $sucursal   = trim((string)($d['sucursal_id']   ?? ''));
        $fecha      = trim((string)($d['fecha_emision'] ?? ''));
        $rutRec     = trim((string)($d['rut_receptor']  ?? '66666666-6'));
        $mntTotal   = (int)($d['mnt_total']    ?? 0);
        $tedXml     = trim((string)($d['ted_xml']       ?? ''));
        $items      = $d['items'] ?? [];
        $maquinaId  = trim((string)($d['maquina_id']    ?? '')) ?: null;

        // Validaciones mínimas
        if ($tipo <= 0 || $folio <= 0 || $sucursal === '' || $tedXml === '') {
            return ['ok' => false, 'error' => 'Faltan campos obligatorios: tipo, folio, sucursal_id, ted_xml'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return ['ok' => false, 'error' => 'fecha_emision debe ser YYYY-MM-DD'];
        }

        // Calcular neto/IVA para boletas afectas
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

            // INSERT nuevo (no duplicado): aplicar la venta UNA sola vez —
            // descuento de stock + asiento en hechos_ventas — dentro de la
            // misma transacción. La UNIQUE KEY (tipo_dte, folio) garantiza
            // que un reintento no la vuelva a aplicar.
            $this->aplicarVenta($tipo, $folio, $sucursal, $items);

            $this->pdo->commit();
            return ['ok' => true, 'id' => $queueId];

        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();

            // UNIQUE KEY (tipo_dte, folio) → duplicado idempotente:
            // el documento (y su venta) ya se procesaron en un intento previo.
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

    /**
     * Descuenta stock y registra el asiento de venta en hechos_ventas.
     *
     * Se ejecuta dentro de la transacción de recibirDte(), así que es atómica
     * con el encolado del DTE. Best-effort por línea: un item con problema
     * (id inexistente, etc.) se registra en el log y NO aborta el resto ni el
     * encolado del documento — la emisión nunca se bloquea por un tema de stock.
     *
     * Los items llegan con las claves del APK (DteDetalle):
     *   NmbItem, QtyItem, PrcItem, DescuentoPct, IdProducto
     */
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

    // ─────────────────────────────────────────────────────────────────────────
    //  CONSULTAR — estado de la cola
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resumen de la cola por estado.
     * Si $sucursalId es null, devuelve totales globales de todas las sucursales.
     */
    public function estadoCola(?string $sucursalId = null): array
    {
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

        // Últimos errores para diagnóstico.
        // Incluye los documentos que fallaron y volvieron a 'pendiente' para
        // reintento (marcarError los deja en 'pendiente' hasta agotar intentos):
        // se filtra por ultimo_error presente, no por estado.
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
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PROCESAR — usado por api.php?action=dte_cola_procesar
    // ─────────────────────────────────────────────────────────────────────────

    /** Devuelve hasta $max items pendientes listos para procesar */
    public function getPendientes(int $max = 20): array
    {
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
        $this->pdo->prepare(
            "UPDATE dte_local_queue SET estado='procesando', fecha_proceso=NOW() WHERE id=?"
        )->execute([$id]);
    }

    public function marcarEnviado(int $id, string $trackId): void
    {
        $this->pdo->prepare(
            "UPDATE dte_local_queue SET estado='enviado', track_id=?, fecha_proceso=NOW() WHERE id=?"
        )->execute([$trackId, $id]);
    }

    public function marcarError(int $id, string $error): void
    {
        // 5 intentos → dropped; menos → pendiente (se reintenta).
        // NO se trunca el mensaje: la columna `ultimo_error` es TEXT (64KB)
        // y los mensajes completos del SII son necesarios para diagnosticar.
        $this->pdo->prepare("
            UPDATE dte_local_queue
            SET estado     = IF(intentos >= 4, 'dropped', 'pendiente'),
                intentos   = intentos + 1,
                ultimo_error = ?,
                fecha_proceso = NOW()
            WHERE id = ?
        ")->execute([$error, $id]);
    }

    /** Devuelve un item específico de la cola por id, o null si no existe. */
    public function getById(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM dte_local_queue WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Resetea contador de intentos y vuelve a 'pendiente' un documento.
     * Usado en reenvío manual desde la UI: el operador interviene
     * conscientemente, así que se ignora el límite de intentos automático.
     */
    public function resetParaReintento(int $id): void
    {
        $this->pdo->prepare("
            UPDATE dte_local_queue
            SET estado='pendiente', intentos=0, ultimo_error=NULL, fecha_proceso=NULL
            WHERE id = ?
        ")->execute([$id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  REPORTE — usado por el módulo pos_urgencia
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resumen global para el dashboard: cola + distribución por sucursal.
     */
    public function resumenGlobal(): array
    {
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
    }
}
