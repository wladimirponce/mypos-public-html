<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;

final class DocumentoIaNormalizerService
{
    private const TIPOS = ['FACTURA_COMPRA', 'GUIA_DESPACHO_COMPRA', 'GUIA_DESPACHO', 'BOLETA_COMPRA', 'DESCONOCIDO'];

    public function normalizarDocumento(array $respuestaIa, int $empresaId): array
    {
        unset($empresaId);

        $alerts = [];
        $type = strtoupper(trim((string) ($respuestaIa['tipo_documento'] ?? 'FACTURA_COMPRA')));
        if ($type === 'GUIA_DESPACHO') {
            $type = 'GUIA_DESPACHO_COMPRA';
        }
        if (!in_array($type, self::TIPOS, true)) {
            $type = 'DESCONOCIDO';
        }
        if ($type === 'DESCONOCIDO') {
            $type = 'FACTURA_COMPRA';
        }

        $rut = $this->normalizarRut($respuestaIa['proveedor_rut'] ?? null);
        if (($respuestaIa['proveedor_rut'] ?? null) !== null && $rut !== null && !$this->validarRut($rut)) {
            $alerts[] = $this->alert('RUT_PROVEEDOR_INVALIDO', 'ERROR', 'RUT de proveedor invalido', ['rut' => $rut]);
        }

        $date = $this->normalizarFecha($respuestaIa['fecha_documento'] ?? null);
        if (!empty($respuestaIa['fecha_documento']) && $date === null) {
            $alerts[] = $this->alert('FECHA_INVALIDA', 'WARNING', 'Fecha de documento invalida', ['valor' => $respuestaIa['fecha_documento']]);
        }

        $folio = $this->textOrNull($respuestaIa['folio'] ?? null);
        if ($folio === null) {
            $alerts[] = $this->alert('FOLIO_NO_DETECTADO', 'WARNING', 'No se detecto folio del documento');
        }

        $items = [];
        foreach (($respuestaIa['items'] ?? []) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $quantity = $this->normalizarCantidad($item['cantidad_detectada'] ?? $item['cantidad'] ?? 0);
            $cost = $this->normalizarMonto($item['costo_unitario_detectado'] ?? $item['costo_unitario'] ?? 0);
            $lineTotal = $this->normalizarMonto($item['total_detectado'] ?? $item['total'] ?? 0);
            $calculatedLineTotal = (int) round(((float) $quantity) * $cost);
            $confidence = $this->normalizarConfianza($item['confianza'] ?? null);
            $itemAlerts = [];

            if ((float) $quantity <= 0) {
                $itemAlerts[] = $this->alert('CANTIDAD_INVALIDA', 'ERROR', 'Cantidad detectada invalida');
            }
            if ($cost < 0) {
                $itemAlerts[] = $this->alert('COSTO_INVALIDO', 'ERROR', 'Costo unitario detectado invalido');
            }
            if ($lineTotal <= 0 && $calculatedLineTotal > 0) {
                $lineTotal = $calculatedLineTotal;
            }
            if ($lineTotal > 0 && abs($lineTotal - $calculatedLineTotal) > 2) {
                $itemAlerts[] = $this->alert('TOTAL_NO_CUADRA', 'WARNING', 'Total de item no cuadra con cantidad y costo');
            }
            if ($confidence !== null && $confidence < 0.75) {
                $itemAlerts[] = $this->alert('BAJA_CONFIANZA_ITEM', 'WARNING', 'Item detectado con baja confianza', ['confianza' => $confidence]);
            }

            $items[] = [
                'linea' => $index + 1,
                'codigo_detectado' => $this->textOrNull($item['codigo_detectado'] ?? null),
                'codigo_barra_detectado' => $this->textOrNull($item['codigo_barra_detectado'] ?? $item['codigo_barra'] ?? null),
                'nombre_detectado' => $this->textOrNull($item['nombre_detectado'] ?? null) ?? 'Producto detectado',
                'cantidad_detectada' => $quantity,
                'costo_unitario_detectado' => $cost,
                'total_detectado' => $lineTotal,
                'cantidad_normalizada' => $quantity,
                'costo_unitario_normalizado' => $cost,
                'total_normalizado' => $lineTotal,
                'confianza' => $confidence,
                'alertas' => $itemAlerts,
            ];
        }

        $net = $this->normalizarMonto($respuestaIa['neto'] ?? 0);
        $iva = $this->normalizarMonto($respuestaIa['iva'] ?? 0);
        $exempt = $this->normalizarMonto($respuestaIa['exento'] ?? 0);
        $detectedTotal = $this->normalizarMonto($respuestaIa['total'] ?? 0);
        $calculatedTotal = $net + $iva + $exempt;
        $itemsTotal = array_sum(array_map(static fn (array $item): int => (int) $item['total_normalizado'], $items));

        if ($detectedTotal <= 0 && $itemsTotal > 0) {
            $detectedTotal = $itemsTotal;
        }
        if ($calculatedTotal <= 0 && $itemsTotal > 0) {
            $calculatedTotal = $itemsTotal;
        }

        $expectedIva = (int) round($net * 0.19);
        if ($net > 0 && $iva > 0 && abs($iva - $expectedIva) > 2) {
            $alerts[] = $this->alert('IVA_NO_CUADRA', 'WARNING', 'IVA detectado no cuadra con neto 19%', [
                'iva_detectado' => $iva,
                'iva_calculado' => $expectedIva,
            ]);
        }

        if ($detectedTotal > 0 && abs($detectedTotal - $calculatedTotal) > 2) {
            $alerts[] = $this->alert('TOTAL_NO_CUADRA', 'ERROR', 'Total detectado no cuadra con neto, exento e IVA', [
                'total_detectado' => $detectedTotal,
                'total_calculado' => $calculatedTotal,
            ]);
        }

        $confidence = $this->normalizarConfianza($respuestaIa['confianza_global'] ?? null)
            ?? $this->calcularConfianzaGlobal($items, $rut !== null && $this->validarRut($rut), abs($detectedTotal - $calculatedTotal) <= 2);

        if ($confidence < 0.80) {
            $alerts[] = $this->alert('BAJA_CONFIANZA_GLOBAL', 'WARNING', 'Documento detectado con baja confianza', ['confianza' => $confidence]);
        }

        return [
            'tipo_documento' => $type,
            'proveedor_rut' => $rut,
            'proveedor_rut_valido' => $rut !== null ? $this->validarRut($rut) : null,
            'proveedor_nombre' => $this->textOrNull($respuestaIa['proveedor_nombre'] ?? null),
            'folio' => $folio,
            'fecha_documento' => $date,
            'neto' => $net,
            'iva' => $iva,
            'exento' => $exempt,
            'total' => $detectedTotal,
            'total_calculado' => $calculatedTotal,
            'diferencia_total' => $detectedTotal - $calculatedTotal,
            'confianza_global' => $confidence,
            'requiere_revision' => true,
            'items' => $items,
            'alertas' => $alerts,
        ];
    }

