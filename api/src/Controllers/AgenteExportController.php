<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Core\HttpException;
use Mypos\Core\Request;
use Mypos\Core\Response;
use Mypos\Services\AuditoriaService;
use Mypos\Services\Agente\ContactoEmpresaService;
use Mypos\Services\Agente\ExportacionService;
use Mypos\Services\MailService;
use Throwable;

/**
 * POST /api/v1/agente/exportar — genera una planilla Excel (registry fijo de
 * tipos, solo lectura) y la envía al correo registrado de la empresa.
 * Registrado con protectedRoute('reportes.ver'): auth+tenant+suscripción como
 * cualquier reporte. El destino NUNCA viene del request (anti-exfiltración).
 */
final class AgenteExportController
{
    public function exportar(): void
    {
        $empresaId = (int) ($_GET['empresa_id'] ?? 0);
        $tipo = '';

        try {
            $payload = Request::json();
            $tipo = strtolower(trim((string) ($payload['tipo'] ?? '')));

            // Defaults de fechas EN MySQL, no con date() de PHP: el PHP del
            // hosting corre en UTC y MySQL en hora chilena — con date() los
            // defaults apuntarian a otro dia (misma clase de bug corregida
            // en AlertasRunner el 2026-07-02).
            $db = \Mypos\Config\Database::connection();
            $hoy = $db->query(
                "SELECT DATE_FORMAT(CURDATE(), '%Y-%m-01') AS desde, CURDATE() AS hasta"
            )->fetch();
            $fechaDesde = $this->fecha((string) ($payload['fecha_desde'] ?? ''), (string) $hoy['desde']);
            $fechaHasta = $this->fecha((string) ($payload['fecha_hasta'] ?? ''), (string) $hoy['hasta']);

            if ($tipo === '') {
                throw new HttpException('tipo obligatorio', 422);
            }

            $export = (new ExportacionService())->generar($empresaId, $tipo, $fechaDesde, $fechaHasta);

            $email = (new ContactoEmpresaService())->email($empresaId);
            if ($email === '') {
                throw new HttpException(
                    'La empresa no tiene un correo registrado para recibir archivos. '
                    . 'Configura el correo en Configuración → Empresa.',
                    422
                );
            }

            $stmt = null; // razon social para el asunto
            $razonSocial = '';
            try {
                $db = \Mypos\Config\Database::connection();
                $stmt = $db->prepare('SELECT razon_social FROM empresas WHERE id = :empresa_id');
                $stmt->execute([':empresa_id' => $empresaId]);
                $razonSocial = (string) ($stmt->fetch()['razon_social'] ?? '');
            } catch (Throwable) {
                $razonSocial = 'Empresa';
            }

            $enviado = (new MailService())->enviarPlanillaAgente(
                $email,
                $razonSocial !== '' ? $razonSocial : 'Empresa',
                $export['titulo'],
                $export['filename'],
                $export['contenido'],
                $export['filas']
            );

            if (!$enviado) {
                throw new HttpException('No se pudo enviar el correo con la planilla. Intenta más tarde.', 502);
            }

            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId,
                'modulo' => 'agente_ia',
                'accion' => 'exportar_excel',
                'entidad' => 'exportacion',
                'entidad_id' => $tipo,
                'descripcion' => 'Planilla enviada por el agente IA: ' . $export['titulo'],
                'metadata' => [
                    'tipo' => $tipo,
                    'filas' => $export['filas'],
                    'destino' => $this->enmascarar($email),
                ],
                'severidad' => 'INFO',
                'resultado' => 'OK',
            ]);

            Response::success([
                'enviado' => true,
                'tipo' => $tipo,
                'titulo' => $export['titulo'],
                'filas' => $export['filas'],
                'destino' => $this->enmascarar($email),
            ]);
        } catch (HttpException $exception) {
            Response::error($exception->getMessage(), $exception->errors(), $exception->statusCode());
        } catch (Throwable $exception) {
            error_log('AgenteExportController: ' . $exception->getMessage());
            AuditoriaService::registrarEvento([
                'empresa_id' => $empresaId ?: null,
                'modulo' => 'agente_ia',
                'accion' => 'exportar_excel',
                'entidad' => 'exportacion',
                'entidad_id' => $tipo !== '' ? $tipo : null,
                'descripcion' => 'Error generando exportación del agente IA',
                'severidad' => 'ERROR',
                'resultado' => 'ERROR',
            ]);
            Response::error('Error interno del servidor', null, 500);
        }
    }

    private function fecha(string $valor, string $default): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? $valor : $default;
    }

    /** wl***@gmail.com — para logs y respuesta del chat sin exponer el correo completo. */
    private function enmascarar(string $email): string
    {
        $partes = explode('@', $email, 2);
        if (count($partes) !== 2) {
            return '***';
        }
        $usuario = $partes[0];
        $visible = mb_substr($usuario, 0, min(2, mb_strlen($usuario)));
        return $visible . '***@' . $partes[1];
    }
}
