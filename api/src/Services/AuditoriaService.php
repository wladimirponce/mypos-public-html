<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\AuditoriaRepository;
use PDO;
use Throwable;

final class AuditoriaService
{
    private const SENSITIVE = [
        'password', 'password_hash', 'token', 'access_token', 'refresh_token',
        'authorization', 'secret', 'api_key', 'caf_xml', 'certificado',
        'private_key', 'clave', 'hash',
    ];
    private const SEVERITIES = ['INFO', 'WARNING', 'ERROR', 'CRITICAL'];
    private const RESULTS = ['OK', 'ERROR'];

    private AuditoriaRepository $repository;

    public function __construct(?AuditoriaRepository $repository = null)
    {
        $this->repository = $repository ?? new AuditoriaRepository(Database::connection());
    }

    public static function registrarEvento(array $evento, ?PDO $connection = null): void
    {
        $service = $connection === null
            ? new self()
            : new self(new AuditoriaRepository($connection));
        $service->registrar($evento);
    }

    public function registrar(array $evento): void
    {
        try {
            $severity = strtoupper((string) ($evento['severidad'] ?? 'INFO'));
            $result = strtoupper((string) ($evento['resultado'] ?? 'OK'));
            $data = [
                'empresa_id' => $evento['empresa_id'] ?? null,
                'sucursal_id' => $evento['sucursal_id'] ?? null,
                'usuario_id' => $evento['usuario_id'] ?? null,
                'modulo' => strtolower((string) ($evento['modulo'] ?? 'sistema')),
                'accion' => strtolower((string) ($evento['accion'] ?? 'evento')),
                'entidad' => strtolower((string) ($evento['entidad'] ?? ($evento['modulo'] ?? 'sistema'))),
                'entidad_id' => $evento['entidad_id'] ?? null,
                'descripcion' => $evento['descripcion'] ?? null,
                'datos_anteriores_json' => $this->jsonOrNull($evento['datos_anteriores'] ?? null),
                'datos_nuevos_json' => $this->jsonOrNull($evento['datos_nuevos'] ?? null),
                'metadata_json' => $this->jsonOrNull($evento['metadata'] ?? null),
                'ip' => $evento['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                'user_agent' => $evento['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
                'dispositivo_id' => $evento['dispositivo_id'] ?? null,
                'severidad' => in_array($severity, self::SEVERITIES, true) ? $severity : 'INFO',
                'resultado' => in_array($result, self::RESULTS, true) ? $result : 'OK',
            ];
            $this->repository->insert($data);
        } catch (Throwable $exception) {
            error_log('Auditoria fallida: ' . $exception->getMessage());
        }
    }

    public function listar(array $filters): array
    {
        $empresaId = (int) ($filters['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $this->validateFilters($filters);
        $limit = $this->limit($filters['limit'] ?? 50);
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        return [
            'items' => $this->repository->list($empresaId, $filters, $limit, $offset),
            'pagination' => ['limit' => $limit, 'offset' => $offset],
        ];
    }

    public function detalle(int $id, int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $event = $this->repository->find($empresaId, $id);
        if ($event === null) {
            throw new HttpException('Evento de auditoria no encontrado', 404);
        }

        $event['datos_anteriores'] = $this->decode($event['datos_anteriores_json'] ?? null);
        $event['datos_nuevos'] = $this->decode($event['datos_nuevos_json'] ?? null);
        $event['metadata'] = $this->decode($event['metadata_json'] ?? null);
        unset($event['datos_anteriores_json'], $event['datos_nuevos_json'], $event['metadata_json']);

        return $event;
    }

    private function jsonOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $sanitized = $this->sanitize($value);
        return json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    private function sanitize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            $keyText = strtolower((string) $key);
            $clean[$key] = $this->isSensitive($keyText) ? '[REDACTED]' : $this->sanitize($item);
        }
        return $clean;
    }

    private function isSensitive(string $key): bool
    {
        foreach (self::SENSITIVE as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }
        return false;
    }

    private function validateFilters(array $filters): void
    {
        foreach (['fecha_desde', 'fecha_hasta'] as $field) {
            if (!empty($filters[$field])) {
                $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $filters[$field]);
                if (!$date || $date->format('Y-m-d') !== (string) $filters[$field]) {
                    throw new HttpException('Formato de fecha invalido', 422);
                }
            }
        }
        if (!empty($filters['fecha_desde']) && !empty($filters['fecha_hasta']) && $filters['fecha_desde'] > $filters['fecha_hasta']) {
            throw new HttpException('fecha_desde no puede ser mayor que fecha_hasta', 422);
        }
        if (!empty($filters['severidad']) && !in_array(strtoupper((string) $filters['severidad']), self::SEVERITIES, true)) {
            throw new HttpException('severidad invalida', 422);
        }
        if (!empty($filters['resultado']) && !in_array(strtoupper((string) $filters['resultado']), self::RESULTS, true)) {
            throw new HttpException('resultado invalido', 422);
        }
    }

    private function limit(mixed $value): int
    {
        $limit = (int) $value;
        if ($limit < 1 || $limit > 100) {
            throw new HttpException('limit debe ser un entero entre 1 y 100', 422);
        }
        return $limit;
    }

    private function decode(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
