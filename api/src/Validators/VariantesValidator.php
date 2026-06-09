<?php

declare(strict_types=1);

namespace Mypos\Validators;

use Mypos\Contracts\VentaItemValidador;
use Mypos\Core\HttpException;

/**
 * Bloquea la venta de un producto padre que tiene variantes activas.
 *
 * Siempre esta en el chain, independientemente de capacidades.
 * El campo 'tiene_variantes' debe ser pre-calculado por VentaService
 * y agregado al array $producto antes de llamar al chain.
 */
final class VariantesValidator implements VentaItemValidador
{
    public function validar(array $producto, array $item, int $empresaId): void
    {
        if ((bool) ($producto['tiene_variantes'] ?? false)) {
            throw new HttpException(
                'El producto "' . $producto['nombre'] . '" tiene variantes. Seleccione una variante específica.',
                422
            );
        }
    }
}
