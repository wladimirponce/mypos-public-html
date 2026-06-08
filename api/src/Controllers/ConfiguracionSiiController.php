<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Mypos\Middleware\PermissionMiddleware;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Services\ConfiguracionService;
use Mypos\Services\DteIntegrationService;
use PDO;
use Throwable;

final class ConfiguracionSiiController
{
    private PDO $connection;
    private ConfiguracionService $configuracionService;
    private DteIntegrationService $dteService;

    public function __construct()
    {
        $this->connection = Database::connection();
        $this->configuracionService = new ConfiguracionService();
        $this->dteService = new DteIntegrationService();
    }

    public function estado(): void
    {
        $this->respond(fn (int $empresaId): array => $this->buildEstado($empresaId), 'configuracion.ver');
    }

    public function solicitarCertificacion(): void
    {
        $this->respond(function (int $empresaId, int $userId): array {
            $payload = Request::json();
            $tipo = in_array($payload['tipo'] ?? '', ['nueva', 'migracion'], true)
                ? (string) $payload['tipo']
                : 'nueva';

            $current = $this->dteService->configuracion($empresaId);
            $metadata = is_array($current['metadata'] ?? null) ? $current['metadata'] : [];
            $metadata['certificacion_solicitada'] = true;
            $metadata['certificacion_tipo'] = $tipo;
            $metadata['certificacion_solicitada_at'] = date('Y-m-d H:i:s');
            $metadata['certificacion_estado'] = 'pendiente';

            $this->dteService->actualizarConfiguracion($userId, [
                'empresa_id' => $empresaId,
                'modo' => (string) ($current['modo'] ?? 'SIMULADO'),
                'sistema_path' => (string) ($current['sistema_path'] ?? ''),
                'endpoint_cli' => $current['endpoint_cli'] ?? null,
                'endpoint_http' => $current['endpoint_http'] ?? null,
                'salida_xml_dir' => $current['salida_xml_dir'] ?? null,
                'salida_pdf_dir' => $current['salida_pdf_dir'] ?? null,
                'ambiente' => (string) ($current['ambiente'] ?? 'CERTIFICACION'),
                'activo' => $current['activo'] ?? true,
                'metadata' => $metadata,
            ]);

            return [
                'estado' => 'pendiente',
                'mensaje' => 'Solicitud de certificacion registrada. Un especialista iniciara el proceso en breve.',
            ];
        }, 'configuracion.editar');
    }

    private function buildEstado(int $empresaId): array
    {
        $config = $this->configuracionService->empresa($empresaId);
        $configMeta = is_array($config['metadata'] ?? null) ? $config['metadata'] : [];

        $datosSii = [
            'giro' => !empty($config['giro']),
            'unidad_sii' => !empty($configMeta['unidad_sii'] ?? ''),
            'email_sii' => !empty($configMeta['email_sii'] ?? ''),
            'numero_resolucion' => !empty($configMeta['numero_resolucion'] ?? ''),
            'fecha_resolucion' => !empty($configMeta['fecha_resolucion'] ?? ''),
            'acteco' => !empty($configMeta['acteco'] ?? ''),
        ];
        $datosCompletos = $datosSii['giro'] && $datosSii['unidad_sii'] && $datosSii['email_sii'];

        $dte = $this->dteService->configuracion($empresaId);
        $dteMeta = is_array($dte['metadata'] ?? null) ? $dte['metadata'] : [];
        $ambiente = (string) ($dte['ambiente'] ?? 'CERTIFICACION');

        $certStmt = $this->connection->prepare(
            'SELECT COUNT(*) FROM archivos_subidos
             WHERE empresa_id = :eid
               AND modulo = \'CONFIGURACION\'
               AND entidad = \'certificado_sii\'
               AND estado = \'ACTIVO\'
             LIMIT 1'
        );
        $certStmt->execute(['eid' => $empresaId]);
        $certSubido = (int) $certStmt->fetchColumn() > 0;

        $cafStmt = $this->connection->prepare(
            'SELECT COUNT(*) FROM caf_archivos WHERE empresa_id = :eid AND estado = \'ACTIVO\' LIMIT 1'
        );
        $cafStmt->execute(['eid' => $empresaId]);
        $cafSubido = (int) $cafStmt->fetchColumn() > 0;

        $certEstado = (string) ($dteMeta['certificacion_estado'] ?? 'no_iniciada');
        $certTipo = $dteMeta['certificacion_tipo'] ?? null;

        $configuracionCompleta = $datosCompletos
            && $certSubido
            && $cafSubido
            && (
                $ambiente === 'PRODUCCION'
                || in_array($certEstado, ['completado', 'aprobado'], true)
            );

        return [
            'datos_completos' => $datosCompletos,
            'datos_detalle' => $datosSii,
            'ambiente' => $ambiente,
            'cert_subido' => $certSubido,
            'caf_subido' => $cafSubido,
            'certificacion_estado' => $certEstado,
            'certificacion_tipo' => $certTipo,
            'configuracion_completa' => $configuracionCompleta,
        ];
    }

    private function respond(callable $callback, string $permission): void
    {
        try {
            $claims = (new AuthMiddleware())->handle();
            $userId = (int) $claims['user_id'];
            $empresaId = $this->requestEmpresaId();
            if ($empresaId <= 0) {
                throw new HttpException('empresa_id obligatorio', 422);
            }
            (new TenantMiddleware())->handle($userId, $empresaId);
            (new PermissionMiddleware())->handle($userId, $empresaId, $permission);
            Response::success($callback($empresaId, $userId));
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function requestEmpresaId(): int
    {
        if (isset($_GET['empresa_id'])) {
            return (int) $_GET['empresa_id'];
        }
        $payload = Request::json();

        return (int) ($payload['empresa_id'] ?? 0);
    }
}
