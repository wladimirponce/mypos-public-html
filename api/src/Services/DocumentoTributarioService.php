<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\DocumentoTributarioRepository;
use Mypos\Repositories\FolioRepository;
use Throwable;

final class DocumentoTributarioService
{
    private const TIPOS = ['BOLETA', 'FACTURA', 'GUIA_DESPACHO', 'NOTA_CREDITO'];
    private const ESTADOS = [
        'BORRADOR',
        'PENDIENTE_EMISION',
        'EMITIDO_INTERNO',
        'ENVIADO_SII',
        'ACEPTADO_SII',
        'RECHAZADO_SII',
        'ANULADO',
    ];
    private const ESTADOS_CREACION = ['BORRADOR', 'PENDIENTE_EMISION'];

    private DocumentoTributarioRepository $repository;

    public function __construct(?DocumentoTributarioRepository $repository = null)
    {
        $this->repository = $repository ?? new DocumentoTributarioRepository(Database::connection());
    }

    public function crearDesdeVenta(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $saleId = $this->positiveInt($payload, 'venta_id');
        $config = (new ConfiguracionService())->efectiva($empresaId);

        if (!(bool) $config['documentos_tributarios_habilitados']) {
            (new ConfiguracionService())->assertFeatureEnabled(
                $empresaId,
                'documentos_tributarios_habilitados',
                'Los documentos tributarios estan deshabilitados para esta empresa'
            );
        }

        $type = $this->type($payload['tipo_documento'] ?? $config['tipo_documento_default']);
        $status = strtoupper((string) ($payload['estado'] ?? 'PENDIENTE_EMISION'));

        if (!in_array($status, self::ESTADOS_CREACION, true)) {
            throw new HttpException('Estado invalido', 422);
        }

        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();
            $sale = $this->repository->findSale($empresaId, $saleId);

            if ($sale === null) {
                throw new HttpException('Venta no encontrada', 404);
            }

            if ((string) $sale['estado'] === 'ANULADA') {
                throw new HttpException('No se puede crear documento tributario desde una venta anulada', 422);
            }

            if ($this->repository->activeDocumentForSaleType($empresaId, $saleId, $type) !== null) {
                throw new HttpException('Ya existe un documento tributario activo para esta venta y tipo', 422);
            }

            $details = $this->repository->saleDetails($empresaId, $saleId);
            if ($details === []) {
                throw new HttpException('La venta no tiene detalles', 422);
            }

            $folio = $this->nullableString($payload['folio'] ?? null);
            $folioOrigin = $folio !== null ? 'MANUAL' : null;
            $receiver = $this->receiverData($type, $sale, $payload, (bool) $config['exigir_cliente_en_factura']);
            $totals = $this->totalsFromDetails($details);
            $taxes = $this->repository->saleTaxes($empresaId, $saleId);
            $metadata = [
                'origen' => 'venta',
                'venta_id' => $saleId,
                'nota' => 'Documento interno preparado para futura integracion SII; no genera XML/PDF real.',
            ];

            $documentId = $this->repository->createDocument([
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) $sale['sucursal_id'],
                'venta_id' => $saleId,
                'cliente_id' => $sale['cliente_id'] !== null ? (int) $sale['cliente_id'] : null,
                'tipo_documento' => $type,
                'folio' => $folio,
                'folio_origen' => $folioOrigin,
                'estado' => $status,
                'total' => (int) $sale['total'],
                'fecha_emision' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'rut_receptor' => $receiver['rut_receptor'],
                'razon_social_receptor' => $receiver['razon_social_receptor'],
                'giro_receptor' => $receiver['giro_receptor'],
                'direccion_receptor' => $receiver['direccion_receptor'],
                'comuna_receptor' => $receiver['comuna_receptor'],
                'ciudad_receptor' => $receiver['ciudad_receptor'],
                'neto' => $totals['neto'],
                'exento' => $totals['exento'],
                'impuestos' => $totals['impuestos'],
                'payload_json' => $this->encodeJson(['venta' => $sale, 'detalles' => $details, 'impuestos' => $taxes]),
                'metadata_json' => $this->encodeJson($metadata),
                'created_by_usuario_id' => $userId,
            ]);

            foreach ($details as $detail) {
                $this->repository->insertDetail([
                    'documento_emitido_id' => $documentId,
                    'venta_detalle_id' => (int) $detail['id'],
                    'producto_id' => $detail['producto_id'] !== null ? (int) $detail['producto_id'] : null,
                    'codigo_producto' => $detail['codigo_producto'],
                    'nombre_producto' => $detail['nombre_producto'],
                    'cantidad' => $detail['cantidad'],
                    'precio_unitario' => (int) $detail['precio_unitario'],
                    'descuento' => (int) $detail['descuento_total'],
                    'neto' => (int) $detail['neto'],
                    'exento' => (int) $detail['exento'],
                    'impuestos' => (int) $detail['impuestos_total'],
                    'total' => (int) $detail['total'],
                ]);
            }

