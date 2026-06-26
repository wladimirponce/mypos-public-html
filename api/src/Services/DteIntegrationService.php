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
        $path = trim((string) ($payload['sistema_path'] ?? $this->defaultSistemaPath()));

        // sistema_path es obligatorio solo en modo REAL
        if ($mode === 'REAL') {
            if ($path === '') {
                throw new HttpException('Error de validacion', 422, ['sistema_path' => ['El campo sistema_path es obligatorio para modo REAL']]);
            }
            if ($this->looksLikeLocalPath($path) && !$this->pathExistsInRuntime($path)) {
                throw new HttpException('El path del sistema DTE no es accesible desde este runtime', 422);
            }
        }

        $this->repository->upsertConfig([
            'empresa_id' => $empresaId,
            'modo' => $mode,
            'sistema_path' => $path,
            'endpoint_cli' => $this->nullableString($payload['endpoint_cli'] ?? null),
            'endpoint_http' => $this->nullableString($payload['endpoint_http'] ?? null),
            'salida_xml_dir' => $this->nullableString($payload['salida_xml_dir'] ?? null),
            'salida_pdf_dir' => $this->nullableString($payload['salida_pdf_dir'] ?? null),
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
                'direccion' => $sucursal['direccion'] ?? $empresa['direccion'] ?? null,
                'comuna' => $sucursal['comuna'] ?? $empresa['comuna'] ?? null,
                'ciudad' => $sucursal['ciudad'] ?? $empresa['ciudad'] ?? null,
                'telefono' => $sucursal['telefono'] ?? $empresa['telefono_contacto'] ?? null,
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

        $xml = (string) ($generate['xml'] ?? '');
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

        $send = $this->adminRequest($endpoint, 'send', [
            'xml' => $xml,
            'tipo' => $tipo,
            'folio' => $folio,
        ], $apiKey);

        $tedXml = $this->extractTedXml($xml);
        $sendOk = !empty($send['ok']);
        $trackId = isset($send['trackId']) ? (string) $send['trackId'] : (isset($send['track_id']) ? (string) $send['track_id'] : null);
        $printPayload = $this->buildPrintPayload($payload, $generate, $send, $tedXml);

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
                'dte_print_payload' => $printPayload,
            ],
            'dte_print_payload' => $printPayload,
            'error' => $sendOk ? null : (string) ($send['error'] ?? $send['mensaje'] ?? 'No se pudo enviar DTE al SII'),
        ];
    }

    private function adminGeneratePayload(array $payload): array
    {
        $document = $payload['documento'];
        $receptor = $payload['receptor'];

        return [
            'tipo' => (int) $document['tipo_dte'],
            'folio' => (int) $document['folio'],
            'fecha' => (string) $document['fecha_emision'],
            // Pedir al admin la representación impresa en PDF (base64) junto con el
            // XML, para guardarla como respaldo en la nube. Reutiliza el generador
            // de certificación (MuestraPdfGenerator) — mismo timbre PDF417.
            'with_pdf' => 1,
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
        $metadata = $this->decodeJson($config['metadata_json'] ?? null) ?? [];
        $envName = is_string($metadata['admin_api_key_env'] ?? null) && trim((string) $metadata['admin_api_key_env']) !== ''
            ? trim((string) $metadata['admin_api_key_env'])
            : 'DTE_ADMIN_API_KEY_' . $empresaId;

        $apiKey = (string) ($_ENV[$envName] ?? getenv($envName) ?: '');
        if ($apiKey === '') {
            $apiKey = (string) ($_ENV['DTE_ADMIN_API_KEY'] ?? getenv('DTE_ADMIN_API_KEY') ?: '');
        }

        if (trim($apiKey) === '') {
            throw new HttpException("Falta configurar API key de admin DTE en {$envName} o DTE_ADMIN_API_KEY", 422);
        }

        return trim($apiKey);
    }

    private function extractTedXml(string $xml): ?string
    {
        if (preg_match('/<TED\b[^>]*>.*?<\/TED>/s', $xml, $match) === 1) {
            return $match[0];
        }

        return null;
    }

    private function withoutXml(array $response): array
    {
        if (isset($response['xml'])) {
            $response['xml_length'] = strlen((string) $response['xml']);
            unset($response['xml']);
        }

        return $response;
    }

    private function buildPrintPayload(array $payload, array $generate, array $send, ?string $tedXml): ?array
    {
        if ($tedXml === null || $tedXml === '') {
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
            'fecha_dte' => (string) $document['fecha_emision'],
            'track_id' => isset($send['trackId']) ? (string) $send['trackId'] : null,
            'ted_xml' => $tedXml,
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
            'verify_url' => $generate['verify_url'] ?? null,
            'total' => (int) $document['total'],
            'neto' => (int) $document['neto'],
            'iva' => (int) $document['impuestos'],
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
            'direccion' => $sucursal['direccion'] ?? $empresa['direccion'] ?? null,
            'comuna' => $sucursal['comuna'] ?? $empresa['comuna'] ?? null,
            'ciudad' => $sucursal['ciudad'] ?? $empresa['ciudad'] ?? null,
            'telefono' => $sucursal['telefono'] ?? $empresa['telefono_contacto'] ?? null,
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

        return $row;
    }

    private function decodeEvent(array $event): array
    {
        $event['metadata'] = $this->decodeJson($event['metadata_json'] ?? null);
        unset($event['metadata_json']);

        return $event;
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
}
