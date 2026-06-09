<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\VarianteRepository;

/**
 * Gestiona variantes de producto (talla / color / sabor).
 *
 * Cada variante es un producto hijo completo en la tabla `productos`.
 * StockService y VentaService operan sobre el producto_id del hijo
 * sin necesitar cambios — la venta siempre usa el hijo.
 *
 * El padre actúa como plantilla y no se puede vender directamente
 * cuando tiene variantes activas (VentaService lo valida).
 */
final class VarianteService
{
    private VarianteRepository $repository;

    public function __construct(?VarianteRepository $repository = null)
    {
        $this->repository = $repository ?? new VarianteRepository(Database::connection());
    }

    /**
     * Lista los ejes de variante disponibles para una empresa con sus opciones.
     * Usado por el POS para construir el selector antes de agregar al carrito.
     */
    public function ejesDisponibles(int $userId, int $empresaId, int $productoPadreId): array
    {
        $this->validarAcceso($empresaId, $productoPadreId);

        return [
            'producto_padre_id' => $productoPadreId,
            'ejes'              => $this->repository->ejesConOpciones($empresaId, $productoPadreId),
        ];
    }

    /**
     * Lista todas las variantes de un producto padre con atributos y stock.
     */
    public function listarVariantes(int $userId, int $empresaId, int $productoPadreId): array
    {
        $this->validarAcceso($empresaId, $productoPadreId);

        return [
            'producto_padre_id' => $productoPadreId,
            'variantes'         => $this->repository->listarPorPadre($empresaId, $productoPadreId),
        ];
    }

    /**
     * Genera variantes para un producto padre a partir de una lista de combinaciones.
     *
     * Cada combinación define:
     *   - codigo_variante: string único dentro del padre (ej: "38-NEGRO")
     *   - atributos: array de { atributo_id, opcion_id?, valor_texto? }
     *   - precio_venta: opcional (si no se envía, hereda el del padre)
     *
     * Idempotente: combinaciones con codigo_variante ya existente se omiten.
     *
     * @param int   $userId
     * @param int   $empresaId
     * @param int   $productoPadreId
     * @param array $combinaciones
     */
    public function generarVariantes(int $userId, int $empresaId, int $productoPadreId, array $combinaciones): array
    {
        $padre = $this->repository->findPadre($empresaId, $productoPadreId);
        if ($padre === null) {
            throw new HttpException('Producto padre no encontrado', 404);
        }

        if ($combinaciones === []) {
            throw new HttpException('Debe enviar al menos una combinación', 422);
        }

        $db = Database::connection();
        $db->beginTransaction();

        $creadas  = [];
        $omitidas = [];

        try {
            foreach ($combinaciones as $idx => $combo) {
                $codigoVariante = trim((string) ($combo['codigo_variante'] ?? ''));
                if ($codigoVariante === '') {
                    throw new HttpException("Combinación $idx: codigo_variante es obligatorio", 422);
                }

                // Ya existe — idempotente
                if ($this->repository->codigoVarianteExiste($empresaId, $productoPadreId, $codigoVariante)) {
                    $omitidas[] = $codigoVariante;
                    continue;
                }

                $atributos = $combo['atributos'] ?? [];
                if (!is_array($atributos) || $atributos === []) {
                    throw new HttpException("Combinación $idx: atributos es obligatorio", 422);
                }

                // Validar ejes
                $this->validarAtributos($empresaId, $atributos, $idx);

                $precioOverride = isset($combo['precio_venta']) && (int) $combo['precio_venta'] > 0
                    ? (int) $combo['precio_venta']
                    : null;

                // 1. Crear producto hijo
                $hijoId = $this->repository->insertProductoHijo($empresaId, $padre, $codigoVariante, $precioOverride);

                // 2. Crear fila en producto_variantes
                $varianteId = $this->repository->insertVariante($empresaId, $hijoId, $productoPadreId, $codigoVariante);

                // 3. Registrar atributos de la variante
                foreach ($atributos as $attr) {
                    $this->repository->insertVarianteAtributo(
                        $empresaId,
                        $varianteId,
                        (int) $attr['atributo_id'],
                        isset($attr['opcion_id']) ? (int) $attr['opcion_id'] : null,
                        isset($attr['valor_texto']) ? (string) $attr['valor_texto'] : null
                    );
                }

                $creadas[] = ['codigo_variante' => $codigoVariante, 'producto_id' => $hijoId];
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        AuditoriaService::registrarEvento([
            'empresa_id'  => $empresaId,
            'usuario_id'  => $userId,
            'modulo'      => 'variantes',
            'accion'      => 'generar_variantes',
            'entidad'     => 'productos',
            'entidad_id'  => $productoPadreId,
            'descripcion' => 'Variantes generadas para producto ' . $productoPadreId,
            'metadata'    => ['creadas' => count($creadas), 'omitidas' => count($omitidas)],
        ]);

        return [
            'producto_padre_id' => $productoPadreId,
            'creadas'           => $creadas,
            'omitidas'          => $omitidas,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validarAcceso(int $empresaId, int $productoPadreId): void
    {
        if ($empresaId <= 0 || $productoPadreId <= 0) {
            throw new HttpException('Parámetros inválidos', 422);
        }
    }

    private function validarAtributos(int $empresaId, array $atributos, int $comboIdx): void
    {
        foreach ($atributos as $i => $attr) {
            $atributoId = isset($attr['atributo_id']) ? (int) $attr['atributo_id'] : 0;
            if ($atributoId <= 0) {
                throw new HttpException("Combinación $comboIdx, atributo $i: atributo_id inválido", 422);
            }

            $def = $this->repository->findAtributo($empresaId, $atributoId);
            if ($def === null) {
                throw new HttpException("Combinación $comboIdx: atributo_id $atributoId no existe", 422);
            }
            if (!(bool) $def['es_eje_variante']) {
                throw new HttpException(
                    "Combinación $comboIdx: atributo '{$def['codigo']}' no es un eje de variante",
                    422
                );
            }

            // Si el atributo es tipo OPCION, se exige opcion_id válida
            if ($def['tipo_dato'] === 'OPCION') {
                $opcionId = isset($attr['opcion_id']) ? (int) $attr['opcion_id'] : 0;
                if ($opcionId <= 0 || !$this->repository->findOpcion($empresaId, $atributoId, $opcionId)) {
                    throw new HttpException(
                        "Combinación $comboIdx: opcion_id inválida para atributo '{$def['codigo']}'",
                        422
                    );
                }
            }
        }
    }
}