    public function normalizarRut(?string $rut): ?string
    {
        if ($rut === null || trim($rut) === '') {
            return null;
        }

        $clean = strtoupper(preg_replace('/[^0-9Kk]/', '', $rut) ?? '');
        if (strlen($clean) < 2) {
            return null;
        }

        return substr($clean, 0, -1) . '-' . substr($clean, -1);
    }

    public function validarRut(?string $rut): bool
    {
        $normalized = $this->normalizarRut($rut);
        if ($normalized === null) {
            return false;
        }

        [$body, $dv] = explode('-', $normalized);
        if ($body === '' || !ctype_digit($body)) {
            return false;
        }

        $sum = 0;
        $multiplier = 2;
        for ($index = strlen($body) - 1; $index >= 0; $index--) {
            $sum += (int) $body[$index] * $multiplier;
            $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
        }
        $rest = 11 - ($sum % 11);
        $expected = $rest === 11 ? '0' : ($rest === 10 ? 'K' : (string) $rest);

        return strtoupper($dv) === $expected;
    }

    public function normalizarMonto(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_float($value)) {
            return max(0, (int) round($value));
        }

        $text = trim((string) $value);
        $text = str_replace(['$', ' ', "\u{00A0}"], '', $text);
        $text = preg_replace('/[^\d,\.\-]/', '', $text) ?? '';
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = str_replace('.', '', $text);
            $text = preg_replace('/,\d+$/', '', $text) ?? $text;
        } elseif (str_contains($text, '.')) {
            $text = str_replace('.', '', $text);
        } elseif (str_contains($text, ',')) {
            $text = preg_replace('/,\d+$/', '', $text) ?? $text;
        }

        return max(0, (int) $text);
    }

    public function normalizarCantidad(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.000';
        }

        $text = str_replace(',', '.', trim((string) $value));
        $text = preg_replace('/[^0-9.\-]/', '', $text) ?? '0';
        $number = is_numeric($text) ? (float) $text : 0.0;

        return number_format(max(0, $number), 3, '.', '');
    }

    public function normalizarFecha(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $text = trim((string) $value);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $text);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    public function calcularTotales(array $items): array
    {
        return ['total_items' => array_sum(array_map(static fn (array $item): int => (int) ($item['total_normalizado'] ?? 0), $items))];
    }

    private function normalizarConfianza(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return round(max(0.0, min(1.0, (float) $value)), 4);
    }

    private function calcularConfianzaGlobal(array $items, bool $rutValido, bool $totalesCuadran): float
    {
        $itemConfidences = array_values(array_filter(
            array_map(static fn (array $item): ?float => $item['confianza'] ?? null, $items),
            static fn (?float $value): bool => $value !== null
        ));
        $averageItems = $itemConfidences === []
            ? 0.70
            : array_sum($itemConfidences) / count($itemConfidences);
        $score = ($averageItems * 0.5)
            + ($rutValido ? 0.2 : 0.0)
            + ($totalesCuadran ? 0.3 : 0.0);

        return round(max(0.0, min(1.0, $score)), 4);
    }

    private function textOrNull(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function alert(string $type, string $severity, string $message, array $metadata = []): array
    {
        return [
            'tipo_alerta' => $type,
            'severidad' => $severity,
            'mensaje' => $message,
            'metadata' => $metadata,
        ];
    }
}
