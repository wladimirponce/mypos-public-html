<?php

declare(strict_types=1);

namespace Mypos\Contracts;

/**
 * Contrato para validadores de items de venta.
 *
 * Cada implementacion encapsula una regla de negocio opt-in que se activa
 * cuando la empresa tiene habilitada la capacidad correspondiente.
 * El chain recorre los validadores en orden; el primero que falla lanza
 * HttpException(422) y corta la cadena.
 *
 * Convencion de datos:
 *  - $producto: fila completa del producto (tal como la devuelve VentaRepository).
 *    Puede incluir campos computados agregados por VentaService antes de llamar
 *    al chain (ej: 'tiene_variantes').
 *  - $item: elemento raw del array 'items' enviado por el frontend.
 *  - $empresaId: necesario para lookups adicionales si el validador los requiere.
 */
interface VentaItemValidador
{
    /**
     * @throws \Mypos\Core\HttpException codigo 422 si el item no es valido.
     */
    public function validar(array $producto, array $item, int $empresaId): void;
}
