<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\DocumentoIaRevisionRepository;

final class DocumentoIaRevisionService
{
    private const TIPOS_COMPRA = ['FACTURA_COMPRA', 'GUIA_DESPACHO_COMPRA', 'BOLETA_COMPRA'];

    public function __construct(
        private readonly DocumentoIaNormalizerService $normalizer = new DocumentoIaNormalizerService(),
        private ?DocumentoIaRevisionRepository $repository = null
    ) {
        $this->repository ??= new DocumentoIaRevisionRepository(Database::connection());
    }

    public function revision(int $documentId, int $empresaId): array
    {
        $document = $this->requireDocument($empresaId, $documentId);

        return [
            'documento_ia' => $this->decodeJsonFields($document),
            'detalles' => array_map(fn (array $detail): array => $this->decodeJsonFields($detail), $this->repository->details($empresaId, $documentId)),
            'alertas' => array_map(fn (array $alert): array => $this->decodeJsonFields($alert), $this->repository->alerts($empresaId, $documentId)),
            'totales_alertas' => $this->repository->alertCounts($empresaId, $documentId),
        ];
    }

    public function actualizarCabecera(int $documentId, array $payload, array $usuario): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $document = $this->requireDocument($empresaId, $documentId);
        $providerId = isset($payload['proveedor_id']) && (int) $payload['proveedor_id'] > 0 ? (int) $payload['proveedor_id'] : null;
        if ($providerId !== null && $this->repository->providerExists($empresaId, $providerId) === null) {
            throw new HttpException('Proveedor no encontrado', 422);
        }

        $type = strtoupper((string) ($payload['tipo_documento_detectado'] ?? $document['tipo_documento_detectado'] ?? 'FACTURA_COMPRA'));
        if (!in_array($type, self::TIPOS_COMPRA, true)) {
            throw new HttpException('Tipo de documento invalido', 422);
        }

        $net = $this->normalizer->normalizarMonto($payload['neto_detectado'] ?? $document['neto_detectado'] ?? 0);
        $iva = $this->normalizer->normalizarMonto($payload['iva_detectado'] ?? $document['iva_detectado'] ?? 0);
        $exempt = $this->normalizer->normalizarMonto($payload['exento_detectado'] ?? $document['exento_detectado'] ?? 0);
        $total = $this->normalizer->normalizarMonto($payload['total_detectado'] ?? $document['total_detectado'] ?? 0);
        $calculated = $net + $iva + $exempt;

        $new = [
            'proveedor_id' => $providerId,
            'proveedor_rut_detectado' => $this->normalizer->normalizarRut($payload['proveedor_rut_detectado'] ?? $document['proveedor_rut_detectado'] ?? null),
            'proveedor_nombre_detectado' => $this->nullableString($payload['proveedor_nombre_detectado'] ?? $document['proveedor_nombre_detectado'] ?? null),
            'folio_detectado' => $this->nullableString($payload['folio_detectado'] ?? $document['folio_detectado'] ?? null),
            'fecha_documento_detectada' => $this->normalizer->normalizarFecha($payload['fecha_documento_detectada'] ?? $document['fecha_documento_detectada'] ?? null),
            'tipo_documento_detectado' => $type,
            'neto_detectado' => $net,
            'iva_detectado' => $iva,
            'exento_detectado' => $exempt,
            'total_detectado' => $total,
            'total_calculado' => $calculated,
            'diferencia_total' => $total - $calculated,
        ];

