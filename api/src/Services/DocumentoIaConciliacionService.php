<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\DocumentoIaRevisionRepository;
use PDO;
use Throwable;

final class DocumentoIaConciliacionService
{
    public function __construct(
        private readonly DocumentoIaNormalizerService $normalizer = new DocumentoIaNormalizerService(),
        private ?DocumentoIaRevisionRepository $repository = null
    ) {
        $this->repository ??= new DocumentoIaRevisionRepository(Database::connection());
    }

    public function normalizarYConciliar(int $documentId, int $empresaId, int $userId): array
    {
        $document = $this->repository->findDocument($empresaId, $documentId);
        if ($document === null) {
            throw new HttpException('Documento IA no encontrado', 404);
        }

        $response = $this->decodeJson($document['respuesta_json'] ?? null);
        if ($response === null) {
            throw new HttpException('El documento IA no tiene respuesta_json valida para normalizar', 422);
        }

        $normalized = $this->normalizer->normalizarDocumento($response, $empresaId);
        $alerts = $normalized['alertas'];
        $provider = $this->conciliarProveedor($normalized, $empresaId);
        $providerId = $provider['proveedor_id'];
        $providerConfidence = $provider['confianza'];
        $alerts = array_merge($alerts, $provider['alertas']);

        $details = [];
        foreach ($normalized['items'] as $item) {
            $match = $this->conciliarItem($item, $empresaId);
            $alerts = array_merge($alerts, $match['alertas']);
            $itemAlerts = array_merge($item['alertas'], $match['alertas_detalle']);

            $details[] = [
                'empresa_id' => $empresaId,
                'documento_ia_id' => $documentId,
                'producto_id' => $match['producto_id'],
                'linea' => $item['linea'],
                'codigo_detectado' => $item['codigo_detectado'],
                'codigo_barra_detectado' => $item['codigo_barra_detectado'],
                'nombre_detectado' => $item['nombre_detectado'],
                'cantidad_detectada' => $item['cantidad_detectada'],
                'costo_unitario_detectado' => $item['costo_unitario_detectado'],
                'total_detectado' => $item['total_detectado'],
                'cantidad_normalizada' => $item['cantidad_normalizada'],
                'costo_unitario_normalizado' => $item['costo_unitario_normalizado'],
                'total_normalizado' => $item['total_normalizado'],
                'cantidad' => $item['cantidad_normalizada'],
                'costo_unitario' => $item['costo_unitario_normalizado'],
                'total' => $item['total_normalizado'],
                'confianza' => $item['confianza'],
                'metodo_match' => $match['metodo_match'],
                'requiere_revision' => $match['producto_id'] === null || $itemAlerts !== [] ? 1 : 0,
                'alertas_json' => $this->jsonOrNull($itemAlerts),
                'confirmado' => $match['producto_id'] !== null && $itemAlerts === [] ? 1 : 0,
            ];
        }

        if ($this->repository->possibleDuplicate(
            $empresaId,
            $normalized['proveedor_rut'],
            $normalized['folio'],
            $normalized['tipo_documento'],
            $documentId
        )) {
            $alerts[] = $this->alert('DOCUMENTO_DUPLICADO_POSIBLE', 'WARNING', 'Existe un documento posiblemente duplicado para proveedor y folio');
        }

        $summary = $this->summarize($alerts);
        $stateRevision = $summary['error'] > 0 || $summary['warning'] > 0 ? 'OBSERVADO' : 'REVISADO';

        $connection = $this->repository->connection();
        try {
            $connection->beginTransaction();
            $this->repository->updateNormalizedDocument($empresaId, $documentId, [
                'proveedor_id' => $providerId,
                'proveedor_rut_detectado' => $normalized['proveedor_rut'],
                'proveedor_nombre_detectado' => $provider['nombre'] ?? $normalized['proveedor_nombre'],
                'proveedor_confianza' => $providerConfidence,
                'folio_detectado' => $normalized['folio'],
                'fecha_documento_detectada' => $normalized['fecha_documento'],
                'tipo_documento_detectado' => $normalized['tipo_documento'],
                'neto_detectado' => $normalized['neto'],
                'iva_detectado' => $normalized['iva'],
                'exento_detectado' => $normalized['exento'],
                'total_detectado' => $normalized['total'],
                'total_calculado' => $normalized['total_calculado'],
                'diferencia_total' => $normalized['diferencia_total'],
                'confianza_global' => $normalized['confianza_global'],
                'requiere_revision' => $stateRevision === 'REVISADO' ? 0 : 1,
                'estado_revision' => $stateRevision,
                'resumen_alertas_json' => $this->encodeJson($summary),
            ]);
            $this->repository->replaceDetails($empresaId, $documentId, $details);
            $this->repository->closeOpenAlerts($empresaId, $documentId, $userId);
            foreach ($alerts as $alert) {
                $this->repository->insertAlert([
                    'empresa_id' => $empresaId,
                    'documento_ia_id' => $documentId,
                    'documento_ia_detalle_id' => $alert['documento_ia_detalle_id'] ?? null,
                    'tipo_alerta' => $alert['tipo_alerta'],
                    'severidad' => $alert['severidad'],
                    'mensaje' => $alert['mensaje'],
                    'metadata_json' => $this->jsonOrNull($alert['metadata'] ?? []),
                ]);
            }
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'usuario_id' => $userId,
            'modulo' => 'documentos_ia',
            'accion' => 'documentos_ia.normalizar',
            'entidad' => 'documentos_ia',
            'entidad_id' => $documentId,
            'descripcion' => 'Documento IA normalizado y conciliado',
            'metadata' => [
                'estado_revision' => $stateRevision,
                'alertas' => $summary,
                'items' => count($details),
            ],
        ]);

