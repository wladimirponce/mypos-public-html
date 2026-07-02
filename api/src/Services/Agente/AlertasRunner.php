<?php

declare(strict_types=1);

namespace Mypos\Services\Agente;

use Mypos\Config\Database;
use PDO;
use Throwable;

/**
 * Orquestador del motor de alertas proactivas. Lo invoca bin/agente-alertas.php
 * (cron cada 15 min); la programacion interna (intervalo + ventana horaria por
 * chequeo) decide que corre en cada pasada, asi cPanel necesita UNA sola linea.
 *
 * Aislamiento multiempresa: itera empresas activas una por una; cada chequeo
 * recibe SU empresa_id y el aviso sale SOLO a los contactos de esa empresa.
 * SOLO LECTURA sobre el negocio; escribe unicamente en agente_alertas_*.
 */
final class AlertasRunner
{
    /**
     * intervalo_min: minutos minimos entre corridas del chequeo.
     * ventanas: rangos horarios [desde, hastaExclusivo] permitidos (null = 24h).
     */
    private const CHEQUEOS = [
        'caja_abierta'     => ['intervalo_min' => 55,   'ventanas' => null],
        'folios_bajos'     => ['intervalo_min' => 350,  'ventanas' => null],
        'stock_critico'    => ['intervalo_min' => 350,  'ventanas' => [[8, 21]]],
        'precio_perdida'   => ['intervalo_min' => 1300, 'ventanas' => [[8, 12]]],
        'cierre_pendiente' => ['intervalo_min' => 230,  'ventanas' => [[8, 10], [22, 24]]],
        'ventas_caida'     => ['intervalo_min' => 230,  'ventanas' => [[14, 16], [19, 21]]],
        'suscripcion'      => ['intervalo_min' => 1300, 'ventanas' => [[9, 13]]],
        'compras_borrador' => ['intervalo_min' => 1300, 'ventanas' => [[9, 13]]],
        'resumen_diario'   => ['intervalo_min' => 1300, 'ventanas' => [[8, 10]]],
    ];

    private PDO $db;
    private AlertasConfigService $configService;
    private AlertasChequeosService $chequeos;
    private AlertasNotifier $notifier;

    public function __construct(
        ?PDO $connection = null,
        ?AlertasConfigService $configService = null,
        ?AlertasChequeosService $chequeos = null,
        ?AlertasNotifier $notifier = null,
    ) {
        $this->db = $connection ?? Database::connection();
        $this->configService = $configService ?? new AlertasConfigService($this->db);
        $this->chequeos = $chequeos ?? new AlertasChequeosService($this->db);
        $this->notifier = $notifier ?? new AlertasNotifier($this->db);
    }

    /**
     * Una pasada completa. Devuelve resumen para el log del cron.
     *
     * @return array{chequeos: string[], empresas: int, avisos: int, errores: string[]}
     */
    public function run(?int $soloEmpresa = null): array
    {
        $debidos = $this->chequeosDebidos();
        $resumen = ['chequeos' => $debidos, 'empresas' => 0, 'avisos' => 0, 'errores' => []];
        if ($debidos === []) {
            return $resumen;
        }

        $empresas = $this->empresasActivas($soloEmpresa);
        $resumen['empresas'] = count($empresas);

        foreach ($empresas as $empresa) {
            $empresaId = (int) $empresa['id'];
            try {
                $config = $this->configService->config($empresaId);
                if (!$config['activo']) {
                    continue;
                }

                $porTipo = [];
                foreach ($debidos as $tipo) {
                    $params = $config['alertas'][$tipo] ?? [];
                    if (!(bool) ($params['activo'] ?? false)) {
                        continue;
                    }
                    $items = $this->ejecutarChequeo($tipo, $empresaId, $params);
                    $items = $this->notifier->filtrarDedupe($empresaId, $tipo, $items);
                    if ($items !== []) {
                        $porTipo[$tipo] = $items;
                    }
                }

                if ($porTipo !== []) {
                    $this->notifier->enviar($empresa, $config, $porTipo);
                    $resumen['avisos'] += array_sum(array_map('count', $porTipo));
                }
            } catch (Throwable $e) {
                // Una empresa con datos raros no debe frenar a las demás.
                $resumen['errores'][] = "empresa $empresaId: " . $e->getMessage();
                error_log("[AgenteAlertas] empresa $empresaId: " . $e->getMessage());
            }
        }

        $this->marcarCorridos($debidos);
        return $resumen;
    }