        $this->repository->updateHeader($empresaId, $documentId, $new);
        $this->recordCorrections($empresaId, $documentId, null, (int) $usuario['usuario_id'], $document, $new, $payload['motivo'] ?? null);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'usuario_id' => (int) $usuario['usuario_id'],
            'modulo' => 'documentos_ia',
            'accion' => 'documentos_ia.correccion_cabecera',
            'entidad' => 'documentos_ia',
            'entidad_id' => $documentId,
            'descripcion' => 'Cabecera de documento IA corregida',
            'datos_anteriores' => $this->onlyHeader($document),
            'datos_nuevos' => $new,
        ]);

        return ['documento_ia_id' => $documentId, 'estado_revision' => 'OBSERVADO'];
    }

    public function actualizarDetalle(int $detailId, array $payload, array $usuario): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $detail = $this->repository->findDetail($empresaId, $detailId);
        if ($detail === null) {
            throw new HttpException('Detalle no encontrado', 404);
        }
        $document = $this->requireDocument($empresaId, (int) $detail['documento_ia_id']);

        $productId = isset($payload['producto_id']) && (int) $payload['producto_id'] > 0 ? (int) $payload['producto_id'] : null;
        if ($productId !== null && $this->repository->productExists($empresaId, $productId) === null) {
            throw new HttpException('Producto no encontrado', 422);
        }

        $quantity = $this->normalizer->normalizarCantidad($payload['cantidad_normalizada'] ?? $detail['cantidad_normalizada'] ?? 0);
        if ((float) $quantity <= 0) {
            throw new HttpException('La cantidad debe ser mayor a 0', 422);
        }
        $cost = $this->normalizer->normalizarMonto($payload['costo_unitario_normalizado'] ?? $detail['costo_unitario_normalizado'] ?? 0);
        $total = $this->normalizer->normalizarMonto($payload['total_normalizado'] ?? round(((float) $quantity) * $cost));
        if ($total <= 0) {
            $total = (int) round(((float) $quantity) * $cost);
        }

        $method = $productId !== null && (int) ($detail['producto_id'] ?? 0) !== $productId
            ? 'MANUAL'
            : (string) ($detail['metodo_match'] ?? 'MANUAL');

        $new = [
            'producto_id' => $productId,
            'codigo_detectado' => $this->nullableString($payload['codigo_detectado'] ?? $detail['codigo_detectado'] ?? null),
            'codigo_barra_detectado' => $this->nullableString($payload['codigo_barra_detectado'] ?? $detail['codigo_barra_detectado'] ?? null),
            'nombre_detectado' => $this->nullableString($payload['nombre_detectado'] ?? $detail['nombre_detectado'] ?? null) ?? 'Producto corregido',
            'cantidad_normalizada' => $quantity,
            'costo_unitario_normalizado' => $cost,
            'total_normalizado' => $total,
            'metodo_match' => $method === 'SIN_MATCH' && $productId !== null ? 'MANUAL' : $method,
            'requiere_revision' => $productId === null ? 1 : 0,
            'confirmado' => $productId === null ? 0 : 1,
        ];

        $this->repository->updateDetail($empresaId, $detailId, $new);
        $this->repository->updateTotalsFromDetails($empresaId, (int) $detail['documento_ia_id']);
        $this->recordCorrections($empresaId, (int) $detail['documento_ia_id'], $detailId, (int) $usuario['usuario_id'], $detail, $new, $payload['motivo'] ?? null);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'usuario_id' => (int) $usuario['usuario_id'],
            'modulo' => 'documentos_ia',
            'accion' => 'documentos_ia.correccion_detalle',
            'entidad' => 'documentos_ia_detalles',
            'entidad_id' => $detailId,
            'descripcion' => 'Detalle de documento IA corregido',
            'datos_anteriores' => $detail,
            'datos_nuevos' => $new,
        ]);

        return [
            'detalle_id' => $detailId,
            'documento_ia_id' => (int) $detail['documento_ia_id'],
            'metodo_match' => $new['metodo_match'],
        ];
    }

    public function aprobarDocumento(int $documentId, array $payload, array $usuario): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $document = $this->requireDocument($empresaId, $documentId);

        if ($this->repository->unresolvedErrorCount($empresaId, $documentId) > 0) {
            $this->auditApprovalBlock($empresaId, $document, (int) $usuario['usuario_id'], 'alertas_error');
            throw new HttpException('No se puede aprobar con alertas ERROR no resueltas', 422);
        }
        if (($document['tipo_documento_detectado'] ?? '') === 'FACTURA_COMPRA' && empty($document['proveedor_id'])) {
            $this->auditApprovalBlock($empresaId, $document, (int) $usuario['usuario_id'], 'proveedor_requerido');
            throw new HttpException('No se puede aprobar una factura sin proveedor vinculado', 422);
        }
        if ($this->repository->detailsWithoutProductCount($empresaId, $documentId) > 0) {
            $this->auditApprovalBlock($empresaId, $document, (int) $usuario['usuario_id'], 'detalle_sin_producto');
            throw new HttpException('No se puede aprobar con detalles sin producto vinculado', 422);
        }
        if (abs((int) ($document['diferencia_total'] ?? 0)) > 2) {
            $this->auditApprovalBlock($empresaId, $document, (int) $usuario['usuario_id'], 'total_no_cuadra');
            throw new HttpException('No se puede aprobar con total descuadrado', 422);
        }

        $this->repository->approveDocument($empresaId, $documentId, (int) $usuario['usuario_id']);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'usuario_id' => (int) $usuario['usuario_id'],
            'modulo' => 'documentos_ia',
            'accion' => 'documentos_ia.aprobar',
            'entidad' => 'documentos_ia',
            'entidad_id' => $documentId,
            'descripcion' => 'Documento IA aprobado para generar compra',
        ]);

        return ['documento_ia_id' => $documentId, 'estado_revision' => 'APROBADO'];
    }

    public function resolverAlerta(int $alertId, array $payload, array $usuario): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $alert = $this->repository->findAlert($empresaId, $alertId);
        if ($alert === null) {
            throw new HttpException('Alerta no encontrada', 404);
        }

        if (!$this->repository->resolveAlert($empresaId, $alertId, (int) $usuario['usuario_id'])) {
            throw new HttpException('La alerta ya estaba resuelta', 422);
        }

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => (int) $usuario['usuario_id'],
            'modulo' => 'documentos_ia',
            'accion' => 'documentos_ia.alerta_resuelta',
            'entidad' => 'documentos_ia_alertas',
            'entidad_id' => $alertId,
            'descripcion' => 'Alerta de documento IA resuelta',
            'metadata' => ['motivo' => $payload['motivo'] ?? null, 'tipo_alerta' => $alert['tipo_alerta']],
        ]);

        return ['alerta_id' => $alertId, 'resuelta' => true];
    }

    public function vincularProveedor(int $documentId, int $providerId, array $payload, array $usuario): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $document = $this->requireDocument($empresaId, $documentId);
        $provider = $this->repository->providerExists($empresaId, $providerId);
        if ($provider === null) {
            throw new HttpException('Proveedor no encontrado', 422);
        }

        $this->repository->updateDocumentProvider($empresaId, $documentId, $provider);
        $this->repository->insertCorrection([
            'empresa_id' => $empresaId,
            'documento_ia_id' => $documentId,
            'documento_ia_detalle_id' => null,
            'usuario_id' => (int) $usuario['usuario_id'],
            'campo' => 'proveedor_id',
            'valor_anterior' => $document['proveedor_id'] !== null ? (string) $document['proveedor_id'] : null,
            'valor_nuevo' => (string) $providerId,
            'motivo' => $payload['motivo'] ?? null,
        ]);
        $this->repository->resolveAlertsByType($empresaId, $documentId, 'PROVEEDOR_NO_ENCONTRADO', (int) $usuario['usuario_id']);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'usuario_id' => (int) $usuario['usuario_id'],
            'modulo' => 'documentos_ia',
            'accion' => 'documentos_ia.vincular_proveedor',
            'entidad' => 'documentos_ia',
            'entidad_id' => $documentId,
            'descripcion' => 'Proveedor vinculado manualmente a documento IA',
            'metadata' => ['proveedor_id' => $providerId],
        ]);

        return ['documento_ia_id' => $documentId, 'proveedor_id' => $providerId];
    }

    public function alertas(int $documentId, int $empresaId, ?bool $resolved): array
    {
        $this->requireDocument($empresaId, $documentId);

        return ['alertas' => array_map(fn (array $alert): array => $this->decodeJsonFields($alert), $this->repository->alerts($empresaId, $documentId, $resolved))];
    }

    private function requireDocument(int $empresaId, int $documentId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        $document = $this->repository->findDocument($empresaId, $documentId);
        if ($document === null) {
            throw new HttpException('Documento IA no encontrado', 404);
        }

        return $document;
    }

    private function recordCorrections(int $empresaId, int $documentId, ?int $detailId, int $userId, array $previous, array $new, mixed $reason): void
    {
        foreach ($new as $field => $value) {
            $old = $previous[$field] ?? null;
            if ((string) $old === (string) $value) {
                continue;
            }

            $this->repository->insertCorrection([
                'empresa_id' => $empresaId,
                'documento_ia_id' => $documentId,
                'documento_ia_detalle_id' => $detailId,
                'usuario_id' => $userId,
                'campo' => $field,
                'valor_anterior' => $old !== null ? (string) $old : null,
                'valor_nuevo' => $value !== null ? (string) $value : null,
                'motivo' => $reason !== null ? (string) $reason : null,
            ]);
        }
    }

    private function auditApprovalBlock(int $empresaId, array $document, int $userId, string $reason): void
    {
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'usuario_id' => $userId,
            'modulo' => 'documentos_ia',
            'accion' => 'documentos_ia.bloqueo_aprobacion',
            'entidad' => 'documentos_ia',
            'entidad_id' => (int) $document['id'],
            'descripcion' => 'Aprobacion de documento IA bloqueada',
            'metadata' => ['motivo' => $reason],
            'severidad' => 'WARNING',
            'resultado' => 'ERROR',
        ]);
    }

    private function onlyHeader(array $document): array
    {
        return [
            'proveedor_id' => $document['proveedor_id'] ?? null,
            'proveedor_rut_detectado' => $document['proveedor_rut_detectado'] ?? null,
            'proveedor_nombre_detectado' => $document['proveedor_nombre_detectado'] ?? null,
            'folio_detectado' => $document['folio_detectado'] ?? null,
            'fecha_documento_detectada' => $document['fecha_documento_detectada'] ?? null,
            'tipo_documento_detectado' => $document['tipo_documento_detectado'] ?? null,
            'neto_detectado' => $document['neto_detectado'] ?? null,
            'iva_detectado' => $document['iva_detectado'] ?? null,
            'exento_detectado' => $document['exento_detectado'] ?? null,
            'total_detectado' => $document['total_detectado'] ?? null,
        ];
    }

    private function decodeJsonFields(array $row): array
    {
        foreach (['resumen_alertas_json', 'alertas_json', 'metadata_json'] as $field) {
            if (isset($row[$field]) && $row[$field] !== null && $row[$field] !== '') {
                $decoded = json_decode((string) $row[$field], true);
                $row[str_replace('_json', '', $field)] = is_array($decoded) ? $decoded : null;
            }
        }

        return $row;
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);
        if ($value <= 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
