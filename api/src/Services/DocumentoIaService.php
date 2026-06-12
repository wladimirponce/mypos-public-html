<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\DocumentoIaRepository;
use Mypos\Repositories\DocumentoIaRevisionRepository;
use Mypos\Repositories\ProductoRepository;
use Mypos\Support\SafeLogger;

final class DocumentoIaService
{
    private const TIPOS_COMPRA = ['FACTURA_COMPRA', 'GUIA_DESPACHO_COMPRA', 'BOLETA_COMPRA'];
    private const ESTADOS_COMPRA = ['BORRADOR', 'CONFIRMADA'];

    private DocumentoIaRepository $repository;
    private DocumentoIaRevisionRepository $revisionRepository;
    private ProductoRepository $products;
    private CompraService $purchases;
    private GeminiService $gemini;
    private UploadService $uploads;
    private DocumentoIaConciliacionService $conciliation;
    private DocumentoIaRevisionService $revision;

    public function __construct(
        ?DocumentoIaRepository $repository = null,
        ?ProductoRepository $products = null,
        ?CompraService $purchases = null,
        ?GeminiService $gemini = null,
        ?UploadService $uploads = null
    ) {
        $connection = Database::connection();
        $this->repository = $repository ?? new DocumentoIaRepository($connection);
        $this->revisionRepository = new DocumentoIaRevisionRepository($connection);
        $this->products = $products ?? new ProductoRepository($connection);
        $this->purchases = $purchases ?? new CompraService();
        $this->gemini = $gemini ?? new GeminiService();
        $this->uploads = $uploads ?? new UploadService();
        $this->conciliation = new DocumentoIaConciliacionService(new DocumentoIaNormalizerService(), $this->revisionRepository);
        $this->revision = new DocumentoIaRevisionService(new DocumentoIaNormalizerService(), $this->revisionRepository);
    }

    public function crear(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $sucursalId = $this->positiveInt($payload, 'sucursal_id');
        $archivoUrl = trim((string) ($payload['archivo_url'] ?? ''));
        $type = strtoupper((string) ($payload['tipo_documento_detectado'] ?? ''));

        if ($archivoUrl === '') {
            throw new HttpException('archivo_url obligatorio', 422);
        }

        if ($type !== '' && !in_array($type, self::TIPOS_COMPRA, true)) {
            throw new HttpException('Tipo de documento invalido', 422);
        }

        if (!$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 422);
        }

        $id = $this->repository->create([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'usuario_id' => $userId,
            'tipo_documento' => $type ?: null,
            'tipo_documento_detectado' => $type ?: null,
            'archivo_ruta' => $archivoUrl,
            'archivo_url' => $archivoUrl,
            'estado' => 'SUBIDO',
        ]);