        if ($alerts !== []) {
            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) $document['sucursal_id'],
                'usuario_id' => $userId,
                'modulo' => 'documentos_ia',
                'accion' => 'documentos_ia.alerta_generada',
                'entidad' => 'documentos_ia',
                'entidad_id' => $documentId,
                'descripcion' => 'Alertas generadas en conciliacion de documento IA',
                'metadata' => ['alertas' => $summary],
                'severidad' => $summary['error'] > 0 ? 'WARNING' : 'INFO',
            ]);
        }

        return [
            'documento_ia_id' => $documentId,
            'estado_revision' => $stateRevision,
            'requiere_revision' => $stateRevision !== 'REVISADO',
            'proveedor_id' => $providerId,
            'total_detectado' => $normalized['total'],
            'total_calculado' => $normalized['total_calculado'],
            'diferencia_total' => $normalized['diferencia_total'],
            'alertas' => $summary,
        ];
    }

    public function conciliarProveedor(array $document, int $empresaId): array
    {
        $alerts = [];
        $provider = null;
        $rut = $document['proveedor_rut'] ?? null;
        $name = $document['proveedor_nombre'] ?? null;

        if ($rut !== null && ($document['proveedor_rut_valido'] ?? false)) {
            $provider = $this->repository->providerByRut($empresaId, $rut);
        }

        if ($provider === null && $name !== null) {
            $provider = $this->repository->providerByName($empresaId, $name);
        }

        if ($provider === null) {
            $alerts[] = $this->alert('PROVEEDOR_NO_ENCONTRADO', 'ERROR', 'Proveedor no encontrado para el documento');
        }

        return [
            'proveedor_id' => $provider !== null ? (int) $provider['id'] : null,
            'nombre' => $provider !== null ? ($provider['razon_social'] ?: $provider['nombre']) : $name,
            'confianza' => $provider !== null ? 1.0000 : 0.0000,
            'alertas' => $alerts,
        ];
    }

    public function conciliarItem(array $item, int $empresaId): array
    {
        $product = null;
        $method = 'SIN_MATCH';
        $alerts = [];
        $detailAlerts = $item['alertas'] ?? [];

        if (!empty($item['codigo_detectado'])) {
            $product = $this->repository->productByCode($empresaId, (string) $item['codigo_detectado']);
            $method = $product !== null ? 'CODIGO' : 'SIN_MATCH';
        }

        if ($product === null && !empty($item['codigo_barra_detectado'])) {
            $product = $this->repository->productByBarcode($empresaId, (string) $item['codigo_barra_detectado']);
            $method = $product !== null ? 'CODIGO_BARRA' : 'SIN_MATCH';
        }

        if ($product === null && !empty($item['nombre_detectado'])) {
            $product = $this->repository->productByName($empresaId, (string) $item['nombre_detectado']);
            $method = $product !== null ? 'NOMBRE' : 'SIN_MATCH';
        }

        if ($product === null) {
            $alert = $this->alert('ITEM_SIN_PRODUCTO', 'ERROR', 'Item detectado sin producto vinculado', [
                'codigo_detectado' => $item['codigo_detectado'],
                'nombre_detectado' => $item['nombre_detectado'],
            ]);
            $alerts[] = $alert;
            $detailAlerts[] = $alert;
        }

        return [
            'producto_id' => $product !== null ? (int) $product['id'] : null,
            'metodo_match' => $method,
            'alertas' => $alerts,
            'alertas_detalle' => $detailAlerts,
        ];
    }

    public function generarAlertas(int $documentoIaId, array $resultado): void
    {
        unset($documentoIaId, $resultado);
    }

    public function detectarDuplicadoProveedorFolio(int $empresaId, ?string $rut, ?string $folio, ?string $fecha): bool
    {
        unset($fecha);

        return $this->repository->possibleDuplicate($empresaId, $rut, $folio, null, 0);
    }

    private function summarize(array $alerts): array
    {
        $summary = ['total' => count($alerts), 'error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($alerts as $alert) {
            $severity = strtolower((string) ($alert['severidad'] ?? 'info'));
            if (isset($summary[$severity])) {
                $summary[$severity]++;
            }
        }

        return $summary;
    }

    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function jsonOrNull(array $payload): ?string
    {
        if ($payload === []) {
            return null;
        }

        return $this->encodeJson($payload);
    }

    private function alert(string $type, string $severity, string $message, array $metadata = []): array
    {
        return [
            'tipo_alerta' => $type,
            'severidad' => $severity,
            'mensaje' => $message,
            'metadata' => $metadata,
        ];
    }
}
