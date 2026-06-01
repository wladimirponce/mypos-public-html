<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\FarmaciaRepository;

/**
 * Servicio de la vertical farmacia.
 *
 * Encapsula la lógica de negocio para búsquedas y consultas farmacéuticas.
 * Delega toda la persistencia a FarmaciaRepository, que trabaja sobre el
 * sistema de atributos personalizados (Sprint 13 — decisión: sin tablas propias).
 */
final class FarmaciaService
{
    private FarmaciaRepository $repository;

    public function __construct(?FarmaciaRepository $repository = null)
    {
        $this->repository = $repository ?? new FarmaciaRepository(Database::connection());
    }

    /**
     * Busca productos por principio activo.
     *
     * @param int    $empresaId ID de la empresa
     * @param string $termino   Texto parcial a buscar (mínimo 2 caracteres)
     * @param int    $limite    Máximo de resultados (1-200, default 50)
     */
    public function buscarPorPrincipioActivo(int $empresaId, string $termino, int $limite = 50): array
    {
        $this->validarEmpresa($empresaId);

        $termino = trim($termino);

        if (strlen($termino) < 2) {
            throw new HttpException('El término de búsqueda debe tener al menos 2 caracteres', 422);
        }

        $resultados = $this->repository->buscarPorPrincipioActivo($empresaId, $termino, $limite);

        return [
            'termino'    => $termino,
            'total'      => count($resultados),
            'productos'  => $resultados,
        ];
    }

    /**
     * Retorna la ficha farmacéutica de un producto.
     *
     * @param int $empresaId   Empresa propietaria del producto
     * @param int $productoId  ID del producto
     */
    public function fichaFarmaceutica(int $empresaId, int $productoId): array
    {
        $this->validarEmpresa($empresaId);

        if ($productoId <= 0) {
            throw new HttpException('producto_id inválido', 422);
        }

        $ficha = $this->repository->fichaFarmaceutica($empresaId, $productoId);

        // Si ningún atributo farmacéutico tiene valor, indicamos que el
        // producto no tiene ficha cargada todavía.
        $tieneData = array_filter(
            $ficha,
            static fn (array $attr): bool => $attr['valor'] !== null
        );

        return [
            'producto_id'        => $productoId,
            'tiene_ficha'        => $tieneData !== [],
            'ficha_farmaceutica' => $ficha,
        ];
    }

    /**
     * Listado de productos que requieren receta médica.
     *
     * @param int $empresaId Empresa
     * @param int $limite    Máximo de resultados (default 200)
     */
    public function productosConReceta(int $empresaId, int $limite = 200): array
    {
        $this->validarEmpresa($empresaId);

        $productos = $this->repository->productosConReceta($empresaId, $limite);

        return [
            'total'    => count($productos),
            'productos' => $productos,
        ];
    }

    /**
     * Retorna los atributos farmacéuticos disponibles para la empresa,
     * con metadatos (tipo, filtrable, visible). Útil para el frontend.
     *
     * @param int $empresaId Empresa
     */
    public function atributosFarmacia(int $empresaId): array
    {
        $this->validarEmpresa($empresaId);

        return [
            'atributos' => $this->repository->atributosFarmacia($empresaId),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function validarEmpresa(int $empresaId): void
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id inválido', 422);
        }
    }
}
