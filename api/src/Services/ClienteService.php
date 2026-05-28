<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\ClienteRepository;

final class ClienteService
{
    private const TIPOS = ['PERSONA', 'EMPRESA'];

    public function __construct(private ?ClienteRepository $repository = null)
    {
        $this->repository ??= new ClienteRepository(Database::connection());
    }

    public function crear(array $payload, ?int $userId = null): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $data = $this->data($payload);
        $this->validateRut($empresaId, $data['rut']);
        $id = $this->repository->create(['empresa_id' => $empresaId] + $data);
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'clientes',
            'accion' => 'crear',
            'entidad' => 'clientes',
            'entidad_id' => $id,
            'descripcion' => 'Cliente creado',
            'datos_nuevos' => ['nombre' => $data['nombre'], 'rut' => $data['rut'], 'permite_credito' => $data['permite_credito']],
        ]);

        return ['cliente_id' => $id] + $this->ver($id, $empresaId);
    }

    public function listar(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $limit = $this->limit($filters['limit'] ?? 20);
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        return $this->repository->list($empresaId, $filters, $limit, $offset);
    }

    public function ver(int $id, int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $client = $this->repository->find($empresaId, $id);
        if ($client === null) {
            throw new HttpException('Cliente no encontrado', 404);
        }

        return $client;
    }

    public function actualizar(int $id, array $payload, ?int $userId = null): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $previous = $this->ver($id, $empresaId);
        $data = $this->data($payload);
        $this->validateRut($empresaId, $data['rut'], $id);
        $this->repository->update($empresaId, $id, $data);
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'clientes',
            'accion' => 'actualizar',
            'entidad' => 'clientes',
            'entidad_id' => $id,
            'descripcion' => 'Cliente actualizado',
            'datos_anteriores' => ['nombre' => $previous['nombre'] ?? null, 'rut' => $previous['rut'] ?? null],
            'datos_nuevos' => ['nombre' => $data['nombre'], 'rut' => $data['rut'], 'permite_credito' => $data['permite_credito']],
        ]);

        return $this->ver($id, $empresaId);
    }

    public function eliminar(int $id, int $empresaId, ?int $userId = null): array
    {
        $previous = $this->ver($id, $empresaId);
        $this->repository->softDelete($empresaId, $id);
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'clientes',
            'accion' => 'eliminar',
            'entidad' => 'clientes',
            'entidad_id' => $id,
            'descripcion' => 'Cliente eliminado logicamente',
            'datos_anteriores' => ['activo' => (int) ($previous['activo'] ?? 1), 'deleted_at' => $previous['deleted_at'] ?? null],
            'datos_nuevos' => ['activo' => 0, 'deleted' => true],
        ]);

        return ['cliente_id' => $id, 'activo' => 0, 'deleted' => true];
    }

    private function data(array $payload): array
    {
        $name = trim((string) ($payload['nombre'] ?? ''));
        if ($name === '') {
            throw new HttpException('nombre obligatorio', 422);
        }
        $type = strtoupper(trim((string) ($payload['tipo_cliente'] ?? 'PERSONA')));
        if (!in_array($type, self::TIPOS, true)) {
            throw new HttpException('tipo_cliente invalido', 422);
        }
        $credit = filter_var($payload['permite_credito'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0;
        $limit = (int) ($payload['limite_credito'] ?? 0);
        if ($limit < 0) {
            throw new HttpException('limite_credito debe ser mayor o igual a 0', 422);
        }

        return [
            'tipo_cliente' => $type,
            'rut' => $this->nullable($payload['rut'] ?? null),
            'nombre' => $name,
            'razon_social' => $this->nullable($payload['razon_social'] ?? null) ?? $name,
            'nombre_fantasia' => $name,
            'giro' => $this->nullable($payload['giro'] ?? null),
            'email' => $this->nullable($payload['email'] ?? null),
            'telefono' => $this->nullable($payload['telefono'] ?? null),
            'direccion' => $this->nullable($payload['direccion'] ?? null),
            'comuna' => $this->nullable($payload['comuna'] ?? null),
            'ciudad' => $this->nullable($payload['ciudad'] ?? null),
            'activo' => filter_var($payload['activo'] ?? true, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'credito_habilitado' => $credit,
            'permite_credito' => $credit,
            'limite_credito' => $limit,
            'observacion' => $this->nullable($payload['observacion'] ?? null),
        ];
    }

    private function validateRut(int $empresaId, ?string $rut, ?int $excludeId = null): void
    {
        if ($rut !== null && $this->repository->rutExists($empresaId, $rut, $excludeId)) {
            throw new HttpException('El RUT ya existe para esta empresa', 422);
        }
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);
        if ($value <= 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }
        return $value;
    }

    private function limit(mixed $value): int
    {
        $limit = (int) $value;
        if ($limit < 1 || $limit > 100) {
            throw new HttpException('limit debe ser un entero entre 1 y 100', 422);
        }
        return $limit;
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
