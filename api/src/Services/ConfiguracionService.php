<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\ConfiguracionRepository;

final class ConfiguracionService
{
    private const TIPOS_DOCUMENTO = ['BOLETA', 'FACTURA', 'GUIA_DESPACHO', 'NOTA_CREDITO'];

    private ConfiguracionRepository $repository;

    public function __construct(?ConfiguracionRepository $repository = null)
    {
        $this->repository = $repository ?? new ConfiguracionRepository(Database::connection());
    }

    public function empresa(int $empresaId): array
    {
        $this->validateEmpresa($empresaId);

        return $this->repository->empresaConfig($empresaId) ?? $this->empresaDefaults($empresaId);
    }

    public function actualizarEmpresa(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $previous = $this->empresa($empresaId);
        $data = [
            'empresa_id' => $empresaId,
            'rut_empresa' => $this->nullable($payload['rut_empresa'] ?? null),
            'razon_social' => $this->nullable($payload['razon_social'] ?? null),
            'nombre_fantasia' => $this->nullable($payload['nombre_fantasia'] ?? null),
            'giro' => $this->nullable($payload['giro'] ?? null),
            'email_contacto' => $this->nullable($payload['email_contacto'] ?? null),
            'telefono_contacto' => $this->nullable($payload['telefono_contacto'] ?? null),
            'direccion' => $this->nullable($payload['direccion'] ?? null),
            'comuna' => $this->nullable($payload['comuna'] ?? null),
            'ciudad' => $this->nullable($payload['ciudad'] ?? null),
            'region' => $this->nullable($payload['region'] ?? null),
            'logo_url' => $this->nullable($payload['logo_url'] ?? null),
            'sitio_web' => $this->nullable($payload['sitio_web'] ?? null),
            'metadata_json' => $this->encodeJson($payload['metadata'] ?? null),
        ];
        $this->repository->upsertEmpresaConfig($data);
        $current = $this->empresa($empresaId);
        $this->audit($empresaId, null, $userId, 'empresa.actualizar', 'empresa_configuracion', $previous, $current);

        return $current;
    }

    public function operacion(int $empresaId): array
    {
        $this->validateEmpresa($empresaId);

        return $this->normalizeOperacion($this->repository->operacionConfig($empresaId) ?? $this->operacionDefaults($empresaId));
    }

    public function actualizarOperacion(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $previous = $this->operacion($empresaId);
        $methodId = $this->optionalPositiveInt($payload['metodo_pago_default_id'] ?? null, 'metodo_pago_default_id');

        if ($methodId !== null && !$this->repository->metodoPagoActivo($methodId)) {
            throw new HttpException('Metodo de pago no existe o esta inactivo', 422);
        }

        $data = [
            'empresa_id' => $empresaId,
            'permitir_stock_negativo' => $this->bool($payload, 'permitir_stock_negativo'),
            'exigir_caja_abierta_para_vender' => $this->bool($payload, 'exigir_caja_abierta_para_vender'),
            'permitir_venta_sin_cliente' => $this->bool($payload, 'permitir_venta_sin_cliente'),
            'permitir_credito_clientes' => $this->bool($payload, 'permitir_credito_clientes'),
            'exigir_cliente_en_factura' => $this->bool($payload, 'exigir_cliente_en_factura'),
            'tipo_documento_default' => $this->tipoDocumento($payload['tipo_documento_default'] ?? 'BOLETA'),
            'metodo_pago_default_id' => $methodId,
            'alerta_stock_bajo_default' => $this->quantity($payload['alerta_stock_bajo_default'] ?? '0.000'),
            'alerta_folios_bajos_default' => $this->intAtLeast($payload['alerta_folios_bajos_default'] ?? 10, 'alerta_folios_bajos_default', 0),
            'dias_alerta_vencimiento_caf' => $this->intAtLeast($payload['dias_alerta_vencimiento_caf'] ?? 30, 'dias_alerta_vencimiento_caf', 0),
            'ia_documentos_habilitada' => $this->bool($payload, 'ia_documentos_habilitada'),
            'documentos_tributarios_habilitados' => $this->bool($payload, 'documentos_tributarios_habilitados'),
            'modo_offline_habilitado' => $this->bool($payload, 'modo_offline_habilitado'),
        ];
        $this->repository->upsertOperacionConfig($data);
        $current = $this->operacion($empresaId);
        $this->audit($empresaId, null, $userId, 'operacion.actualizar', 'empresa_configuracion_operativa', $previous, $current);

        return $current;
    }

