<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Config\Database;
use Mypos\Core\Response;
use Throwable;

/**
 * Endpoints públicos (sin autenticación). Solo lectura y datos no sensibles.
 * Usado por la página de verificación de boletas (mypos.cl/boleta).
 */
final class PublicController
{
    /**
     * Verificación pública de una boleta por RUT del emisor + folio.
     * Devuelve un resumen (emisor, folio, fecha, neto/IVA/total, estado).
     * No expone el detalle de productos ni datos del cliente.
     */
    public function boleta(): void
    {
        try {
            $rut = $this->normalizeRut((string) ($_GET['rut'] ?? ''));
            $folio = (int) ($_GET['folio'] ?? 0);
            $tipo = strtoupper(trim((string) ($_GET['tipo'] ?? 'BOLETA')));

            if ($rut === '' || $folio <= 0) {
                Response::error('Indique el RUT del emisor y el número de boleta.', null, 422);
                return;
            }

            $conn = Database::connection();
            $stmt = $conn->prepare(
                "SELECT e.razon_social, e.rut, e.giro, e.direccion, e.comuna, e.ciudad,
                        d.folio, d.tipo_documento, d.fecha_emision,
                        d.neto, d.exento, d.impuestos, d.total, d.estado
                 FROM documentos_emitidos d
                 INNER JOIN empresas e ON e.id = d.empresa_id
                 WHERE REPLACE(REPLACE(UPPER(e.rut), '.', ''), ' ', '') = :rut
                   AND d.folio = :folio
                   AND d.tipo_documento = :tipo
                 ORDER BY d.id DESC
                 LIMIT 1"
            );
            $stmt->execute(['rut' => $rut, 'folio' => $folio, 'tipo' => $tipo]);
            $row = $stmt->fetch();

            if (!is_array($row)) {
                Response::error('No se encontró una boleta con ese RUT y número.', null, 404);
                return;
            }

            $direccion = trim(implode(', ', array_filter([
                $row['direccion'] ?? null,
                $row['comuna'] ?? null,
                $row['ciudad'] ?? null,
            ])));

            Response::success([
                'emisor' => [
                    'razon_social' => $row['razon_social'],
                    'rut' => $row['rut'],
                    'giro' => $row['giro'],
                    'direccion' => $direccion !== '' ? $direccion : null,
                ],
                'tipo_documento' => $row['tipo_documento'],
                'folio' => (int) $row['folio'],
                'fecha_emision' => $row['fecha_emision'],
                'neto' => (int) $row['neto'],
                'exento' => (int) $row['exento'],
                'iva' => (int) $row['impuestos'],
                'total' => (int) $row['total'],
                'estado' => $row['estado'],
            ]);
        } catch (Throwable $exception) {
            error_log('[PublicController.boleta] ' . $exception->getMessage());
            Response::error('No se pudo consultar la boleta.', null, 500);
        }
    }

    private function normalizeRut(string $rut): string
    {
        return strtoupper(str_replace(['.', ' '], '', trim($rut)));
    }
}
