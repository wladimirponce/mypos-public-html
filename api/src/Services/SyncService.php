<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\SyncRepository;
use Throwable;

final class SyncService
{
    private const EVENTOS = ['VENTA_OFFLINE', 'CLIENTE_OFFLINE', 'STOCK_CONSULTA', 'DOCUMENTO_OFFLINE', 'HEARTBEAT', 'SYNC_BATCH'];
    private const ESTADOS_EVENTO = ['RECIBIDO', 'PROCESADO', 'RECHAZADO', 'CONFLICTO', 'DUPLICADO'];
    private const RESOLUCIONES = ['PENDIENTE', 'RESUELTO', 'IGNORADO'];

    private SyncRepository $repository;

    public function __construct(?SyncRepository $repository = null)
    {
        $this->repository = $repository ?? new SyncRepository(Database::connection());
    }

    public function estado(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $deviceId = $this->positiveInt($filters, 'dispositivo_id');
        $device = $this->requireDevice($empresaId, $deviceId, false);
        $sucursalId = $device['sucursal_id'] !== null ? (int) $device['sucursal_id'] : null;
        $config = (new ConfiguracionService())->efectiva($empresaId, $sucursalId);
        $folios = [];

        if ($sucursalId !== null) {
            $folios = (new FolioService())->listarAsignaciones([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'dispositivo_id' => $deviceId,
                'estado' => 'ACTIVA',
            ]);
        }

        return [
            'dispositivo_id' => $deviceId,
            'estado' => (string) $device['estado'],
            'ultimo_sync_at' => $device['ultimo_sync_at'],
            'servidor_fecha' => (new DateTimeImmutable())->format(DATE_ATOM),
            'configuracion_efectiva' => $config,
            'folios_disponibles' => $folios,
            'pendientes_servidor' => [],
        ];
    }

