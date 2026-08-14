<?php

declare(strict_types=1);

namespace Mypos\Middleware;

use Mypos\Core\Auth;
use Mypos\Core\HttpException;
use Mypos\Config\Database;
use Mypos\Repositories\SuscripcionRepository;
use Mypos\Support\AppConfig;

final class SubscriptionMiddleware
{
    public function handle(): array
    {
        // Require authentication first
        $claims = (new AuthMiddleware())->handle();

        // Exención de cobro para el operador de plataforma (dueño de MyPOS):
        // entra sin que se le exija suscripción vigente. Se identifica por el
        // email del token contra PLATFORM_OWNER_EMAILS (mismo allowlist que los
        // links de precio especial).
        $email = (string) ($claims['email'] ?? '');
        if ($email !== '' && AppConfig::isPlatformOwnerEmail($email)) {
            return $claims;
        }

        $empresaId = Auth::empresaId();
        
        if (!$empresaId) {
            throw new HttpException('Empresa no seleccionada en el contexto', 400);
        }

        // Toda empresa necesita suscripcion vigente para operar. La regla de
        // FLOW_MONTHLY_CHARGE_RULES no decide el acceso: solo aporta monto y
        // pasarela de respaldo cuando la suscripcion no trae precio propio.
        // La vigencia la define fecha_fin, que solo avanza con un pago confirmado
        // por el webhook o con una accion explicita desde el panel admin.
        $connection = Database::connection();
        $repository = new SuscripcionRepository($connection);
        $suscripcion = $repository->getSubscriptionStatus($empresaId);

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
