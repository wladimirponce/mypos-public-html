<?php

declare(strict_types=1);

namespace Mypos\Validators;

use Mypos\Contracts\VentaItemValidador;

/**
 * Encadena validadores de items de venta y los ejecuta en orden.
 *
 * VentaService construye el chain una vez por llamada a prepareItems()
 * segun las capacidades activas de la empresa, de modo que el numero de
 * validadores varia sin tocar el loop de items.
 *
 * Ejemplo de uso:
 *   $chain = (new VentaValidatorChain())
 *       ->add(new VariantesValidator())
 *       ->add(new LoteRequeridoValidator());
 *
 *   foreach ($items as $item) {
 *       $chain->validar($producto, $item, $empresaId);
 *   }
 */
final class VentaValidatorChain
{
    /** @var VentaItemValidador[] */
    private array $validators = [];

    public function add(VentaItemValidador $validator): self
    {
        $this->validators[] = $validator;

        return $this;
    }

    /**
     * Ejecuta todos los validadores sobre el item.
     * El primero que falla lanza HttpException y corta la cadena.
     *
     * @throws \Mypos\Core\HttpException
     */
    public function validar(array $producto, array $item, int $empresaId): void
    {
        foreach ($this->validators as $validator) {
            $validator->validar($producto, $item, $empresaId);
        }
    }

    public function isEmpty(): bool
    {
        return $this->validators === [];
    }
}