            foreach ($taxes as $tax) {
                $this->repository->insertTax([
                    'documento_emitido_id' => $documentId,
                    'impuesto_id' => $tax['impuesto_id'] !== null ? (int) $tax['impuesto_id'] : null,
                    'codigo_impuesto' => (string) $tax['codigo_impuesto'],
                    'nombre_impuesto' => (string) $tax['nombre_impuesto'],
                    'tasa_base_10000' => (int) $tax['tasa_base_10000'],
                    'monto' => (int) $tax['monto'],
                ]);
            }

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) $sale['sucursal_id'],
                'usuario_id' => $userId,
                'modulo' => 'documentos_tributarios',
                'accion' => 'crear_desde_venta',
                'entidad' => 'documentos_emitidos',
                'entidad_id' => $documentId,
                'descripcion' => 'Documento tributario interno creado desde venta',
                'datos_nuevos' => [
                    'venta_id' => $saleId,
                    'tipo_documento' => $type,
                    'estado' => $status,
                    'folio' => $folio,
                    'total' => (int) $sale['total'],
                ],
            ], $connection);

            $connection->commit();

            return [
                'documento_emitido_id' => $documentId,
                'venta_id' => $saleId,
                'tipo_documento' => $type,
                'estado' => $status,
                'folio' => $folio,
                'total' => (int) $sale['total'],
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function listar(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateFilters($empresaId, $filters);

        return $this->repository->list($empresaId, $filters);
    }

    public function detalle(int $id, int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $document = $this->requireDocument($empresaId, $id);

        return [
            'documento' => $document,
            'detalles' => $this->repository->documentDetails($id),
            'impuestos' => $this->repository->documentTaxes($id),
            'venta' => $this->repository->relatedSale($empresaId, $document['venta_id'] !== null ? (int) $document['venta_id'] : null),
            'metadata_json' => $this->decodeJson($document['metadata_json'] ?? null),
        ];
    }

    public function marcarEmitidoInterno(int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $document = $this->requireDocument($empresaId, $id);

        if (!in_array((string) $document['estado'], ['BORRADOR', 'PENDIENTE_EMISION'], true)) {
            throw new HttpException('El documento no puede marcarse como emitido interno desde su estado actual', 422);
        }

        $folio = $this->nullableString($payload['folio'] ?? null);
        $metadata = isset($payload['metadata']) && is_array($payload['metadata'])
            ? $payload['metadata']
            : ['observacion' => 'Documento preparado internamente; pendiente integracion SII'];

        $this->repository->updateStatus($empresaId, $id, $this->statusPayload([
            'estado' => 'EMITIDO_INTERNO',
            'folio' => $folio,
            'folio_origen' => $folio !== null ? 'MANUAL' : null,
            'metadata_json' => $this->encodeJson($metadata),
        ]));

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'modulo' => 'documentos_tributarios',
            'accion' => 'marcar_emitido_interno',
            'entidad' => 'documentos_emitidos',
            'entidad_id' => $id,
            'descripcion' => 'Documento marcado como emitido interno',
            'datos_anteriores' => ['estado' => (string) $document['estado'], 'folio' => $document['folio']],
            'datos_nuevos' => ['estado' => 'EMITIDO_INTERNO', 'folio' => $folio ?? $document['folio']],
        ]);

        return ['documento_emitido_id' => $id, 'estado' => 'EMITIDO_INTERNO', 'folio' => $folio ?? $document['folio']];
    }

    public function marcarEnviadoSii(int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $document = $this->requireDocument($empresaId, $id);
        $trackId = $this->nullableString($payload['track_id'] ?? null);

        $this->repository->updateStatus($empresaId, $id, $this->statusPayload([
            'estado' => 'ENVIADO_SII',
            'track_id' => $trackId,
            'respuesta_sii_json' => $this->encodeJson($this->arrayOrEmpty($payload['respuesta_sii'] ?? [])),
        ]));

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'modulo' => 'documentos_tributarios',
            'accion' => 'marcar_enviado_sii',
            'entidad' => 'documentos_emitidos',
            'entidad_id' => $id,
            'descripcion' => 'Documento marcado como enviado SII simulado',
            'datos_anteriores' => ['estado' => (string) $document['estado']],
            'datos_nuevos' => ['estado' => 'ENVIADO_SII', 'track_id' => $trackId],
        ]);

        return ['documento_emitido_id' => $id, 'estado' => 'ENVIADO_SII', 'track_id' => $trackId];
    }

    public function marcarAceptadoSii(int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $document = $this->requireDocument($empresaId, $id);

        $this->repository->updateStatus($empresaId, $id, $this->statusPayload([
            'estado' => 'ACEPTADO_SII',
            'respuesta_sii_json' => $this->encodeJson($this->arrayOrEmpty($payload['respuesta_sii'] ?? [])),
        ]));

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'modulo' => 'documentos_tributarios',
            'accion' => 'marcar_aceptado_sii',
            'entidad' => 'documentos_emitidos',
            'entidad_id' => $id,
            'descripcion' => 'Documento marcado como aceptado SII simulado',
            'datos_anteriores' => ['estado' => (string) $document['estado']],
            'datos_nuevos' => ['estado' => 'ACEPTADO_SII'],
        ]);

        return ['documento_emitido_id' => $id, 'estado' => 'ACEPTADO_SII'];
    }

    public function marcarRechazadoSii(int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $document = $this->requireDocument($empresaId, $id);

        $this->repository->updateStatus($empresaId, $id, $this->statusPayload([
            'estado' => 'RECHAZADO_SII',
            'respuesta_sii_json' => $this->encodeJson($this->arrayOrEmpty($payload['respuesta_sii'] ?? [])),
            'error_sii' => $this->nullableString($payload['error_sii'] ?? null),
        ]));

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'modulo' => 'documentos_tributarios',
            'accion' => 'marcar_rechazado_sii',
            'entidad' => 'documentos_emitidos',
            'entidad_id' => $id,
            'descripcion' => 'Documento marcado como rechazado SII simulado',
            'datos_anteriores' => ['estado' => (string) $document['estado']],
            'datos_nuevos' => ['estado' => 'RECHAZADO_SII', 'error_sii' => $payload['error_sii'] ?? null],
        ]);

        return ['documento_emitido_id' => $id, 'estado' => 'RECHAZADO_SII'];
    }

    public function anular(int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $reason = $this->reason($payload['motivo'] ?? null);
        $document = $this->requireDocument($empresaId, $id);

        if ((string) $document['estado'] === 'ANULADO') {
            throw new HttpException('El documento tributario interno ya esta anulado', 422);
        }

        $this->repository->updateStatus($empresaId, $id, $this->statusPayload([
            'estado' => 'ANULADO',
            'motivo_anulacion' => $reason,
        ]));

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'modulo' => 'documentos_tributarios',
            'accion' => 'anular',
            'entidad' => 'documentos_emitidos',
            'entidad_id' => $id,
            'descripcion' => 'Documento tributario interno anulado',
            'datos_anteriores' => ['estado' => (string) $document['estado']],
            'datos_nuevos' => ['estado' => 'ANULADO', 'motivo' => $reason],
        ]);

        return ['documento_emitido_id' => $id, 'estado' => 'ANULADO'];
    }

    public function asignarFolio(int $userId, int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $document = $this->requireDocument($empresaId, $id);

        if ($document['folio'] !== null && $document['folio'] !== '') {
            throw new HttpException('El documento tributario ya tiene folio asignado', 422);
        }

        if ((string) $document['estado'] === 'ANULADO') {
            throw new HttpException('No se puede asignar folio a un documento anulado', 422);
        }

        $folioService = new FolioService(new FolioRepository($this->repository->connection()));

        $result = $folioService->asignarFolioADocumento($userId, $id, array_merge($payload, [
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) ($payload['sucursal_id'] ?? $document['sucursal_id']),
        ]));

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'usuario_id' => $userId,
            'modulo' => 'documentos_tributarios',
            'accion' => 'asignar_folio',
            'entidad' => 'documentos_emitidos',
            'entidad_id' => $id,
            'descripcion' => 'Folio asignado a documento tributario',
            'datos_anteriores' => ['folio' => $document['folio'], 'estado' => (string) $document['estado']],
            'datos_nuevos' => $result,
        ]);

        return $result;
    }

    public function emitirDte(int $userId, int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $document = $this->requireDocument($empresaId, $id);

        (new ConfiguracionService())->assertFeatureEnabled(
            $empresaId,
            'documentos_tributarios_habilitados',
            'Los documentos tributarios estan deshabilitados para esta empresa'
        );

        if ((string) $document['estado'] === 'ANULADO') {
            throw new HttpException('No se puede emitir DTE de un documento anulado', 422);
        }

        if ((string) $document['estado'] === 'ACEPTADO_SII') {
            throw new HttpException('El documento ya esta aceptado por SII', 422);
        }

        $dteService = new DteIntegrationService();
        $dteService->assertReadyForEmission($empresaId);

        if ($document['folio'] === null || $document['folio'] === '') {
            $assignIfMissing = filter_var($payload['asignar_folio_si_falta'] ?? false, FILTER_VALIDATE_BOOL);

            if (!$assignIfMissing) {
                throw new HttpException('El documento debe tener folio antes de emitir DTE', 422);
            }

            $this->asignarFolio($userId, $id, [
                'empresa_id' => $empresaId,
                'sucursal_id' => (int) ($payload['sucursal_id'] ?? $document['sucursal_id']),
                'caja_id' => $payload['caja_id'] ?? null,
                'dispositivo_id' => $payload['dispositivo_id'] ?? null,
                'origen' => 'ONLINE',
            ]);
            $document = $this->requireDocument($empresaId, $id);
        }

        if ($document['folio'] === null || $document['folio'] === '') {
            throw new HttpException('No fue posible asignar folio al documento', 422);
        }

        return $dteService->emitirDocumento($userId, $document, $payload);
    }

    private function requireDocument(int $empresaId, int $id): array
    {
        $document = $this->repository->find($empresaId, $id);

        if ($document === null) {
            throw new HttpException('Documento tributario no encontrado', 404);
        }

        return $document;
    }

    private function validateFilters(int $empresaId, array $filters): void
    {
        if (!empty($filters['sucursal_id']) && !$this->repository->sucursalExists($empresaId, (int) $filters['sucursal_id'])) {
            throw new HttpException('Sucursal no encontrada', 422);
        }

        if (!empty($filters['tipo_documento'])) {
            $this->type($filters['tipo_documento']);
        }

        if (!empty($filters['estado']) && !in_array(strtoupper((string) $filters['estado']), self::ESTADOS, true)) {
            throw new HttpException('Estado invalido', 422);
        }

        foreach (['fecha_desde', 'fecha_hasta'] as $field) {
            if (!empty($filters[$field])) {
                $this->date($filters[$field]);
            }
        }

        if (!empty($filters['fecha_desde']) && !empty($filters['fecha_hasta']) && $filters['fecha_desde'] > $filters['fecha_hasta']) {
            throw new HttpException('fecha_desde no puede ser mayor que fecha_hasta', 422);
        }
    }

    private function receiverData(string $type, array $sale, array $payload, bool $requireInvoiceClient): array
    {
        $receiver = [
            'rut_receptor' => $this->nullableString($payload['rut_receptor'] ?? $sale['cliente_rut'] ?? null),
            'razon_social_receptor' => $this->nullableString($payload['razon_social_receptor'] ?? $sale['cliente_razon_social'] ?? null),
            'giro_receptor' => $this->nullableString($payload['giro_receptor'] ?? null),
            'direccion_receptor' => $this->nullableString($payload['direccion_receptor'] ?? $sale['cliente_direccion'] ?? null),
            'comuna_receptor' => $this->nullableString($payload['comuna_receptor'] ?? $sale['cliente_comuna'] ?? null),
            'ciudad_receptor' => $this->nullableString($payload['ciudad_receptor'] ?? $sale['cliente_ciudad'] ?? null),
        ];

        if ($type === 'FACTURA' && $requireInvoiceClient && ($receiver['rut_receptor'] === null || $receiver['razon_social_receptor'] === null)) {
            throw new HttpException('Factura requiere cliente o datos de receptor', 422);
        }

        return $receiver;
    }

    private function totalsFromDetails(array $details): array
    {
        return [
            'neto' => array_sum(array_map(static fn (array $item): int => (int) $item['neto'], $details)),
            'exento' => array_sum(array_map(static fn (array $item): int => (int) $item['exento'], $details)),
            'impuestos' => array_sum(array_map(static fn (array $item): int => (int) $item['impuestos_total'], $details)),
        ];
    }

    private function statusPayload(array $data): array
    {
        return array_merge([
            'estado' => null,
            'folio' => null,
            'folio_origen' => null,
            'track_id' => null,
            'respuesta_sii_json' => null,
            'error_sii' => null,
            'motivo_anulacion' => null,
            'metadata_json' => null,
        ], $data);
    }

    private function type(mixed $value): string
    {
        $type = strtoupper(trim((string) $value));

        if (!in_array($type, self::TIPOS, true)) {
            throw new HttpException('Tipo de documento invalido', 422);
        }

        return $type;
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);

        if ($value <= 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return $value;
    }

    private function reason(mixed $value): string
    {
        $reason = trim((string) $value);

        if (strlen($reason) < 5) {
            throw new HttpException('El motivo es obligatorio y debe tener al menos 5 caracteres', 422);
        }

        return $reason;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function date(mixed $value): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);

        if (!$date || $date->format('Y-m-d') !== (string) $value) {
            throw new HttpException('Formato de fecha invalido', 422);
        }

        return (string) $value;
    }
}