        return ['documento_ia_id' => $id, 'estado' => 'SUBIDO'];
    }

    public function procesar(int $id, array $payload): array
    {
        if (strtoupper((string) ($payload['modo'] ?? '')) === 'GEMINI') {
            throw new HttpException('Use /api/v1/documentos-ia/{id}/procesar-gemini para procesamiento real', 422);
        }

        $empresaId = $this->positiveInt($payload, 'empresa_id');
        (new ConfiguracionService())->assertFeatureEnabled(
            $empresaId,
            'ia_documentos_habilitada',
            'La funcionalidad de documentos IA esta deshabilitada para esta empresa.'
        );

        $document = $this->requireDocument($empresaId, $id);
        $result = $payload['resultado_ia'] ?? null;

        if (!is_array($result)) {
            throw new HttpException('resultado_ia obligatorio', 422);
        }

        if (in_array((string) ($document['estado'] ?? ''), ['CONFIRMADO', 'COMPRA_GENERADA'], true)) {
            throw new HttpException('El documento ya fue confirmado', 422);
        }

        return $this->aplicarResultadoIa($empresaId, $id, $document, $result);
    }

    public function procesarGemini(int $userId, int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        (new ConfiguracionService())->assertFeatureEnabled(
            $empresaId,
            'ia_documentos_habilitada',
            'La funcionalidad de documentos IA esta deshabilitada para esta empresa.'
        );
        $document = $this->requireDocument($empresaId, $id);

        if (in_array((string) ($document['estado'] ?? ''), ['CONFIRMADO', 'COMPRA_GENERADA'], true)) {
            throw new HttpException('El documento ya fue confirmado', 422);
        }

        $files = $this->repository->uploadedFiles($empresaId, $id);
        if ($files === []) {
            $legacyFile = $this->repository->uploadedFile($empresaId, $id);
            $files = $legacyFile === null ? [] : [$legacyFile];
        }
        if ($files === []) {
            throw new HttpException('Documento IA no tiene archivo subido asociado', 422);
        }
        $file = $files[0];

        $processingId = $this->repository->createProcessing([
            'empresa_id' => $empresaId,
            'documento_ia_id' => $id,
            'archivo_subido_id' => (int) $file['id'],
            'proveedor' => 'GEMINI',
            'modelo' => (string) ($_ENV['GEMINI_MODEL'] ?? getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash'),
            'estado' => 'PROCESANDO',
            'request_json' => $this->encodeJson([
                'archivos' => array_map(static fn (array $item): array => [
                    'archivo_id' => (int) $item['id'],
                    'mime_type' => $item['mime_type'],
                    'size_bytes' => (int) $item['size_bytes'],
                ], $files),
            ]),
            'created_by_usuario_id' => $userId,
        ]);

        try {
            $geminiFiles = array_map(fn (array $item): array => [
                'absolute_path' => $this->uploads->absolutePath((string) $item['ruta_relativa']),
                'mime_type' => (string) $item['mime_type'],
            ], $files);
            $gemini = $this->gemini->procesarDocumentoCompraMultipagina($geminiFiles);
            $result = $gemini['resultado'];
            $applied = $this->aplicarResultadoIa($empresaId, $id, $document, $result);
            $normalized = $this->conciliation->normalizarYConciliar($id, $empresaId, $userId);
            $this->repository->updateProcessing(
                $processingId,
                'PROCESADO',
                $gemini['raw_response'] ?? $result,
                null,
                isset($gemini['tokens_input']) ? (int) $gemini['tokens_input'] : null,
                isset($gemini['tokens_output']) ? (int) $gemini['tokens_output'] : null,
            );
            $this->auditGemini($empresaId, (int) $document['sucursal_id'], $userId, $id, (int) $file['id'], 'documentos_ia.procesar_gemini', 'OK');

            return array_merge($applied, [
                'estado_revision' => $normalized['estado_revision'],
                'requiere_revision' => $normalized['requiere_revision'],
                'alertas' => $normalized['alertas'],
                'paginas_procesadas' => count($files),
            ]);
        } catch (HttpException $exception) {
            $this->repository->updateProcessing($processingId, 'ERROR', null, $exception->getMessage());
            $this->repository->markError($empresaId, $id, $exception->getMessage());
            $this->auditGemini($empresaId, (int) $document['sucursal_id'], $userId, $id, (int) $file['id'], 'documentos_ia.gemini_error', 'ERROR', $exception->getMessage());
            throw $exception;
        } catch (\Throwable $exception) {
            SafeLogger::error('Unexpected Gemini document processing error', [
                'type' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine(),
                'empresa_id' => $empresaId,
                'documento_ia_id' => $id,
                'paginas' => count($files),
            ]);
            $this->repository->updateProcessing($processingId, 'ERROR', null, 'Error controlado al procesar con Gemini');
            $this->repository->markError($empresaId, $id, 'Error controlado al procesar con Gemini');
            $this->auditGemini($empresaId, (int) $document['sucursal_id'], $userId, $id, (int) $file['id'], 'documentos_ia.gemini_error', 'ERROR', 'Error controlado al procesar con Gemini');
            throw new HttpException('Error controlado al procesar con Gemini', 502);
        }
    }

    private function aplicarResultadoIa(int $empresaId, int $id, array $document, array $result): array
    {
        $items = $result['items'] ?? [];
        if (!is_array($items)) {
            throw new HttpException('Items IA invalidos', 422);
        }

        $type = strtoupper((string) ($result['tipo_documento'] ?? $document['tipo_documento_detectado'] ?? 'FACTURA_COMPRA'));
        if ($type === 'DESCONOCIDO') {
            $type = 'FACTURA_COMPRA';
        }
        if (!in_array($type, self::TIPOS_COMPRA, true)) {
            throw new HttpException('Tipo de documento invalido', 422);
        }

        $this->repository->updateProcessed($empresaId, $id, [
            'tipo_documento' => $type,
            'tipo_documento_detectado' => $type,
            'proveedor_detectado' => $this->nullableString($result['proveedor_nombre'] ?? null),
            'proveedor_rut_detectado' => $this->nullableString($result['proveedor_rut'] ?? null),
            'folio' => $this->nullableString($result['folio'] ?? null),
            'folio_detectado' => $this->nullableString($result['folio'] ?? null),
            'fecha_detectada' => $this->dateOrNull($result['fecha_documento'] ?? null),
            'neto_detectado' => $this->nonNegativeIntValue($result['neto'] ?? 0),
            'iva_detectado' => $this->nonNegativeIntValue($result['iva'] ?? 0),
            'exento_detectado' => $this->nonNegativeIntValue($result['exento'] ?? 0),
            'total_detectado' => $this->nonNegativeIntValue($result['total'] ?? 0),
            'respuesta_json' => $this->encodeJson($result),
        ]);

        $this->repository->clearDetails($empresaId, $id);

        $linked = 0;
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new HttpException('Item IA invalido', 422);
            }

            $productId = $this->resolveProductId($empresaId, $item);
            if ($productId !== null) {
                $linked++;
            }

            $this->repository->insertDetail($this->detailPayload($empresaId, $id, $index + 1, $item, $productId));
        }

        $count = count($items);

        return [
            'documento_ia_id' => $id,
            'estado' => 'PROCESADO',
            'items_detectados' => $count,
            'items_vinculados' => $linked,
            'items_pendientes' => $count - $linked,
        ];
    }

    public function normalizar(int $userId, int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        (new ConfiguracionService())->assertFeatureEnabled(
            $empresaId,
            'ia_documentos_habilitada',
            'La funcionalidad de documentos IA esta deshabilitada para esta empresa.'
        );

        return $this->conciliation->normalizarYConciliar($id, $empresaId, $userId);
    }

    public function revision(int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');

        return $this->revision->revision($id, $empresaId);
    }

    public function actualizarRevisionCabecera(int $userId, int $id, array $payload): array
    {
        return $this->revision->actualizarCabecera($id, $payload, ['usuario_id' => $userId]);
    }

    public function actualizarRevisionDetalle(int $userId, int $detailId, array $payload): array
    {
        return $this->revision->actualizarDetalle($detailId, $payload, ['usuario_id' => $userId]);
    }

    public function alertas(int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $resolved = null;
        if (array_key_exists('resuelta', $payload) && $payload['resuelta'] !== '') {
            $resolved = filter_var($payload['resuelta'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($resolved === null) {
                throw new HttpException('resuelta debe ser true/false', 422);
            }
        }

        return $this->revision->alertas($id, $empresaId, $resolved);
    }

    public function resolverAlerta(int $userId, int $alertId, array $payload): array
    {
        return $this->revision->resolverAlerta($alertId, $payload, ['usuario_id' => $userId]);
    }

    public function aprobar(int $userId, int $id, array $payload): array
    {
        return $this->revision->aprobarDocumento($id, $payload, ['usuario_id' => $userId]);
    }

    public function vincularProveedor(int $userId, int $id, array $payload): array
    {
        $providerId = $this->positiveInt($payload, 'proveedor_id');

        return $this->revision->vincularProveedor($id, $providerId, $payload, ['usuario_id' => $userId]);
    }

    public function listar(int $empresaId, array $filters): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        return ['documentos_ia' => $this->repository->list($empresaId, $filters)];
    }

    public function detalle(int $empresaId, int $id): array
    {
        $document = $this->requireDocument($empresaId, $id);

        return [
            'documento_ia' => $document,
            'detalles' => $this->repository->details($empresaId, $id),
        ];
    }

    public function editar(int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $document = $this->requireDocument($empresaId, $id);

        if (in_array((string) ($document['estado'] ?? ''), ['CONFIRMADO', 'COMPRA_GENERADA'], true)) {
            throw new HttpException('El documento ya fue confirmado', 422);
        }

        $items = $payload['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new HttpException('El documento debe incluir items', 422);
        }

        $type = strtoupper((string) ($payload['tipo_documento_detectado'] ?? $document['tipo_documento_detectado'] ?? 'FACTURA_COMPRA'));
        if (!in_array($type, self::TIPOS_COMPRA, true)) {
            throw new HttpException('Tipo de documento invalido', 422);
        }

        $this->repository->updateEdited($empresaId, $id, [
            'proveedor_id' => $payload['proveedor_id'] ?? null,
            'tipo_documento' => $type,
            'tipo_documento_detectado' => $type,
            'folio' => $this->nullableString($payload['folio_detectado'] ?? null),
            'folio_detectado' => $this->nullableString($payload['folio_detectado'] ?? null),
            'fecha_detectada' => $this->dateOrNull($payload['fecha_detectada'] ?? null),
            'total_detectado' => $this->nonNegativeIntValue($payload['total_detectado'] ?? 0),
            'json_editado' => $this->encodeJson($payload),
        ]);

        $this->repository->clearDetails($empresaId, $id);

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new HttpException('Item invalido', 422);
            }

            $productId = isset($item['producto_id']) && (int) $item['producto_id'] > 0 ? (int) $item['producto_id'] : null;
            if ($productId !== null && !$this->repository->productExists($empresaId, $productId)) {
                throw new HttpException('Producto no existe', 422);
            }

            $this->repository->insertDetail($this->detailPayload($empresaId, $id, $index + 1, $item, $productId));
        }

        return ['documento_ia_id' => $id, 'estado' => 'EDITADO'];
    }

    public function vincularProducto(int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $detailId = $this->positiveInt($payload, 'detalle_id');
        $productId = $this->positiveInt($payload, 'producto_id');
        $this->requireDocument($empresaId, $id);

        if (!$this->repository->productExists($empresaId, $productId)) {
            throw new HttpException('Producto no existe', 422);
        }

        if (!$this->repository->linkProduct($empresaId, $id, $detailId, $productId)) {
            throw new HttpException('Detalle no encontrado', 404);
        }

        return ['detalle_id' => $detailId, 'producto_id' => $productId];
    }

    public function generarCompra(int $userId, int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        (new ConfiguracionService())->assertFeatureEnabled(
            $empresaId,
            'ia_documentos_habilitada',
            'La funcionalidad de documentos IA esta deshabilitada para esta empresa.'
        );

        $purchaseStatus = strtoupper((string) ($payload['estado_compra'] ?? 'BORRADOR'));
        $document = $this->requireDocument($empresaId, $id);

        if (!in_array($purchaseStatus, self::ESTADOS_COMPRA, true)) {
            throw new HttpException('Estado de compra invalido', 422);
        }

        if (!empty($document['compra_id'])) {
            throw new HttpException('El documento IA ya tiene una compra asociada', 422);
        }

        $revisionDocument = $this->revisionRepository->findDocument($empresaId, $id);
        if ($revisionDocument === null) {
            throw new HttpException('Documento IA no encontrado', 404);
        }
        if (($revisionDocument['estado_revision'] ?? '') !== 'APROBADO') {
            throw new HttpException('El documento IA debe estar aprobado antes de generar compra.', 422);
        }
        if ($this->revisionRepository->unresolvedErrorCount($empresaId, $id) > 0) {
            throw new HttpException('No se puede generar compra con alertas ERROR no resueltas', 422);
        }
        if (abs((int) ($revisionDocument['diferencia_total'] ?? 0)) > 2) {
            throw new HttpException('No se puede generar compra con total descuadrado', 422);
        }

        $details = $this->revisionRepository->details($empresaId, $id);
        if ($details === []) {
            throw new HttpException('El documento no tiene items', 422);
        }

        $purchaseItems = [];
        foreach ($details as $detail) {
            $productId = (int) ($detail['producto_id'] ?? 0);
            if ($productId <= 0) {
                throw new HttpException('Todos los items deben estar vinculados a un producto', 422);
            }

            $quantity = $this->quantity($detail['cantidad_normalizada'] ?? $detail['cantidad_detectada'] ?? $detail['cantidad'] ?? null);
            $cost = $this->nonNegativeIntValue($detail['costo_unitario_normalizado'] ?? $detail['costo_unitario_detectado'] ?? $detail['costo_unitario'] ?? 0);
            $net = $this->nonNegativeIntValue($detail['total_normalizado'] ?? $detail['total_detectado'] ?? $detail['total'] ?? round($quantity * $cost));

            $purchaseItems[] = [
                'producto_id' => $productId,
                'cantidad' => $this->formatQuantity($quantity),
                'costo_unitario' => $cost,
                'neto' => $net,
                'iva' => 0,
                'total' => $net,
            ];
        }

        $purchase = $this->purchases->crear($userId, [
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'proveedor_id' => $revisionDocument['proveedor_id'] !== null ? (int) $revisionDocument['proveedor_id'] : null,
            'tipo_documento' => $revisionDocument['tipo_documento_detectado'] ?: 'FACTURA_COMPRA',
            'folio' => $revisionDocument['folio_detectado'] ?: $revisionDocument['folio'],
            'fecha_documento' => $revisionDocument['fecha_documento_detectada'] ?: ($revisionDocument['fecha_detectada'] ?: null),
            'fecha_ingreso' => (new DateTimeImmutable())->format('Y-m-d'),
            'estado' => $purchaseStatus,
            'observacion' => 'Generada desde documento IA #' . $id,
            'items' => $purchaseItems,
        ]);

        $purchaseId = (int) $purchase['compra_id'];
        $this->revisionRepository->markPurchaseGenerated($empresaId, $id, $purchaseId);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'usuario_id' => $userId,
            'modulo' => 'documentos_ia',
            'accion' => 'documentos_ia.generar_compra_desde_aprobado',
            'entidad' => 'documentos_ia',
            'entidad_id' => $id,
            'descripcion' => 'Compra generada desde documento IA aprobado',
            'metadata' => [
                'compra_id' => $purchaseId,
                'estado_compra' => $purchaseStatus,
                'items' => count($purchaseItems),
            ],
        ]);

        return [
            'documento_ia_id' => $id,
            'compra_id' => $purchaseId,
            'estado_compra' => $purchaseStatus,
        ];
    }

    private function requireDocument(int $empresaId, int $id): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $document = $this->repository->find($empresaId, $id);
        if ($document === null) {
            throw new HttpException('Documento IA no encontrado', 404);
        }

        return $document;
    }

    public function eliminar(int $userId, int $empresaId, int $id): array
    {
        $document = $this->repository->find($empresaId, $id);
        if ($document === null) {
            throw new HttpException('Documento IA no encontrado', 404);
        }

        $this->repository->markDeleted($empresaId, $id);
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $document['sucursal_id'],
            'usuario_id' => $userId,
            'modulo' => 'documentos_ia',
            'accion' => 'eliminar',
            'entidad' => 'documentos_ia',
            'entidad_id' => $id,
            'descripcion' => 'Documento IA eliminado',
            'datos_anteriores' => ['estado' => (string) ($document['estado'] ?? '')],
            'datos_nuevos' => ['deleted' => true],
        ]);

        return ['documento_ia_id' => $id, 'eliminado' => true];
    }

    private function resolveProductId(int $empresaId, array $item): ?int
    {
        $code = trim((string) ($item['codigo_detectado'] ?? ''));
        $barcode = trim((string) ($item['codigo_barra_detectado'] ?? $item['codigo_barra'] ?? ''));
        if ($barcode === '' && preg_match('/^\d{8,14}$/', $code) === 1) {
            $barcode = $code;
        }

        if ($barcode !== '') {
            $product = $this->products->searchByCode($empresaId, $barcode);
            if (is_array($product)) {
                return (int) $product['id'];
            }
        }

        if ($code === '') {
            return null;
        }

        $product = $this->products->searchByCode($empresaId, $code);

        return is_array($product) ? (int) $product['id'] : null;
    }

    private function detailPayload(int $empresaId, int $documentId, int $line, array $item, ?int $productId): array
    {
        $quantity = $this->quantity($item['cantidad_detectada'] ?? $item['cantidad'] ?? null);
        $cost = $this->nonNegativeIntValue($item['costo_unitario_detectado'] ?? $item['costo_unitario'] ?? 0);
        $total = $this->nonNegativeIntValue($item['total_detectado'] ?? $item['total'] ?? round($quantity * $cost));
        $confirmed = (int) ($item['confirmado'] ?? ($productId !== null ? 1 : 0));

        $detectedCode = $this->nullableString($item['codigo_detectado'] ?? null);
        $detectedBarcode = $this->nullableString($item['codigo_barra_detectado'] ?? $item['codigo_barra'] ?? null);
        if ($detectedBarcode === null && $detectedCode !== null && preg_match('/^\d{8,14}$/', $detectedCode) === 1) {
            $detectedBarcode = $detectedCode;
            $detectedCode = null;
        }

        return [
            'empresa_id' => $empresaId,
            'documento_ia_id' => $documentId,
            'producto_id' => $productId,
            'linea' => $line,
            'codigo_detectado' => $detectedCode,
            'codigo_barra_detectado' => $detectedBarcode,
            'nombre_detectado' => (string) ($item['nombre_detectado'] ?? 'Producto detectado'),
            'cantidad_detectada' => $this->formatQuantity($quantity),
            'costo_unitario_detectado' => $cost,
            'total_detectado' => $total,
            'cantidad_normalizada' => $this->formatQuantity($quantity),
            'costo_unitario_normalizado' => $cost,
            'total_normalizado' => $total,
            'cantidad' => $this->formatQuantity($quantity),
            'costo_unitario' => $cost,
            'total' => $total,
            'confianza' => isset($item['confianza']) && is_numeric($item['confianza']) ? (float) $item['confianza'] : null,
            'metodo_match' => $productId !== null ? 'CODIGO' : 'SIN_MATCH',
            'requiere_revision' => $confirmed === 1 ? 0 : 1,
            'confirmado' => $confirmed,
        ];
    }

    private function auditGemini(
        int $empresaId,
        int $sucursalId,
        int $userId,
        int $documentId,
        int $fileId,
        string $action,
        string $result,
        ?string $error = null
    ): void {
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'usuario_id' => $userId,
            'modulo' => 'documentos_ia',
            'accion' => $action,
            'entidad' => 'documentos_ia',
            'entidad_id' => $documentId,
            'descripcion' => $result === 'OK' ? 'Documento IA procesado con Gemini' : 'Error al procesar documento IA con Gemini',
            'metadata' => [
                'archivo_id' => $fileId,
                'proveedor' => 'GEMINI',
                'modelo' => (string) ($_ENV['GEMINI_MODEL'] ?? getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash'),
                'error' => $error,
            ],
            'severidad' => $result === 'OK' ? 'INFO' : 'WARNING',
            'resultado' => $result,
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

    private function nonNegativeIntValue(mixed $value): int
    {
        if (!is_numeric($value) || (int) $value < 0) {
            throw new HttpException('Los montos deben ser enteros >= 0', 422);
        }

        return (int) $value;
    }

    private function quantity(mixed $value): float
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new HttpException('La cantidad debe ser mayor a 0', 422);
        }

        return round((float) $value, 3);
    }

    private function formatQuantity(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new HttpException('Fecha invalida', 422);
        }

        return (string) $value;
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
}
