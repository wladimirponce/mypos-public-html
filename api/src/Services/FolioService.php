<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\FolioRepository;
use Throwable;

final class FolioService
{
    private const TIPOS = ['BOLETA', 'FACTURA', 'GUIA_DESPACHO', 'NOTA_CREDITO'];
    private const ORIGENES = ['ONLINE', 'OFFLINE'];

    private FolioRepository $repository;

    public function __construct(?FolioRepository $repository = null)
    {
        $this->repository = $repository ?? new FolioRepository(Database::connection());
    }

    public function registrarCaf(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $type = $this->type($payload['tipo_documento'] ?? null);
        $from = $this->positiveInt($payload, 'folio_desde');
        $to = $this->positiveInt($payload, 'folio_hasta');

        $this->validateEmpresa($empresaId);

        if ($to < $from) {
            throw new HttpException('folio_hasta debe ser mayor o igual a folio_desde', 422);
        }

        $authDate = $this->nullableDate($payload['fecha_autorizacion'] ?? null);
        $expirationDate = $this->nullableDate($payload['fecha_vencimiento'] ?? null);

        if ($expirationDate !== null && $authDate !== null && $expirationDate < $authDate) {
            throw new HttpException('fecha_vencimiento no puede ser menor que fecha_autorizacion', 422);
        }

        if ($this->repository->cafOverlapExists($empresaId, $type, $from, $to)) {
            throw new HttpException('Existe un CAF activo que se solapa con el rango informado', 422);
        }

        $cafId = $this->repository->createCaf([
            'empresa_id' => $empresaId,
            'tipo_documento' => $type,
            'rut_emisor' => $this->nullableString($payload['rut_emisor'] ?? null),
            'razon_social_emisor' => $this->nullableString($payload['razon_social_emisor'] ?? null),
            'folio_desde' => $from,
            'folio_hasta' => $to,
            'fecha_autorizacion' => $authDate,
            'fecha_vencimiento' => $expirationDate,
            'archivo_path' => $this->nullableString($payload['archivo_path'] ?? null),
            'caf_xml' => $this->nullableString($payload['caf_xml'] ?? null),
            'created_by_usuario_id' => $userId,
        ]);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'folios',
            'accion' => 'crear_caf',
            'entidad' => 'caf_archivos',
            'entidad_id' => $cafId,
            'descripcion' => 'CAF registrado',
            'datos_nuevos' => [
                'tipo_documento' => $type,
                'folio_desde' => $from,
                'folio_hasta' => $to,
                'fecha_vencimiento' => $expirationDate,
                'estado' => 'ACTIVO',
            ],
        ]);

        return [
            'caf_archivo_id' => $cafId,
            'tipo_documento' => $type,
            'folio_desde' => $from,
            'folio_hasta' => $to,
            'estado' => 'ACTIVO',
        ];
    }

    public function listarCafs(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateEmpresa($empresaId);

        if (!empty($filters['tipo_documento'])) {
            $this->type($filters['tipo_documento']);
        }

        return $this->repository->listCafs($empresaId, $filters);
    }

    public function crearAsignacion(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $cafId = $this->positiveInt($payload, 'caf_archivo_id');
        $sucursalId = $this->positiveInt($payload, 'sucursal_id');
        $type = $this->type($payload['tipo_documento'] ?? null);
        $from = $this->positiveInt($payload, 'folio_desde');
        $to = $this->positiveInt($payload, 'folio_hasta');
        $alertMin = $this->intAtLeast($payload['alerta_minimo'] ?? 10, 'alerta_minimo', 0);
        $cajaId = $this->optionalPositiveInt($payload['caja_id'] ?? null, 'caja_id');
        $deviceId = $this->optionalPositiveInt($payload['dispositivo_id'] ?? null, 'dispositivo_id');

        $this->validateEmpresa($empresaId);
        $this->validateSucursal($empresaId, $sucursalId);
        $this->validateCaja($empresaId, $sucursalId, $cajaId);
        $this->validateDispositivo($empresaId, $sucursalId, $deviceId);

        if ($to < $from) {
            throw new HttpException('folio_hasta debe ser mayor o igual a folio_desde', 422);
        }

        $caf = $this->requireActiveCaf($empresaId, $cafId);

        if ((string) $caf['tipo_documento'] !== $type) {
            throw new HttpException('El tipo de documento no coincide con el CAF', 422);
        }

        if ($from < (int) $caf['folio_desde'] || $to > (int) $caf['folio_hasta']) {
            throw new HttpException('El subrango debe estar dentro del rango autorizado del CAF', 422);
        }

        if ($this->repository->assignmentOverlapExists($empresaId, $type, $from, $to)) {
            throw new HttpException('Existe una asignacion activa que se solapa con el rango informado', 422);
        }

        $assignmentId = $this->repository->createAssignment([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'caja_id' => $cajaId,
            'dispositivo_id' => $deviceId,
            'caf_id' => $cafId,
            'tipo_documento' => $type,
            'folio_desde' => $from,
            'folio_hasta' => $to,
            'folio_actual' => $from - 1,
            'alerta_minimo' => $alertMin,
            'created_by_usuario_id' => $userId,
        ]);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'usuario_id' => $userId,
            'dispositivo_id' => $deviceId,
            'modulo' => 'folios',
            'accion' => 'crear_asignacion',
            'entidad' => 'folios_asignaciones',
            'entidad_id' => $assignmentId,
            'descripcion' => 'Rango de folios asignado',
            'datos_nuevos' => [
                'caf_archivo_id' => $cafId,
                'caja_id' => $cajaId,
                'tipo_documento' => $type,
                'folio_desde' => $from,
                'folio_hasta' => $to,
                'folio_actual' => $from - 1,
                'alerta_minimo' => $alertMin,
            ],
        ]);

        return [
            'folio_asignacion_id' => $assignmentId,
            'tipo_documento' => $type,
            'folio_desde' => $from,
            'folio_hasta' => $to,
            'folio_actual' => $from - 1,
            'estado' => 'ACTIVA',
        ];
    }

    public function listarAsignaciones(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateEmpresa($empresaId);

        if (!empty($filters['sucursal_id'])) {
            $this->validateSucursal($empresaId, (int) $filters['sucursal_id']);
        }

        if (!empty($filters['tipo_documento'])) {
            $this->type($filters['tipo_documento']);
        }

        return $this->repository->listAssignments($empresaId, $filters);
    }

    public function disponibilidad(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $sucursalId = $this->positiveInt($filters, 'sucursal_id');
        $type = $this->type($filters['tipo_documento'] ?? null);
        $cajaId = $this->optionalPositiveInt($filters['caja_id'] ?? null, 'caja_id');
        $deviceId = $this->optionalPositiveInt($filters['dispositivo_id'] ?? null, 'dispositivo_id');

        $this->validateEmpresa($empresaId);
        $this->validateSucursal($empresaId, $sucursalId);
        $this->validateCaja($empresaId, $sucursalId, $cajaId);
        $this->validateDispositivo($empresaId, $sucursalId, $deviceId);

        $assignment = $this->requireApplicableAssignment($empresaId, $sucursalId, $type, $cajaId, $deviceId, false);

        return $this->availabilityPayload($assignment);
    }

    public function consumir(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $sucursalId = $this->positiveInt($payload, 'sucursal_id');
        $type = $this->type($payload['tipo_documento'] ?? null);
        $origin = $this->origin($payload['origen'] ?? 'ONLINE');
        $cajaId = $this->optionalPositiveInt($payload['caja_id'] ?? null, 'caja_id');
        $deviceId = $this->optionalPositiveInt($payload['dispositivo_id'] ?? null, 'dispositivo_id');
        $documentId = $this->optionalPositiveInt($payload['documento_emitido_id'] ?? null, 'documento_emitido_id');

        $this->validateEmpresa($empresaId);
        $this->validateSucursal($empresaId, $sucursalId);
        $this->validateCaja($empresaId, $sucursalId, $cajaId);
        $this->validateDispositivo($empresaId, $sucursalId, $deviceId);

        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();
            $document = null;

            if ($documentId !== null) {
                $document = $this->repository->findDocumentForUpdate($empresaId, $documentId);

                if ($document === null) {
                    throw new HttpException('Documento tributario no encontrado', 404);
                }

                if ($document['folio'] !== null && $document['folio'] !== '') {
                    throw new HttpException('El documento tributario ya tiene folio asignado', 422);
                }

                if ((string) $document['tipo_documento'] !== $type) {
                    throw new HttpException('El tipo de documento no coincide con el documento tributario', 422);
                }

                if ((int) $document['sucursal_id'] !== $sucursalId) {
                    throw new HttpException('La sucursal no coincide con el documento tributario', 422);
                }
            }

            $assignment = $this->requireApplicableAssignment($empresaId, $sucursalId, $type, $cajaId, $deviceId, true);
            $next = (int) $assignment['folio_actual'] + 1;

            if ($next > (int) $assignment['folio_hasta']) {
                $this->repository->updateAssignmentAfterConsumption((int) $assignment['id'], (int) $assignment['folio_actual'], true);
                throw new HttpException('No hay folios disponibles para la asignacion seleccionada', 422);
            }

            $remaining = (int) $assignment['folio_hasta'] - $next;
            $consumedId = $this->repository->createConsumedFolio([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'caja_id' => $cajaId,
                'dispositivo_id' => $deviceId,
                'caf_archivo_id' => (int) $assignment['caf_id'],
                'asignacion_id' => (int) $assignment['id'],
                'documento_emitido_id' => $documentId,
                'tipo_documento' => $type,
                'folio' => $next,
                'origen' => $origin,
                'created_by_usuario_id' => $userId,
                'metadata_json' => $this->encodeJson([
                    'origen_consumo' => 'FolioService',
                    'documento_emitido_id' => $documentId,
                    'caja_id' => $cajaId,
                    'dispositivo_id' => $deviceId,
                ]),
            ]);

            $this->repository->updateAssignmentAfterConsumption((int) $assignment['id'], $next, $remaining === 0);

            if ($documentId !== null) {
                $this->repository->updateDocumentFolio([
                    'empresa_id' => $empresaId,
                    'documento_emitido_id' => $documentId,
                    'folio' => $next,
                    'caf_id' => (int) $assignment['caf_id'],
                    'folio_asignacion_id' => (int) $assignment['id'],
                    'folio_consumido_id' => $consumedId,
                    'dispositivo_id' => $deviceId,
                    'emision_origen' => $origin,
                ]);
            }

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'usuario_id' => $userId,
                'dispositivo_id' => $deviceId,
                'modulo' => 'folios',
                'accion' => 'consumir',
                'entidad' => 'folios_consumidos',
                'entidad_id' => $consumedId,
                'descripcion' => 'Folio consumido',
                'datos_nuevos' => [
                    'folio_asignacion_id' => (int) $assignment['id'],
                    'caf_archivo_id' => (int) $assignment['caf_id'],
                    'documento_emitido_id' => $documentId,
                    'caja_id' => $cajaId,
                    'tipo_documento' => $type,
                    'folio' => $next,
                    'origen' => $origin,
                    'disponibles_restantes' => $remaining,
                ],
            ], $connection);

            $connection->commit();

            return [
                'folio_consumido_id' => $consumedId,
                'folio_asignacion_id' => (int) $assignment['id'],
                'tipo_documento' => $type,
                'folio' => $next,
                'estado' => 'USADO_INTERNO',
                'disponibles_restantes' => $remaining,
                'alerta_folios_bajos' => $remaining <= (int) $assignment['alerta_minimo'],
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function validarFolioOfflineDisponible(array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $sucursalId = $this->positiveInt($payload, 'sucursal_id');
        $type = $this->type($payload['tipo_documento'] ?? null);
        $folio = $this->positiveInt($payload, 'folio');
        $deviceId = $this->optionalPositiveInt($payload['dispositivo_id'] ?? null, 'dispositivo_id');

        $this->validateEmpresa($empresaId);
        $this->validateSucursal($empresaId, $sucursalId);
        $this->validateDispositivo($empresaId, $sucursalId, $deviceId);

        if ($this->repository->consumedFolioExists($empresaId, $type, $folio)) {
            throw new HttpException('Folio offline ya consumido', 422);
        }

        $assignment = $this->repository->findAssignmentContainingFolio(
            $empresaId,
            $sucursalId,
            $type,
            $folio,
            $deviceId,
            false
        );

        if ($assignment === null) {
            throw new HttpException('Folio offline fuera de asignacion activa', 422);
        }

        if ($this->isExpired($assignment['fecha_vencimiento'] ?? null)) {
            throw new HttpException('El CAF asociado a la asignacion esta vencido', 422);
        }

        return $assignment;
    }

    public function consumirOffline(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $sucursalId = $this->positiveInt($payload, 'sucursal_id');
        $type = $this->type($payload['tipo_documento'] ?? null);
        $folio = $this->positiveInt($payload, 'folio');
        $deviceId = $this->optionalPositiveInt($payload['dispositivo_id'] ?? null, 'dispositivo_id');
        $documentId = $this->optionalPositiveInt($payload['documento_emitido_id'] ?? null, 'documento_emitido_id');
        $uuidOffline = $this->nullableString($payload['uuid_offline'] ?? null);
        $origin = $this->origin($payload['origen'] ?? 'OFFLINE');

        if ($origin !== 'OFFLINE') {
            throw new HttpException('El consumo offline debe indicar origen OFFLINE', 422);
        }

        $this->validateEmpresa($empresaId);
        $this->validateSucursal($empresaId, $sucursalId);
        $this->validateDispositivo($empresaId, $sucursalId, $deviceId);

        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();

            if ($uuidOffline !== null) {
                $existingUuid = $this->repository->consumedOfflineUuidExists($empresaId, $type, $uuidOffline);
                if ($existingUuid !== null) {
                    $connection->commit();
                    return [
                        'folio_consumido_id' => (int) $existingUuid['id'],
                        'folio_asignacion_id' => null,
                        'tipo_documento' => $type,
                        'folio' => (int) $existingUuid['folio'],
                        'estado' => (string) $existingUuid['estado'],
                        'disponibles_restantes' => null,
                        'alerta_folios_bajos' => false,
                    ];
                }
            }

            $document = null;
            if ($documentId !== null) {
                $document = $this->repository->findDocumentForUpdate($empresaId, $documentId);
                if ($document === null) {
                    throw new HttpException('Documento tributario no encontrado', 404);
                }

                if ($document['folio'] !== null && $document['folio'] !== '') {
                    throw new HttpException('El documento tributario ya tiene folio asignado', 422);
                }

                if ((string) $document['tipo_documento'] !== $type) {
                    throw new HttpException('El tipo de documento no coincide con el documento tributario', 422);
                }
            }

            if ($this->repository->consumedFolioExists($empresaId, $type, $folio)) {
                throw new HttpException('Folio offline ya consumido', 422);
            }

            $assignment = $this->repository->findAssignmentContainingFolio(
                $empresaId,
                $sucursalId,
                $type,
                $folio,
                $deviceId,
                true
            );

            if ($assignment === null) {
                throw new HttpException('Folio offline fuera de asignacion activa', 422);
            }

            if ($this->isExpired($assignment['fecha_vencimiento'] ?? null)) {
                throw new HttpException('El CAF asociado a la asignacion esta vencido', 422);
            }

            $remaining = (int) $assignment['folio_hasta'] - max($folio, (int) $assignment['folio_actual']);
            $consumedId = $this->repository->createConsumedFolio([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'caja_id' => null,
                'dispositivo_id' => $deviceId,
                'caf_archivo_id' => (int) $assignment['caf_id'],
                'asignacion_id' => (int) $assignment['id'],
                'folio_asignacion_id' => (int) $assignment['id'],
                'documento_emitido_id' => $documentId,
                'tipo_documento' => $type,
                'folio' => $folio,
                'uuid_offline' => $uuidOffline,
                'origen' => 'OFFLINE',
                'created_by_usuario_id' => $userId,
                'metadata_json' => $this->encodeJson([
                    'origen_consumo' => 'SyncService',
                    'uuid_offline' => $uuidOffline,
                    'documento_emitido_id' => $documentId,
                    'dispositivo_id' => $deviceId,
                ]),
            ]);

            if ($folio > (int) $assignment['folio_actual']) {
                $this->repository->updateAssignmentAfterConsumption(
                    (int) $assignment['id'],
                    $folio,
                    $folio >= (int) $assignment['folio_hasta']
                );
            }

            if ($documentId !== null) {
                $this->repository->updateDocumentFolio([
                    'empresa_id' => $empresaId,
                    'documento_emitido_id' => $documentId,
                    'folio' => $folio,
                    'caf_id' => (int) $assignment['caf_id'],
                    'folio_asignacion_id' => (int) $assignment['id'],
                    'folio_consumido_id' => $consumedId,
                    'dispositivo_id' => $deviceId,
                    'emision_origen' => 'OFFLINE',
                ]);
            }

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'usuario_id' => $userId,
                'dispositivo_id' => $deviceId,
                'modulo' => 'folios',
                'accion' => 'folio.offline.consumido',
                'entidad' => 'folios_consumidos',
                'entidad_id' => $consumedId,
                'descripcion' => 'Folio offline consumido por sincronizacion',
                'datos_nuevos' => [
                    'folio_asignacion_id' => (int) $assignment['id'],
                    'documento_emitido_id' => $documentId,
                    'tipo_documento' => $type,
                    'folio' => $folio,
                    'uuid_offline' => $uuidOffline,
                    'disponibles_restantes' => $remaining,
                ],
            ], $connection);

            $connection->commit();

            return [
                'folio_consumido_id' => $consumedId,
                'folio_asignacion_id' => (int) $assignment['id'],
                'tipo_documento' => $type,
                'folio' => $folio,
                'estado' => 'USADO_INTERNO',
                'disponibles_restantes' => $remaining,
                'alerta_folios_bajos' => $remaining <= (int) $assignment['alerta_minimo'],
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function listarConsumidos(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateEmpresa($empresaId);
        $this->validateDateFilters($filters);

        if (!empty($filters['tipo_documento'])) {
            $this->type($filters['tipo_documento']);
        }

        return $this->repository->listConsumed($empresaId, $filters);
    }

    public function alertas(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateEmpresa($empresaId);

        if (!empty($filters['sucursal_id'])) {
            $this->validateSucursal($empresaId, (int) $filters['sucursal_id']);
        }

        if (!empty($filters['tipo_documento'])) {
            $this->type($filters['tipo_documento']);
        }

        return [
            'folios_bajos' => $this->repository->lowFolioAssignments($empresaId, $filters),
            'folios_agotados' => $this->repository->depletedAssignments($empresaId, $filters),
            'caf_vencidos' => $this->repository->expiredCafs($empresaId, $filters),
            'caf_por_vencer' => $this->repository->expiringCafs($empresaId, $filters),
        ];
    }

    public function asignarFolioADocumento(int $userId, int $documentId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $document = $this->repository->findDocumentForUpdate($empresaId, $documentId);

        if ($document === null) {
            throw new HttpException('Documento tributario no encontrado', 404);
        }

        if ($document['folio'] !== null && $document['folio'] !== '') {
            throw new HttpException('El documento tributario ya tiene folio asignado', 422);
        }

        $sucursalId = isset($payload['sucursal_id']) && (int) $payload['sucursal_id'] > 0
            ? (int) $payload['sucursal_id']
            : (int) $document['sucursal_id'];

        $result = $this->consumir($userId, array_merge($payload, [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'tipo_documento' => (string) $document['tipo_documento'],
            'documento_emitido_id' => $documentId,
        ]));

        return [
            'documento_emitido_id' => $documentId,
            'tipo_documento' => (string) $document['tipo_documento'],
            'folio' => $result['folio'],
            'estado' => 'EMITIDO_INTERNO',
        ];
    }

    private function requireActiveCaf(int $empresaId, int $cafId): array
    {
        $caf = $this->repository->findCaf($empresaId, $cafId);

        if ($caf === null) {
            throw new HttpException('CAF no encontrado', 404);
        }

        if ((string) $caf['estado'] !== 'ACTIVO') {
            throw new HttpException('El CAF no esta activo', 422);
        }

        if ($this->isExpired($caf['fecha_vencimiento'] ?? null)) {
            throw new HttpException('El CAF esta vencido', 422);
        }

        return $caf;
    }

    private function requireApplicableAssignment(
        int $empresaId,
        int $sucursalId,
        string $type,
        ?int $cajaId,
        ?int $deviceId,
        bool $lock
    ): array {
        $assignment = $this->repository->findApplicableAssignment($empresaId, $sucursalId, $type, $cajaId, $deviceId, $lock);

        if ($assignment === null) {
            throw new HttpException('No existe una asignacion activa de folios disponible', 422);
        }

        if ($this->isExpired($assignment['fecha_vencimiento'] ?? null)) {
            throw new HttpException('El CAF asociado a la asignacion esta vencido', 422);
        }

        return $assignment;
    }

    private function availabilityPayload(array $assignment): array
    {
        $current = (int) $assignment['folio_actual'];
        $to = (int) $assignment['folio_hasta'];
        $next = $current + 1;
        $available = max(0, $to - $current);
        $alertMin = (int) $assignment['alerta_minimo'];

        return [
            'tipo_documento' => (string) $assignment['tipo_documento'],
            'folio_asignacion_id' => (int) $assignment['id'],
            'folio_desde' => (int) $assignment['folio_desde'],
            'folio_hasta' => $to,
            'folio_actual' => $current,
            'siguiente_folio' => $available > 0 ? $next : null,
            'disponibles' => $available,
            'alerta_minimo' => $alertMin,
            'alerta_folios_bajos' => $available <= $alertMin,
            'estado' => (string) $assignment['estado'],
        ];
    }

    private function validateEmpresa(int $empresaId): void
    {
        if (!$this->repository->empresaExists($empresaId)) {
            throw new HttpException('Empresa no encontrada', 422);
        }
    }

    private function validateSucursal(int $empresaId, int $sucursalId): void
    {
        if (!$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('Sucursal no encontrada', 422);
        }
    }

    private function validateCaja(int $empresaId, int $sucursalId, ?int $cajaId): void
    {
        if ($cajaId !== null && !$this->repository->cajaExists($empresaId, $sucursalId, $cajaId)) {
            throw new HttpException('Caja no encontrada', 422);
        }
    }

    private function validateDispositivo(int $empresaId, int $sucursalId, ?int $deviceId): void
    {
        if ($deviceId !== null && !$this->repository->dispositivoExists($empresaId, $sucursalId, $deviceId)) {
            throw new HttpException('Dispositivo no encontrado', 422);
        }
    }

    private function validateDateFilters(array $filters): void
    {
        foreach (['fecha_desde', 'fecha_hasta'] as $field) {
            if (!empty($filters[$field])) {
                $this->date($filters[$field]);
            }
        }

        if (!empty($filters['fecha_desde']) && !empty($filters['fecha_hasta']) && $filters['fecha_desde'] > $filters['fecha_hasta']) {
            throw new HttpException('fecha_desde no puede ser mayor que fecha_hasta', 422);
        }
    }

    private function type(mixed $value): string
    {
        $type = strtoupper(trim((string) $value));

        if (!in_array($type, self::TIPOS, true)) {
            throw new HttpException('Tipo de documento invalido', 422);
        }

        return $type;
    }

    private function origin(mixed $value): string
    {
        $origin = strtoupper(trim((string) $value));

        if (!in_array($origin, self::ORIGENES, true)) {
            throw new HttpException('Origen invalido', 422);
        }

        return $origin;
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

    private function intAtLeast(mixed $value, string $field, int $minimum): int
    {
        $integer = (int) $value;

        if ($integer < $minimum) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} debe ser mayor o igual a {$minimum}"]]);
        }

        return $integer;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->date($value);
    }

    private function date(mixed $value): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);

        if (!$date || $date->format('Y-m-d') !== (string) $value) {
            throw new HttpException('Formato de fecha invalido', 422);
        }

        return (string) $value;
    }

    private function isExpired(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return (string) $value < (new DateTimeImmutable('today'))->format('Y-m-d');
    }

    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
