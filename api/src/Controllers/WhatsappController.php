<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Mypos\Middleware\AuthMiddleware;
use Throwable;

final class WhatsappController
{
    /**
     * Genera un token de vinculación por WhatsApp.
     *
     * M4 (auditoría): requiere usuario autenticado — antes era anónimo, lo que
     * permitía inserción ilimitada de filas (DoS de tabla). El token usa
     * random_bytes (64 bits) en vez de md5(uniqid()) truncado a 8 hex, para que
     * no sea enumerable. Ver docs/AUDITORIA_SEGURIDAD_2026-07.md.
     */
    public function generateToken(): void
    {
        try {
            (new AuthMiddleware())->handle();

            $token = 'WTS-' . strtoupper(bin2hex(random_bytes(8)));

            $db = Database::connection();
            $stmt = $db->prepare('INSERT INTO whatsapp_verifications (token, estado) VALUES (?, "pendiente")');
            $stmt->execute([$token]);

            Response::success([
                'token' => $token,
                'status' => 'pendiente',
            ]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al generar token', null, 500);
        }
    }

    /**
     * Consulta el estado de un token de vinculación.
     *
     * M4 (auditoría): requiere usuario autenticado — antes era anónimo y
     * devolvía el teléfono completo (PII) a cualquiera que pasara un token. El
     * teléfono se enmascara (solo últimos 4 dígitos) para confirmar la
     * vinculación sin exponer el número completo.
     */
    public function status(array $params = []): void
    {
        try {
            (new AuthMiddleware())->handle();

            $token = $_GET['token'] ?? '';
            if ($token === '') {
                throw new HttpException('Token requerido', 400);
            }

            $db = Database::connection();
            $stmt = $db->prepare('SELECT token, telefono, estado FROM whatsapp_verifications WHERE token = ? LIMIT 1');
            $stmt->execute([$token]);
            $result = $stmt->fetch();

            if (!$result) {
                throw new HttpException('Token no encontrado', 404);
            }

            Response::success([
                'token' => $result['token'],
                'telefono' => $this->maskPhone((string) ($result['telefono'] ?? '')),
                'status' => $result['estado'],
            ]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->errors(), $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al consultar estado', null, 500);
        }
    }

    /**
     * Enmascara un teléfono dejando visibles solo los últimos 4 dígitos.
     */
    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        $last4 = substr($digits, -4);

        return '•••• ' . $last4;
    }
}
