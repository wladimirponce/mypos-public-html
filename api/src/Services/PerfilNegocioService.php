<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\PerfilNegocioRepository;

/**
 * Gestiona perfiles de negocio y capacidades por empresa.
 *
 * Un "perfil" es un conjunto de capacidades + atributos de producto
 * estándar que se activan al configurar el tipo de negocio.
 *
 * Empresas sin perfil usan la base genérica de productos más cualquier
 * atributo que el administrador agregue manualmente.
 */
final class PerfilNegocioService
{
    private PerfilNegocioRepository $repository;

    private const PERFILES_VALIDOS = [
        // 'FARMACIA' retirado de MyPOS: lo cubre el sistema especializado de
        // Agentika. Las empresas que ya lo tengan activo conservan sus
        // capacidades; simplemente deja de ofrecerse como perfil nuevo.
        'MINIMARKET',
        'BOTILLERIA',
        'ALMACEN',
        'FERRETERIA',
        'PANADERIA_PASTELERIA',
        'CARNICERIA',
        'VERDULERIA',
        'ROPA_CALZADO',
        'DISTRIBUIDORA_MAYORISTA',
        'GENERICO',
    ];

    /**
     * Rubros iniciales por perfil.
     *
     * Una empresa nueva nacia con la tabla `rubros` vacia y, como no habia
     * ninguna pantalla que creara rubros, el catalogo entero quedaba en "Sin
     * rubro" de forma permanente. Sembrar un punto de partida razonable evita
     * ese arranque en frio; el cliente los edita, desactiva o amplia desde
     * Productos > Rubros.
     */
    private const RUBROS_POR_PERFIL = [
        'MINIMARKET' => ['Abarrotes', 'Bebidas', 'Lacteos', 'Snacks', 'Limpieza', 'Congelados'],
        'BOTILLERIA' => ['Cervezas', 'Vinos', 'Destilados', 'Bebidas y jugos', 'Snacks', 'Hielo y accesorios'],
        'ALMACEN' => ['Abarrotes', 'Bebidas', 'Lacteos', 'Limpieza', 'Golosinas'],
        'FERRETERIA' => ['Herramientas', 'Fijaciones', 'Electricidad', 'Gasfiteria', 'Pinturas', 'Construccion'],
        'PANADERIA_PASTELERIA' => ['Panaderia', 'Pasteleria', 'Insumos', 'Bebidas', 'Cafeteria'],
        'CARNICERIA' => ['Vacuno', 'Cerdo', 'Pollo', 'Cecinas', 'Congelados'],
        'VERDULERIA' => ['Verduras', 'Frutas', 'Hierbas', 'Huevos', 'Abarrotes'],
        'ROPA_CALZADO' => ['Damas', 'Varones', 'Ninos', 'Calzado', 'Accesorios'],
        'DISTRIBUIDORA_MAYORISTA' => ['Abarrotes', 'Bebidas', 'Limpieza', 'Cuidado personal', 'Otros'],
        'GENERICO' => ['General', 'Servicios'],
    ];

    public function __construct(?PerfilNegocioRepository $repository = null)
    {
        $this->repository = $repository
            ?? new PerfilNegocioRepository(Database::connection());
    }

    /**
     * Lista los perfiles disponibles en el sistema.
     */
    public function perfilesDisponibles(): array
    {
        return [
            'perfiles' => $this->repository->perfilesDisponibles(),
        ];
    }

