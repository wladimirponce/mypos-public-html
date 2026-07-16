<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Repositories\MercadoPagoRepository;
use Mypos\Support\Crypto;
use Mypos\Support\MercadoPagoClient;
use Mypos\Support\SecurityAlert;
use PDO;
use Throwable;

/**
 * Orquesta la integracion MercadoPago Point (medio de pago con tarjeta via
 * terminal fisica), parametrizada por empresa y multi-sucursal.
 *
 * Flujo: se cobra primero (iniciarCobro crea un "intento" + order en la terminal),
 * el estado real se confirma por webhook + polling (estadoCobro), y la venta se
 * cierra recien cuando el intento esta APROBADO — validado y consumido dentro de
 * la transaccion de la venta (confirmarPagoParaVenta), igual que un vale.
 */
final class MercadoPagoService
{
    /** Monto en CLP: sin decimales. Ver formatAmount(). */
    private const EXPIRATION_DEFAULT = 'PT15M';

    private MercadoPagoRepository $repository;

    public function __construct(?MercadoPagoRepository $repository = null)
    {
        $this->repository = $repository ?? new MercadoPagoRepository(Database::connection());
    }

    // ── Configuracion (credenciales por empresa) ──────────────────────────────

    public function guardarConfig(int $userId, int $empresaId, array $payload): array
    {
        if ($empresaId <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }

        $ambiente = strtolower(trim((string) ($payload['ambiente'] ?? 'sandbox')));
        if (!in_array($ambiente, ['sandbox', 'produccion'], true)) {
            throw new HttpException('ambiente invalido (sandbox|produccion)', 422);
        }

        // El access_token solo se re-cifra si viene uno nuevo; en edicion sin token
        // se conserva el existente (COALESCE en el repositorio).
        $tokenPlano = trim((string) ($payload['access_token'] ?? ''));
        $existente = $this->repository->getConfig($empresaId);
        if ($tokenPlano === '' && $existente === null) {
            throw new HttpException('El access_token de MercadoPago es obligatorio', 422);
        }

        $this->repository->upsertConfig([
            'empresa_id' => $empresaId,
            'ambiente' => $ambiente,
            'access_token_cifrado' => $tokenPlano !== '' ? Crypto::encrypt($tokenPlano) : null,
            'webhook_secret' => $this->nullable($payload['webhook_secret'] ?? null),
            'user_id_mp' => $this->nullable($payload['user_id_mp'] ?? null),
            'activo' => $this->bool($payload['activo'] ?? true),
        ]);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'mercadopago',
            'accion' => 'configurar',
            'entidad' => 'mercadopago_config',
            'descripcion' => 'Configuracion MercadoPago actualizada',
            'datos_nuevos' => [
                'ambiente' => $ambiente,
                'token_actualizado' => $tokenPlano !== '',
                'activo' => $this->bool($payload['activo'] ?? true),
            ],
        ]);

        return $this->obtenerConfig($empresaId);
    }

    /** Estado de la config SIN exponer el token en claro. */
    public function obtenerConfig(int $empresaId): array
    {
        $config = $this->repository->getConfig($empresaId);
        if ($config === null) {
            return ['configurado' => false];
        }

        return [
            'configurado' => !empty($config['access_token_cifrado']),
            'ambiente' => (string) $config['ambiente'],
            'activo' => (int) $config['activo'],
            'tiene_webhook_secret' => !empty($config['webhook_secret']),
            'user_id_mp' => $config['user_id_mp'] ?? null,
            'updated_at' => $config['updated_at'] ?? null,
        ];
    }

    // ── Terminales ────────────────────────────────────────────────────────────

    public function listarTerminales(int $empresaId, array $filters): array
    {
        return ['terminales' => array_map(
            fn (array $t): array => $this->presentarTerminal($t),
            $this->repository->listTerminales($empresaId, $filters)
        )];
    }

    public function crearTerminal(int $userId, int $empresaId, array $payload): array
    {
        $data = $this->validarTerminal($empresaId, $payload);

        if ($this->repository->terminalIdExiste($empresaId, $data['terminal_id'])) {
            throw new HttpException('Ya existe una terminal con ese terminal_id', 422);
        }

        $data['empresa_id'] = $empresaId;
        $id = $this->repository->insertTerminal($data);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $data['sucursal_id'],
            'usuario_id' => $userId,
            'modulo' => 'mercadopago',
            'accion' => 'crear',
            'entidad' => 'mercadopago_terminales',
            'entidad_id' => $id,
            'descripcion' => 'Terminal MercadoPago creada',
            'datos_nuevos' => ['terminal_id' => $data['terminal_id'], 'nombre' => $data['nombre']],
        ]);

        return $this->presentarTerminal((array) $this->repository->findTerminal($empresaId, $id));
    }

    public function actualizarTerminal(int $userId, int $empresaId, int $id, array $payload): array
    {
        if ($this->repository->findTerminal($empresaId, $id) === null) {
            throw new HttpException('Terminal no encontrada', 404);
        }

        $data = $this->validarTerminal($empresaId, $payload);
        if ($this->repository->terminalIdExiste($empresaId, $data['terminal_id'], $id)) {
            throw new HttpException('Ya existe otra terminal con ese terminal_id', 422);
        }

        $this->repository->updateTerminal($empresaId, $id, $data);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => $data['sucursal_id'],
            'usuario_id' => $userId,
            'modulo' => 'mercadopago',
            'accion' => 'editar',
            'entidad' => 'mercadopago_terminales',
            'entidad_id' => $id,
            'descripcion' => 'Terminal MercadoPago actualizada',
            'datos_nuevos' => ['terminal_id' => $data['terminal_id'], 'nombre' => $data['nombre']],
        ]);

        return $this->presentarTerminal((array) $this->repository->findTerminal($empresaId, $id));
    }

    public function cambiarEstadoTerminal(int $userId, int $empresaId, int $id, bool $activo): array
    {
        if ($this->repository->findTerminal($empresaId, $id) === null) {
            throw new HttpException('Terminal no encontrada', 404);
        }

        $this->repository->setTerminalEstado($empresaId, $id, $activo ? 1 : 0);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'usuario_id' => $userId,
            'modulo' => 'mercadopago',
            'accion' => $activo ? 'activar' : 'desactivar',
            'entidad' => 'mercadopago_terminales',
            'entidad_id' => $id,
            'descripcion' => 'Estado de terminal MercadoPago actualizado',
            'datos_nuevos' => ['activo' => $activo ? 1 : 0],
        ]);

        return $this->presentarTerminal((array) $this->repository->findTerminal($empresaId, $id));
    }

    // ── Cobro ─────────────────────────────────────────────────────────────────

    public function iniciarCobro(int $userId, int $empresaId, int $terminalRefId, int $monto, ?string $descripcion = null): array
    {
        if ($monto <= 0) {
            throw new HttpException('El monto a cobrar debe ser mayor que cero', 422);
        }

        $terminal = $this->repository->findTerminal($empresaId, $terminalRefId);
        if ($terminal === null) {
            throw new HttpException('Terminal no encontrada', 404);
        }
        if ((int) $terminal['activo'] !== 1) {
            throw new HttpException('La terminal esta inactiva', 422);
        }

        $client = $this->clientFor($empresaId);
        $externalReference = $this->generarExternalReference($empresaId);
        $idempotencyKey = MercadoPagoClient::uuidV4();

        $orderPayload = [
            'type' => 'point',
            'external_reference' => $externalReference,
            'description' => $descripcion !== null && trim($descripcion) !== '' ? trim($descripcion) : 'Venta POS',
            'expiration_time' => self::EXPIRATION_DEFAULT,
            'transactions' => [
                'payments' => [
                    ['amount' => $this->formatAmount($monto)],
                ],
            ],
            'config' => [
                'point' => [
                    'terminal_id' => (string) $terminal['terminal_id'],
                    'print_on_terminal' => 'no_ticket',
                ],
            ],
        ];

        $response = $client->crearOrdenPoint($orderPayload, $idempotencyKey);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw $this->traducirErrorOrden($response);
        }

        $orderId = isset($response['body']['id']) ? (string) $response['body']['id'] : null;
        $mapeo = $this->mapEstadoDesdeOrden($response['body']);

        $intentoId = $this->repository->insertIntento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $terminal['sucursal_id'],
            'terminal_id_ref' => $terminalRefId,
            'external_reference' => $externalReference,
            'provider_order_id' => $orderId,
            'idempotency_key' => $idempotencyKey,
            'monto' => $monto,
            'estado' => $mapeo['estado'],
            'status_detail' => $mapeo['status_detail'],
            'raw_response_json' => $response['raw'],
            'usuario_id' => $userId,
        ]);

        AuditoriaService::registrarEvento([
            'empresa_id' => $empresaId,
            'sucursal_id' => (int) $terminal['sucursal_id'],
            'usuario_id' => $userId,
            'modulo' => 'mercadopago',
            'accion' => 'cobrar',
            'entidad' => 'mercadopago_intentos',
            'entidad_id' => $intentoId,
            'descripcion' => 'Cobro MercadoPago iniciado en terminal',
            'datos_nuevos' => [
                'external_reference' => $externalReference,
                'provider_order_id' => $orderId,
                'monto' => $monto,
                'terminal_id' => (string) $terminal['terminal_id'],
            ],
        ]);

        return [
            'intento_id' => $intentoId,
            'external_reference' => $externalReference,
            'provider_order_id' => $orderId,
            'estado' => $mapeo['estado'],
            'monto' => $monto,
        ];
    }

    /** Polling del POS: si sigue PENDIENTE, consulta la order real y actualiza. */
    public function estadoCobro(int $empresaId, int $intentoId): array
    {
        $intento = $this->repository->findIntento($empresaId, $intentoId);
        if ($intento === null) {
            throw new HttpException('Intento de cobro no encontrado', 404);
        }

        if ((string) $intento['estado'] === 'PENDIENTE' && !empty($intento['provider_order_id'])) {
            $intento = $this->refrescarDesdeMp($empresaId, $intento) ?? $intento;
        }

        return $this->presentarIntento($intento);
    }

    // ── Webhook ───────────────────────────────────────────────────────────────

    /**
     * Procesa una notificacion de MercadoPago. Idempotente. NO cierra ventas:
     * solo actualiza el estado del intento consultando la order real por API.
     *
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     */
    public function procesarWebhook(array $payload, array $headers): void
    {
        $topic = strtolower((string) ($payload['type'] ?? $payload['topic'] ?? ''));
        $action = isset($payload['action']) ? (string) $payload['action'] : null;
        $resourceId = (string) ($payload['data']['id'] ?? $payload['resource'] ?? $payload['id'] ?? '');

        if ($resourceId === '') {
            return; // nada accionable
        }

        $signature = $headers['x-signature'] ?? $headers['X-Signature'] ?? null;

        $eventId = $this->repository->insertWebhookEvent([
            'empresa_id' => null,
            'topic' => $topic !== '' ? $topic : 'desconocido',
            'resource_id' => $resourceId,
            'action' => $action,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'x_signature' => $signature,
        ]);

        if ($eventId === null) {
            return; // reintento duplicado ya procesado
        }

        // Solo el tema orders nos interesa para conciliar el cobro.
        if (!str_contains($topic, 'order')) {
            $this->repository->marcarWebhookProcesado($eventId, null);
            return;
        }

        $intento = $this->repository->findIntentoByOrderId($resourceId);
        if ($intento === null) {
            // Puede llegar antes de que persistamos el order_id: se ignora; el
            // polling (estadoCobro) reconciliara. Se deja registrado el evento.
            $this->repository->marcarWebhookProcesado($eventId, null);
            return;
        }

        $empresaId = (int) $intento['empresa_id'];

        if (!$this->firmaValida($signature, $resourceId, $headers, $empresaId)) {
            error_log('[MercadoPago] Firma de webhook invalida para order ' . $resourceId);
            SecurityAlert::emit('webhook.firma_invalida', 'high', [
                'component' => 'webhook',
                'provider' => 'mercadopago',
                'empresa_id' => $empresaId,
                'resource' => 'order:' . $resourceId,
                'reason' => 'firma_webhook_invalida',
            ]);
            $this->repository->marcarWebhookProcesado($eventId, $empresaId);
            return;
        }

        $this->refrescarDesdeMp($empresaId, $intento);
        $this->repository->marcarWebhookProcesado($eventId, $empresaId);
    }

    // ── Consumo en la venta (dentro de la transaccion del llamador) ────────────

    /**
     * Valida y consume el intento como pago de una venta. Debe llamarse DENTRO de
     * la transaccion de la venta ya iniciada por el llamador (patron ValeCredito).
     * Lanza 422 si el intento no esta aprobado, el monto no coincide, o ya fue usado.
     */
    public function confirmarPagoParaVenta(PDO $connection, int $empresaId, string $referencia, int $montoEsperado, int $ventaId): array
    {
        $repository = new MercadoPagoRepository($connection);
        $intento = $repository->findIntentoByReference($empresaId, $referencia, true);

        if ($intento === null) {
            throw new HttpException("Pago MercadoPago {$referencia} no encontrado", 422);
        }
        if ($intento['venta_id'] !== null) {
            throw new HttpException("El pago MercadoPago {$referencia} ya fue utilizado en otra venta", 422);
        }
        if ((string) $intento['estado'] !== 'APROBADO') {
            throw new HttpException(
                "El pago MercadoPago {$referencia} no esta aprobado (estado: {$intento['estado']})",
                422
            );
        }
        if ((int) $intento['monto'] !== $montoEsperado) {
            // Discrepancia de monto al consumir el pago: señal fuerte de manipulación.
            SecurityAlert::emit('pagos.monto_no_coincide', 'high', [
                'component' => 'pagos',
                'provider' => 'mercadopago',
                'empresa_id' => $empresaId,
                'resource' => 'venta:' . $ventaId,
                'amount' => (int) $intento['monto'],
                'reason' => 'esperado_' . $montoEsperado,
            ]);
            throw new HttpException(
                "El monto del pago MercadoPago no coincide (cobrado: {$intento['monto']}, esperado: {$montoEsperado})",
                422,
                ['pagos' => ['El monto del pago con tarjeta no coincide con el total']]
            );
        }

        $repository->marcarConsumido((int) $intento['id'], $ventaId);

        return [
            'intento_id' => (int) $intento['id'],
            'provider_order_id' => $intento['provider_order_id'] ?? null,
            'monto' => (int) $intento['monto'],
        ];
    }

    // ── Internos ────────────────────────────────────────────────────────────────

    /** Consulta la order real en MP y actualiza el intento; devuelve el intento fresco. */
    private function refrescarDesdeMp(int $empresaId, array $intento): ?array
    {
        try {
            $client = $this->clientFor($empresaId);
            $response = $client->obtenerOrden((string) $intento['provider_order_id']);
            if ($response['status'] < 200 || $response['status'] >= 300) {
                return null;
            }

            $mapeo = $this->mapEstadoDesdeOrden($response['body']);
            $this->repository->updateIntentoEstado((int) $intento['id'], [
                'estado' => $mapeo['estado'],
                'status_detail' => $mapeo['status_detail'],
                'provider_order_id' => isset($response['body']['id']) ? (string) $response['body']['id'] : null,
                'raw_response_json' => $response['raw'],
            ]);

            return $this->repository->findIntento($empresaId, (int) $intento['id']);
        } catch (Throwable $exception) {
            error_log('[MercadoPago] No se pudo refrescar order ' . ($intento['provider_order_id'] ?? '?') . ': ' . $exception->getMessage());
            return null;
        }
    }

    private function clientFor(int $empresaId): MercadoPagoClient
    {
        $config = $this->repository->getConfig($empresaId);
        if ($config === null || (int) $config['activo'] !== 1) {
            throw new HttpException('MercadoPago no esta configurado o esta inactivo para esta empresa', 422);
        }
        if (empty($config['access_token_cifrado'])) {
            throw new HttpException('Falta el access_token de MercadoPago', 422);
        }

        return new MercadoPagoClient(Crypto::decrypt((string) $config['access_token_cifrado']));
    }

    /**
     * Mapea el estado de una order de MP a nuestro enum interno.
     *
     * @param array<string,mixed> $order
     * @return array{estado:string, status_detail:?string}
     */
    private function mapEstadoDesdeOrden(array $order): array
    {
        $status = strtolower((string) ($order['status'] ?? ''));
        $detail = isset($order['status_detail']) ? (string) $order['status_detail'] : null;

        // Estado del pago anidado (mas fiable que el de la order para Point).
        $pagoStatus = strtolower((string) ($order['transactions']['payments'][0]['status'] ?? ''));
        if ($pagoStatus !== '') {
            $detail = (string) ($order['transactions']['payments'][0]['status_detail'] ?? $detail);
            $status = $pagoStatus === 'approved' ? 'processed'
                : ($pagoStatus === 'rejected' ? 'failed'
                : ($pagoStatus === 'cancelled' ? 'canceled' : $status));
        }

        $estado = match (true) {
            in_array($status, ['processed', 'approved', 'accredited'], true) => 'APROBADO',
            in_array($status, ['canceled', 'cancelled'], true) => 'CANCELADO',
            $status === 'expired' => 'EXPIRADO',
            in_array($status, ['failed', 'rejected'], true) => 'RECHAZADO',
            default => 'PENDIENTE',
        };

        return ['estado' => $estado, 'status_detail' => $detail];
    }

    /**
     * Valida x-signature del webhook con el secret de la empresa (HMAC-SHA256 sobre
     * el manifest id:...;request-id:...;ts:...). Si no hay secret configurado o no
     * viene firma, no bloquea (la verdad final la da el GET con el token propio).
     *
     * @param array<string,string> $headers
     */
    private function firmaValida(?string $signature, string $resourceId, array $headers, int $empresaId): bool
    {
        $config = $this->repository->getConfig($empresaId);
        $secret = $config['webhook_secret'] ?? null;
        if ($secret === null || trim((string) $secret) === '' || $signature === null) {
            return true; // no configurado: no se puede validar, se confia en el GET
        }

        // Formato: "ts=1699...,v1=hexhmac"
        $ts = null;
        $v1 = null;
        foreach (explode(',', $signature) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 'ts') {
                $ts = $kv[1];
            } elseif ($kv[0] === 'v1') {
                $v1 = $kv[1];
            }
        }
        if ($ts === null || $v1 === null) {
            return false;
        }

        $requestId = $headers['x-request-id'] ?? $headers['X-Request-Id'] ?? '';
        $manifest = "id:{$resourceId};request-id:{$requestId};ts:{$ts};";
        $esperado = hash_hmac('sha256', $manifest, (string) $secret);

        return hash_equals($esperado, $v1);
    }

    private function validarTerminal(int $empresaId, array $payload): array
    {
        $sucursalId = (int) ($payload['sucursal_id'] ?? 0);
        if ($sucursalId <= 0 || !$this->repository->sucursalExists($empresaId, $sucursalId)) {
            throw new HttpException('sucursal_id invalido para la empresa', 422);
        }

        $cajaId = isset($payload['caja_id']) && (int) $payload['caja_id'] > 0 ? (int) $payload['caja_id'] : null;
        if ($cajaId !== null && !$this->repository->cajaExists($empresaId, $cajaId)) {
            throw new HttpException('caja_id invalido para la empresa', 422);
        }

        $terminalId = trim((string) ($payload['terminal_id'] ?? ''));
        if ($terminalId === '') {
            throw new HttpException('terminal_id (identificador de la terminal en MercadoPago) es obligatorio', 422);
        }

        $nombre = trim((string) ($payload['nombre'] ?? ''));
        if ($nombre === '') {
            throw new HttpException('El nombre de la terminal es obligatorio', 422);
        }

        return [
            'sucursal_id' => $sucursalId,
            'caja_id' => $cajaId,
            'terminal_id' => $terminalId,
            'nombre' => $nombre,
            'mp_store_id' => $this->nullable($payload['mp_store_id'] ?? null),
            'mp_pos_id' => $this->nullable($payload['mp_pos_id'] ?? null),
            'serial' => $this->nullable($payload['serial'] ?? null),
            'activo' => $this->bool($payload['activo'] ?? true),
        ];
    }

    private function traducirErrorOrden(array $response): HttpException
    {
        $body = $response['body'];
        $mensaje = (string) ($body['message'] ?? 'Error creando la orden en MercadoPago');
        $errores = $body['errors'] ?? $body['cause'] ?? null;

        // La terminal ya tiene una order encolada: 409 explicito para el POS.
        $raw = strtolower($response['raw']);
        if (str_contains($raw, 'already_queued_order_for_terminal')) {
            return new HttpException('La terminal ya tiene un cobro en curso. Finalizalo o cancelalo antes de iniciar otro.', 409);
        }

        error_log('[MercadoPago] Error creando order (' . $response['status'] . '): ' . $response['raw']);

        return new HttpException($mensaje, $response['status'] >= 400 && $response['status'] < 500 ? 422 : 502, is_array($errores) ? ['mercadopago' => array_map('strval', (array) $errores)] : null);
    }

    /** CLP no tiene decimales. La API espera el monto como string. */
    private function formatAmount(int $monto): string
    {
        return (string) $monto;
    }

    private function generarExternalReference(int $empresaId): string
    {
        return 'MP-' . $empresaId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    }

    private function presentarTerminal(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'sucursal_id' => (int) $t['sucursal_id'],
            'sucursal_nombre' => $t['sucursal_nombre'] ?? null,
            'caja_id' => isset($t['caja_id']) && $t['caja_id'] !== null ? (int) $t['caja_id'] : null,
            'caja_nombre' => $t['caja_nombre'] ?? null,
            'terminal_id' => (string) $t['terminal_id'],
            'nombre' => (string) $t['nombre'],
            'mp_store_id' => $t['mp_store_id'] ?? null,
            'mp_pos_id' => $t['mp_pos_id'] ?? null,
            'serial' => $t['serial'] ?? null,
            'activo' => (int) $t['activo'],
        ];
    }

    private function presentarIntento(array $i): array
    {
        return [
            'intento_id' => (int) $i['id'],
            'external_reference' => (string) $i['external_reference'],
            'provider_order_id' => $i['provider_order_id'] ?? null,
            'estado' => (string) $i['estado'],
            'status_detail' => $i['status_detail'] ?? null,
            'monto' => (int) $i['monto'],
            'venta_id' => isset($i['venta_id']) && $i['venta_id'] !== null ? (int) $i['venta_id'] : null,
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function bool(mixed $value): int
    {
        return in_array($value, [true, 1, '1', 'true', 'on'], true) ? 1 : 0;
    }
}
