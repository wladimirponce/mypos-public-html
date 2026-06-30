<?php

declare(strict_types=1);

namespace Mypos\Services;

use DateTimeImmutable;
use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\DteIntegrationRepository;
use Throwable;

final class DteIntegrationService
{
    private const MODOS = ['SIMULADO', 'REAL'];
    private const AMBIENTES = ['CERTIFICACION', 'PRODUCCION'];
    private const ESTADOS = ['PENDIENTE', 'EN_PROCESO', 'EMITIDO', 'ENVIADO', 'ACEPTADO', 'RECHAZADO', 'ERROR'];
    private const TIPOS = ['BOLETA', 'FACTURA', 'GUIA_DESPACHO', 'NOTA_CREDITO'];

    private DteIntegrationRepository $repository;

    public function __construct(?DteIntegrationRepository $repository = null)
    {
        $this->repository = $repository ?? new DteIntegrationRepository(Database::connection());
    }

    public function configuracion(int $empresaId): array
    {
        $this->validateEmpresa($empresaId);

        return $this->normalizeConfig($this->repository->config($empresaId) ?? [
            'empresa_id' => $empresaId,
            'modo' => 'SIMULADO',
            'sistema_path' => $this->defaultSistemaPath(),
            'endpoint_cli' => null,
            'endpoint_http' => null,
            'salida_xml_dir' => null,
            'salida_pdf_dir' => null,
            'ambiente' => 'CERTIFICACION',
            'activo' => 1,
            'metadata_json' => null,
        ]);
    }

