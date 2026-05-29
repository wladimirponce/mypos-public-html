<?php

declare(strict_types=1);

namespace Mypos\Controllers;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Core\Response;
use Throwable;

final class WhatsappController
{
    public function generateToken(): void
    {
        try {
            $token = 'WTS-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
            
            $db = Database::connection();
            $stmt = $db->prepare('INSERT INTO whatsapp_verifications (token, estado) VALUES (?, "pendiente")');
            $stmt->execute([$token]);

            Response::success([
                'token' => $token,
                'status' => 'pendiente'
            ]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al generar token', null, 500);
        }
    }

    public function status(array $params): void
    {
        try {
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
                'telefono' => $result['telefono'],
                'status' => $result['estado']
            ]);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), null, $e->statusCode());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Response::error('Error al consultar estado', null, 500);
        }
    }
}