    public function sucursal(int $empresaId, int $sucursalId): array
    {
        $this->validateSucursal($empresaId, $sucursalId);
        $config = $this->repository->sucursalConfig($empresaId, $sucursalId) ?? $this->sucursalDefaults($empresaId, $sucursalId);

        return [
            'sucursal_id' => $sucursalId,
            'configuracion_sucursal' => $this->normalizeSucursal($config),
            'configuracion_efectiva' => $this->efectiva($empresaId, $sucursalId),
        ];
    }

    public function actualizarSucursal(int $userId, int $sucursalId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $previous = $this->sucursal($empresaId, $sucursalId);
        $data = [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'direccion' => $this->nullable($payload['direccion'] ?? null),
            'comuna' => $this->nullable($payload['comuna'] ?? null),
            'ciudad' => $this->nullable($payload['ciudad'] ?? null),
            'telefono' => $this->nullable($payload['telefono'] ?? null),
            'email' => $this->nullable($payload['email'] ?? null),
            'activa' => array_key_exists('activa', $payload) ? $this->bool($payload, 'activa') : 1,
            'exigir_caja_abierta_para_vender' => $this->nullableBool($payload, 'exigir_caja_abierta_para_vender'),
            'permitir_stock_negativo' => $this->nullableBool($payload, 'permitir_stock_negativo'),
            'tipo_documento_default' => isset($payload['tipo_documento_default']) && $payload['tipo_documento_default'] !== null
                ? $this->tipoDocumento($payload['tipo_documento_default'])
                : null,
            'metadata_json' => $this->encodeJson($payload['metadata'] ?? null),
        ];
        $this->repository->upsertSucursalConfig($data);
        $current = $this->sucursal($empresaId, $sucursalId);
        $this->audit($empresaId, $sucursalId, $userId, 'sucursal.actualizar', 'sucursal_configuracion', $previous, $current);

        return $current;
    }

    public function efectiva(int $empresaId, ?int $sucursalId = null): array
    {
        $operation = $this->operacion($empresaId);
        $effective = $operation;
        $effective['empresa_id'] = $empresaId;
        $effective['sucursal_id'] = $sucursalId;

        if ($sucursalId !== null && $sucursalId > 0) {
            $this->validateSucursal($empresaId, $sucursalId);
            $branch = $this->repository->sucursalConfig($empresaId, $sucursalId);
            if ($branch !== null) {
                foreach (['exigir_caja_abierta_para_vender', 'permitir_stock_negativo', 'tipo_documento_default'] as $field) {
                    if ($branch[$field] !== null && $branch[$field] !== '') {
                        $effective[$field] = in_array($field, ['exigir_caja_abierta_para_vender', 'permitir_stock_negativo'], true)
                            ? (bool) (int) $branch[$field]
                            : (string) $branch[$field];
                    }
                }
            }
        }

        return $effective;
    }

