<?php

declare(strict_types=1);

namespace Mypos\Middleware;

use Mypos\Core\Auth;
use Mypos\Core\HttpException;
use Mypos\Config\Database;
use PDO;

final class SubscriptionMiddleware
{
    public function handle(): array
    {
        // Require authentication first
        $claims = (new AuthMiddleware())->handle();
        
        $empresaId = Auth::empresaId();
        
        if (!$empresaId) {
            throw new HttpException('Empresa no seleccionada en el contexto', 400);
        }
        
        $connection = Database::connection();
        $stmt = $connection->prepare('SELECT * FROM empresas_suscripcion WHERE empresa_id = :empresa_id');
        $stmt->execute(['empresa_id' => $empresaId]);
        $suscripcion = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$suscripcion) {
            throw new HttpException('Tu suscripción no se encuentra activa o no existe. Por favor regulariza tu pago.', 402);
        }
        
        $estado = (string) $suscripcion['estado'];
        $fechaFin = $suscripcion['fecha_fin'] ? strtotime($suscripcion['fecha_fin']) : 0;
        $now = time();
        
        // Allow a small grace period? Let's just do strict check for now
        if ($estado !== 'activa' || $fechaFin < $now) {
            // Update to vencida if not already
            if ($estado === 'activa') {
                $stmtUpdate = $connection->prepare('UPDATE empresas_suscripcion SET estado = "vencida" WHERE empresa_id = :empresa_id');
                $stmtUpdate->execute(['empresa_id' => $empresaId]);
            }
            throw new HttpException('Tu suscripción ha expirado. Por favor, realiza el pago para continuar usando el sistema.', 402);
        }
        
        return $claims;
    }
}
