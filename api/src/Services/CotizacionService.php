<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use DateTimeZone;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\CotizacionRepository;
use Throwable;

final class CotizacionService
{
    private CotizacionRepository $repository;

    public function __construct(?CotizacionRepository $repository = null)
    {
        $this->repository = $repository ?? new CotizacionRepository(Database::connection());
    }

    public function crear(int $userId, array $payload): array
    {
        $empresaId  = $this->positiveInt($payload, 'empresa_id');
        $sucursalId = $this->positiveInt($payload, 'sucursal_id');
        $items      = $payload['items'] ?? [];

        if (empty($items) || !is_array($items)) {
            throw new HttpException('La cotización debe tener al menos un ítem', 422);
        }

        $fechaEmision = $this->date((string) ($payload['fecha_emision'] ?? $this->today()));
        $validezDias  = max(1, (int) ($payload['validez_dias'] ?? 30));
        $validezHasta = $this->addDays($fechaEmision, $validezDias);
        $clienteId    = !empty($payload['cliente_id']) ? (int) $payload['cliente_id'] : null;

        $conn = $this->repository->connection();
        try {
            $conn->beginTransaction();

            $cotizacionId = $this->repository->insert([
                'empresa_id'   => $empresaId,
                'sucursal_id'  => $sucursalId,
                'cliente_id'   => $clienteId,
                'usuario_id'   => $userId,
                'validez_dias' => $validezDias,
                'validez_hasta'=> $validezHasta,
                'fecha_emision'=> $fechaEmision,
                'moneda'       => $payload['moneda']     ?? 'CLP',
                'observacion'  => !empty($payload['observacion']) ? trim((string) $payload['observacion']) : null,
                'condiciones'  => !empty($payload['condiciones'])  ? trim((string) $payload['condiciones'])  : null,
            ]);

            foreach (array_values($items) as $i => $item) {
                $this->repository->insertItem([
                    'empresa_id'           => $empresaId,
                    'cotizacion_id'        => $cotizacionId,
                    'linea'                => $i + 1,
                    'producto_id'          => !empty($item['producto_id']) ? (int) $item['producto_id'] : null,
                    'codigo'               => $item['codigo'] ?? null,
                    'nombre'               => trim((string) ($item['nombre'] ?? '')),
                    'cantidad'             => max(0.001, (float) ($item['cantidad'] ?? 1)),
                    'precio_unitario'      => max(0, (int) ($item['precio_unitario'] ?? 0)),
                    'descuento_porcentaje' => min(100, max(0, (float) ($item['descuento_porcentaje'] ?? 0))),
                    'observacion'          => $item['observacion'] ?? null,
                ]);
            }

            $this->repository->refreshTotales($cotizacionId);
            $conn->commit();

            $cot = $this->repository->find($empresaId, $cotizacionId);
            return [
                'cotizacion_id'      => $cotizacionId,
                'numero_cotizacion'  => $cot['numero_cotizacion'] ?? '',
                'estado'             => 'BORRADOR',
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public function listar(int $empresaId, array $filters): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        // Lazy vencimiento antes de listar
        $this->repository->markVencidas($empresaId);

        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $total       = $this->repository->countList($empresaId, $filters);
        $cotizaciones = $this->repository->list($empresaId, $filters, $perPage, $offset);

        return [
            'cotizaciones' => $cotizaciones,
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'total_pages'  => (int) ceil($total / $perPage),
        ];
    }

    public function detalle(int $empresaId, int $id): array
    {
        if ($empresaId <= 0 || $id <= 0) {
            throw new HttpException('Parámetros inválidos', 422);
        }
        // Lazy vencimiento
        $this->repository->markVencidas($empresaId);

        $cot = $this->repository->find($empresaId, $id);
        if ($cot === null) {
            throw new HttpException('Cotización no encontrada', 404);
        }

        return [
            'cotizacion' => $cot,
            'items'      => $this->repository->items($id),
        ];
    }

    public function enviar(int $userId, int $id, int $empresaId): array
    {
        $conn = $this->repository->connection();
        try {
            $conn->beginTransaction();
            $cot = $this->repository->findForUpdate($empresaId, $id);
            if ($cot === null) {
                throw new HttpException('Cotización no encontrada', 404);
            }
            if ($cot['estado'] !== 'BORRADOR') {
                throw new HttpException('Solo se puede enviar una cotización en estado BORRADOR', 422);
            }
            $this->repository->updateEstado($id, 'ENVIADA');
            $conn->commit();
            return ['cotizacion_id' => $id, 'estado' => 'ENVIADA'];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public function aprobar(int $userId, int $id, int $empresaId): array
    {
        $conn = $this->repository->connection();
        try {
            $conn->beginTransaction();
            $cot = $this->repository->findForUpdate($empresaId, $id);
            if ($cot === null) {
                throw new HttpException('Cotización no encontrada', 404);
            }
            if (!in_array($cot['estado'], ['ENVIADA', 'BORRADOR'], true)) {
                throw new HttpException('No se puede aprobar una cotización en estado ' . $cot['estado'], 422);
            }
            if ($cot['validez_hasta'] < $this->today()) {
                $this->repository->updateEstado($id, 'VENCIDA');
                $conn->commit();
                throw new HttpException('La cotización está vencida', 422);
            }
            $this->repository->updateEstado($id, 'APROBADA');
            $conn->commit();
            return ['cotizacion_id' => $id, 'estado' => 'APROBADA'];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public function rechazar(int $userId, int $id, int $empresaId, ?string $motivo): array
    {
        $conn = $this->repository->connection();
        try {
            $conn->beginTransaction();
            $cot = $this->repository->findForUpdate($empresaId, $id);
            if ($cot === null) {
                throw new HttpException('Cotización no encontrada', 404);
            }
            if (in_array($cot['estado'], ['CONVERTIDA', 'RECHAZADA'], true)) {
                throw new HttpException('La cotización ya está ' . $cot['estado'], 422);
            }
            $this->repository->updateEstado($id, 'RECHAZADA', [
                'rechazada_at'   => true,
                'motivo_rechazo' => $motivo,
            ]);
            $conn->commit();
            return ['cotizacion_id' => $id, 'estado' => 'RECHAZADA'];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public function convertir(int $userId, int $id, int $empresaId, array $payload): array
    {
        $conn = $this->repository->connection();
        try {
            $conn->beginTransaction();
            $cot = $this->repository->findForUpdate($empresaId, $id);
            if ($cot === null) {
                throw new HttpException('Cotización no encontrada', 404);
            }
            if ($cot['estado'] === 'CONVERTIDA') {
                throw new HttpException('La cotización ya fue convertida', 422);
            }
            if (!in_array($cot['estado'], ['APROBADA', 'ENVIADA', 'BORRADOR'], true)) {
                throw new HttpException('No se puede convertir una cotización en estado ' . $cot['estado'], 422);
            }
            if ($cot['validez_hasta'] < $this->today()) {
                $this->repository->updateEstado($id, 'VENCIDA');
                $conn->commit();
                throw new HttpException('La cotización está vencida', 422);
            }

            // Preparar items para la venta
            $items = $this->repository->items($id);
            $ventaItems = array_map(fn($i) => [
                'producto_id'  => $i['producto_id'],
                'nombre'       => $i['nombre'],
                'codigo'       => $i['codigo'],
                'cantidad'     => (float) $i['cantidad'],
                'precio_unit'  => (int) $i['precio_unitario'],
                'descuento_porcentaje' => (float) $i['descuento_porcentaje'],
            ], array_filter($items, fn($i) => $i['producto_id'] !== null));

            if (empty($ventaItems)) {
                throw new HttpException('La cotización no tiene ítems con producto asociado para convertir a venta', 422);
            }

            $ventaPayload = array_merge($payload, [
                'empresa_id'     => $empresaId,
                'sucursal_id'    => $cot['sucursal_id'],
                'cliente_id'     => $cot['cliente_id'],
                'cotizacion_id'  => $id,
                'items'          => $ventaItems,
                'tipo_venta'     => $payload['tipo_venta'] ?? 'BOLETA',
                'condicion_pago' => $payload['condicion_pago'] ?? 'CONTADO',
            ]);

            $ventaService = new VentaService();
            $ventaResult  = $ventaService->registrarVenta($userId, $ventaPayload);
            $ventaId      = (int) ($ventaResult['venta_id'] ?? 0);

            if ($ventaId > 0) {
                $this->repository->linkVenta($id, $ventaId);
            }

            $conn->commit();
            return [
                'cotizacion_id' => $id,
                'venta_id'      => $ventaId,
                'estado'        => 'CONVERTIDA',
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public function generarPdf(int $empresaId, int $id): string
    {
        $this->repository->markVencidas($empresaId);
        $cot = $this->repository->find($empresaId, $id);
        if ($cot === null) {
            throw new HttpException('Cotización no encontrada', 404);
        }
        $items    = $this->repository->items($id);
        $empresa  = $this->repository->empresaInfo($empresaId) ?? [];

        $html = $this->buildPdfHtml($empresa, $cot, $items);
        return (new PdfService())->fromHtml($html);
    }

    private function buildPdfHtml(array $empresa, array $cot, array $items): string
    {
        $fmt = fn($n) => '$' . number_format((int) $n, 0, ',', '.');
        $fmtDate = fn($d) => $d ? date('d/m/Y', strtotime($d)) : '—';

        $estadoLabel = [
            'BORRADOR'   => 'Borrador',
            'ENVIADA'    => 'Enviada',
            'APROBADA'   => 'Aprobada',
            'RECHAZADA'  => 'Rechazada',
            'VENCIDA'    => 'Vencida',
            'CONVERTIDA' => 'Convertida a venta',
        ][$cot['estado']] ?? $cot['estado'];

        $rowsHtml = '';
        foreach ($items as $item) {
            $pct = (float) $item['descuento_porcentaje'] > 0
                ? "<small style='color:#e53e3e'>-{$item['descuento_porcentaje']}%</small>"
                : '';
            $rowsHtml .= "
            <tr>
              <td style='padding:6px 8px;border-bottom:1px solid #eee'>{$item['linea']}</td>
              <td style='padding:6px 8px;border-bottom:1px solid #eee'>
                <strong>" . htmlspecialchars($item['nombre']) . "</strong>" .
                ($item['codigo'] ? "<br><small style='color:#999'>" . htmlspecialchars($item['codigo']) . "</small>" : '') .
                ($item['observacion'] ? "<br><small style='color:#666'>" . htmlspecialchars($item['observacion']) . "</small>" : '') .
                "</td>
              <td style='padding:6px 8px;border-bottom:1px solid #eee;text-align:right'>" . number_format((float) $item['cantidad'], 2, ',', '.') . "</td>
              <td style='padding:6px 8px;border-bottom:1px solid #eee;text-align:right'>{$fmt($item['precio_unitario'])} {$pct}</td>
              <td style='padding:6px 8px;border-bottom:1px solid #eee;text-align:right;font-weight:600'>{$fmt($item['subtotal'])}</td>
            </tr>";
        }

        $descuentoRow = (int) $cot['descuento_total'] > 0
            ? "<tr><td colspan='4' style='text-align:right;padding:6px 8px;color:#e53e3e'>Descuentos</td><td style='text-align:right;padding:6px 8px;color:#e53e3e'>-{$fmt($cot['descuento_total'])}</td></tr>"
            : '';

        $clienteHtml = '';
        if (!empty($cot['cliente_nombre'])) {
            $clienteHtml = "
            <div style='margin-bottom:20px'>
              <p style='font-weight:700;margin-bottom:4px;font-size:11px;text-transform:uppercase;color:#666'>Cliente</p>
              <p style='font-weight:600;font-size:13px'>" . htmlspecialchars($cot['cliente_nombre']) . "</p>" .
              ($cot['cliente_rut']      ? "<p style='color:#666;font-size:11px'>{$cot['cliente_rut']}</p>"      : '') .
              ($cot['cliente_email']    ? "<p style='color:#666;font-size:11px'>{$cot['cliente_email']}</p>"    : '') .
              ($cot['cliente_direccion']? "<p style='color:#666;font-size:11px'>" . htmlspecialchars($cot['cliente_direccion']) . "</p>" : '') .
              "</div>";
        }

        $condicionesHtml = !empty($cot['condiciones'])
            ? "<div style='margin-top:24px;padding:12px;background:#f7f7f7;border-radius:4px;font-size:11px;color:#555'>
                <p style='font-weight:700;margin-bottom:4px'>Condiciones</p>
                <p>" . nl2br(htmlspecialchars($cot['condiciones'])) . "</p>
               </div>"
            : '';

        $observacionHtml = !empty($cot['observacion'])
            ? "<p style='font-size:11px;color:#666;margin-top:8px'><em>" . htmlspecialchars($cot['observacion']) . "</em></p>"
            : '';

        $razonSocial = htmlspecialchars($empresa['razon_social'] ?? '');
        $rut         = htmlspecialchars($empresa['rut']         ?? '');
        $giro        = htmlspecialchars($empresa['giro']        ?? '');
        $direccion   = htmlspecialchars($empresa['direccion']   ?? '');

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size:12px; color:#333; padding:32px 36px; }
  .header { display:table; width:100%; margin-bottom:28px; }
  .header-left  { display:table-cell; vertical-align:top; width:55%; }
  .header-right { display:table-cell; vertical-align:top; text-align:right; }
  .doc-title { font-size:28px; font-weight:700; color:#1a56db; letter-spacing:-0.5px; }
  .doc-number { font-size:14px; font-weight:600; color:#1a56db; margin-top:2px; }
  .empresa-name { font-size:15px; font-weight:700; margin-bottom:2px; }
  .empresa-detail { font-size:10px; color:#666; line-height:1.6; }
  table.items { width:100%; border-collapse:collapse; margin-top:16px; }
  table.items thead th { background:#1a56db; color:white; padding:8px; font-size:11px; text-align:left; }
  table.items thead th:nth-child(3),
  table.items thead th:nth-child(4),
  table.items thead th:nth-child(5) { text-align:right; }
  .totals-row td { padding:6px 8px; font-size:12px; }
  .total-final { font-size:16px; font-weight:700; color:#1a56db; }
  .meta-box { display:table; width:100%; margin-bottom:20px; }
  .meta-left, .meta-right { display:table-cell; vertical-align:top; }
  .meta-right { text-align:right; }
  .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:10px; font-weight:700; background:#dbeafe; color:#1e40af; }
  .divider { border:none; border-top:1px solid #eee; margin:16px 0; }
</style>
</head>
<body>

<div class="header">
  <div class="header-left">
    <p class="empresa-name">{$razonSocial}</p>
    <p class="empresa-detail">{$rut}</p>
    <p class="empresa-detail">{$giro}</p>
    <p class="empresa-detail">{$direccion}</p>
  </div>
  <div class="header-right">
    <p class="doc-title">COTIZACIÓN</p>
    <p class="doc-number">{$cot['numero_cotizacion']}</p>
    <p style="margin-top:6px"><span class="badge">{$estadoLabel}</span></p>
  </div>
</div>

<hr class="divider">

<div class="meta-box">
  <div class="meta-left">
    {$clienteHtml}
  </div>
  <div class="meta-right" style="font-size:11px;color:#555;line-height:1.8">
    <p><strong>Fecha emisión:</strong> {$fmtDate($cot['fecha_emision'])}</p>
    <p><strong>Válida hasta:</strong> {$fmtDate($cot['validez_hasta'])}</p>
    <p><strong>Elaborada por:</strong> {$cot['usuario_nombre']}</p>
    <p><strong>Sucursal:</strong> {$cot['sucursal_nombre']}</p>
  </div>
</div>

<table class="items">
  <thead>
    <tr>
      <th style="width:4%">#</th>
      <th>Descripción</th>
      <th style="width:10%;text-align:right">Cant.</th>
      <th style="width:18%;text-align:right">Precio unit.</th>
      <th style="width:16%;text-align:right">Subtotal</th>
    </tr>
  </thead>
  <tbody>
    {$rowsHtml}
  </tbody>
  <tfoot>
    {$descuentoRow}
    <tr>
      <td colspan='4' style='text-align:right;padding:10px 8px;font-size:12px;border-top:2px solid #1a56db'>
        <strong>TOTAL {$cot['moneda']}</strong>
      </td>
      <td style='text-align:right;padding:10px 8px;border-top:2px solid #1a56db' class='total-final'>
        {$fmt($cot['total'])}
      </td>
    </tr>
  </tfoot>
</table>

{$observacionHtml}
{$condicionesHtml}

<div style="margin-top:40px;font-size:9px;color:#aaa;text-align:center;border-top:1px solid #eee;padding-top:12px">
  Cotización emitida el {$fmtDate($cot['fecha_emision'])} · Válida por {$cot['validez_dias']} días · {$razonSocial}
</div>

</body>
</html>
HTML;
    }

    private function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d');
    }

    private function date(string $value): string
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            throw new HttpException('Fecha inválida: ' . $value, 422);
        }
        return $value;
    }

    private function addDays(string $fecha, int $days): string
    {
        return (new DateTimeImmutable($fecha))->modify("+{$days} days")->format('Y-m-d');
    }

    private function positiveInt(array $data, string $field): int
    {
        $v = (int) ($data[$field] ?? 0);
        if ($v <= 0) {
            throw new HttpException('Error de validación', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }
        return $v;
    }
}
