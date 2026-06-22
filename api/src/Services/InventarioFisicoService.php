<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Config\Database;
use Mypos\Core\HttpException;
use Mypos\Middleware\TenantMiddleware;
use Mypos\Repositories\InventarioFisicoRepository;

final class InventarioFisicoService
{
    private InventarioFisicoRepository $repository;
    private StockService $stockService;

    public function __construct()
    {
        $pdo = Database::connection();
        $this->repository   = new InventarioFisicoRepository($pdo);
        $this->stockService = new StockService();
    }

    public function listar(int $userId, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $this->tenant($userId, $empresaId);

        $rows = $this->repository->list($empresaId, 30);

        return ['inventarios' => array_map(static fn (array $r): array => [
            'id'             => (int) $r['id'],
            'nombre'         => $r['nombre'],
            'estado'         => $r['estado'],
            'sucursal_id'    => (int) $r['sucursal_id'],
            'sucursal_nombre' => $r['sucursal_nombre'] ?? null,
            'total_items'    => (int) $r['total_items'],
            'items_contados' => (int) $r['items_contados'],
            'items_con_dif'  => (int) $r['items_con_dif'],
            'notas'          => $r['notas'],
            'created_at'     => $r['created_at'],
            'applied_at'     => $r['applied_at'],
        ], $rows)];
    }

    public function crear(int $userId, array $body): array
    {
        $empresaId  = $this->requireEmpresaId($body);
        $sucursalId = $this->requirePositiveInt($body, 'sucursal_id', 'sucursal_id es obligatorio');
        $nombre     = trim((string) ($body['nombre'] ?? ''));
        if ($nombre === '') {
            throw new HttpException('El nombre del inventario es obligatorio', 422);
        }
        $this->tenant($userId, $empresaId);

        $id = $this->repository->create([
            'empresa_id'  => $empresaId,
            'sucursal_id' => $sucursalId,
            'nombre'      => $nombre,
            'usuario_id'  => $userId,
        ]);

        $total = $this->repository->populateItems($id, $empresaId, $sucursalId);

        return ['id' => $id, 'total_items' => $total];
    }

    public function get(int $userId, int $id, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $this->tenant($userId, $empresaId);

        $inv = $this->findOrFail($empresaId, $id);
        $items = $this->repository->getItems($id, $empresaId);

        return [
            'id'             => (int) $inv['id'],
            'nombre'         => $inv['nombre'],
            'estado'         => $inv['estado'],
            'sucursal_id'    => (int) $inv['sucursal_id'],
            'total_items'    => (int) $inv['total_items'],
            'items_contados' => (int) $inv['items_contados'],
            'items_con_dif'  => (int) $inv['items_con_dif'],
            'notas'          => $inv['notas'],
            'created_at'     => $inv['created_at'],
            'applied_at'     => $inv['applied_at'],
            'items'          => array_map(static fn (array $it): array => [
                'id'                   => (int) $it['id'],
                'producto_id'          => (int) $it['producto_id'],
                'nombre_producto'      => $it['nombre_producto'],
                'codigo_producto'      => $it['codigo_producto'],
                'cantidad_sistema'     => (float) $it['cantidad_sistema'],
                'cantidad_contada'     => $it['cantidad_contada'] !== null ? (float) $it['cantidad_contada'] : null,
                'ajuste_movimiento_id' => $it['ajuste_movimiento_id'] !== null ? (int) $it['ajuste_movimiento_id'] : null,
            ], $items),
        ];
    }

    public function guardarConteos(int $userId, int $id, array $body): array
    {
        $empresaId = $this->requireEmpresaId($body);
        $this->tenant($userId, $empresaId);

        $inv = $this->findOrFail($empresaId, $id);
        if ($inv['estado'] === 'APLICADO') {
            throw new HttpException('El inventario ya fue aplicado y no puede modificarse', 409);
        }

        $items = $body['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new HttpException('Se requiere al menos un item con cantidad_contada', 422);
        }

        $saved = $this->repository->saveConteoLote($id, $empresaId, $items);

        return ['saved' => $saved];
    }

    public function aplicar(int $userId, int $id, array $params): array
    {
        $empresaId = $this->requireEmpresaId($params);
        $this->tenant($userId, $empresaId);

        $inv = $this->findOrFail($empresaId, $id);
        if ($inv['estado'] === 'APLICADO') {
            throw new HttpException('El inventario ya fue aplicado', 409);
        }

        $items = $this->repository->getItemsConDiferencia($id, $empresaId);
        $ajustados = 0;

        foreach ($items as $item) {
            $diferencia = round((float) $item['cantidad_contada'] - (float) $item['cantidad_sistema'], 3);
            if (abs($diferencia) < 0.001) {
                continue;
            }

            $resultado = $this->stockService->ajustarStock([
                'empresa_id'     => $empresaId,
                'sucursal_id'    => (int) $inv['sucursal_id'],
                'producto_id'    => (int) $item['producto_id'],
                'tipo'           => 'AJUSTE',
                'cantidad'       => $diferencia,
                'referencia_tipo' => 'INVENTARIO_FISICO',
                'referencia_id'  => $id,
                'usuario_id'     => $userId,
                'observacion'    => 'Inventario físico: ' . $inv['nombre'],
            ]);

            $this->repository->setAjusteMovimiento((int) $item['id'], (int) $resultado['movimiento_id']);
            $ajustados++;
        }

        $this->repository->marcarAplicado($empresaId, $id);

        return [
            'inventario_id' => $id,
            'estado'        => 'APLICADO',
            'ajustados'     => $ajustados,
        ];
    }

    // ─── Privados ───

    private function findOrFail(int $empresaId, int $id): array
    {
        $inv = $this->repository->find($empresaId, $id);
        if ($inv === null) {
            throw new HttpException('Inventario no encontrado', 404);
        }
        return $inv;
    }

    private function requireEmpresaId(array $params): int
    {
        $id = (int) ($params['empresa_id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException('empresa_id obligatorio', 422);
        }
        return $id;
    }

    private function requirePositiveInt(array $params, string $field, string $message): int
    {
        $v = (int) ($params[$field] ?? 0);
        if ($v <= 0) {
            throw new HttpException($message, 422);
        }
        return $v;
    }

    private function tenant(int $userId, int $empresaId): void
    {
        (new TenantMiddleware())->handle($userId, $empresaId);
    }
}