    public function procesarEventos(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $deviceId = $this->positiveInt($payload, 'dispositivo_id');
        $device = $this->requireDevice($empresaId, $deviceId, true);
        $events = $payload['eventos'] ?? null;

        if (!is_array($events) || $events === []) {
            throw new HttpException('eventos obligatorio', 422);
        }

        $summary = [
            'procesados' => 0,
            'rechazados' => 0,
            'conflictos' => 0,
            'duplicados' => 0,
            'resultados' => [],
        ];

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $device['sucursal_id'] !== null ? (int) $device['sucursal_id'] : null,
            'usuario_id' => $userId,
            'dispositivo_id' => $deviceId,
            'modulo' => 'offline',
            'accion' => 'sync.eventos.recibir',
            'entidad' => 'sync_eventos',
            'descripcion' => 'Lote de eventos offline recibido',
            'metadata' => ['cantidad_eventos' => count($events)],
        ]);

        foreach ($events as $event) {
            $result = $this->processSingleEvent($userId, $empresaId, $device, $event);
            $summary['resultados'][] = $result;

            match ($result['estado']) {
                'PROCESADO' => $summary['procesados']++,
                'RECHAZADO' => $summary['rechazados']++,
                'CONFLICTO' => $summary['conflictos']++,
                'DUPLICADO' => $summary['duplicados']++,
                default => null,
            };
        }

        $this->touchDevice($empresaId, $deviceId);

        return $summary;
    }

    public function listarEventos(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateEmpresa($empresaId);
        $this->validateDateFilters($filters);

        if (!empty($filters['estado']) && !in_array(strtoupper((string) $filters['estado']), self::ESTADOS_EVENTO, true)) {
            throw new HttpException('estado invalido', 422);
        }

        if (!empty($filters['tipo_evento']) && !in_array(strtoupper((string) $filters['tipo_evento']), self::EVENTOS, true)) {
            throw new HttpException('tipo_evento invalido', 422);
        }

        $limit = $this->limit($filters['limit'] ?? 50);
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        return [
            'items' => $this->repository->listEvents($empresaId, $filters, $limit, $offset),
            'pagination' => ['limit' => $limit, 'offset' => $offset],
        ];
    }

    public function listarConflictos(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateEmpresa($empresaId);

        if (!empty($filters['resolucion']) && !in_array(strtoupper((string) $filters['resolucion']), self::RESOLUCIONES, true)) {
            throw new HttpException('resolucion invalida', 422);
        }

        return $this->repository->listConflicts($empresaId, $filters);
    }

    public function resolverConflicto(int $userId, int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $resolution = strtoupper(trim((string) ($payload['resolucion'] ?? '')));

        if (!in_array($resolution, ['RESUELTO', 'IGNORADO'], true)) {
            throw new HttpException('resolucion invalida', 422);
        }

        $conflict = $this->repository->findConflict($empresaId, $id);
        if ($conflict === null) {
            throw new HttpException('Conflicto no encontrado', 404);
        }

        $this->repository->resolveConflict(
            $empresaId,
            $id,
            $userId,
            $resolution,
            isset($payload['comentario']) ? trim((string) $payload['comentario']) : null
        );

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $conflict['sucursal_id'] !== null ? (int) $conflict['sucursal_id'] : null,
            'usuario_id' => $userId,
            'dispositivo_id' => $conflict['dispositivo_id'] !== null ? (int) $conflict['dispositivo_id'] : null,
            'modulo' => 'offline',
            'accion' => 'sync.conflicto.resuelto',
            'entidad' => 'sync_conflictos',
            'entidad_id' => $id,
            'descripcion' => 'Conflicto de sincronizacion resuelto administrativamente',
            'datos_anteriores' => ['resolucion' => $conflict['resolucion']],
            'datos_nuevos' => ['resolucion' => $resolution],
        ]);

        return ['conflicto_id' => $id, 'resolucion' => $resolution];
    }

    private function processSingleEvent(int $userId, int $empresaId, array $device, mixed $rawEvent): array
    {
        if (!is_array($rawEvent)) {
            return [
                'uuid_evento' => null,
                'estado' => 'RECHAZADO',
                'entidad' => null,
                'entidad_id' => null,
                'mensaje' => 'Evento invalido',
            ];
        }

        $uuidEvent = trim((string) ($rawEvent['uuid_evento'] ?? ''));
        $type = strtoupper(trim((string) ($rawEvent['tipo_evento'] ?? '')));
        $entity = trim((string) ($rawEvent['entidad'] ?? ''));
        $entityUuid = isset($rawEvent['entidad_uuid']) ? trim((string) $rawEvent['entidad_uuid']) : null;
        $eventPayload = $rawEvent['payload'] ?? [];

        if ($uuidEvent === '') {
            return $this->rejectedResult(null, $entity, 'uuid_evento obligatorio');
        }

        $existing = $this->repository->eventByUuid($empresaId, $uuidEvent);
        if ($existing !== null) {
            $decoded = $this->decodeJson($existing['resultado_json'] ?? null);
            return [
                'uuid_evento' => $uuidEvent,
                'estado' => 'DUPLICADO',
                'entidad' => (string) $existing['entidad'],
                'entidad_id' => $existing['entidad_id'] !== null ? (int) $existing['entidad_id'] : null,
                'mensaje' => 'Evento ya recibido previamente',
                'resultado_previo' => $decoded,
            ];
        }

        if (!in_array($type, self::EVENTOS, true)) {
            return $this->persistRejectedEvent($userId, $empresaId, $device, $rawEvent, 'tipo_evento no soportado');
        }

        $eventId = $this->repository->createEvent([
            'empresa_id' => $empresaId,
            'sucursal_id' => $device['sucursal_id'] !== null ? (int) $device['sucursal_id'] : null,
            'dispositivo_id' => (int) $device['id'],
            'usuario_id' => $userId,
            'uuid_evento' => $uuidEvent,
            'tipo_evento' => $type,
            'entidad' => $entity !== '' ? $entity : strtolower($type),
            'entidad_uuid' => $entityUuid,
            'payload_json' => $this->json($rawEvent),
            'payload_json_legacy' => $this->json($rawEvent),
        ]);

        try {
            if ($type !== 'VENTA_OFFLINE') {
                throw new HttpException('tipo_evento no soportado en esta fase', 422);
            }

            $saleResult = $this->processOfflineSale($userId, $empresaId, $device, $eventId, $rawEvent, $eventPayload);
            $this->repository->updateEvent($eventId, $saleResult['estado'], $saleResult['entidad_id'], $this->json($saleResult), null);
            $this->auditEvent($userId, $empresaId, $device, 'sync.evento.procesado', $eventId, $saleResult);

            return $saleResult;
        } catch (HttpException $exception) {
            $conflictType = $this->conflictType($exception->getMessage());
            $state = $conflictType === 'PAYLOAD_INVALIDO' ? 'RECHAZADO' : 'CONFLICTO';
            $message = $exception->getMessage();
            $result = [
                'uuid_evento' => $uuidEvent,
                'estado' => $state,
                'entidad' => $entity !== '' ? $entity : strtolower($type),
                'entidad_id' => null,
                'mensaje' => $message,
            ];

            if ($state === 'CONFLICTO') {
                $this->createConflict($empresaId, $device, $eventId, $conflictType, $rawEvent, $message);
            }

            $this->repository->updateEvent($eventId, $state, null, $this->json($result), $message);
            $this->auditEvent($userId, $empresaId, $device, $state === 'CONFLICTO' ? 'sync.conflicto.creado' : 'sync.evento.rechazado', $eventId, $result);

            return $result;
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $result = [
                'uuid_evento' => $uuidEvent,
                'estado' => 'RECHAZADO',
                'entidad' => $entity !== '' ? $entity : strtolower($type),
                'entidad_id' => null,
                'mensaje' => 'Error procesando evento offline',
            ];
            $this->repository->updateEvent($eventId, 'RECHAZADO', null, $this->json($result), 'Error interno');
            $this->auditEvent($userId, $empresaId, $device, 'sync.evento.rechazado', $eventId, $result);

            return $result;
        }
    }

    private function processOfflineSale(
        int $userId,
        int $empresaId,
        array $device,
        int $eventId,
        array $rawEvent,
        mixed $eventPayload
    ): array {
        if (!is_array($eventPayload)) {
            throw new HttpException('Payload invalido', 422);
        }

        $uuidOffline = trim((string) ($eventPayload['uuid_offline'] ?? $rawEvent['entidad_uuid'] ?? ''));
        if ($uuidOffline === '') {
            throw new HttpException('uuid_offline obligatorio', 422);
        }

        $existingSale = $this->repository->saleByUuidOffline($empresaId, $uuidOffline);
        if ($existingSale !== null) {
            return [
                'uuid_evento' => (string) $rawEvent['uuid_evento'],
                'estado' => 'DUPLICADO',
                'entidad' => 'ventas',
                'entidad_id' => (int) $existingSale['id'],
                'mensaje' => 'Venta offline ya sincronizada',
            ];
        }

        $sucursalId = $this->positiveInt($eventPayload, 'sucursal_id');
        if (!$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 422);
        }

        if ($device['sucursal_id'] !== null && (int) $device['sucursal_id'] !== $sucursalId) {
            throw new HttpException('Dispositivo no pertenece a la sucursal indicada', 422);
        }

        $this->validatePaymentsTotal($eventPayload);
        $payments = $this->normalizePayments($eventPayload['pagos'] ?? []);
        $documentPayload = is_array($eventPayload['documento'] ?? null) ? $eventPayload['documento'] : null;

        if ($documentPayload !== null && isset($documentPayload['folio'])) {
            (new FolioService())->validarFolioOfflineDisponible([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'tipo_documento' => $documentPayload['tipo_documento'] ?? null,
                'folio' => $documentPayload['folio'],
                'dispositivo_id' => (int) $device['id'],
            ]);
        }

        $salePayload = [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'cliente_id' => $eventPayload['cliente_id'] ?? null,
            'condicion_pago' => $eventPayload['condicion_pago'] ?? 'CONTADO',
            'caja_apertura_id' => $eventPayload['caja_apertura_id'] ?? null,
            'items' => $eventPayload['items'] ?? [],
            'pagos' => $payments,
            'uuid_offline' => $uuidOffline,
            'dispositivo_id' => (int) $device['id'],
            'sync_evento_id' => $eventId,
            'origen' => 'OFFLINE',
            'sync_estado' => 'SYNC_OK',
            'created_offline_at' => $this->offlineDate($rawEvent['created_offline_at'] ?? $eventPayload['created_offline_at'] ?? null),
        ];

        $sale = (new VentaService())->registrarVenta($userId, $salePayload);
        $saleId = (int) $sale['venta_id'];
        $documentId = null;
        $folio = null;

        if ($documentPayload !== null && !empty($documentPayload['tipo_documento'])) {
            $document = (new DocumentoTributarioService())->crearDesdeVenta($userId, [
                'empresa_id' => $empresaId,
                'venta_id' => $saleId,
                'tipo_documento' => (string) $documentPayload['tipo_documento'],
                'estado' => 'PENDIENTE_EMISION',
            ]);
            $documentId = (int) $document['documento_emitido_id'];

            if (isset($documentPayload['folio']) && $documentPayload['folio'] !== '') {
                $folioResult = (new FolioService())->consumirOffline($userId, [
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'tipo_documento' => (string) $documentPayload['tipo_documento'],
                    'folio' => (int) $documentPayload['folio'],
                    'documento_emitido_id' => $documentId,
                    'dispositivo_id' => (int) $device['id'],
                    'uuid_offline' => (string) ($documentPayload['uuid_offline'] ?? $uuidOffline),
                    'origen' => 'OFFLINE',
                ]);
                $folio = (int) $folioResult['folio'];
            }
        }

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'usuario_id' => $userId,
            'dispositivo_id' => (int) $device['id'],
            'modulo' => 'offline',
            'accion' => 'venta.offline.sincronizada',
            'entidad' => 'ventas',
            'entidad_id' => $saleId,
            'descripcion' => 'Venta offline sincronizada',
            'datos_nuevos' => [
                'uuid_offline' => $uuidOffline,
                'sync_evento_id' => $eventId,
                'documento_emitido_id' => $documentId,
                'folio' => $folio,
            ],
        ]);

        return [
            'uuid_evento' => (string) $rawEvent['uuid_evento'],
            'estado' => 'PROCESADO',
            'entidad' => 'ventas',
            'entidad_id' => $saleId,
            'mensaje' => 'Venta offline sincronizada',
            'documento_emitido_id' => $documentId,
            'folio' => $folio,
        ];
    }

    private function normalizePayments(mixed $payments): array
    {
        if (!is_array($payments) || $payments === []) {
            throw new HttpException('La venta debe incluir pagos', 422);
        }

        $normalized = [];
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                throw new HttpException('Pago invalido', 422);
            }

            $methodId = isset($payment['metodo_pago_id']) ? (int) $payment['metodo_pago_id'] : 0;
            if ($methodId > 0) {
                $method = $this->repository->paymentMethodById($methodId);
                if ($method === null) {
                    throw new HttpException('Metodo de pago no encontrado', 422);
                }
                $payment['metodo_pago_codigo'] = (string) $method['codigo'];
            }

            $normalized[] = $payment;
        }

        return $normalized;
    }

    private function validatePaymentsTotal(array $payload): void
    {
        if (!isset($payload['total'])) {
            return;
        }

        $paymentTotal = 0;
        foreach (($payload['pagos'] ?? []) as $payment) {
            if (is_array($payment)) {
                $paymentTotal += (int) ($payment['monto'] ?? 0);
            }
        }

        if ($paymentTotal !== (int) $payload['total']) {
            throw new HttpException('TOTAL_NO_CUADRA', 422);
        }
    }

    private function createConflict(int $empresaId, array $device, int $eventId, string $type, array $event, string $message): void
    {
        $this->repository->createConflict([
            'empresa_id' => $empresaId,
            'sucursal_id' => $device['sucursal_id'] !== null ? (int) $device['sucursal_id'] : null,
            'dispositivo_id' => (int) $device['id'],
            'sync_evento_id' => $eventId,
            'tipo_conflicto' => $type,
            'entidad' => (string) ($event['entidad'] ?? 'offline'),
            'entidad_uuid' => isset($event['entidad_uuid']) ? (string) $event['entidad_uuid'] : null,
            'entidad_id' => null,
            'descripcion' => $message,
            'payload_json' => $this->json($this->lightPayload($event)),
        ]);
    }

    private function persistRejectedEvent(int $userId, int $empresaId, array $device, array $event, string $message): array
    {
        $uuidEvent = (string) ($event['uuid_evento'] ?? '');
        $eventId = $this->repository->createEvent([
            'empresa_id' => $empresaId,
            'sucursal_id' => $device['sucursal_id'] !== null ? (int) $device['sucursal_id'] : null,
            'dispositivo_id' => (int) $device['id'],
            'usuario_id' => $userId,
            'uuid_evento' => $uuidEvent,
            'tipo_evento' => strtoupper((string) ($event['tipo_evento'] ?? '')),
            'entidad' => (string) ($event['entidad'] ?? 'offline'),
            'entidad_uuid' => isset($event['entidad_uuid']) ? (string) $event['entidad_uuid'] : null,
            'payload_json' => $this->json($event),
            'payload_json_legacy' => $this->json($event),
        ]);
        $result = $this->rejectedResult($uuidEvent, (string) ($event['entidad'] ?? null), $message);
        $this->repository->updateEvent($eventId, 'RECHAZADO', null, $this->json($result), $message);

        return $result;
    }

    private function rejectedResult(?string $uuidEvent, ?string $entity, string $message): array
    {
        return [
            'uuid_evento' => $uuidEvent,
            'estado' => 'RECHAZADO',
            'entidad' => $entity,
            'entidad_id' => null,
            'mensaje' => $message,
        ];
    }

    private function requireDevice(int $empresaId, int $deviceId, bool $mustBeActive): array
    {
        $this->validateEmpresa($empresaId);
        $device = $this->repository->deviceById($empresaId, $deviceId);

        if ($device === null) {
            throw new HttpException('Dispositivo no encontrado', 422);
        }

        if ($mustBeActive && (string) $device['estado'] !== 'ACTIVO') {
            throw new HttpException('El dispositivo no esta activo para sincronizar', 422);
        }

        return $device;
    }

    private function validateEmpresa(int $empresaId): void
    {
        if ($empresaId <= 0 || !$this->repository->empresaExists($empresaId)) {
            throw new HttpException('Empresa no encontrada', 422);
        }
    }

    private function positiveInt(array $payload, string $field): int
    {
        $value = (int) ($payload[$field] ?? 0);
        if ($value <= 0) {
            throw new HttpException($field . ' obligatorio', 422);
        }

        return $value;
    }

    private function limit(mixed $value): int
    {
        $limit = (int) $value;
        if ($limit < 1 || $limit > 100) {
            throw new HttpException('limit debe estar entre 1 y 100', 422);
        }

        return $limit;
    }

    private function validateDateFilters(array $filters): void
    {
        $from = !empty($filters['fecha_desde']) ? $this->date((string) $filters['fecha_desde'], 'fecha_desde') : null;
        $to = !empty($filters['fecha_hasta']) ? $this->date((string) $filters['fecha_hasta'], 'fecha_hasta') : null;

        if ($from !== null && $to !== null && $from > $to) {
            throw new HttpException('fecha_desde no puede ser mayor que fecha_hasta', 422);
        }
    }

    private function date(string $value, string $field): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new HttpException($field . ' debe tener formato YYYY-MM-DD', 422);
        }

        return $value;
    }

    private function offlineDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new HttpException('created_offline_at invalido', 422);
        }
    }

    private function conflictType(string $message): string
    {
        $normalized = strtolower($message);

        return match (true) {
            str_contains($normalized, 'folio') => 'FOLIO_DUPLICADO',
            str_contains($normalized, 'stock') => 'STOCK_INSUFICIENTE',
            str_contains($normalized, 'producto') => 'PRODUCTO_NO_EXISTE',
            str_contains($normalized, 'cliente') => 'CLIENTE_NO_EXISTE',
            str_contains($normalized, 'caja') => 'CAJA_NO_VALIDA',
            str_contains($normalized, 'total_no_cuadra') => 'TOTAL_NO_CUADRA',
            default => 'PAYLOAD_INVALIDO',
        };
    }

    private function auditEvent(int $userId, int $empresaId, array $device, string $action, int $eventId, array $result): void
    {
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $device['sucursal_id'] !== null ? (int) $device['sucursal_id'] : null,
            'usuario_id' => $userId,
            'dispositivo_id' => (int) $device['id'],
            'modulo' => 'offline',
            'accion' => $action,
            'entidad' => 'sync_eventos',
            'entidad_id' => $eventId,
            'descripcion' => 'Evento de sincronizacion procesado',
            'metadata' => $result,
            'severidad' => $result['estado'] === 'PROCESADO' ? 'INFO' : 'WARNING',
            'resultado' => $result['estado'] === 'PROCESADO' ? 'OK' : 'ERROR',
        ]);
    }

    private function touchDevice(int $empresaId, int $deviceId): void
    {
        $statement = $this->repository->connection()->prepare(
            'UPDATE dispositivos
             SET ultimo_sync_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND empresa_id = :empresa_id'
        );
        $statement->execute(['id' => $deviceId, 'empresa_id' => $empresaId]);
    }

    private function lightPayload(array $event): array
    {
        unset($event['payload']['items'], $event['payload']['pagos']);

        return $event;
    }

    private function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeJson(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