    /** @return string[] chequeos que corresponde correr en esta pasada */
    private function chequeosDebidos(): array
    {
        // Minutos desde la última corrida calculados EN MySQL: comparar
        // last_run_at (hora de MySQL) contra time() de PHP mezcla timezones
        // y rompe los intervalos si difieren (bug encontrado en el test).
        $transcurrido = [];
        $rows = $this->db->query(
            'SELECT chequeo, TIMESTAMPDIFF(MINUTE, last_run_at, NOW()) AS minutos
             FROM agente_alertas_estado'
        )->fetchAll();
        foreach ($rows as $row) {
            $transcurrido[(string) $row['chequeo']] = $row['minutos'] !== null
                ? (int) $row['minutos']
                : null;
        }

        $hora = (int) date('G');
        $debidos = [];
        foreach (self::CHEQUEOS as $tipo => $regla) {
            if ($regla['ventanas'] !== null) {
                $enVentana = false;
                foreach ($regla['ventanas'] as [$desde, $hasta]) {
                    if ($hora >= $desde && $hora < $hasta) {
                        $enVentana = true;
                        break;
                    }
                }
                if (!$enVentana) {
                    continue;
                }
            }
            $minutos = $transcurrido[$tipo] ?? null;
            if ($minutos !== null && $minutos < $regla['intervalo_min']) {
                continue;
            }
            $debidos[] = $tipo;
        }
        return $debidos;
    }

    private function ejecutarChequeo(string $tipo, int $empresaId, array $params): array
    {
        return match ($tipo) {
            'precio_perdida' => $this->chequeos->precioPerdida($empresaId, $params),
            'stock_critico' => $this->chequeos->stockCritico($empresaId, $params),
            // De noche revisa el dia en curso; en la manana refuerza con ayer.
            'cierre_pendiente' => $this->chequeos->cierrePendiente(
                $empresaId,
                $params,
                (int) date('G') < 12 ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d')
            ),
            'caja_abierta' => $this->chequeos->cajaAbierta($empresaId, $params),
            'ventas_caida' => $this->chequeos->ventasCaida($empresaId, $params),
            'folios_bajos' => $this->chequeos->foliosBajos($empresaId, $params),
            'suscripcion' => $this->chequeos->suscripcion($empresaId, $params),
            'compras_borrador' => $this->chequeos->comprasBorrador($empresaId, $params),
            'resumen_diario' => $this->chequeos->resumenDiario($empresaId, $params),
            default => [],
        };
    }

    /** @return array<int, array{id: int|string, razon_social: string, email: ?string}> */
    private function empresasActivas(?int $soloEmpresa): array
    {
        if ($soloEmpresa !== null && $soloEmpresa > 0) {
            $stmt = $this->db->prepare(
                'SELECT id, razon_social, email FROM empresas WHERE id = :id AND activo = 1'
            );
            $stmt->execute([':id' => $soloEmpresa]);
            return $stmt->fetchAll();
        }
        return $this->db
            ->query('SELECT id, razon_social, email FROM empresas WHERE activo = 1 ORDER BY id')
            ->fetchAll();
    }

    private function marcarCorridos(array $tipos): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO agente_alertas_estado (chequeo, last_run_at) VALUES (:chequeo, NOW())
             ON DUPLICATE KEY UPDATE last_run_at = NOW()'
        );
        foreach ($tipos as $tipo) {
            $stmt->execute([':chequeo' => $tipo]);
        }
    }
}