    public function assertFeatureEnabled(int $empresaId, string $field, string $message): void
    {
        $config = $this->efectiva($empresaId);
        if (isset($config[$field]) && $config[$field] === false) {
            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'modulo' => 'configuracion',
                'accion' => 'bloqueo_operacion',
                'entidad' => 'empresa_configuracion_operativa',
                'descripcion' => $message,
                'metadata' => ['campo' => $field],
                'severidad' => 'WARNING',
                'resultado' => 'ERROR',
            ]);
            throw new HttpException($message, 422);
        }
    }

    private function validateEmpresa(int $empresaId): void
    {
        if ($empresaId <= 0 || !$this->repository->empresaExists($empresaId)) {
            throw new HttpException('Empresa no encontrada', 422);
        }
    }

    private function validateSucursal(int $empresaId, int $sucursalId): void
    {
        if ($empresaId <= 0 || $sucursalId <= 0 || !$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 422);
        }
    }

    private function normalizeOperacion(array $row): array
    {
        foreach ([
            'permitir_stock_negativo', 'exigir_caja_abierta_para_vender',
            'permitir_venta_sin_cliente', 'permitir_credito_clientes',
            'exigir_cliente_en_factura', 'ia_documentos_habilitada',
            'documentos_tributarios_habilitados', 'modo_offline_habilitado',
        ] as $field) {
            $row[$field] = (bool) (int) $row[$field];
        }
        $row['alerta_stock_bajo_default'] = number_format((float) $row['alerta_stock_bajo_default'], 3, '.', '');
        $row['alerta_folios_bajos_default'] = (int) $row['alerta_folios_bajos_default'];
        $row['dias_alerta_vencimiento_caf'] = (int) $row['dias_alerta_vencimiento_caf'];
        $row['metodo_pago_default_id'] = $row['metodo_pago_default_id'] !== null ? (int) $row['metodo_pago_default_id'] : null;

        return $row;
    }

    private function normalizeSucursal(array $row): array
    {
        foreach (['exigir_caja_abierta_para_vender', 'permitir_stock_negativo'] as $field) {
            $row[$field] = $row[$field] === null ? null : (bool) (int) $row[$field];
        }

        return $row;
    }

    private function empresaDefaults(int $empresaId): array
    {
        return ['empresa_id' => $empresaId];
    }

    private function operacionDefaults(int $empresaId): array
    {
        return [
            'empresa_id' => $empresaId,
            'permitir_stock_negativo' => 0,
            'exigir_caja_abierta_para_vender' => 0,
            'permitir_venta_sin_cliente' => 1,
            'permitir_credito_clientes' => 1,
            'exigir_cliente_en_factura' => 1,
            'tipo_documento_default' => 'BOLETA',
            'metodo_pago_default_id' => null,
            'alerta_stock_bajo_default' => '0.000',
            'alerta_folios_bajos_default' => 10,
            'dias_alerta_vencimiento_caf' => 30,
            'ia_documentos_habilitada' => 1,
            'documentos_tributarios_habilitados' => 1,
            'modo_offline_habilitado' => 0,
        ];
    }

    private function sucursalDefaults(int $empresaId, int $sucursalId): array
    {
        return [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'direccion' => null,
            'comuna' => null,
            'ciudad' => null,
            'telefono' => null,
            'email' => null,
            'activa' => 1,
            'exigir_caja_abierta_para_vender' => null,
            'permitir_stock_negativo' => null,
            'tipo_documento_default' => null,
            'metadata_json' => null,
        ];
    }

    private function audit(int $empresaId, ?int $sucursalId, int $userId, string $action, string $entity, array $previous, array $current): void
    {
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'usuario_id' => $userId,
            'modulo' => 'configuracion',
            'accion' => $action,
            'entidad' => $entity,
            'descripcion' => 'Configuracion actualizada',
            'datos_anteriores' => $previous,
            'datos_nuevos' => $current,
        ]);
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);
        if ($value <= 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return $value;
    }

    private function optionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $integer = (int) $value;
        if ($integer <= 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} debe ser mayor que cero"]]);
        }

        return $integer;
    }

    private function bool(array $data, string $field): int
    {
        if (!array_key_exists($field, $data)) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return filter_var($data[$field], FILTER_VALIDATE_BOOL) ? 1 : 0;
    }

    private function nullableBool(array $data, string $field): ?int
    {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            return null;
        }

        return filter_var($data[$field], FILTER_VALIDATE_BOOL) ? 1 : 0;
    }

    private function tipoDocumento(mixed $value): string
    {
        $type = strtoupper(trim((string) $value));
        if (!in_array($type, self::TIPOS_DOCUMENTO, true)) {
            throw new HttpException('Tipo de documento invalido', 422);
        }

        return $type;
    }

    private function quantity(mixed $value): string
    {
        if (!is_numeric($value) || (float) $value < 0) {
            throw new HttpException('La alerta de stock debe ser numerica >= 0', 422);
        }

        return number_format(round((float) $value, 3), 3, '.', '');
    }

    private function intAtLeast(mixed $value, string $field, int $minimum): int
    {
        if (!is_numeric($value) || (int) $value < $minimum) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} debe ser mayor o igual a {$minimum}"]]);
        }

        return (int) $value;
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function encodeJson(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }
}

