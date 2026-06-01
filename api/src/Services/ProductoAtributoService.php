<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Repositories\ProductoAtributoRepository;
use PDOException;

final class ProductoAtributoService
{
    private const TYPES = ['TEXTO', 'NUMERO', 'DECIMAL', 'FECHA', 'BOOLEANO', 'OPCION', 'MULTIOPCION'];

    private ProductoAtributoRepository $repository;

    public function __construct()
    {
        $this->repository = new ProductoAtributoRepository(Database::connection());
    }

    public function listDefinitions(int $userId, int $empresaId, bool $includeInactive = false): array
    {
        $this->tenant($userId, $empresaId);

        return ['atributos' => $this->repository->listDefinitions($empresaId, $includeInactive)];
    }

    public function createDefinition(int $userId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->tenant($userId, $empresaId);
        $this->validateDefinition($data);

        return ['id' => $this->guard(fn (): int => $this->repository->createDefinition($data))];
    }

    public function updateDefinition(int $userId, int $id, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->tenant($userId, $empresaId);
        $this->validateDefinition($data);
        $this->notFoundUnless($this->repository->updateDefinition($id, $empresaId, $data));

        return ['id' => $id];
    }

    public function deleteDefinition(int $userId, int $id, int $empresaId): array
    {
        $this->tenant($userId, $empresaId);
        $this->notFoundUnless($this->repository->deactivateDefinition($id, $empresaId));

        return ['id' => $id, 'activo' => 0];
    }

    public function valuesForProduct(int $userId, int $productoId, int $empresaId): array
    {
        $this->validateProductAccess($userId, $productoId, $empresaId);

        return ['atributos' => $this->repository->valuesForProduct($productoId, $empresaId)];
    }

    public function updateValuesForProduct(int $userId, int $productoId, array $data): array
    {
        $empresaId = $this->empresaId($data);
        $this->validateProductAccess($userId, $productoId, $empresaId);

        $values = $data['valores'] ?? null;

        if (!is_array($values)) {
            throw new HttpException('Error de validacion', 422, ['valores' => ['El campo valores debe ser un arreglo']]);
        }

        $attributeIds = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }

            $attributeIds[] = (int) ($value['atributo_id'] ?? 0);
        }

        $definitions = $this->repository->definitionsByIds($empresaId, array_values(array_unique(array_filter($attributeIds))));
        $normalized = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new HttpException('Error de validacion', 422, ['valores' => ['Cada valor debe ser un objeto']]);
            }

            $attributeId = (int) ($value['atributo_id'] ?? 0);

            if ($attributeId <= 0 || !isset($definitions[$attributeId])) {
                throw new HttpException('Atributo no encontrado', 422, ['atributo_id' => ['El atributo no existe para la empresa']]);
            }

            if ((int) $definitions[$attributeId]['activo'] !== 1) {
                throw new HttpException('Atributo inactivo', 422, ['atributo_id' => ['El atributo esta inactivo']]);
            }

            $normalized[$attributeId] = $this->normalizeValue((string) $definitions[$attributeId]['tipo_dato'], $value['valor'] ?? null);
        }

        $this->repository->replaceValues($productoId, $empresaId, $normalized);

        return ['producto_id' => $productoId, 'actualizados' => count($normalized)];
    }

    private function validateDefinition(array $data): void
    {
        $this->requireText($data, 'codigo');
        $this->requireText($data, 'nombre');
        $this->requireText($data, 'tipo_dato');

        if (!preg_match('/^[a-z0-9_]+$/', (string) $data['codigo'])) {
            throw new HttpException('Error de validacion', 422, ['codigo' => ['El codigo debe usar minusculas, numeros y guion bajo']]);
        }

        if (!in_array($data['tipo_dato'], self::TYPES, true)) {
            throw new HttpException('Error de validacion', 422, ['tipo_dato' => ['Tipo de dato invalido']]);
        }

        if (in_array($data['tipo_dato'], ['OPCION', 'MULTIOPCION'], true)) {
            $options = $data['opciones'] ?? [];

            if (!is_array($options) || count($options) === 0) {
                throw new HttpException('Error de validacion', 422, ['opciones' => ['Los atributos de opciones requieren al menos una opcion']]);
            }
        }
    }

    private function normalizeValue(string $type, mixed $value): array
    {
        return match ($type) {
            'TEXTO', 'OPCION' => ['valor_texto' => $value === null ? null : trim((string) $value)],
            'NUMERO' => $this->normalizeInteger($value),
            'DECIMAL' => $this->normalizeDecimal($value),
            'FECHA' => $this->normalizeDate($value),
            'BOOLEANO' => ['valor_booleano' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ? 1 : 0],
            'MULTIOPCION' => ['valor_json' => json_encode(is_array($value) ? array_values($value) : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            default => [],
        };
    }

    private function normalizeInteger(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['valor_numero' => null];
        }

        if (!is_numeric($value)) {
            throw new HttpException('Error de validacion', 422, ['valor' => ['El valor debe ser numerico']]);
        }

        return ['valor_numero' => (int) $value];
    }

    private function normalizeDecimal(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['valor_decimal' => null];
        }

        if (!is_numeric($value)) {
            throw new HttpException('Error de validacion', 422, ['valor' => ['El valor debe ser decimal']]);
        }

        return ['valor_decimal' => (string) $value];
    }

    private function normalizeDate(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['valor_fecha' => null];
        }

        $text = (string) $value;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            throw new HttpException('Error de validacion', 422, ['valor' => ['La fecha debe usar formato YYYY-MM-DD']]);
        }

        return ['valor_fecha' => $text];
    }

    private function validateProductAccess(int $userId, int $productoId, int $empresaId): void
    {
        $this->tenant($userId, $empresaId);

        if (!$this->repository->productExists($productoId, $empresaId)) {
            throw new HttpException('Producto no encontrado', 404);
        }
    }

    private function empresaId(array $data): int
    {
        $empresaId = (int) ($data['empresa_id'] ?? 0);

        if ($empresaId <= 0) {
            throw new HttpException('Error de validacion', 422, ['empresa_id' => ['La empresa_id es obligatoria']]);
        }

        return $empresaId;
    }

    private function requireText(array $data, string $field): void
    {
        if (trim((string) ($data[$field] ?? '')) === '') {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }
    }

    private function tenant(int $userId, int $empresaId): void
    {
        (new TenantMiddleware())->handle($userId, $empresaId);
    }

    private function notFoundUnless(bool $condition): void
    {
        if (!$condition) {
            throw new HttpException('Registro no encontrado', 404);
        }
    }

    private function guard(callable $callback): int
    {
        try {
            return $callback();
        } catch (PDOException $exception) {
            throw new HttpException('No se pudo guardar el atributo', 422, ['database' => [$exception->getMessage()]]);
        }
    }
}
