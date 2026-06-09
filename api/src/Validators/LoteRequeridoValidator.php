<?php

declare(strict_types=1);

namespace Mypos\Validators;

use Mypos\Contracts\VentaItemValidador;
use Mypos\Core\HttpException;

/**
 * Bloquea la venta si el producto tiene 'requiere_lote = 1' y el item
 * no incluye un lote_id valido.
 *
 * Se agrega al chain solo cuando la empresa tiene la capacidad
 * LOTES_VENCIMIENTO activa (ver VentaService::buildValidatorChain).
 *
 * El campo 'requiere_lote' debe estar presente en $producto (devuelto
 * por VentaRepository::findProductById / findProductByCode).
 */
final class LoteRequeridoValidator implements VentaItemValidador
{
    public function validar(array $producto, array $item, int $empresaId): void
    {
        $requiereLote = (int) ($producto['requiere_lote'] ?? 0) === 1;

        if (!$requiereLote) {
            return;
        }

        $loteProvisto = isset($item['lote_id']) && (int) $item['lote_id'] > 0;

        if (!$loteProvisto) {
            throw new HttpException(
                'El producto "' . $producto['nombre'] . '" requiere indicar un lote. '
                . 'Use GET /api/v1/lotes/fefo para obtener la asignacion FEFO recomendada.',
                422
            );
        }
    }
}
