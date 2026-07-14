<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\PromoLinkRepository;
use Mypos\Support\AppConfig;
use Mypos\Support\PlanCatalog;

/**
 * Links de precio especial para el registro.
 *
 * El dueño de plataforma crea un link con un precio mensual custom para un plan.
 * El link es reutilizable sin límite (con expiración opcional) y el precio es
 * recurrente: al registrarse se estampa en la suscripción de la empresa y se
 * cobra cada mes hasta que se cambie a mano.
 */
final class PromoLinkService
{
    private PromoLinkRepository $repository;

    public function __construct(?PromoLinkRepository $repository = null)
    {
        $this->repository = $repository ?? new PromoLinkRepository(Database::connection());
    }

    /** Crea un link. Devuelve el registro enriquecido con la URL de registro. */
    public function crear(array $payload, int $creadoPor): array
    {
        $planId = PlanCatalog::normalize((string) ($payload['plan_id'] ?? 'mypos-start'));
        if (!PlanCatalog::isValid($planId)) {
            throw new HttpException('Plan no válido', 422);
        }

        $precio = (int) ($payload['precio_clp'] ?? 0);
        if ($precio <= 0) {
            throw new HttpException('El precio especial debe ser mayor a 0', 422, ['precio_clp' => ['requerido']]);
        }

        $codigo = strtoupper(trim((string) ($payload['codigo'] ?? '')));
        if ($codigo === '') {
            $codigo = $this->generarCodigoUnico();
        } elseif (!preg_match('/^[A-Z0-9\-]{3,60}$/', $codigo)) {
            throw new HttpException('El código solo admite letras, números y guiones (3-60)', 422, ['codigo' => ['formato']]);
        } elseif ($this->repository->codigoExists($codigo)) {
            throw new HttpException('Ya existe un link con ese código', 409, ['codigo' => ['duplicado']]);
        }

        $expiracion = trim((string) ($payload['fecha_expiracion'] ?? ''));
        $expiracion = $expiracion !== '' ? $expiracion : null;

        $id = $this->repository->create([
            'codigo'           => $codigo,
            'descripcion'      => trim((string) ($payload['descripcion'] ?? '')) ?: null,
            'plan_id'          => $planId,
            'precio_clp'       => $precio,
            'moneda'           => 'CLP',
            'activo'           => 1,
            'fecha_expiracion' => $expiracion,
            'creado_por'       => $creadoPor,
        ]);

        return $this->enriquecer($this->repository->findById($id) ?? []);
    }

    /** @return array{links: array<int, array<string, mixed>>} */
    public function listar(): array
    {
        $links = array_map(fn (array $row) => $this->enriquecer($row), $this->repository->all());

        return ['links' => $links];
    }

    public function cambiarEstado(int $id, bool $activo): array
    {
        if ($this->repository->findById($id) === null) {
            throw new HttpException('Link no encontrado', 404);
        }
        $this->repository->setActivo($id, $activo);

        return $this->enriquecer($this->repository->findById($id) ?? []);
    }

    /**
     * Resuelve un código para el registro (endpoint público). Lanza si el link
     * no existe / está inactivo / expiró.
     */
    public function resolver(string $codigo): array
    {
        $link = $this->linkVigenteOrFail($codigo);
        $plan = PlanCatalog::get($link['plan_id']);

        return [
            'valido'             => true,
            'codigo'             => $link['codigo'],
            'descripcion'        => $link['descripcion'],
            'plan_id'            => $link['plan_id'],
            'plan_nombre'        => $plan['nombre'],
            'precio_clp'         => (int) $link['precio_clp'],
            'precio_normal_clp'  => (int) $plan['price_clp'],
            'moneda'             => $link['moneda'],
        ];
    }

    /**
     * Valida un código y devuelve el link vigente (fila cruda) para el registro.
     * Devuelve null si el código está vacío (registro normal sin promo). No
     * incrementa usos: eso se hace con marcarUso() tras confirmar el registro.
     */
    public function validarParaRegistro(?string $codigo): ?array
    {
        $codigo = strtoupper(trim((string) $codigo));
        if ($codigo === '') {
            return null;
        }

        return $this->linkVigenteOrFail($codigo);
    }

    /** Contabiliza un registro efectivo hecho con este link. */
    public function marcarUso(int $linkId): void
    {
        $this->repository->incrementUsos($linkId);
    }

    private function linkVigenteOrFail(string $codigo): array
    {
        $codigo = strtoupper(trim($codigo));
        $link = $this->repository->findByCodigo($codigo);

        if ($link === null) {
            throw new HttpException('El link de promoción no existe', 404, ['codigo' => ['no_existe']]);
        }
        if ((int) $link['activo'] !== 1) {
            throw new HttpException('El link de promoción está desactivado', 410, ['codigo' => ['inactivo']]);
        }
        if (!empty($link['fecha_expiracion']) && $link['fecha_expiracion'] < date('Y-m-d')) {
            throw new HttpException('El link de promoción expiró', 410, ['codigo' => ['expirado']]);
        }

        return $link;
    }

    private function enriquecer(array $row): array
    {
        if ($row === []) {
            return $row;
        }
        $plan = PlanCatalog::get((string) $row['plan_id']);
        $row['precio_clp']        = (int) $row['precio_clp'];
        $row['precio_normal_clp'] = (int) $plan['price_clp'];
        $row['plan_nombre']       = $plan['nombre'];
        $row['activo']            = (int) $row['activo'];
        $row['usos']              = (int) $row['usos'];
        $row['url']               = $this->registerUrl((string) $row['plan_id'], (string) $row['codigo']);

        return $row;
    }

    private function registerUrl(string $planId, string $codigo): string
    {
        $base = rtrim(AppConfig::appUrl(), '/');
        return $base . '/register?plan=' . rawurlencode($planId) . '&promo=' . rawurlencode($codigo);
    }

    private function generarCodigoUnico(): string
    {
        do {
            $codigo = strtoupper(bin2hex(random_bytes(4))); // 8 chars hex
        } while ($this->repository->codigoExists($codigo));

        return $codigo;
    }
}