    public function actualizarConfiguracion(int $userId, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $previous = $this->configuracion($empresaId);
        $mode = $this->mode($payload['modo'] ?? 'SIMULADO');
        $path = trim((string) ($payload['sistema_path'] ?? $previous['sistema_path'] ?? $this->defaultSistemaPath()));
        $endpointHttp = $this->nullableString($payload['endpoint_http'] ?? $previous['endpoint_http'] ?? null);

        // En SaaS el modo REAL usa endpoint_http hacia el facturador admin. El
        // sistema_path queda solo para integraciones locales/legacy.
        if ($mode === 'REAL') {
            if ($path === '' && $endpointHttp === null) {
                throw new HttpException('Error de validacion', 422, [
                    'endpoint_http' => ['Configura endpoint_http del facturador o sistema_path para modo REAL'],
                ]);
            }
            if ($path !== '' && $this->looksLikeLocalPath($path) && !$this->pathExistsInRuntime($path)) {
                throw new HttpException('El path del sistema DTE no es accesible desde este runtime', 422);
            }
        }

        $this->repository->upsertConfig([
            'empresa_id' => $empresaId,
            'modo' => $mode,
            'sistema_path' => $path,
            'endpoint_cli' => $this->nullableString($payload['endpoint_cli'] ?? $previous['endpoint_cli'] ?? null),
            'endpoint_http' => $endpointHttp,
            'salida_xml_dir' => $this->nullableString($payload['salida_xml_dir'] ?? $previous['salida_xml_dir'] ?? null),
            'salida_pdf_dir' => $this->nullableString($payload['salida_pdf_dir'] ?? $previous['salida_pdf_dir'] ?? null),
            'ambiente' => $this->environment($payload['ambiente'] ?? 'CERTIFICACION'),
            'activo' => $this->bool($payload['activo'] ?? true),
            'metadata_json' => $this->encodeJson(is_array($payload['metadata'] ?? null) ? $payload['metadata'] : []),
        ]);

        $current = $this->configuracion($empresaId);
        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'dte',
            'accion' => 'configuracion_actualizar',
            'entidad' => 'dte_configuracion',
            'descripcion' => 'Configuracion DTE actualizada',
            'datos_anteriores' => $previous,
            'datos_nuevos' => $current,
        ]);

        return $current;
    }

    public function assertReadyForEmission(int $empresaId): array
    {
        $config = $this->configuracion($empresaId);

        if (!(bool) $config['activo']) {
            throw new HttpException('La integracion DTE esta inactiva para esta empresa', 422);
        }

        if ($config['modo'] === 'REAL' && empty($config['endpoint_http'])) {
            throw new HttpException('Integracion real DTE no configurada; use modo SIMULADO o configure endpoint real.', 422);
        }
        if ($config['modo'] === 'REAL') {
            $this->adminApiKey($config, $empresaId);
        }

        return $config;
    }

    public function emitirDocumento(int $userId, array $document, array $options = []): array
    {
        $empresaId = (int) $document['empresa_id'];
        $config = $this->assertReadyForEmission($empresaId);

        $request = $this->construirPayloadDesdeDocumento((int) $document['id'], $empresaId);
        // Formato del PDF elegido en la caja (carta / 80 / 58): viaja al admin para
        // que genere la representación impresa en ese formato.
        $request['formato_pdf'] = (string) ($options['formato_pdf'] ?? 'carta');
        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();
            $emissionId = $this->repository->createEmission([
                'empresa_id' => $empresaId,
                'sucursal_id' => $document['sucursal_id'] !== null ? (int) $document['sucursal_id'] : null,
                'documento_emitido_id' => (int) $document['id'],
                'tipo_documento' => (string) $document['tipo_documento'],
                'folio' => (int) $document['folio'],
                'modo' => (string) $config['modo'],
                'estado' => 'EN_PROCESO',
                'request_json' => $this->encodeJson($request),
                'intentos' => 1,
                'created_by_usuario_id' => $userId,
            ]);

            $this->event($empresaId, $emissionId, (int) $document['id'], 'emision_iniciada', 'Emision DTE iniciada', [
                'modo' => $config['modo'],
                'tipo_documento' => $document['tipo_documento'],
                'folio' => $document['folio'],
            ]);

            $result = $config['modo'] === 'SIMULADO'
                ? $this->emitirSimulado($request, $config)
                : $this->emitirReal($request, $config);

            $this->persistEmissionResult($empresaId, $emissionId, (int) $document['id'], $result, 0);
            $this->event($empresaId, $emissionId, (int) $document['id'], 'emision_resultado', 'Resultado de emision DTE', $result);

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $document['sucursal_id'] !== null ? (int) $document['sucursal_id'] : null,
                'usuario_id' => $userId,
                'modulo' => 'dte',
                'accion' => 'emitir',
                'entidad' => 'dte_emisiones',
                'entidad_id' => $emissionId,
                'descripcion' => 'Emision DTE ejecutada',
                'datos_nuevos' => [
                    'documento_emitido_id' => (int) $document['id'],
                    'tipo_documento' => (string) $document['tipo_documento'],
                    'folio' => (int) $document['folio'],
                    'modo' => (string) $config['modo'],
                    'estado' => $result['estado'],
                ],
            ], $connection);

            $connection->commit();

            // Notificación al cliente (email/WhatsApp) — fire-and-forget, no bloquea la respuesta
            try {
                $ventaId = $this->repository->ventaIdFromDocument((int) $document['id']);
                if ($ventaId !== null) {
                    (new NotificacionVentaService($connection))->notificar($ventaId, (int) $document['id'], $empresaId);
                }
            } catch (Throwable $notifException) {
                error_log('[DTE] Notificacion fallo (no critico): ' . $notifException->getMessage());
            }

            return [
                'documento_emitido_id' => (int) $document['id'],
                'dte_emision_id' => $emissionId,
                'tipo_documento' => (string) $document['tipo_documento'],
                'folio' => (int) $document['folio'],
                'estado' => $result['estado'],
                'modo' => (string) $config['modo'],
                'xml_path' => $result['xml_path'],
                'pdf_path' => $result['pdf_path'],
                'track_id' => $result['track_id'] ?? null,
                'dte_print_payload' => $result['dte_print_payload'] ?? null,
                'error' => $result['error'] ?? null,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function listarEmisiones(array $filters): array
    {
        $empresaId = $this->positiveInt($filters, 'empresa_id');
        $this->validateEmpresa($empresaId);
        $this->validateFilters($filters);

        return ['emisiones' => $this->repository->listEmissions($empresaId, $filters)];
    }

    public function detalleEmision(int $id, int $empresaId): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $emission = $this->requireEmission($empresaId, $id);

        return [
            'emision' => $emission,
            'documento' => $this->repository->findDocument($empresaId, (int) $emission['documento_emitido_id']),
            'request' => $this->decodeJson($emission['request_json'] ?? null),
            'response' => $this->decodeJson($emission['response_json'] ?? null),
            'eventos' => array_map(fn (array $event): array => $this->decodeEvent($event), $this->repository->emissionEvents($empresaId, $id)),
        ];
    }

    public function reintentar(int $userId, int $id, array $payload): array
    {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $emission = $this->requireEmission($empresaId, $id);

        if (!in_array((string) $emission['estado'], ['ERROR', 'RECHAZADO'], true)) {
            throw new HttpException('Solo se pueden reintentar emisiones en ERROR o RECHAZADO', 422);
        }

        $document = $this->repository->findDocument($empresaId, (int) $emission['documento_emitido_id']);
        if ($document === null) {
            throw new HttpException('Documento tributario no encontrado', 404);
        }

        $config = $this->configuracion($empresaId);
        $request = $this->decodeJson($emission['request_json'] ?? null) ?? $this->construirPayloadDesdeDocumento((int) $document['id'], $empresaId);
        $connection = $this->repository->connection();

        try {
            $connection->beginTransaction();
            $result = $config['modo'] === 'SIMULADO'
                ? $this->emitirSimulado($request, $config)
                : $this->emitirReal($request, $config);

            $this->persistEmissionResult($empresaId, $id, (int) $document['id'], $result, 1);
            $this->event($empresaId, $id, (int) $document['id'], 'reintento', 'Reintento de emision DTE', $result);

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'sucursal_id' => $document['sucursal_id'] !== null ? (int) $document['sucursal_id'] : null,
                'usuario_id' => $userId,
                'modulo' => 'dte',
                'accion' => 'reintentar',
                'entidad' => 'dte_emisiones',
                'entidad_id' => $id,
                'descripcion' => 'Emision DTE reintentada',
                'datos_nuevos' => ['estado' => $result['estado']],
            ], $connection);

            $connection->commit();

            return [
                'dte_emision_id' => $id,
                'documento_emitido_id' => (int) $document['id'],
                'estado' => $result['estado'],
                'modo' => (string) $config['modo'],
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function marcarAceptado(int $userId, int $id, array $payload): array
    {
        return $this->marcarEstadoManual($userId, $id, $payload, 'ACEPTADO', 'ACEPTADO_SII', 'ACEPTADO', null);
    }

    public function marcarRechazado(int $userId, int $id, array $payload): array
    {
        $error = $this->nullableString($payload['error_sii'] ?? null) ?? 'Rechazo manual';

        return $this->marcarEstadoManual($userId, $id, $payload, 'RECHAZADO', 'RECHAZADO_SII', 'RECHAZADO', $error);
    }

    public function construirPayloadDesdeDocumento(int $documentId, int $empresaId): array
    {
        $document = $this->repository->findDocument($empresaId, $documentId);
        if ($document === null) {
            throw new HttpException('Documento tributario no encontrado', 404);
        }

        $details = $this->repository->documentDetails($documentId);
        $taxes = $this->repository->documentTaxes($documentId);
        $configuration = new ConfiguracionService();
        $empresa = $configuration->empresa($empresaId);
        $sucursal = $document['sucursal_id'] !== null
            ? $configuration->sucursal($empresaId, (int) $document['sucursal_id'])['configuracion_sucursal']
            : [];

        return [
            'documento' => [
                'id' => (int) $document['id'],
                'empresa_id' => (int) $document['empresa_id'],
                'sucursal_id' => $document['sucursal_id'] !== null ? (int) $document['sucursal_id'] : null,
                'tipo_documento' => (string) $document['tipo_documento'],
                'tipo_dte' => $this->tipoDte((string) $document['tipo_documento']),
                'folio' => (int) $document['folio'],
                'fecha_emision' => (string) $document['fecha_emision'],
                'neto' => (int) $document['neto'],
                'exento' => (int) $document['exento'],
                'impuestos' => (int) $document['impuestos'],
                'total' => (int) $document['total'],
            ],
            'emisor' => [
                'rut' => $empresa['rut_empresa'] ?? null,
                'razon_social' => $empresa['razon_social'] ?? null,
                'nombre_fantasia' => $empresa['nombre_fantasia'] ?? null,
                'giro' => $empresa['giro'] ?? null,
                'direccion' => $this->firstNonEmpty($sucursal['direccion'] ?? null, $empresa['direccion'] ?? null),
                'comuna' => $this->firstNonEmpty($sucursal['comuna'] ?? null, $empresa['comuna'] ?? null),
                'ciudad' => $this->firstNonEmpty($sucursal['ciudad'] ?? null, $empresa['ciudad'] ?? null),
                'telefono' => $this->firstNonEmpty($sucursal['telefono'] ?? null, $empresa['telefono_contacto'] ?? null),
                'sitio_web' => $empresa['sitio_web'] ?? null,
            ],
            'receptor' => [
                'rut' => $document['rut_receptor'],
                'nombre' => $document['razon_social_receptor'] ?: 'Consumidor Final',
                'giro' => $document['giro_receptor'],
                'direccion' => $document['direccion_receptor'],
                'comuna' => $document['comuna_receptor'],
                'ciudad' => $document['ciudad_receptor'],
            ],
            'items' => array_map(static fn (array $detail): array => [
                'codigo' => $detail['codigo_producto'],
                'nombre' => $detail['nombre_producto'],
                'cantidad' => (float) $detail['cantidad'],
                'precio' => (int) $detail['precio_unitario'],
                'descuento' => (int) $detail['descuento'],
                'neto' => (int) $detail['neto'],
                'exento' => (int) $detail['exento'],
                'impuestos' => (int) $detail['impuestos'],
                'total' => (int) $detail['total'],
            ], $details),
            'impuestos' => array_map(static fn (array $tax): array => [
                'codigo' => $tax['codigo_impuesto'],
                'nombre' => $tax['nombre_impuesto'],
                'tasa_base_10000' => (int) $tax['tasa_base_10000'],
                'monto' => (int) $tax['monto'],
            ], $taxes),
        ];
    }

    private function emitirSimulado(array $payload, array $config): array
    {
        $type = (string) $payload['documento']['tipo_documento'];
        $folio = (int) $payload['documento']['folio'];
        $date = (new DateTimeImmutable())->format('YmdHis');
        $base = $type . '_' . $folio . '_' . $date;
        $xmlPath = $config['salida_xml_dir'] !== null && $config['salida_xml_dir'] !== ''
            ? rtrim((string) $config['salida_xml_dir'], '\\/') . DIRECTORY_SEPARATOR . $base . '.xml'
            : '/storage/dte/simulado/' . $type . '_' . $folio . '.xml';
        $pdfPath = $config['salida_pdf_dir'] !== null && $config['salida_pdf_dir'] !== ''
            ? rtrim((string) $config['salida_pdf_dir'], '\\/') . DIRECTORY_SEPARATOR . $base . '.pdf'
            : '/storage/dte/simulado/' . $type . '_' . $folio . '.pdf';

        return [
            'success' => true,
            'estado' => 'EMITIDO',
            'track_id' => null,
            'xml_path' => $xmlPath,
            'pdf_path' => $pdfPath,
            'response' => [
                'modo' => 'SIMULADO',
                'ambiente' => $config['ambiente'],
                'mensaje' => 'DTE simulado generado sin enviar al SII',
                'payload_resumen' => [
                    'tipo_documento' => $type,
                    'folio' => $folio,
                    'total' => (int) $payload['documento']['total'],
                ],
            ],
            'error' => null,
        ];
    }

    private function emitirReal(array $payload, array $config): array
    {
        if (empty($config['endpoint_http'])) {
            throw new HttpException('Integracion real DTE no configurada; use modo SIMULADO o configure endpoint real.', 422);
        }

        $endpoint = (string) $config['endpoint_http'];
        $apiKey = $this->adminApiKey($config, (int) $payload['documento']['empresa_id']);
        $request = $this->adminGeneratePayload($payload);
        $cafXml = $this->cafXmlForEmission(
            (int) $payload['documento']['empresa_id'],
            (int) $payload['documento']['tipo_dte'],
            (int) $payload['documento']['folio']
        );
        if ($cafXml !== null) {
            $request['caf_xml_base64'] = base64_encode($cafXml);
        }
        $generate = $this->adminRequest($endpoint, 'generate', $request, $apiKey);

        if (empty($generate['ok'])) {
            return [
                'success' => false,
                'estado' => 'ERROR',
                'track_id' => null,
                'xml_path' => null,
                'pdf_path' => null,
                'response' => [
                    'modo' => 'REAL',
                    'paso' => 'generate',
                    'request' => $request,
                    'admin_response' => $generate,
                ],
                'error' => (string) ($generate['error'] ?? $generate['message'] ?? 'No se pudo generar DTE real'),
            ];
        }

        // El admin devuelve el XML firmado en base64 (byte-exacto; en ISO-8859-1 no
        // sobrevive json_encode). Decodificar para usarlo en send y extraer el TED.
        $xml = !empty($generate['xml_base64'])
            ? (string) base64_decode((string) $generate['xml_base64'], true)
            : (string) ($generate['xml'] ?? '');
        $tipo = (int) ($generate['tipo'] ?? $request['tipo']);
        $folio = (int) ($generate['folio'] ?? $request['folio'] ?? 0);

        if ($xml === '' || $folio <= 0) {
            return [
                'success' => false,
                'estado' => 'ERROR',
                'track_id' => null,
                'xml_path' => null,
                'pdf_path' => null,
                'response' => [
                    'modo' => 'REAL',
                    'paso' => 'generate',
                    'request' => $request,
                    'admin_response' => $this->withoutXml($generate),
                ],
                'error' => 'Admin DTE no devolvio XML o folio valido',
            ];
        }

        // Reenviar el XML firmado en base64: en ISO-8859-1 rompería el json_encode
        // del payload hacia el admin ("No se pudo serializar payload DTE").
        $send = $this->adminRequest($endpoint, 'send', [
            'empresa_id' => (int) $payload['documento']['empresa_id'],
            'xml_base64' => base64_encode($xml),
            'tipo' => $tipo,
            'folio' => $folio,
        ], $apiKey);

        $tedXml = $this->tedXmlFromGenerateResponse($generate) ?? $this->extractTedXml($xml);
        $sendOk = !empty($send['ok']);
        $trackId = isset($send['trackId']) ? (string) $send['trackId'] : (isset($send['track_id']) ? (string) $send['track_id'] : null);
        $printPayload = $this->buildPrintPayload($payload, $generate, $send, $tedXml);
        if ($printPayload !== null && isset($generate['pdf_error'])) {
            $printPayload['pdf_error'] = (string) $generate['pdf_error'];
        }
        $printDiagnostics = [
            'has_xml_base64' => isset($generate['xml_base64']) && is_string($generate['xml_base64']) && trim($generate['xml_base64']) !== '',
            'has_ted_xml_base64' => isset($generate['ted_xml_base64']) && is_string($generate['ted_xml_base64']) && trim($generate['ted_xml_base64']) !== '',
            'has_ted_png_base64' => isset($generate['ted_png_base64']) && is_string($generate['ted_png_base64']) && trim($generate['ted_png_base64']) !== '',
            'has_pdf_base64' => isset($generate['pdf_base64']) && is_string($generate['pdf_base64']) && trim($generate['pdf_base64']) !== '',
            'pdf_error' => isset($generate['pdf_error']) ? (string) $generate['pdf_error'] : null,
            'ted_png_error' => isset($generate['ted_png_error']) ? (string) $generate['ted_png_error'] : null,
            'ted_extraido' => $tedXml !== null && $tedXml !== '',
        ];

        // Respaldo en la nube: guardar el PDF que devolvió el admin (with_pdf) en
        // storage/boletas/{empresa}/{folio}.pdf. La venta no falla si esto falla.
        $pdfPath = null;
        if (!empty($generate['pdf_base64'])) {
            $pdfPath = $this->guardarBoletaPdf(
                (int) $payload['documento']['empresa_id'],
                $tipo,
                $folio,
                (string) $generate['pdf_base64']
            );
        }

        return [
            'success' => $sendOk,
            'estado' => $sendOk ? 'ENVIADO' : 'ERROR',
            'track_id' => $trackId,
            'xml_path' => "admin://dte/T{$tipo}F{$folio}",
            'pdf_path' => $pdfPath,
            'response' => [
                'modo' => 'REAL',
                'generate' => $this->withoutXml($generate),
                'send' => $send,
                'ted_xml' => $tedXml,
                'print_diagnostics' => $printDiagnostics,
                'dte_print_payload' => $printPayload,
            ],
            'dte_print_payload' => $printPayload,
            'error' => $sendOk
                ? ($printPayload === null ? $this->printPayloadErrorMessage($printDiagnostics) : null)
                : (string) ($send['error'] ?? $send['mensaje'] ?? $generate['pdf_error'] ?? 'No se pudo enviar DTE al SII'),
        ];
    }

    private function printPayloadErrorMessage(array $diagnostics): string
    {
        $parts = [];
        if (!empty($diagnostics['pdf_error'])) {
            $parts[] = 'PDF: ' . $diagnostics['pdf_error'];
        }
        if (!empty($diagnostics['ted_png_error'])) {
            $parts[] = 'PNG timbre: ' . $diagnostics['ted_png_error'];
        }
        if ($parts !== []) {
            return 'El facturador emitio la boleta, pero no genero payload imprimible. ' . implode(' | ', $parts);
        }

        return 'El facturador emitio la boleta, pero no devolvio TED, PNG ni PDF imprimible.';
    }

    private function adminGeneratePayload(array $payload): array
    {
        $document = $payload['documento'];
        $receptor = $payload['receptor'];

        return [
            // empresa_id explicito: el admin (modo SaaS) resuelve la empresa por id
            // via getById; getByApiKey no aplica porque no hay key por empresa.
            'empresa_id' => (int) $document['empresa_id'],
            'tipo' => (int) $document['tipo_dte'],
            'folio' => (int) $document['folio'],
            'fecha' => (string) $document['fecha_emision'],
            // Pedir al admin la representación impresa en PDF (base64) junto con el
            // XML, para guardarla como respaldo en la nube. Reutiliza el generador
            // de certificación (MuestraPdfGenerator) — mismo timbre PDF417.
            'with_pdf' => 1,
            // Formato del PDF que eligió la caja (carta / 80 / 58). Solo aplica a boletas.
            'formato_pdf' => (string) ($payload['formato_pdf'] ?? 'carta'),
            'receptor' => [
                'rut' => (string) ($receptor['rut'] ?? '66666666-6'),
                'nombre' => (string) ($receptor['nombre'] ?? 'Consumidor Final'),
                'giro' => $receptor['giro'] ?? null,
                'direccion' => $receptor['direccion'] ?? null,
                'comuna' => $receptor['comuna'] ?? null,
                'ciudad' => $receptor['ciudad'] ?? null,
            ],
            'items' => array_map(static fn (array $item): array => [
                'codigo' => $item['codigo'] ?? null,
                'nombre' => (string) ($item['nombre'] ?? 'Item'),
                'cantidad' => (float) ($item['cantidad'] ?? 1),
                'precio' => (int) ($item['precio'] ?? 0),
                'descuento' => (int) ($item['descuento'] ?? 0),
                'exento' => (int) ($item['exento'] ?? 0) > 0,
            ], $payload['items'] ?? []),
        ];
    }

    /**
     * Lee el CAF desde el archivo en disco (bytes originales del SII, ISO-8859-1).
     * Devuelve null si no hay archivo: el admin usará su propio filesystem
     * (escrito por provisionarCredenciales).
     * NUNCA lee de la columna caf_xml: puede tener '?' en lugar de Ñ por la
     * corrupción utf8mb4 al guardar bytes ISO-8859-1 raw sin convertir primero.
     */
    private function cafXmlForEmission(int $empresaId, int $tipoDte, int $folio): ?string
    {
        $tipoDocumento = match ($tipoDte) {
            33, 34 => 'FACTURA',
            39, 41 => 'BOLETA',
            52 => 'GUIA_DESPACHO',
            56 => 'NOTA_DEBITO',
            61 => 'NOTA_CREDITO',
            default => null,
        };
        if ($tipoDocumento === null || $folio <= 0) {
            return null;
        }

        $statement = Database::connection()->prepare(
            'SELECT archivo_path
             FROM caf_archivos
             WHERE empresa_id = :empresa_id
               AND tipo_documento = :tipo_documento
               AND estado = \'ACTIVO\'
               AND :folio BETWEEN folio_desde AND folio_hasta
             ORDER BY folio_desde ASC
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'tipo_documento' => $tipoDocumento,
            'folio' => $folio,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        $path = trim((string) ($row['archivo_path'] ?? ''));
        if ($path === '') {
            return null;
        }

        $fullPath = dirname(__DIR__, 2) . '/storage/' . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
        if (!is_file($fullPath)) {
            return null;
        }

        $bytes = file_get_contents($fullPath);
        return (is_string($bytes) && $bytes !== '') ? $bytes : null;
    }

    /** Directorio base de respaldo de boletas/DTE en PDF (en la nube/servidor). */
    private function boletasDir(int $empresaId): string
    {
        return dirname(__DIR__, 2) . '/storage/boletas/' . $empresaId;
    }

    /**
     * Guarda la representación impresa (PDF base64 que devolvió el admin) en
     * storage/boletas/{empresa}/{folio}.pdf. Devuelve la ruta relativa, o null
     * si no se pudo escribir (la venta NO debe fallar por esto).
     */
    private function guardarBoletaPdf(int $empresaId, int $tipo, int $folio, string $base64): ?string
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $dir = $this->boletasDir($empresaId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        // Nombre = número de boleta (folio). Boletas (39/41) usan su propio rango
        // de folios; otros tipos se prefijan para no colisionar.
        $name = in_array($tipo, [39, 41], true) ? "{$folio}.pdf" : "T{$tipo}-{$folio}.pdf";
        $full = $dir . '/' . $name;
        if (@file_put_contents($full, $bytes) === false) {
            return null;
        }
        return "boletas/{$empresaId}/{$name}";
    }

    /**
     * Ruta absoluta del PDF guardado para un folio (boleta = {folio}.pdf; otros
     * tipos = T{tipo}-{folio}.pdf). Devuelve null si no existe.
     */
    public function rutaBoletaPdf(int $empresaId, int $folio): ?string
    {
        $dir = $this->boletasDir($empresaId);
        $direct = $dir . "/{$folio}.pdf";
        if (is_file($direct)) {
            return $direct;
        }
        $matches = glob($dir . "/T*-{$folio}.pdf") ?: [];
        return $matches[0] ?? null;
    }

    /**
     * Ruta absoluta del PDF de una emisión por su id (para descargar/ver desde el
     * POS cuando no hay impresora local). null si no hay PDF guardado.
     */
    public function pdfPathDeEmision(int $emisionId, int $empresaId): ?string
    {
        $detalle = $this->detalleEmision($emisionId, $empresaId);
        $folio = (int) ($detalle['emision']['folio'] ?? 0);
        return $folio > 0 ? $this->rutaBoletaPdf($empresaId, $folio) : null;
    }

    /**
     * Devuelve el PDF de una emision desde storage o, si no se pudo persistir el
     * archivo, desde el pdf_base64 guardado en response_json.
     */
    public function pdfBytesDeEmision(int $emisionId, int $empresaId): ?array
    {
        $detalle = $this->detalleEmision($emisionId, $empresaId);
        $emision = $detalle['emision'] ?? [];
        $folio = (int) ($emision['folio'] ?? 0);
        if ($folio <= 0) {
            return null;
        }

        $path = $this->rutaBoletaPdf($empresaId, $folio);
        if ($path !== null && is_file($path)) {
            $bytes = file_get_contents($path);
            if (is_string($bytes) && $bytes !== '') {
                return [
                    'bytes' => $bytes,
                    'filename' => basename($path),
                ];
            }
        }

        $base64 = $this->pdfBase64FromEmissionResponse($detalle['response'] ?? null);
        if ($base64 === null) {
            return null;
        }

        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $tipo = $this->tipoDteFromEmission($emision['tipo_documento'] ?? 39);
        $this->guardarBoletaPdf($empresaId, $tipo, $folio, $base64);

        $filename = in_array($tipo, [39, 41], true) ? "{$folio}.pdf" : "T{$tipo}-{$folio}.pdf";
        return [
            'bytes' => $bytes,
            'filename' => $filename,
        ];
    }

    /**
     * Provisiona en el facturador (admin) el certificado digital (.pfx + clave) y/o
     * el CAF de producción de la empresa. El navegador sube los archivos al backend
     * web (multipart) y este los reenvía al admin con la API key, que los escribe en
     * su filesystem por RUT/AMBIENTE. Cierra el hueco de tener que copiarlos a mano.
     *
     * @param array $payload  Campos de texto ($_POST): empresa_id, password_certificado, caf_tipo
     * @param array $files    Archivos ($_FILES): 'certificado' (.pfx) y/o 'caf' (XML)
     */
    public function provisionarCredenciales(int $userId, array $payload, array $files): array
    {
        $empresaId = (int) ($payload['empresa_id'] ?? 0);
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $config = $this->assertReadyForEmission($empresaId);
        $endpoint = (string) $config['endpoint_http'];
        $apiKey = $this->adminApiKey($config, $empresaId);

        $request = ['empresa_id' => $empresaId];

        $pfxTmp = (string) ($files['certificado']['tmp_name'] ?? '');
        if ($pfxTmp !== '' && is_uploaded_file($pfxTmp)) {
            $bytes = file_get_contents($pfxTmp);
            if ($bytes === false || $bytes === '') {
                throw new HttpException('No se pudo leer el certificado subido', 422);
            }
            $request['pfx_base64'] = base64_encode($bytes);
            $request['pfx_password'] = (string) ($payload['password_certificado'] ?? $payload['pfx_password'] ?? '');
        }

        $cafTmp = (string) ($files['caf']['tmp_name'] ?? '');
        if ($cafTmp !== '' && is_uploaded_file($cafTmp)) {
            $cafXml = file_get_contents($cafTmp);
            if ($cafXml === false || $cafXml === '') {
                throw new HttpException('No se pudo leer el archivo CAF subido', 422);
            }
            // El CAF del SII viene en ISO-8859-1; enviarlo en base64 preserva los
            // bytes exactos (criticos para el timbre) y evita que json_encode falle
            // por contenido no-UTF8.
            $request['caf_xml_base64'] = base64_encode($cafXml);
            $request['caf_tipo'] = (int) ($payload['caf_tipo'] ?? 39);
        }

        if (!isset($request['pfx_base64']) && !isset($request['caf_xml_base64'])) {
            throw new HttpException('Debe enviar el certificado (.pfx) y/o el CAF (XML).', 422);
        }

        $response = $this->adminRequest($endpoint, 'provisionar_credenciales', $request, $apiKey);
        if (empty($response['ok'])) {
            throw new HttpException(
                (string) ($response['error'] ?? 'No se pudieron provisionar las credenciales en el facturador'),
                422
            );
        }

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'dte',
            'accion' => 'provisionar_credenciales',
            'entidad' => 'facturador',
            'entidad_id' => $empresaId,
            'descripcion' => 'Credenciales (cert/CAF) provisionadas en el facturador',
            'datos_nuevos' => [
                'ambiente' => $response['ambiente'] ?? null,
                'cert' => isset($request['pfx_base64']),
                'caf' => isset($request['caf_xml_base64']),
                'tipo_caf' => $request['caf_tipo'] ?? null,
            ],
        ]);

        return [
            'provisionado' => true,
            'ambiente' => $response['ambiente'] ?? null,
            'archivos' => $response['escritos'] ?? [],
            'mensaje' => (string) ($response['mensaje'] ?? 'Credenciales provisionadas en el facturador.'),
        ];
    }

    /**
     * Provisiona el CAF en el facturador (admin) automaticamente al subirlo, SOLO si la
     * empresa ya esta en REAL/PRODUCCION con endpoint configurado. Asi se elimina el paso
     * manual "Provisionar Facturador". Best-effort: en certificacion (SIMULADO) o si el
     * admin no responde, no pasa nada — el CAF queda guardado y el backend lo envia por
     * override (caf_xml_base64) en cada emision.
     *
     * @return array{provisioned: bool, reason?: string, response?: array}
     */
    public function provisionarCafSiListo(int $empresaId, string $cafXml, int $tipoDte): array
    {
        if ($empresaId <= 0 || trim($cafXml) === '') {
            return ['provisioned' => false, 'reason' => 'sin_caf'];
        }
        $config = $this->configuracion($empresaId);
        if (($config['modo'] ?? '') !== 'REAL' || trim((string) ($config['endpoint_http'] ?? '')) === '') {
            // Aun en certificacion / sin endpoint: no se auto-provisiona (no se rompe).
            return ['provisioned' => false, 'reason' => 'facturador_no_listo'];
        }

        $endpoint = (string) $config['endpoint_http'];
        $apiKey = $this->adminApiKey($config, $empresaId);
        $response = $this->adminRequest($endpoint, 'provisionar_credenciales', [
            'empresa_id' => $empresaId,
            'caf_xml_base64' => base64_encode($cafXml),
            'caf_tipo' => $tipoDte,
        ], $apiKey);

        return ['provisioned' => !empty($response['ok']), 'response' => $response];
    }

    private function adminRequest(string $endpoint, string $action, array $payload, string $apiKey): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'Extension cURL no disponible en PHP'];
        }

        $url = rtrim($endpoint, '?&');
        $url .= (str_contains($url, '?') ? '&' : '?') . 'action=' . rawurlencode($action);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['ok' => false, 'error' => 'No se pudo serializar payload DTE'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-API-KEY: ' . $apiKey,
            ],
        ]);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($curlError !== '') {
            return ['ok' => false, 'error' => 'Error de red contra admin DTE: ' . $curlError, 'http' => $http];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'Admin DTE no devolvio JSON valido',
                'http' => $http,
                'raw' => is_string($raw) ? substr($raw, 0, 500) : null,
            ];
        }

        if ($http >= 400) {
            $decoded['ok'] = false;
            $decoded['http'] = $http;
        }

        return $decoded;
    }

    private function adminApiKey(array $config, int $empresaId): string
    {
        // 1) Fuente principal: clave en BD (autogestionada, sin .env). El admin valida
        //    contra esta MISMA columna (comparten base de datos), asi que ambos lados
        //    quedan consistentes sin intervencion manual.
        $dbKey = $this->repository->adminApiKey($empresaId);
        if ($dbKey !== null) {
            return $dbKey;
        }

        // 2) Compatibilidad: variable de entorno de instalaciones antiguas.
        $metadata = $this->decodeJson($config['metadata_json'] ?? null) ?? [];
        $envName = is_string($metadata['admin_api_key_env'] ?? null) && trim((string) $metadata['admin_api_key_env']) !== ''
            ? trim((string) $metadata['admin_api_key_env'])
            : 'DTE_ADMIN_API_KEY_' . $empresaId;
        $apiKey = trim((string) ($_ENV[$envName] ?? getenv($envName) ?: ''));
        if ($apiKey === '') {
            $apiKey = trim((string) ($_ENV['DTE_ADMIN_API_KEY'] ?? getenv('DTE_ADMIN_API_KEY') ?: ''));
        }

        // 3) Sin BD ni .env: autogenerar y persistir. La proxima llamada (y el admin)
        //    leen la misma clave desde la BD. Nunca falla por falta de configuracion.
        if ($apiKey === '') {
            $apiKey = bin2hex(random_bytes(24));
        }
        try {
            $this->repository->setAdminApiKey($empresaId, $apiKey);
        } catch (\Throwable $e) {
            // Si la columna no existe aun (migracion 065 sin aplicar), seguimos con la
            // clave en memoria; el admin no exige match mientras la columna este vacia.
        }

        return $apiKey;
    }

    private function extractTedXml(string $xml): ?string
    {
        if (preg_match('/<TED\b[^>]*>.*?<\/TED>/s', $xml, $match) === 1) {
            return $match[0];
        }

        return null;
    }

    private function tedXmlFromGenerateResponse(array $generate): ?string
    {
        $base64 = $generate['ted_xml_base64'] ?? null;
        if (!is_string($base64) || trim($base64) === '') {
            return null;
        }

        $decoded = base64_decode($base64, true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        return $this->extractTedXml($decoded);
    }

    private function withoutXml(array $response): array
    {
        if (isset($response['xml'])) {
            $response['xml_length'] = strlen((string) $response['xml']);
            unset($response['xml']);
        }
        if (isset($response['xml_base64'])) {
            $response['xml_length'] = strlen((string) base64_decode((string) $response['xml_base64'], true));
            unset($response['xml_base64']);
        }

        return $response;
    }

    private function buildPrintPayload(array $payload, array $generate, array $send, ?string $tedXml): ?array
    {
        $pdfBase64 = is_string($generate['pdf_base64'] ?? null) ? trim((string) $generate['pdf_base64']) : '';
        $timbrePngBase64 = is_string($generate['ted_png_base64'] ?? null) ? trim((string) $generate['ted_png_base64']) : '';
        if (($tedXml === null || $tedXml === '') && $pdfBase64 === '' && $timbrePngBase64 === '') {
            return null;
        }

        $document = $payload['documento'];
        $emisor = $this->emisorParaImpresion($payload);
        $items = array_map(static fn (array $item): array => [
            'nombre' => (string) ($item['nombre'] ?? 'Item'),
            'cantidad' => (float) ($item['cantidad'] ?? 1),
            'precio_unitario' => (int) ($item['precio'] ?? 0),
            'subtotal' => (int) ($item['total'] ?? 0),
        ], $payload['items'] ?? []);

        return [
            'tipo' => 'boleta_electronica_dte',
            'tipo_dte' => (int) ($generate['tipo'] ?? $document['tipo_dte']),
            'folio_dte' => (int) ($generate['folio'] ?? $document['folio']),
            'fecha_dte' => $this->formatPrintDate($document['fecha_emision'] ?? null),
            'track_id' => isset($send['trackId']) ? (string) $send['trackId'] : null,
            'ted_xml' => $tedXml,
            // El TED está en ISO-8859-1 (incluye el CAF con Ñ/tildes). En base64
            // viaja byte-exacto hasta el print server sin que JSON_INVALID_UTF8_SUBSTITUTE
            // lo corrompa; si no, el PDF417 codifica un TED roto y el SII lo rechaza.
            'ted_b64' => $tedXml !== null && $tedXml !== '' ? base64_encode($tedXml) : null,
            // PDF oficial generado por admin (mismo layout que "Ver boleta"). El print
            // server lo usa al guardar/imprimir PDF para no reconstruir otro formato.
            'pdf_base64' => $pdfBase64 !== '' ? $pdfBase64 : null,
            // Imagen PNG del timbre PDF417 renderizada por el admin (TCPDF), que es la
            // que la app del SII reconoce. Si viene, el print server la usa tal cual en
            // vez de rasterizar su propio PDF417.
            'timbre_png_b64' => $timbrePngBase64 !== '' ? $timbrePngBase64 : null,
            'razon_social' => $emisor['razon_social'] ?? null,
            'nombre_fantasia' => $emisor['nombre_fantasia'] ?? null,
            'rut_emisor' => $emisor['rut'] ?? null,
            'giro' => $emisor['giro'] ?? null,
            'direccion' => $emisor['direccion'] ?? null,
            'comuna' => $emisor['comuna'] ?? null,
            'ciudad' => $emisor['ciudad'] ?? null,
            'telefono' => $emisor['telefono'] ?? null,
            'sitio_web' => $emisor['sitio_web'] ?? null,
            'nro_resol' => $generate['nro_resol'] ?? null,
            'fch_resol' => $generate['fch_resol'] ?? null,
            // Link de verificación pública en MyPOS (se imprime abajo en el ticket):
            // el cliente puede ver la boleta por RUT + folio sin login.
            'verify_url' => 'www.mypos.cl/boleta',
            'total' => (int) $document['total'],
            'neto' => (int) $document['neto'],
            'iva' => (int) $document['impuestos'],
            'exento' => (int) ($document['exento'] ?? 0),
            'productos' => $items,
        ];
    }

    private function emisorParaImpresion(array $payload): array
    {
        if (is_array($payload['emisor'] ?? null) && $payload['emisor'] !== []) {
            return $payload['emisor'];
        }

        $document = $payload['documento'];
        $empresaId = (int) $document['empresa_id'];
        $configuration = new ConfiguracionService();
        $empresa = $configuration->empresa($empresaId);
        $sucursal = isset($document['sucursal_id']) && (int) $document['sucursal_id'] > 0
            ? $configuration->sucursal($empresaId, (int) $document['sucursal_id'])['configuracion_sucursal']
            : [];

        return [
            'rut' => $empresa['rut_empresa'] ?? null,
            'razon_social' => $empresa['razon_social'] ?? null,
            'nombre_fantasia' => $empresa['nombre_fantasia'] ?? null,
            'giro' => $empresa['giro'] ?? null,
            'direccion' => $this->firstNonEmpty($sucursal['direccion'] ?? null, $empresa['direccion'] ?? null),
            'comuna' => $this->firstNonEmpty($sucursal['comuna'] ?? null, $empresa['comuna'] ?? null),
            'ciudad' => $this->firstNonEmpty($sucursal['ciudad'] ?? null, $empresa['ciudad'] ?? null),
            'telefono' => $this->firstNonEmpty($sucursal['telefono'] ?? null, $empresa['telefono_contacto'] ?? null),
            'sitio_web' => $empresa['sitio_web'] ?? null,
        ];
    }

    private function persistEmissionResult(int $empresaId, int $emissionId, int $documentId, array $result, int $incrementAttempts): void
    {
        $this->repository->updateEmissionResult($empresaId, $emissionId, [
            'estado' => (string) $result['estado'],
            'response_json' => $this->encodeJson($result['response'] ?? []),
            'xml_path' => $result['xml_path'] ?? null,
            'pdf_path' => $result['pdf_path'] ?? null,
            'track_id' => $result['track_id'] ?? null,
            'error_mensaje' => $result['error'] ?? null,
            'incrementar_intentos' => $incrementAttempts,
        ]);

        $documentState = match ((string) $result['estado']) {
            'ACEPTADO' => 'ACEPTADO_SII',
            'RECHAZADO' => 'RECHAZADO_SII',
            'ENVIADO' => 'ENVIADO_SII',
            default => 'EMITIDO_INTERNO',
        };
        $siiState = match ((string) $result['estado']) {
            'ACEPTADO' => 'ACEPTADO',
            'RECHAZADO', 'ERROR' => 'RECHAZADO',
            'ENVIADO' => 'PENDIENTE',
            default => 'NO_ENVIADO',
        };

        $this->repository->updateDocumentDte($empresaId, $documentId, [
            'estado' => $documentState,
            'xml_path' => $result['xml_path'] ?? null,
            'pdf_path' => $result['pdf_path'] ?? null,
            'track_id' => $result['track_id'] ?? null,
            'respuesta_sii_json' => $this->encodeJson($result['response'] ?? []),
            'error_sii' => $result['error'] ?? null,
            'estado_sii' => $siiState,
        ]);
    }

    private function marcarEstadoManual(
        int $userId,
        int $id,
        array $payload,
        string $emissionState,
        string $documentState,
        string $siiState,
        ?string $error
    ): array {
        $empresaId = $this->positiveInt($payload, 'empresa_id');
        $emission = $this->requireEmission($empresaId, $id);
        $trackId = $this->nullableString($payload['track_id'] ?? $emission['track_id'] ?? null);
        $response = is_array($payload['respuesta_sii'] ?? null) ? $payload['respuesta_sii'] : [];

        $this->repository->updateEmissionResult($empresaId, $id, [
            'estado' => $emissionState,
            'response_json' => $this->encodeJson($response),
            'xml_path' => $emission['xml_path'] ?? null,
            'pdf_path' => $emission['pdf_path'] ?? null,
            'track_id' => $trackId,
            'error_mensaje' => $error,
            'incrementar_intentos' => 0,
        ]);
        $this->repository->updateDocumentDte($empresaId, (int) $emission['documento_emitido_id'], [
            'estado' => $documentState,
            'xml_path' => $emission['xml_path'] ?? null,
            'pdf_path' => $emission['pdf_path'] ?? null,
            'track_id' => $trackId,
            'respuesta_sii_json' => $this->encodeJson($response),
            'error_sii' => $error,
            'estado_sii' => $siiState,
        ]);
        $this->event($empresaId, $id, (int) $emission['documento_emitido_id'], strtolower($emissionState), 'Estado DTE actualizado manualmente', [
            'estado' => $emissionState,
            'track_id' => $trackId,
            'error' => $error,
        ]);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'dte',
            'accion' => strtolower('marcar_' . $emissionState),
            'entidad' => 'dte_emisiones',
            'entidad_id' => $id,
            'descripcion' => 'Estado DTE actualizado manualmente',
            'datos_anteriores' => ['estado' => (string) $emission['estado']],
            'datos_nuevos' => ['estado' => $emissionState, 'track_id' => $trackId, 'error' => $error],
        ]);

        return [
            'dte_emision_id' => $id,
            'documento_emitido_id' => (int) $emission['documento_emitido_id'],
            'estado' => $emissionState,
            'track_id' => $trackId,
        ];
    }

    private function event(int $empresaId, ?int $emissionId, ?int $documentId, string $type, ?string $description, array $metadata = []): void
    {
        $this->repository->createEvent([
            'empresa_id' => $empresaId,
            'dte_emision_id' => $emissionId,
            'documento_emitido_id' => $documentId,
            'tipo_evento' => $type,
            'descripcion' => $description,
            'metadata_json' => $this->encodeJson($metadata),
        ]);
    }

    private function requireEmission(int $empresaId, int $id): array
    {
        $emission = $this->repository->findEmission($empresaId, $id);
        if ($emission === null) {
            throw new HttpException('Emision DTE no encontrada', 404);
        }

        return $emission;
    }

    private function validateEmpresa(int $empresaId): void
    {
        if ($empresaId <= 0 || !$this->repository->empresaExists($empresaId)) {
            throw new HttpException('Empresa no encontrada', 422);
        }
    }

    private function validateFilters(array $filters): void
    {
        if (!empty($filters['tipo_documento']) && !in_array(strtoupper((string) $filters['tipo_documento']), self::TIPOS, true)) {
            throw new HttpException('Tipo de documento invalido', 422);
        }
        if (!empty($filters['estado']) && !in_array(strtoupper((string) $filters['estado']), self::ESTADOS, true)) {
            throw new HttpException('Estado DTE invalido', 422);
        }
        if (!empty($filters['modo'])) {
            $this->mode($filters['modo']);
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

    private function normalizeConfig(array $row): array
    {
        $row['empresa_id'] = (int) $row['empresa_id'];
        $row['activo'] = (bool) (int) $row['activo'];
        if (empty($row['endpoint_http'])) {
            $row['endpoint_http'] = $_ENV['DTE_ENDPOINT_HTTP'] ?? getenv('DTE_ENDPOINT_HTTP') ?: null;
        }

        return $row;
    }

    private function decodeEvent(array $event): array
    {
        $event['metadata'] = $this->decodeJson($event['metadata_json'] ?? null);
        unset($event['metadata_json']);

        return $event;
    }

    private function pdfBase64FromEmissionResponse(?array $response): ?string
    {
        $candidates = [
            $response['generate']['pdf_base64'] ?? null,
            $response['dte_print_payload']['pdf_base64'] ?? null,
            $response['generate']['dte_print_payload']['pdf_base64'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return str_contains($candidate, ',') ? explode(',', $candidate, 2)[1] : $candidate;
            }
        }

        return null;
    }

    private function tipoDteFromEmission(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return match (strtoupper(trim((string) $value))) {
            'BOLETA' => 39,
            'BOLETA_EXENTA' => 41,
            'FACTURA' => 33,
            'FACTURA_EXENTA' => 34,
            'GUIA_DESPACHO' => 52,
            default => 39,
        };
    }

    private function positiveInt(array $data, string $field): int
    {
        $value = (int) ($data[$field] ?? 0);
        if ($value <= 0) {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return $value;
    }

    private function mode(mixed $value): string
    {
        $mode = strtoupper(trim((string) $value));
        if (!in_array($mode, self::MODOS, true)) {
            throw new HttpException('Modo DTE invalido', 422);
        }

        return $mode;
    }

    private function environment(mixed $value): string
    {
        $environment = strtoupper(trim((string) $value));
        if (!in_array($environment, self::AMBIENTES, true)) {
            throw new HttpException('Ambiente DTE invalido', 422);
        }

        return $environment;
    }

    private function tipoDte(string $type): int
    {
        return match ($type) {
            'BOLETA' => 39,
            'FACTURA' => 33,
            'GUIA_DESPACHO' => 52,
            'NOTA_CREDITO' => 61,
            default => 0,
        };
    }

    private function date(mixed $value): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
        if (!$date || $date->format('Y-m-d') !== (string) $value) {
            throw new HttpException('Formato de fecha invalido', 422);
        }

        return (string) $value;
    }

    private function bool(mixed $value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) ? 1 : 0;
    }

    private function requiredString(mixed $value, string $field): string
    {
        $string = trim((string) $value);
        if ($string === '') {
            throw new HttpException('Error de validacion', 422, [$field => ["El campo {$field} es obligatorio"]]);
        }

        return $string;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $string = $this->nullableString($value);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function formatPrintDate(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return (new DateTimeImmutable('now', new \DateTimeZone('America/Santiago')))->format('Y-m-d H:i:s');
        }

        try {
            return (new DateTimeImmutable($raw, new \DateTimeZone('America/Santiago')))
                ->setTimezone(new \DateTimeZone('America/Santiago'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $raw;
        }
    }

    private function looksLikeLocalPath(string $path): bool
    {
        return str_contains($path, ':\\') || str_starts_with($path, '/') || str_starts_with($path, '\\\\');
    }

    private function pathExistsInRuntime(string $path): bool
    {
        return is_dir($path) || is_file($path);
    }

    private function defaultSistemaPath(): string
    {
        return (string) ($_ENV['DTE_SISTEMA_PATH'] ?? getenv('DTE_SISTEMA_PATH') ?: '');
    }

    private function encodeJson(array $payload): string
    {
        // JSON_INVALID_UTF8_SUBSTITUTE: el response trae el TED/XML del SII en
        // ISO-8859-1 (bytes no-UTF8); sin esta bandera json_encode devuelve false y
        // se perdía todo (o se violaba el CHECK json_valid de las columnas JSON en
        // MariaDB). Con la bandera, los bytes invalidos se sustituyen y el JSON
        // siempre es valido. El timbre real para imprimir viene del PDF del admin.
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}';
    }

    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
