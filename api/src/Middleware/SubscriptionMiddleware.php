<?php

declare(strict_types=1);

namespace Mypos\Middleware;

use Mypos\Core\Auth;
use Mypos\Core\HttpException;
use Mypos\Config\Database;
use Mypos\Repositories\SuscripcionRepository;
use Mypos\Support\AppConfig;
use Mypos\Support\SubscriptionLifecycle;

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

        $vigencia = SubscriptionLifecycle::evaluate($suscripcion);

        // La columna se sincroniza con el calendario, pero NO decide el acceso:
        // durante los dias de gracia la fila ya dice 'vencida' y aun asi se deja
        // pasar. Quien corta es `permite_acceso`.
        if ($suscripcion !== null) {
            $estadoEsperado = SubscriptionLifecycle::estadoPersistible($vigencia['fase']);
            if ((string) $suscripcion['estado'] !== $estadoEsperado) {
                $statement = $connection->prepare(
                    'UPDATE empresas_suscripcion SET estado = :estado WHERE empresa_id = :empresa_id'
                );
                $statement->execute(['estado' => $estadoEsperado, 'empresa_id' => $empresaId]);
            }
        }

        if (!$vigencia['permite_acceso']) {
            throw new HttpException($vigencia['mensaje'], 402);
        }

        return $claims;
    }
}