    /**
     * Activa un perfil para una empresa.
     *
     * - Establece perfil_negocio en empresa_configuracion_operativa.
     * - Activa las capacidades asociadas al perfil.
     * - Siembra en producto_atributos_definicion los atributos estándar del
     *   perfil (INSERT IGNORE — no sobrescribe atributos ya creados).
     *
     * @param int    $empresaId ID de la empresa
     * @param string $perfil    Código de uno de los perfiles disponibles
     * @param int    $usuarioId Usuario que realiza la acción
     */
    public function activarPerfil(int $empresaId, string $perfil, int $usuarioId): array
    {
        $this->validar($empresaId, $perfil);

        $perfil = strtoupper(trim($perfil));
        $db     = Database::connection();

        $db->beginTransaction();
        try {
            // 1. Registrar el perfil en la configuración operativa
            $this->repository->setPerfilNegocio($empresaId, $perfil);

            // 2. Activar capacidades del perfil
            $capacidades     = $this->repository->findCapacidadesByPerfil($perfil);
            $codigosActivados = [];
            foreach ($capacidades as $cap) {
                $this->repository->upsertCapacidad($empresaId, (int) $cap['id'], $usuarioId);
                $codigosActivados[] = $cap['codigo'];
            }

            // 3. Sembrar atributos estándar del perfil en la empresa
            $plantillas        = $this->repository->plantillasByPerfil($perfil);
            $atributosSembrados = 0;
            foreach ($plantillas as $plantilla) {
                $atributoId = $this->repository->insertAtributoDefinicion($empresaId, $plantilla);

                if ($atributoId > 0 && $plantilla['opciones_json'] !== null) {
                    $opciones = json_decode($plantilla['opciones_json'], true) ?? [];
                    foreach ($opciones as $opcion) {
                        $this->repository->insertAtributoOpcion($empresaId, $atributoId, $opcion);
                    }
                }
                $atributosSembrados++;
            }

            // 4. Sembrar los rubros iniciales del perfil
            $rubrosSembrados = 0;
            foreach (self::RUBROS_POR_PERFIL[$perfil] ?? [] as $nombreRubro) {
                $this->repository->insertRubroIfMissing($empresaId, $nombreRubro);
                $rubrosSembrados++;
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        AuditoriaService::registrarEvento([
            'empresa_id'  => $empresaId,
            'usuario_id'  => $usuarioId,
            'modulo'      => 'perfil_negocio',
            'accion'      => 'activar_perfil',
            'entidad'     => 'empresa_configuracion_operativa',
            'entidad_id'  => $empresaId,
            'descripcion' => "Perfil $perfil activado para empresa $empresaId",
            'metadata'    => [
                'perfil'             => $perfil,
                'capacidades'        => $codigosActivados,
                'atributos_sembrados' => $atributosSembrados,
                'rubros_sembrados'   => $rubrosSembrados,
            ],
        ]);

        return [
            'perfil'             => $perfil,
            'capacidades'        => $codigosActivados,
            'atributos_sembrados' => $atributosSembrados,
            'rubros_sembrados'   => $rubrosSembrados,
        ];
    }

    /**
     * Retorna las capacidades activas de una empresa como mapa código → bool.
     * Incluye todas las capacidades del sistema; las inactivas quedan en false.
     */
    public function capacidadesActivas(int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id inválido', 422);
        }

        $todas   = $this->repository->findAllCapacidades();
        $activas = $this->repository->capacidadesActivas($empresaId);

        $mapa = [];
        foreach ($todas as $c) {
            $mapa[$c['codigo']] = false;
        }
        foreach ($activas as $c) {
            if ((bool) $c['activo']) {
                $mapa[$c['codigo']] = true;
            }
        }

        return [
            'perfil_negocio' => $this->repository->getPerfilNegocio($empresaId),
            'capacidades'    => $mapa,
        ];
    }

    /**
     * Verifica si una empresa tiene activa una capacidad específica.
     */
    public function tieneCapacidad(int $empresaId, string $codigo): bool
    {
        if ($empresaId <= 0) {
            return false;
        }
        $activas = $this->repository->capacidadesActivas($empresaId);
        foreach ($activas as $c) {
            if ($c['codigo'] === $codigo && (bool) $c['activo']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Activa o desactiva una capacidad individual para la empresa.
     *
     * A diferencia de activarPerfil(), este método opera sobre una
     * capacidad específica sin tocar el resto del perfil ni los atributos.
     *
     * @param int    $empresaId ID de la empresa
     * @param string $codigo    Código de la capacidad (ej: 'MERMA_OPERATIVA')
     * @param bool   $activo    true para activar, false para desactivar
     * @param int    $usuarioId Usuario que realiza la acción
     */
    public function toggleCapacidad(int $empresaId, string $codigo, bool $activo, int $usuarioId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id inválido', 422);
        }

        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            throw new HttpException('El código de capacidad no puede estar vacío', 422);
        }

        $cap = $this->repository->findCapacidadByCode($codigo);
        if ($cap === null) {
            throw new HttpException("Capacidad '$codigo' no encontrada o no disponible en el sistema", 404);
        }

        $this->repository->toggleCapacidad($empresaId, (int) $cap['id'], $activo, $usuarioId);

        AuditoriaService::registrarEvento([
            'empresa_id'  => $empresaId,
            'usuario_id'  => $usuarioId,
            'modulo'      => 'perfil_negocio',
            'accion'      => $activo ? 'activar_capacidad' : 'desactivar_capacidad',
            'entidad'     => 'empresa_capacidades',
            'entidad_id'  => $empresaId,
            'descripcion' => ($activo ? 'Capacidad activada' : 'Capacidad desactivada') . ": $codigo para empresa $empresaId",
            'metadata'    => ['codigo' => $codigo, 'activo' => $activo],
        ]);

        return [
            'codigo'  => $codigo,
            'nombre'  => $cap['nombre'],
            'activo'  => $activo,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validar(int $empresaId, string $perfil): void
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id inválido', 422);
        }

        $perfil = strtoupper(trim($perfil));
        if (!in_array($perfil, self::PERFILES_VALIDOS, true)) {
            throw new HttpException(
                'Perfil inválido. Valores permitidos: ' . implode(', ', self::PERFILES_VALIDOS),
                422
            );
        }
    }
}
